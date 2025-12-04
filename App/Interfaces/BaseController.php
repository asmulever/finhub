<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\LogService;
use App\Infrastructure\IdentityProviderFactory;
use App\Infrastructure\IdentityProviderInterface;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

abstract class BaseController
{
    private ?IdentityProviderInterface $identityProvider = null;
    private ?JwtService $guardedJwtService = null;
    private ?LogService $logService = null;

    protected function recordAuthenticatedUser(object $payload): void
    {
        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        if ($userId !== null) {
            RequestContext::setUserId($userId);
        }
    }

    protected function logWarning(int $status, string $message, array $context = []): void
    {
        $this->logger()->warning($message, array_merge($context, [
            'http_status' => $status,
            'origin' => $context['origin'] ?? static::class,
        ]));
    }

    protected function logger(): LogService
    {
        if ($this->logService === null) {
            $this->logService = LogService::getInstance();
        }

        return $this->logService;
    }

    protected function authorize(JwtService $jwtService, bool $requireAdmin = false): ?object
    {
        $payload = $this->getIdentityProvider($jwtService)->authorize(RequestContext::getRoute(), static::class);
        if ($payload === null) {
            return null;
        }

        if ($requireAdmin && (($payload->role ?? '') !== 'admin')) {
            $this->logWarning(403, 'Forbidden access to admin endpoint', ['user_id' => $payload->uid ?? null, 'route' => RequestContext::getRoute()]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }

        return $payload;
    }

    protected function authorizeAdmin(JwtService $jwtService): ?object
    {
        return $this->authorize($jwtService, true);
    }

    private function getIdentityProvider(JwtService $jwtService): IdentityProviderInterface
    {
        if ($this->identityProvider === null || $this->guardedJwtService !== $jwtService) {
            $this->identityProvider = IdentityProviderFactory::create($jwtService);
            $this->guardedJwtService = $jwtService;
        }

        return $this->identityProvider;
    }

    protected function getAccessTokenFromRequest(JwtService $jwtService): ?string
    {
        return $this->getIdentityProvider($jwtService)->extractAccessToken();
    }

    protected function getJsonInput(): ?array
    {
        $rawInput = file_get_contents('php://input') ?: '';
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            $this->logWarning(400, 'Invalid JSON body', ['route' => RequestContext::getRoute()]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return null;
        }

        RequestContext::setRequestPayload($input);
        return $input;
    }
}

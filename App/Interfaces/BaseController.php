<?php

declare(strict_types=1);

namespace App\Interfaces;

require_once __DIR__ . '/Security/AuthorizationHeaderProvider.php';
require_once __DIR__ . '/Security/ServerArrayAuthorizationHeaderProvider.php';
require_once __DIR__ . '/Security/GetAllHeadersAuthorizationHeaderProvider.php';
require_once __DIR__ . '/Security/ApacheRequestHeadersAuthorizationHeaderProvider.php';
require_once __DIR__ . '/Security/CompositeAuthorizationHeaderProvider.php';
require_once __DIR__ . '/Security/AccessTokenExtractor.php';

use App\Application\LogService;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;
use App\Interfaces\Security\AccessTokenExtractor;
use App\Interfaces\Security\ApacheRequestHeadersAuthorizationHeaderProvider;
use App\Interfaces\Security\CompositeAuthorizationHeaderProvider;
use App\Interfaces\Security\GetAllHeadersAuthorizationHeaderProvider;
use App\Interfaces\Security\ServerArrayAuthorizationHeaderProvider;

abstract class BaseController
{
    private ?CompositeAuthorizationHeaderProvider $authorizationProvider = null;
    private ?LogService $logService = null;

    protected function getAccessTokenFromRequest(): ?string
    {
        if ($this->authorizationProvider === null) {
            $this->authorizationProvider = new CompositeAuthorizationHeaderProvider([
                new ServerArrayAuthorizationHeaderProvider(),
                new GetAllHeadersAuthorizationHeaderProvider(),
                new ApacheRequestHeadersAuthorizationHeaderProvider(),
            ]);
        }

        $token = AccessTokenExtractor::extract($this->authorizationProvider);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $cookieToken = $_COOKIE['access_token'] ?? null;
        return (is_string($cookieToken) && $cookieToken !== '') ? $cookieToken : null;
    }

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

    protected function authorizeAdmin(JwtService $jwtService): ?object
    {
        $token = $this->getAccessTokenFromRequest();
        if ($token === null) {
            $this->logWarning(401, 'Missing token for admin endpoint', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logWarning(401, 'Invalid token for admin endpoint', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);
        if (($payload->role ?? '') !== 'admin') {
            $this->logWarning(403, 'Forbidden access to admin endpoint', ['user_id' => $payload->uid ?? null, 'route' => RequestContext::getRoute()]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }

        return $payload;
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

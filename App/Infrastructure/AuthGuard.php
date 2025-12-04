<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\LogService;
use App\Interfaces\Security\AccessTokenExtractor;
use App\Interfaces\Security\ApacheRequestHeadersAuthorizationHeaderProvider;
use App\Interfaces\Security\CompositeAuthorizationHeaderProvider;
use App\Interfaces\Security\GetAllHeadersAuthorizationHeaderProvider;
use App\Interfaces\Security\ServerArrayAuthorizationHeaderProvider;
use App\Infrastructure\RequestContext;

final class AuthGuard implements IdentityProviderInterface
{
    private ?CompositeAuthorizationHeaderProvider $authorizationProvider = null;
    private ?string $lastRoute = null;

    public function __construct(
        private readonly JwtService $jwtService,
        private readonly LogService $logger
    ) {
    }

    public function authorize(string $route, string $origin): ?object
    {
        $token = $this->extractAccessToken();
        if ($token === null) {
            $this->logWarning(401, 'Missing token', $route, $origin);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logWarning(401, 'Invalid token', $route, $origin);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);
        return $payload;
    }

    public function extractAccessToken(): ?string
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

    private function logWarning(int $status, string $message, string $route, string $origin): void
    {
        $this->logger->warning($message, [
            'http_status' => $status,
            'origin' => $origin,
            'route' => $route,
        ]);
    }

    private function recordAuthenticatedUser(object $payload): void
    {
        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        if ($userId !== null) {
            RequestContext::setUserId($userId);
        }
    }
}

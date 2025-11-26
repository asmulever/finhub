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
}

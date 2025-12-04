<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\LogService;
use App\Infrastructure\Config;
use App\Infrastructure\RequestContext;

final class AllowAnonymousIdentityProvider implements IdentityProviderInterface
{
    private readonly object $payload;

    public function __construct(private readonly LogService $logger)
    {
        $this->payload = $this->buildPayload();
    }

    public function authorize(string $route, string $origin): ?object
    {
        $this->logger->info('Allowing anonymous access', [
            'origin' => $origin,
            'route' => $route,
        ]);

        $this->recordAuthenticatedUser($this->payload);
        return $this->payload;
    }

    public function extractAccessToken(): ?string
    {
        return null;
    }

    private function recordAuthenticatedUser(object $payload): void
    {
        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        if ($userId !== null) {
            RequestContext::setUserId($userId);
        }
    }

    private function buildPayload(): object
    {
        $raw = Config::get('AUTH_TEST_PAYLOAD', '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return (object)$decoded;
            }
        }

        return (object)[
            'uid' => 1,
            'email' => 'anonymous@localhost',
            'role' => 'admin',
        ];
    }
}

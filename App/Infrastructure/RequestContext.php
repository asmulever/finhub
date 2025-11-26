<?php

declare(strict_types=1);

namespace App\Infrastructure;

class RequestContext
{
    private static array $data = [
        'correlation_id' => null,
        'method' => 'GET',
        'route' => '/',
        'query_params' => [],
        'request_payload' => null,
        'user_id' => null,
        'client_ip' => null,
        'user_agent' => null,
    ];

    private static array $sensitiveKeys = [
        'password',
        'pass',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'auth',
        'secret',
    ];

    public static function bootstrap(array $server, string $requestUri, string $requestMethod): void
    {
        self::$data['correlation_id'] = bin2hex(random_bytes(12));
        self::$data['method'] = strtoupper($requestMethod ?: 'GET');
        self::setRoute($requestUri);
        self::setQueryParams($_GET ?? []);
        self::$data['client_ip'] = self::detectClientIp($server);
        self::$data['user_agent'] = $server['HTTP_USER_AGENT'] ?? 'unknown';
    }

    public static function setRoute(string $route): void
    {
        $normalized = $route === '' ? '/' : $route;
        self::$data['route'] = $normalized;
    }

    public static function getRoute(): string
    {
        return self::$data['route'];
    }

    public static function getCorrelationId(): string
    {
        if (self::$data['correlation_id'] === null) {
            self::$data['correlation_id'] = bin2hex(random_bytes(12));
        }
        return (string)self::$data['correlation_id'];
    }

    public static function setUserId(?int $userId): void
    {
        self::$data['user_id'] = $userId;
    }

    public static function getUserId(): ?int
    {
        return self::$data['user_id'];
    }

    public static function setRequestPayload(?array $payload): void
    {
        self::$data['request_payload'] = $payload === null ? null : self::sanitizeArray($payload);
    }

    public static function setQueryParams(array $params): void
    {
        self::$data['query_params'] = self::sanitizeArray($params);
    }

    public static function getData(): array
    {
        return self::$data;
    }

    public static function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
                continue;
            }

            if (self::isSensitiveKey((string)$key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $keyLower = strtolower($key);
        foreach (self::$sensitiveKeys as $sensitive) {
            if (str_contains($keyLower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function detectClientIp(array $server): string
    {
        $candidates = [
            $server['HTTP_X_FORWARDED_FOR'] ?? null,
            $server['HTTP_CLIENT_IP'] ?? null,
            $server['REMOTE_ADDR'] ?? 'unknown',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim(explode(',', $candidate)[0]);
            }
        }

        return 'unknown';
    }
}

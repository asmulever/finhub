<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\LogService;
use App\Infrastructure\Config;

final class CronSecretValidator
{
    private const ORIGIN = 'etl-cron';

    public function __construct(private readonly LogService $logger)
    {
    }

    /**
     * Verifica el secreto del cron y responde si falta o no coincide.
     *
     * @param string $route Ruta solicitada para contexto del log.
     * @param array<string,mixed> $queryParams
     * @param array<string,mixed> $serverVars
     */
    public function validate(string $route, array $queryParams, array $serverVars): bool
    {
        $expected = $this->getCronSecretFromEnv();
        if ($expected === null || $expected === '') {
            $this->logger->warning('CRON secret is not configured', [
                'origin' => self::ORIGIN,
                'route' => $route,
                'http_status' => 500,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'CRON secret not configured']);
            return false;
        }

        $provided = $this->extractProvidedSecret($queryParams, $serverVars);
        if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
            $this->logger->warning('Invalid CRON secret', [
                'route' => $route,
                'origin' => self::ORIGIN,
                'http_status' => 403,
            ]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $queryParams
     * @param array<string,mixed> $serverVars
     */
    private function extractProvidedSecret(array $queryParams, array $serverVars): ?string
    {
        if (
            isset($queryParams['secret']) &&
            is_string($queryParams['secret']) &&
            $queryParams['secret'] !== ''
        ) {
            return $queryParams['secret'];
        }

        if (
            isset($serverVars['HTTP_X_CRON_KEY']) &&
            is_string($serverVars['HTTP_X_CRON_KEY']) &&
            $serverVars['HTTP_X_CRON_KEY'] !== ''
        ) {
            return $serverVars['HTTP_X_CRON_KEY'];
        }

        return null;
    }

    private function getCronSecretFromEnv(): ?string
    {
        $primary = Config::get('CRON_SECRET');
        if (is_string($primary) && $primary !== '') {
            return $primary;
        }

        $legacy = Config::get('cronapikey');
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return null;
    }
}

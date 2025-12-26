<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\MarketData\ProviderMetrics;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

/**
 * Consolida el uso real de proveedores (TwelveData/EODHD) para el dashboard.
 */
final class ProviderUsageService
{
    private ?TwelveDataClient $twelveDataClient;
    private ?EodhdClient $eodhdClient;
    private ProviderMetrics $metrics;
    private LoggerInterface $logger;

    public function __construct(
        ?TwelveDataClient $twelveDataClient,
        ?EodhdClient $eodhdClient,
        ProviderMetrics $metrics,
        LoggerInterface $logger
    ) {
        $this->twelveDataClient = $twelveDataClient;
        $this->eodhdClient = $eodhdClient;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    /**
     * Devuelve allowed/used/remaining priorizando datos reales de los proveedores.
     */
    public function getUsage(): array
    {
        $base = $this->metrics->getAll();
        $providers = [
            'twelvedata' => $this->baseProvider($base['providers']['twelvedata'] ?? []),
            'eodhd' => $this->baseProvider($base['providers']['eodhd'] ?? []),
        ];

        $tdUsage = $this->fetchTwelveDataUsage();
        if ($tdUsage !== null) {
            $providers['twelvedata']['allowed'] = $tdUsage['allowed'];
            $providers['twelvedata']['remaining'] = $tdUsage['remaining'];
            $providers['twelvedata']['used'] = $tdUsage['used'];
        }

        $eodhdUsage = $this->fetchEodhdUsage();
        if ($eodhdUsage !== null) {
            $providers['eodhd']['allowed'] = $eodhdUsage['allowed'];
            $providers['eodhd']['remaining'] = $eodhdUsage['remaining'];
            $providers['eodhd']['used'] = $eodhdUsage['used'];
        }

        return ['providers' => $providers];
    }

    private function baseProvider(array $metrics): array
    {
        $provider = array_merge([
            'allowed' => null,
            'used' => null,
            'success' => 0,
            'failed' => 0,
            'remaining' => null,
        ], $metrics);
        // Eliminamos contadores locales de allowed/used/remaining: solo se llenan con datos remotos.
        $provider['allowed'] = null;
        $provider['used'] = null;
        $provider['remaining'] = null;
        return $provider;
    }

    private function fetchTwelveDataUsage(): ?array
    {
        if ($this->twelveDataClient === null) {
            return null;
        }
        try {
            $usage = $this->twelveDataClient->fetchUsage();
            $limit = $usage['limit'] ?? null;
            $remaining = $usage['remaining'] ?? null;
            $used = $usage['used'] ?? null;
            if ($used === null && $limit !== null && $remaining !== null) {
                $used = max(0, $limit - $remaining);
            }
            if ($limit === null && $remaining === null && $used === null) {
                return null;
            }
            return [
                'allowed' => $limit,
                'remaining' => $remaining,
                'used' => $used,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('provider_usage.twelvedata.error', [
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchEodhdUsage(): ?array
    {
        if ($this->eodhdClient === null) {
            return null;
        }
        $payload = null;
        try {
            $payload = $this->eodhdClient->fetchUser();
        } catch (\Throwable $exception) {
            $this->logger->warning('provider_usage.eodhd.user_error', [
                'message' => $exception->getMessage(),
            ]);
        }
        $limit = $this->findFirstNumeric($payload ?? [], ['limit', 'requests_limit', 'daily_limit']);
        $remaining = $this->findFirstNumeric($payload ?? [], ['remaining', 'requests_left', 'requests_remaining']);
        $used = $this->findFirstNumeric($payload ?? [], ['used', 'requests_used']);

        $rateLimit = $this->eodhdClient->getLastRateLimit();
        if (is_array($rateLimit)) {
            if (isset($rateLimit['limit']) && $rateLimit['limit'] !== null) {
                $limit = (int) $rateLimit['limit'];
            }
            if (isset($rateLimit['remaining']) && $rateLimit['remaining'] !== null) {
                $remaining = (int) $rateLimit['remaining'];
            }
        }

        if ($used === null && $limit !== null && $remaining !== null) {
            $used = max(0, $limit - $remaining);
        }
        if ($limit === null && $remaining === null && $used === null) {
            return null;
        }

        return [
            'allowed' => $limit,
            'remaining' => $remaining,
            'used' => $used,
        ];
    }

    private function findFirstNumeric(array $data, array $keys): ?int
    {
        $queue = [$data];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (!is_array($current)) {
                continue;
            }
            foreach ($current as $key => $value) {
                foreach ($keys as $candidate) {
                    if (is_string($key) && strcasecmp($key, $candidate) === 0 && is_numeric($value)) {
                        return (int) $value;
                    }
                }
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }
        return null;
    }
}

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
            'twelvedata' => $this->baseProvider($base['providers']['twelvedata'] ?? [], 'twelvedata'),
            'eodhd' => $this->baseProvider($base['providers']['eodhd'] ?? [], 'eodhd'),
            'alphavantage' => $this->baseProvider($base['providers']['alphavantage'] ?? [], 'alphavantage'),
        ];

        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $snapshot = $base['snapshot'] ?? null;
        $snapshotDate = is_array($snapshot) ? ($snapshot['date'] ?? null) : null;
        $snapshotProviders = is_array($snapshot) ? ($snapshot['providers'] ?? []) : [];

        if ($snapshotDate === $today && !empty($snapshotProviders)) {
            $providers = $this->applySnapshot($providers, $snapshotProviders);
            return ['providers' => $providers];
        }

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
            if ($eodhdUsage['remaining'] !== null && $eodhdUsage['remaining'] <= 2) {
                $this->metrics->disable('eodhd', $this->secondsUntilTomorrow(), 'remaining_low');
                $providers['eodhd']['disabled_reason'] = 'remaining_low';
            }
        }

        $this->metrics->storeSnapshot([
            'twelvedata' => [
                'allowed' => $providers['twelvedata']['allowed'],
                'remaining' => $providers['twelvedata']['remaining'],
                'used' => $providers['twelvedata']['used'],
            ],
            'eodhd' => [
                'allowed' => $providers['eodhd']['allowed'],
                'remaining' => $providers['eodhd']['remaining'],
                'used' => $providers['eodhd']['used'],
            ],
            'alphavantage' => [
                'allowed' => $providers['alphavantage']['allowed'],
                'remaining' => $providers['alphavantage']['remaining'],
                'used' => $providers['alphavantage']['used'],
            ],
        ]);

        return ['providers' => $providers];
    }

    private function baseProvider(array $metrics, string $name): array
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
        $disabledInfo = $this->metrics->disabledInfo($name);
        $provider['disabled'] = $disabledInfo['disabled'];
        $provider['disabled_until'] = $disabledInfo['until'];
        $provider['disabled_reason'] = $provider['disabled_reason'] ?? $disabledInfo['reason'];
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
            if ($this->isQuotaError($exception)) {
                $this->metrics->disable('eodhd', $this->secondsUntilTomorrow(), 'quota_error');
            }
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

    /**
     * @param array<string,array<string,mixed>> $providers
     * @param array<string,array<string,mixed>> $snapshotProviders
     * @return array<string,array<string,mixed>>
     */
    private function applySnapshot(array $providers, array $snapshotProviders): array
    {
        foreach ($snapshotProviders as $name => $data) {
            if (!isset($providers[$name])) {
                $providers[$name] = $this->baseProvider([], $name);
            }
            foreach (['allowed', 'remaining', 'used'] as $field) {
                if (isset($data[$field])) {
                    $providers[$name][$field] = $data[$field];
                }
            }
        }
        return $providers;
    }

    private function isQuotaError(\Throwable $exception): bool
    {
        $msg = strtolower($exception->getMessage());
        return str_contains($msg, '402') || str_contains($msg, '403') || str_contains($msg, 'payment required') || str_contains($msg, 'quota');
    }

    private function secondsUntilTomorrow(): int
    {
        $now = new \DateTimeImmutable('now');
        $tomorrow = $now->setTime(0, 0)->modify('+1 day');
        return max(60, (int) ($tomorrow->getTimestamp() - $now->getTimestamp()));
    }
}

<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

/**
 * Contador diario de llamadas por proveedor, persistido en archivo JSON.
 * Se reinicia automáticamente al cambiar la fecha.
 */
final class ProviderMetrics
{
    private string $baseDir;
    private int $twelveDailyLimit;
    private int $eodhdDailyLimit;
    private int $alphaDailyLimit;
    private int $noDataTtl;

    public function __construct(string $baseDir, int $twelveDailyLimit = 800, int $eodhdDailyLimit = 20, int $alphaDailyLimit = 25)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->twelveDailyLimit = $twelveDailyLimit;
        $this->eodhdDailyLimit = $eodhdDailyLimit;
        $this->alphaDailyLimit = $alphaDailyLimit;
        $this->noDataTtl = 86400;
    }

    /**
    * Registra un intento (éxito o fallo) para un proveedor.
    */
    public function record(string $provider, bool $success): void
    {
        $provider = strtolower($provider);
        $data = $this->load();
        if (!isset($data['providers'][$provider])) {
            $data['providers'][$provider] = $this->defaults($provider);
        }
        $data['providers'][$provider]['used']++;
        if ($success) {
            $data['providers'][$provider]['success']++;
        } else {
            $data['providers'][$provider]['failed']++;
        }
        $this->save($data);
    }

    /**
     * Devuelve el estado actual, calculando remaining.
     */
    public function getAll(): array
    {
        $data = $this->load();
        foreach (['twelvedata', 'eodhd', 'alphavantage'] as $providerName) {
            if (!isset($data['providers'][$providerName])) {
                $data['providers'][$providerName] = $this->defaults($providerName);
            }
            $this->normalizeDisabled($data['providers'][$providerName]);
        }
        foreach ($data['providers'] as $name => &$provider) {
            $provider['remaining'] = max(0, ($provider['allowed'] ?? 0) - ($provider['used'] ?? 0));
        }
        $data['no_data'] = $this->pruneNoData($data['no_data'] ?? []);
        $this->save($data);
        return $data;
    }

    public function disable(string $provider, int $seconds, string $reason): void
    {
        $provider = strtolower($provider);
        $data = $this->load();
        if (!isset($data['providers'][$provider])) {
            $data['providers'][$provider] = $this->defaults($provider);
        }
        $data['providers'][$provider]['disabled_until'] = (new \DateTimeImmutable())->modify('+' . $seconds . ' seconds')->format(\DateTimeInterface::ATOM);
        $data['providers'][$provider]['disabled_reason'] = $reason;
        $this->save($data);
    }

    public function isDisabled(string $provider): bool
    {
        $provider = strtolower($provider);
        $data = $this->load();
        if (!isset($data['providers'][$provider])) {
            return false;
        }
        $this->normalizeDisabled($data['providers'][$provider]);
        $this->save($data);
        $until = $data['providers'][$provider]['disabled_until'] ?? null;
        if ($until === null) {
            return false;
        }
        return (new \DateTimeImmutable($until)) > new \DateTimeImmutable('now');
    }

    public function disabledInfo(string $provider): array
    {
        $provider = strtolower($provider);
        $data = $this->load();
        if (!isset($data['providers'][$provider])) {
            return ['disabled' => false, 'until' => null, 'reason' => null];
        }
        $this->normalizeDisabled($data['providers'][$provider]);
        $this->save($data);
        $until = $data['providers'][$provider]['disabled_until'] ?? null;
        $reason = $data['providers'][$provider]['disabled_reason'] ?? null;
        $disabled = $until !== null && (new \DateTimeImmutable($until)) > new \DateTimeImmutable('now');
        return ['disabled' => $disabled, 'until' => $until, 'reason' => $reason];
    }

    public function markNoData(string $provider, string $symbol, ?string $exchange = null, ?int $ttlSeconds = null): void
    {
        $provider = strtolower($provider);
        $data = $this->load();
        $data['no_data'] = $this->pruneNoData($data['no_data'] ?? []);
        $ttl = $ttlSeconds !== null ? $ttlSeconds : $this->noDataTtl;
        $data['no_data'][$this->noDataKey($provider, $symbol, $exchange)] = [
            'provider' => $provider,
            'symbol' => strtoupper(trim($symbol)),
            'exchange' => $exchange !== null ? strtoupper(trim($exchange)) : null,
            'expires_at' => time() + $ttl,
        ];
        $this->save($data);
    }

    public function isNoData(string $provider, string $symbol, ?string $exchange = null): bool
    {
        $provider = strtolower($provider);
        $data = $this->load();
        $data['no_data'] = $this->pruneNoData($data['no_data'] ?? []);
        $this->save($data);
        $key = $this->noDataKey($provider, $symbol, $exchange);
        return isset($data['no_data'][$key]);
    }

    private function load(): array
    {
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $this->ensureDir();
        $path = $this->path();
        if (!file_exists($path)) {
            return $this->fresh($today);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || ($decoded['date'] ?? '') !== $today) {
            return $this->fresh($today);
        }
        return $decoded;
    }

    private function save(array $data): void
    {
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $path = $this->path();
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function fresh(string $date): array
    {
        return [
            'date' => $date,
            'providers' => [
                'twelvedata' => $this->defaults('twelvedata'),
                'eodhd' => $this->defaults('eodhd'),
                'alphavantage' => $this->defaults('alphavantage'),
            ],
            'no_data' => [],
            'snapshot' => [
                'date' => null,
                'providers' => [],
            ],
        ];
    }

    private function defaults(string $provider): array
    {
        $allowed = match ($provider) {
            'eodhd' => $this->eodhdDailyLimit,
            'alphavantage' => $this->alphaDailyLimit,
            default => $this->twelveDailyLimit,
        };
        return [
            'allowed' => $allowed,
            'used' => 0,
            'success' => 0,
            'failed' => 0,
            'last_reset' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'disabled_until' => null,
            'disabled_reason' => null,
        ];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
    }

    private function path(): string
    {
        return sprintf('%s/provider_metrics.json', $this->baseDir);
    }

    private function normalizeDisabled(array &$provider): void
    {
        $until = $provider['disabled_until'] ?? null;
        if ($until === null) {
            return;
        }
        if ((new \DateTimeImmutable($until)) <= new \DateTimeImmutable('now')) {
            $provider['disabled_until'] = null;
            $provider['disabled_reason'] = null;
        }
    }

    /**
     * @param array<string,array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private function pruneNoData(array $items): array
    {
        $now = time();
        $filtered = [];
        foreach ($items as $key => $entry) {
            $expires = $entry['expires_at'] ?? 0;
            if (!is_int($expires) || $expires <= $now) {
                continue;
            }
            $filtered[$key] = $entry;
        }
        return $filtered;
    }

    private function noDataKey(string $provider, string $symbol, ?string $exchange): string
    {
        return sprintf('%s|%s|%s', strtolower($provider), strtoupper(trim($symbol)), strtoupper(trim((string) $exchange)));
    }

    /**
     * Guarda un snapshot de consumo diario (allowed/used/remaining) para todos los proveedores.
     *
     * @param array<string,array<string,mixed>> $providers
     */
    public function storeSnapshot(array $providers): void
    {
        $data = $this->load();
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $data['snapshot'] = [
            'date' => $today,
            'providers' => $providers,
        ];
        $this->save($data);
    }
}

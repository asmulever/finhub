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

    public function __construct(string $baseDir, int $twelveDailyLimit = 800, int $eodhdDailyLimit = 20)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->twelveDailyLimit = $twelveDailyLimit;
        $this->eodhdDailyLimit = $eodhdDailyLimit;
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
        foreach ($data['providers'] as $name => &$provider) {
            $provider['remaining'] = max(0, ($provider['allowed'] ?? 0) - ($provider['used'] ?? 0));
        }
        return $data;
    }

    private function load(): array
    {
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $this->ensureDir();
        $path = $this->pathForDate($today);
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
        $path = $this->pathForDate($today);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function fresh(string $date): array
    {
        return [
            'date' => $date,
            'providers' => [
                'twelvedata' => $this->defaults('twelvedata'),
                'eodhd' => $this->defaults('eodhd'),
            ],
        ];
    }

    private function defaults(string $provider): array
    {
        $allowed = match ($provider) {
            'eodhd' => $this->eodhdDailyLimit,
            default => $this->twelveDailyLimit,
        };
        return [
            'allowed' => $allowed,
            'used' => 0,
            'success' => 0,
            'failed' => 0,
            'last_reset' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
    }

    private function pathForDate(string $date): string
    {
        return sprintf('%s/providers_%s.json', $this->baseDir, str_replace('-', '', $date));
    }
}

<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

/**
 * Cache en disco para snapshots de CEDEARs de RAVA con soporte de stale/backoff.
 * (Módulo MarketData - Infrastructure)
 */
final class RavaCedearsCache
{
    private string $baseDir;
    private string $file;

    public function __construct(string $baseDir, string $fileName = 'cedears.json')
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->file = $this->baseDir . '/' . ltrim($fileName, '/');
    }

    /**
     * @return array{data?:array<string,mixed>,fetched_at?:int,ttl?:int,backoff_until?:int}|null
     */
    public function read(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persiste snapshot + metadatos (fetched_at, ttl, backoff opcional).
     *
     * @param array<string,mixed> $data
     */
    public function write(array $data, int $fetchedAt, int $ttlSeconds, ?int $backoffUntil = null): void
    {
        $this->ensureDir();
        $payload = [
            'data' => $data,
            'fetched_at' => $fetchedAt,
            'ttl' => $ttlSeconds,
        ];
        if ($backoffUntil !== null) {
            $payload['backoff_until'] = $backoffUntil;
        }
        file_put_contents($this->file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function touchBackoff(int $backoffUntil): void
    {
        $existing = $this->read();
        if ($existing === null) {
            return;
        }
        $data = $existing['data'] ?? [];
        $fetchedAt = (int) ($existing['fetched_at'] ?? time());
        $ttl = (int) ($existing['ttl'] ?? 0);
        $this->write($data, $fetchedAt, $ttl, $backoffUntil);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
    }
}

<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

/**
 * Cache simple en disco para respuestas de cotización externa.
 */
final class QuoteCache
{
    private string $baseDir;
    private int $defaultTtl;

    public function __construct(string $baseDir, int $defaultTtlSeconds = 86400)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->defaultTtl = $defaultTtlSeconds > 0 ? $defaultTtlSeconds : 86400;
    }

    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            @unlink($path);
            return null;
        }
        $expiresAt = (int) ($payload['_expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            @unlink($path);
            return null;
        }
        return $payload['data'] ?? null;
    }

    public function set(string $key, array $data, ?int $ttlSeconds = null): void
    {
        $this->ensureDir();
        $ttl = $ttlSeconds !== null && $ttlSeconds > 0 ? $ttlSeconds : $this->defaultTtl;
        $payload = [
            '_expires_at' => time() + $ttl,
            'data' => $data,
        ];
        file_put_contents($this->pathFor($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha1', $key);
        return sprintf('%s/%s.json', $this->baseDir, $hash);
    }
}

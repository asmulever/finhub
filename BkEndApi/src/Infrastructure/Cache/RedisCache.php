<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Cache;

use FinHub\Application\Cache\CacheInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;
use Predis\Client;

/**
 * Cache Redis (Predis) con prefijo y TTL por defecto.
 * Pensado para uso táctico (30 MB / 100 ops/s).
 */
final class RedisCache implements CacheInterface
{
    private Client $client;
    private LoggerInterface $logger;
    private string $prefix;
    private int $defaultTtl;
    private int $maxTtl;

    public function __construct(Client $client, LoggerInterface $logger, string $prefix = 'apb', int $defaultTtl = 120, int $maxTtl = 900)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->prefix = rtrim($prefix, ':');
        $this->defaultTtl = max(1, $defaultTtl);
        $this->maxTtl = max($this->defaultTtl, $maxTtl);
    }

    public function get(string $key, mixed $default = null)
    {
        $cacheKey = $this->qualify($key);
        try {
            $value = $this->client->get($cacheKey);
            if ($value === null) {
                return $default;
            }
            return $this->decode($value);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.redis.get_failed', [
                'key' => $cacheKey,
                'message' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds): bool
    {
        $cacheKey = $this->qualify($key);
        $ttl = $this->normalizeTtl($ttlSeconds);
        try {
            $encoded = $this->encode($value);
            $this->client->setex($cacheKey, $ttl, $encoded);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.redis.set_failed', [
                'key' => $cacheKey,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $cacheKey = $this->qualify($key);
        try {
            $this->client->del([$cacheKey]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.redis.delete_failed', [
                'key' => $cacheKey,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function remember(string $key, callable $resolver, int $ttlSeconds)
    {
        $cached = $this->get($key, null);
        if ($cached !== null) {
            return $cached;
        }
        $value = $resolver();
        $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $cacheKey = $this->qualify($key);
        $ttl = $this->normalizeTtl($ttlSeconds);
        try {
            $newValue = $this->client->incr($cacheKey);
            if ($newValue === 1) {
                $this->client->expire($cacheKey, $ttl);
            }
            return (int) $newValue;
        } catch (\Throwable $e) {
            $this->logger->warning('cache.redis.incr_failed', [
                'key' => $cacheKey,
                'message' => $e->getMessage(),
            ]);
            return 1;
        }
    }

    private function qualify(string $key): string
    {
        $clean = ltrim($key, ':');
        return $this->prefix . ':' . $clean;
    }

    private function normalizeTtl(int $ttlSeconds): int
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : $this->defaultTtl;
        return min($ttl, $this->maxTtl);
    }

    private function encode(mixed $value): string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value) || $value === null) {
            return json_encode($value);
        }
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function decode(string $payload): mixed
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $payload;
    }
}

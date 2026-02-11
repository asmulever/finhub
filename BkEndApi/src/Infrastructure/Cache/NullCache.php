<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Cache;

use FinHub\Application\Cache\CacheInterface;

/**
 * Implementación nula: no persiste nada, sirve como fallback cuando Redis no está disponible.
 */
final class NullCache implements CacheInterface
{
    public function get(string $key, mixed $default = null)
    {
        return $default;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function remember(string $key, callable $resolver, int $ttlSeconds)
    {
        return $resolver();
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        // Degradar en modo sin estado: devolver 1 para no bloquear.
        return 1;
    }
}

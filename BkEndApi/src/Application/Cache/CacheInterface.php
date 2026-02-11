<?php
declare(strict_types=1);

namespace FinHub\Application\Cache;

/**
 * Cache táctico de clave/valor con TTL.
 * Implementaciones deben ser fail-safe: no lanzar excepciones hacia Application.
 */
interface CacheInterface
{
    /**
     * @param mixed $default Valor devuelto si la clave no existe o hay error.
     * @return mixed
     */
    public function get(string $key, mixed $default = null);

    /**
     * @param mixed $value Valor serializable.
     */
    public function set(string $key, mixed $value, int $ttlSeconds): bool;

    public function delete(string $key): bool;

    /**
     * Ejecuta el callback y guarda el resultado si no existe la clave.
     *
     * @param callable():mixed $resolver
     * @return mixed
     */
    public function remember(string $key, callable $resolver, int $ttlSeconds);

    /**
     * Incrementa un contador y aplica TTL cuando se crea.
     */
    public function increment(string $key, int $ttlSeconds): int;
}

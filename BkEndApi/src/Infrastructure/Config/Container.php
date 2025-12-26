<?php
namespace FinHub\Infrastructure\Config;

final class Container
{
    /** @var array<string, mixed> */
    private array $services;
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param array<string, mixed> $services */
    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function get(string $key)
    {
        if (array_key_exists($key, $this->instances)) {
            return $this->instances[$key];
        }
        if (!array_key_exists($key, $this->services)) {
            throw new \RuntimeException(sprintf('Servicio no registrado: %s', $key));
        }
        $service = $this->services[$key];
        if ($service instanceof \Closure) {
            $resolved = $service($this);
            $this->instances[$key] = $resolved;
            return $resolved;
        }
        return $service;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->services) || array_key_exists($key, $this->instances);
    }
}

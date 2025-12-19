<?php
namespace FinHub\Infrastructure\Config;

final class Container
{
    /** @var array<string, mixed> */
    private array $services;

    /** @param array<string, mixed> $services */
    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function get(string $key)
    {
        if (!array_key_exists($key, $this->services)) {
            throw new \RuntimeException(sprintf('Servicio no registrado: %s', $key));
        }
        return $this->services[$key];
    }
}

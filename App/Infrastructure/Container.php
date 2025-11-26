<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Container
{
    /** @var array<string, array{factory: callable, shared: bool}> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory, bool $shared = true): void
    {
        $this->definitions[$id] = [
            'factory' => $factory,
            'shared' => $shared,
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || isset($this->instances[$id]);
    }

    /**
     * @template T
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new \RuntimeException("Service {$id} is not registered in the container");
        }

        $definition = $this->definitions[$id];
        $factory = $definition['factory'];
        $service = $factory($this);

        if ($definition['shared']) {
            $this->instances[$id] = $service;
        }

        return $service;
    }
}

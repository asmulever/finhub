<?php
namespace FinHub\Infrastructure\Config;

final class Config
{
    private array $vars;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
    }

    public function get(string $key, $default = null)
    {
        return $this->vars[$key] ?? $default;
    }

    public function require(string $key)
    {
        if (!array_key_exists($key, $this->vars)) {
            throw new \RuntimeException(sprintf('Configuración requerida ausente: %s', $key));
        }
        return $this->vars[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}

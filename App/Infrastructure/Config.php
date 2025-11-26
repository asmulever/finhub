<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Config
{
    private static array $instance = [];
    private static bool $loaded = false;

    public static function get(string $key, $default = null)
    {
        self::ensureLoaded();
        return array_key_exists($key, self::$instance) ? self::$instance[$key] : $default;
    }

    public static function getRequired(string $key): string
    {
        self::ensureLoaded();
        if (!array_key_exists($key, self::$instance) || self::$instance[$key] === '') {
            throw new \RuntimeException("Missing required configuration key: {$key}");
        }

        return self::$instance[$key];
    }

    public static function bootstrap(): void
    {
        self::ensureLoaded();
    }

    private static function ensureLoaded(): void
    {
        if (!self::$loaded) {
            self::load();
            self::$loaded = true;
        }
    }

    private static function load(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env file is required but was not found at project root.');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                throw new \RuntimeException("Invalid .env entry: {$line}");
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            self::$instance[$name] = $value;
            if (\function_exists('putenv')) {
                \putenv("{$name}={$value}");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Config
{
    private static array $instance = [];

    public static function get(string $key, $default = null)
    {
        if (empty(self::$instance)) {
            self::load();
        }

        return self::$instance[$key] ?? $default;
    }

    private static function load(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                self::$instance[$name] = $value;
                // Make values available to getenv for downstream consumers
                putenv("{$name}={$value}");
            }
        }
    }
}

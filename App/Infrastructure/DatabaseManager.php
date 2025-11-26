<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use PDOException;

class DatabaseManager
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $hosts = self::buildHostList(Config::getRequired('DB_HOST'));
        $port = (int)Config::getRequired('DB_PORT');
        $dbName = Config::getRequired('DB_DATABASE');
        $user = Config::getRequired('DB_USERNAME');
        $password = Config::getRequired('DB_PASSWORD');

        $lastException = null;

        foreach ($hosts as $host) {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

            try {
                $pdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo = $pdo;
                return self::$pdo;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        $error = $lastException?->getMessage() ?? 'Unable to connect to any configured host.';
        throw new \RuntimeException("Database connection failed: {$error}", 0, $lastException);
    }

    /**
     * @return string[]
     */
    private static function buildHostList(?string $hostConfig): array
    {
        $hosts = [];

        if (is_string($hostConfig) && trim($hostConfig) !== '') {
            $hosts = array_filter(array_map('trim', explode(',', $hostConfig)));
        }

        $fallback = Config::get('DB_FALLBACK_HOST');
        if (is_string($fallback) && trim($fallback) !== '') {
            $hosts[] = trim($fallback);
        }

        $hosts[] = '127.0.0.1';
        $hosts[] = 'localhost';

        return array_values(array_unique($hosts));
    }
}

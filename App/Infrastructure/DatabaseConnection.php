<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use PDOException;

class DatabaseConnection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $hosts = self::buildHostList(Config::getRequired('DB_HOST'));
            $port = (int)Config::getRequired('DB_PORT');
            $db   = Config::getRequired('DB_DATABASE');
            $user = Config::getRequired('DB_USERNAME');
            $pass = Config::getRequired('DB_PASSWORD');

            $logger = new Logger();
            $lastException = null;

            foreach ($hosts as $host) {
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

                try {
                    $pdo = new PDO($dsn, $user, $pass);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$instance = $pdo;

                    $logger->info("Database connection to $db at $host established successfully.");
                    break;
                } catch (PDOException $e) {
                    $logger->warning("Database connection attempt to {$host} failed: " . $e->getMessage());
                    $lastException = $e;
                }
            }

            if (self::$instance === null) {
                $errorMessage = $lastException?->getMessage() ?? 'No valid database hosts configured';
                $logger->error("Database connection failed after trying hosts: " . implode(', ', $hosts) . ". Error: " . $errorMessage);
                throw new \RuntimeException("Database connection failed: " . $errorMessage, 0, $lastException);
            }
        }

        return self::$instance;
    }

    /**
     * Returns the list of hosts to try when establishing the connection.
     *
     * Allows DB_HOST to be a comma-separated list and adds sane local fallbacks
     * so deployments that don't provide a Docker DNS entry (e.g., 'db') still work.
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

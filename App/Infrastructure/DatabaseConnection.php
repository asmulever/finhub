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
            $host = Config::get('DB_HOST');
            $port = Config::get('DB_PORT');
            $db   = Config::get('DB_DATABASE');
            $user = Config::get('DB_USERNAME');
            $pass = Config::get('DB_PASSWORD');

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
            $logger = new Logger();

            try {
                self::$instance = new PDO($dsn, $user, $pass);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $logger->info("Database connection to $db at $host established successfully.");
            } catch (PDOException $e) {
                $logger->error("Database connection failed: " . $e->getMessage());
                throw new \RuntimeException($e->getMessage());
            }
        }

        return self::$instance;
    }
}

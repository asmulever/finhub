<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

class SchemaManager
{
    public static function ensureSchema(): array
    {
        $logger = new Logger();

        try {
            $pdo = DatabaseConnection::getInstance();
        } catch (\Throwable $e) {
            $logger->error('Database connection failed during schema check: ' . $e->getMessage());
            return ['db' => 'error', 'message' => $e->getMessage()];
        }

        self::ensureUsersTable($pdo, $logger);
        self::ensureFinancialObjectsTable($pdo, $logger);
        self::seedData($pdo, $logger);

        return [
            'db' => 'connected',
            'users' => self::countTable($pdo, 'users'),
            'financial_objects' => self::countTable($pdo, 'financial_objects'),
        ];
    }

    private static function ensureUsersTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        self::addColumnIfMissing($pdo, $logger, 'users', 'role', "VARCHAR(50) DEFAULT 'user'");
        self::addColumnIfMissing($pdo, $logger, 'users', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    private static function ensureFinancialObjectsTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS financial_objects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            symbol VARCHAR(50) NOT NULL,
            type VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_symbol (symbol)
        )");

        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'symbol', "VARCHAR(50) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'type', "VARCHAR(50) NOT NULL DEFAULT 'unknown'");
        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        self::ensureUniqueIndex($pdo, $logger, 'financial_objects', 'unique_symbol', 'symbol');
    }

    private static function seedData(PDO $pdo, Logger $logger): void
    {
        $userCount = self::countTable($pdo, 'users');
        if ($userCount === 0) {
            $logger->info('Seeding default users.');
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (:email, :password, :role)");
            $seedUsers = [
                ['admin@example.com', 'admin123', 'admin'],
                ['analyst@example.com', 'analyst123', 'user'],
                ['ops@example.com', 'ops123', 'user'],
                ['trader@example.com', 'trader123', 'user'],
                ['auditor@example.com', 'audit123', 'user'],
            ];
            foreach ($seedUsers as [$email, $plain, $role]) {
                $stmt->execute([
                    'email' => $email,
                    'password' => password_hash($plain, PASSWORD_DEFAULT),
                    'role' => $role
                ]);
            }
        }

        $foCount = self::countTable($pdo, 'financial_objects');
        if ($foCount === 0) {
            $logger->info('Seeding default financial objects.');
            $pdo->exec("INSERT INTO financial_objects (name, symbol, type) VALUES 
                ('S&P 500 ETF', 'SPY', 'etf'),
                ('Apple Inc', 'AAPL', 'stock'),
                ('US Treasury 10Y', 'UST10Y', 'bond'),
                ('Bitcoin', 'BTC', 'crypto'),
                ('MSCI Emerging Markets ETF', 'EEM', 'etf')
            ");
        }
    }

    private static function addColumnIfMissing(PDO $pdo, Logger $logger, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
        $stmt->execute(['column' => $column]);
        $exists = $stmt->fetchColumn();

        if ($exists === false) {
            $logger->info("Adding missing column {$column} to {$table}");
            try {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            } catch (\PDOException $e) {
                $logger->warning("Could not add column {$column} to {$table}: " . $e->getMessage());
            }
        }
    }

    private static function ensureUniqueIndex(PDO $pdo, Logger $logger, string $table, string $indexName, string $column): void
    {
        $stmt = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name = :index");
        $stmt->execute(['index' => $indexName]);
        $exists = $stmt->fetchColumn();

        if ($exists === false) {
            $logger->info("Adding missing unique index {$indexName} on {$table}({$column})");
            try {
                $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$indexName} ({$column})");
            } catch (\PDOException $e) {
                $logger->warning("Could not create unique index {$indexName} on {$table}: " . $e->getMessage());
            }
        }
    }

    private static function countTable(PDO $pdo, string $table): int
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int)$stmt->fetchColumn();
    }
}

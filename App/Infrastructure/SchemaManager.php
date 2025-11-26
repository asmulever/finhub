<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

class SchemaManager
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    public static function ensureSchema(): array
    {
        $logger = new Logger();

        try {
            $pdo = DatabaseManager::getConnection();
        } catch (\Throwable $e) {
            $logger->error('Database connection failed during schema check: ' . $e->getMessage());
            return ['db' => 'error', 'message' => $e->getMessage()];
        }

        self::ensureUsersTable($pdo, $logger);
        self::ensureFinancialObjectsTable($pdo, $logger);
        self::ensureAccountsTable($pdo, $logger);
        self::ensurePortfolioTickersTable($pdo, $logger);
        self::ensureProductsTable($pdo, $logger);
        self::ensureOrdersTable($pdo, $logger);
        self::seedData($pdo, $logger);
        self::applyRootUserPolicy($pdo, $logger);
        self::ensureApiLogsTable($pdo, $logger);

        return [
            'db' => 'connected',
            'users' => self::countTable($pdo, 'users'),
            'financial_objects' => self::countTable($pdo, 'financial_objects'),
            'products' => self::countTable($pdo, 'products'),
            'orders' => self::countTable($pdo, 'orders'),
        ];
    }

    private static function ensureUsersTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) " . self::TABLE_OPTIONS);

        self::addColumnIfMissing($pdo, $logger, 'users', 'role', "VARCHAR(50) DEFAULT 'user'");
        self::addColumnIfMissing($pdo, $logger, 'users', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
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
        ) " . self::TABLE_OPTIONS);

        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'symbol', "VARCHAR(50) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'type', "VARCHAR(50) NOT NULL DEFAULT 'unknown'");
        self::addColumnIfMissing($pdo, $logger, 'financial_objects', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        self::ensureUniqueIndex($pdo, $logger, 'financial_objects', 'unique_symbol', 'symbol');
    }

    private static function ensureAccountsTable(PDO $pdo, Logger $logger): void
    {
        self::renameLegacyTable($pdo, $logger, 'accounts', 'id');

        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            broker_name VARCHAR(120) NOT NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'USD',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) " . self::TABLE_OPTIONS);
    }

    private static function ensurePortfolioTickersTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS portfolio_tickers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            financial_object_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
            avg_price DECIMAL(18,4) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pt_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_pt_financial_object FOREIGN KEY (financial_object_id) REFERENCES financial_objects(id) ON DELETE CASCADE,
            UNIQUE KEY unique_account_ticker (account_id, financial_object_id)
        ) " . self::TABLE_OPTIONS);

        if (!self::columnExists($pdo, 'portfolio_tickers', 'account_id')) {
            $logger->info('Adding account_id to portfolio_tickers.');
            $pdo->exec("ALTER TABLE portfolio_tickers ADD COLUMN account_id INT UNSIGNED NULL AFTER id");
        }

        if (self::columnExists($pdo, 'portfolio_tickers', 'portfolio_id')) {
            $logger->info('Migrating portfolio_tickers.portfolio_id to account_id.');
            $pdo->exec("UPDATE portfolio_tickers SET account_id = portfolio_id WHERE account_id IS NULL");
            $pdo->exec("ALTER TABLE portfolio_tickers DROP COLUMN portfolio_id");
        }

        $pdo->exec("ALTER TABLE portfolio_tickers MODIFY COLUMN account_id INT UNSIGNED NOT NULL");
    }

    private static function ensureProductsTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sku VARCHAR(120) NOT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_product_sku (sku)
        ) " . self::TABLE_OPTIONS);

        self::ensureUniqueIndex($pdo, $logger, 'products', 'unique_product_sku', 'sku');
    }

    private static function ensureOrdersTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) " . self::TABLE_OPTIONS);
    }

    private static function ensureApiLogsTable(PDO $pdo, Logger $logger): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(20) NOT NULL,
            http_status SMALLINT NOT NULL,
            method VARCHAR(10) NOT NULL,
            route VARCHAR(255) NOT NULL,
            message VARCHAR(500) NOT NULL,
            exception_class VARCHAR(255) NULL,
            stack_trace MEDIUMTEXT NULL,
            request_payload MEDIUMTEXT NULL,
            query_params TEXT NULL,
            user_id INT UNSIGNED NULL,
            client_ip VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            correlation_id VARCHAR(64) NOT NULL
        ) " . self::TABLE_OPTIONS);

        self::ensureIndex($pdo, $logger, 'api_logs', 'idx_api_logs_status', 'http_status');
        self::ensureIndex($pdo, $logger, 'api_logs', 'idx_api_logs_created', 'created_at');
        self::ensureIndex($pdo, $logger, 'api_logs', 'idx_api_logs_corr', 'correlation_id');
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
        $stmt->execute(['column' => $column]);
        return $stmt->fetchColumn() !== false;
    }

    private static function seedData(PDO $pdo, Logger $logger): void
    {
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

    private static function applyRootUserPolicy(PDO $pdo, Logger $logger): void
    {
        $enabled = Config::get('ENABLE_ROOT_USER', '0');
        $rootEmail = Config::getRequired('ROOT_EMAIL');
        $rootPassword = Config::getRequired('ROOT_PASSWORD');
        $fullAdminRole = 'admin'; // Admin completo = rol admin + usuario activo.

        if ($enabled !== '1') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $rootEmail]);
            $existingId = $stmt->fetchColumn();
            if ($existingId !== false) {
                $update = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = :id");
                $update->execute(['id' => $existingId]);
                $logger->info("Root user {$rootEmail} marked inactive due to ENABLE_ROOT_USER=0.");
            }
            return;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $rootEmail]);
        $existingId = $stmt->fetchColumn();

        $hash = password_hash($rootPassword, PASSWORD_DEFAULT);
        if ($existingId === false) {
            $insert = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active) VALUES (:email, :password, :role, :is_active)");
            $insert->execute([
                'email' => $rootEmail,
                'password' => $hash,
                'role' => $fullAdminRole,
                'is_active' => 1,
            ]);
            $logger->info("Root user {$rootEmail} created via ENABLE_ROOT_USER flag.");
            return;
        }

        $update = $pdo->prepare("UPDATE users SET password_hash = :password, role = :role, is_active = 1 WHERE id = :id");
        $update->execute([
            'password' => $hash,
            'role' => $fullAdminRole,
            'id' => $existingId,
        ]);
        $logger->info("Root user {$rootEmail} updated to maintain admin status.");
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
    private static function ensureIndex(PDO $pdo, Logger $logger, string $table, string $indexName, string $column): void
    {
        $stmt = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name = :index");
        $stmt->execute(['index' => $indexName]);
        if ($stmt->fetchColumn() === false) {
            try {
                $pdo->exec("CREATE INDEX {$indexName} ON {$table} ({$column})");
            } catch (\PDOException $e) {
                $logger->warning("Could not create index {$indexName} on {$table}: " . $e->getMessage());
            }
        }
    }

    private static function renameLegacyTable(PDO $pdo, Logger $logger, string $table, string $column): void
    {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
        $stmt->execute(['table' => $table]);
        if ($stmt->fetchColumn() === false) {
            return;
        }

        $columnStmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
        $columnStmt->execute(['column' => $column]);
        $info = $columnStmt->fetch(PDO::FETCH_ASSOC);

        if ($info === false) {
            return;
        }

        $type = strtolower((string)($info['Type'] ?? ''));
        if (str_contains($type, 'char')) {
            $legacyName = "{$table}_legacy";
            $logger->warning("Renaming legacy table {$table} to {$legacyName}");
            $pdo->exec("RENAME TABLE {$table} TO {$legacyName}");
        }
    }

}

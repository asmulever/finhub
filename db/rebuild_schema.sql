-- Rebuild script for FinHub schema (InnoDB)
-- Drops tables in dependency order and recreates the MVP model.

DROP TABLE IF EXISTS portfolio_tickers;
DROP TABLE IF EXISTS portfolios;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS financial_objects;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE financial_objects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    broker_name VARCHAR(120) NOT NULL,
    currency VARCHAR(12) NOT NULL DEFAULT 'USD',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portfolio_tickers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    financial_object_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
    avg_price DECIMAL(18,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pt_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_financial_object FOREIGN KEY (financial_object_id) REFERENCES financial_objects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_account_ticker (account_id, financial_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

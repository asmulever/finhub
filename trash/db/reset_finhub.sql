-- ============================================================
-- Paso 0: Respaldo de los tickers actuales (financial_objects)
-- ============================================================
CREATE DATABASE IF NOT EXISTS finhub_tmp_backup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TABLE IF EXISTS finhub_tmp_backup.financial_objects_backup;

CREATE TABLE finhub_tmp_backup.financial_objects_backup AS
SELECT *
FROM finhub.financial_objects;

-- ============================================================
-- Paso 1: Drop + recreación total de la base finhub
-- ============================================================
DROP DATABASE IF EXISTS finhub;
CREATE DATABASE finhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE finhub;

-- =========================
-- Schema actualizado
-- =========================
CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE financial_objects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  symbol VARCHAR(50) NOT NULL UNIQUE,
  type VARCHAR(50) NOT NULL DEFAULT 'unknown',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  broker_name VARCHAR(120) NOT NULL,
  currency VARCHAR(12) NOT NULL DEFAULT 'USD',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portfolios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_portfolios_account FOREIGN KEY (account_id)
    REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE portfolio_tickers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  portfolio_id INT UNSIGNED NOT NULL,
  financial_object_id INT UNSIGNED NOT NULL,
  weight DECIMAL(10,4) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pt_portfolio FOREIGN KEY (portfolio_id)
    REFERENCES portfolios(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_financial_object FOREIGN KEY (financial_object_id)
    REFERENCES financial_objects(id) ON DELETE CASCADE,
  UNIQUE KEY unique_portfolio_ticker (portfolio_id, financial_object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Paso 2: Usuario administrador inicial
-- ============================================================
INSERT INTO users (email, password_hash, role)
VALUES (
  'root@example.com',
  '$2y$10$1hjEfskYiOxKtbBcT2GysuqqFfJxm0dC.SYQA.sr/Ceu7r1C3ryJO', -- hash de root.25
  'admin'
);

-- ============================================================
-- Paso 3: Restaurar tickers existentes
-- ============================================================
INSERT INTO financial_objects (id, name, symbol, type, created_at)
SELECT id, name, symbol, type, created_at
FROM finhub_tmp_backup.financial_objects_backup
ORDER BY id;

-- Ajustar el AUTO_INCREMENT para que continúe tras los datos restaurados
SET @nextTickerId = (SELECT COALESCE(MAX(id), 0) + 1 FROM financial_objects);
SET @sql = CONCAT('ALTER TABLE financial_objects AUTO_INCREMENT = ', @nextTickerId);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- (Opcional) agrega tickers por defecto si faltara alguno:
INSERT IGNORE INTO financial_objects (name, symbol, type)
VALUES
  ('S&P 500 ETF', 'SPY', 'etf'),
  ('Apple Inc', 'AAPL', 'stock'),
  ('US Treasury 10Y', 'UST10Y', 'bond'),
  ('Bitcoin', 'BTC', 'crypto'),
  ('MSCI Emerging Markets ETF', 'EEM', 'etf');

-- ============================================================
-- Paso 4: Limpieza de respaldo temporal
-- ============================================================
DROP TABLE IF EXISTS finhub_tmp_backup.financial_objects_backup;
DROP DATABASE IF EXISTS finhub_tmp_backup;

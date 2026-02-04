-- Reset completo de base de datos y creacion de esquema finhub
-- Advertencia: este script elimina la base existente.

DROP DATABASE IF EXISTS `finhub`;
CREATE DATABASE `finhub` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `finhub`;

-- Usuarios y sesiones
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` CHAR(36) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessions_token_unique` (`token`),
  KEY `sessions_user_idx` (`user_id`),
  CONSTRAINT `sessions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Portfolios e instrumentos
CREATE TABLE IF NOT EXISTS `portfolios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `base_currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `portfolios_user_idx` (`user_id`),
  CONSTRAINT `portfolios_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `instruments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(32) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `exchange` VARCHAR(64) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instruments_symbol_unique` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `holdings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `instrument_id` INT UNSIGNED NOT NULL,
  `quantity` DECIMAL(28,8) NOT NULL,
  `avg_cost` DECIMAL(28,8) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `holdings_portfolio_idx` (`portfolio_id`),
  KEY `holdings_instrument_idx` (`instrument_id`),
  CONSTRAINT `holdings_portfolio_fk` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `holdings_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instrumentos por portfolio (catálogo del usuario)
CREATE TABLE IF NOT EXISTS `portfolio_instruments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `especie` VARCHAR(32) NOT NULL,
  `name` VARCHAR(191) DEFAULT NULL,
  `exchange` VARCHAR(64) DEFAULT NULL,
  `currency` CHAR(3) DEFAULT NULL,
  `country` VARCHAR(64) DEFAULT NULL,
  `type` VARCHAR(64) DEFAULT NULL,
  `mic_code` VARCHAR(16) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_portfolio_especie` (`portfolio_id`, `especie`),
  KEY `idx_portfolio_id` (`portfolio_id`),
  CONSTRAINT `fk_portfolio_instruments_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instrument_id` INT UNSIGNED NOT NULL,
  `as_of` DATETIME NOT NULL,
  `open` DECIMAL(28,8) NOT NULL,
  `high` DECIMAL(28,8) NOT NULL,
  `low` DECIMAL(28,8) NOT NULL,
  `close` DECIMAL(28,8) NOT NULL,
  `volume` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `source` VARCHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prices_unique` (`instrument_id`, `as_of`, `source`),
  KEY `prices_instrument_idx` (`instrument_id`),
  CONSTRAINT `prices_instrument_fk` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ingestion y auditoria
CREATE TABLE IF NOT EXISTS `ingestion_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(64) NOT NULL,
  `mode` ENUM('delta','backfill') NOT NULL,
  `status` ENUM('pending','success','failed') NOT NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  `metrics_json` JSON NOT NULL,
  `error_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ingestion_runs_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(128) NOT NULL,
  `entity` VARCHAR(128) NOT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `data_json` JSON NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_actor_idx` (`actor_user_id`),
  CONSTRAINT `audit_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Predicciones
CREATE TABLE IF NOT EXISTS `instrument_prediction_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('global','user') NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('running','success','partial','failed') NOT NULL,
  `started_at` DATETIME(6) NOT NULL,
  `finished_at` DATETIME(6) DEFAULT NULL,
  `summary_json` JSON DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `instrument_prediction_runs_user_idx` (`user_id`),
  KEY `instrument_prediction_runs_status_idx` (`status`),
  CONSTRAINT `instrument_prediction_runs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `instrument_predictions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `symbol` VARCHAR(32) NOT NULL,
  `horizon_days` INT UNSIGNED NOT NULL,
  `prediction` ENUM('up','down','neutral') NOT NULL,
  `confidence` DECIMAL(5,4) DEFAULT NULL,
  `change_pct` DECIMAL(10,6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `instrument_predictions_user_idx` (`user_id`),
  KEY `instrument_predictions_run_idx` (`run_id`),
  KEY `instrument_predictions_symbol_idx` (`symbol`, `horizon_days`),
  CONSTRAINT `instrument_predictions_run_fk` FOREIGN KEY (`run_id`) REFERENCES `instrument_prediction_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `instrument_predictions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Signals
CREATE TABLE IF NOT EXISTS `signals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(64) NOT NULL,
  `especie` VARCHAR(128) DEFAULT NULL,
  `name` VARCHAR(191) DEFAULT NULL,
  `exchange` VARCHAR(64) DEFAULT NULL,
  `currency` CHAR(3) DEFAULT NULL,
  `as_of` DATETIME NOT NULL,
  `action` ENUM('BUY','HOLD','SELL') NOT NULL,
  `confidence` DECIMAL(5,4) DEFAULT NULL,
  `horizon_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `signal_strength` DECIMAL(12,6) DEFAULT NULL,
  `price_last` DECIMAL(20,8) DEFAULT NULL,
  `entry_reference` DECIMAL(20,8) DEFAULT NULL,
  `exp_return_pct` DECIMAL(10,6) DEFAULT NULL,
  `exp_return_amt` DECIMAL(20,8) DEFAULT NULL,
  `range_p10_pct` DECIMAL(10,6) DEFAULT NULL,
  `range_p50_pct` DECIMAL(10,6) DEFAULT NULL,
  `range_p90_pct` DECIMAL(10,6) DEFAULT NULL,
  `range_p10_amt` DECIMAL(20,8) DEFAULT NULL,
  `range_p90_amt` DECIMAL(20,8) DEFAULT NULL,
  `volatility_atr` DECIMAL(18,8) DEFAULT NULL,
  `stop_price` DECIMAL(20,8) DEFAULT NULL,
  `take_price` DECIMAL(20,8) DEFAULT NULL,
  `stop_distance_pct` DECIMAL(10,6) DEFAULT NULL,
  `take_distance_pct` DECIMAL(10,6) DEFAULT NULL,
  `risk_reward` DECIMAL(10,6) DEFAULT NULL,
  `trend_state` ENUM('UP','DOWN','SIDE') DEFAULT NULL,
  `momentum_state` ENUM('BULL','BEAR','NEUTRAL') DEFAULT NULL,
  `regime` ENUM('HIGH_VOL','LOW_VOL','NEUTRAL') DEFAULT NULL,
  `rationale_short` VARCHAR(255) DEFAULT NULL,
  `rationale_tags` JSON DEFAULT NULL,
  `rationale_json` JSON DEFAULT NULL,
  `data_quality` ENUM('OK','STALE','MISSING','PARTIAL') DEFAULT 'OK',
  `data_points_used` INT UNSIGNED DEFAULT NULL,
  `costs_included` TINYINT(1) NOT NULL DEFAULT 0,
  `backtest_ref` VARCHAR(128) DEFAULT NULL,
  `series_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `signals_symbol_idx` (`symbol`, `as_of`),
  KEY `signals_action_idx` (`action`),
  KEY `signals_horizon_idx` (`horizon_days`),
  KEY `signals_especie_idx` (`especie`(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data Lake: snapshots y latest
CREATE TABLE IF NOT EXISTS `dl_price_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(32) NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  `as_of` DATETIME(6) NOT NULL,
  `payload_json` JSON NOT NULL,
  `payload_hash` BINARY(32) NOT NULL,
  `http_status` SMALLINT UNSIGNED NULL,
  `error_code` VARCHAR(64) NULL,
  `error_msg` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_snapshot` (`symbol`, `provider`, `as_of`, `payload_hash`),
  KEY `idx_symbol_provider_asof` (`symbol`, `provider`, `as_of`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dl_price_latest` (
  `symbol` VARCHAR(32) NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  `as_of` DATETIME(6) NOT NULL,
  `payload_json` JSON NOT NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data Lake: catalogo de instrumentos
CREATE TABLE IF NOT EXISTS `dl_instrument_catalog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(32) NOT NULL,
  `name` VARCHAR(191) DEFAULT NULL,
  `tipo` VARCHAR(64) DEFAULT NULL,
  `panel` VARCHAR(64) DEFAULT NULL,
  `mercado` VARCHAR(64) DEFAULT NULL,
  `currency` CHAR(3) DEFAULT NULL,
  `source` VARCHAR(64) DEFAULT NULL,
  `as_of` DATETIME(6) DEFAULT NULL,
  `price` DECIMAL(20,8) DEFAULT NULL,
  `var_pct` DECIMAL(10,6) DEFAULT NULL,
  `var_mtd` DECIMAL(10,6) DEFAULT NULL,
  `var_ytd` DECIMAL(10,6) DEFAULT NULL,
  `volume_nominal` DECIMAL(20,6) DEFAULT NULL,
  `volume_efectivo` DECIMAL(20,6) DEFAULT NULL,
  `anterior` DECIMAL(20,8) DEFAULT NULL,
  `apertura` DECIMAL(20,8) DEFAULT NULL,
  `maximo` DECIMAL(20,8) DEFAULT NULL,
  `minimo` DECIMAL(20,8) DEFAULT NULL,
  `operaciones` INT UNSIGNED DEFAULT NULL,
  `meta_json` JSON DEFAULT NULL,
  `captured_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_catalog_symbol` (`symbol`),
  KEY `idx_catalog_captured` (`captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backtesting
CREATE TABLE IF NOT EXISTS `backtests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `strategy_id` VARCHAR(64) NOT NULL,
  `universe` JSON NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `initial_capital` DECIMAL(18,4) NOT NULL,
  `final_capital` DECIMAL(18,4) NULL,
  `risk_per_trade_pct` DECIMAL(8,4) NOT NULL,
  `commission_pct` DECIMAL(8,4) NOT NULL,
  `min_fee` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `slippage_bps` DECIMAL(8,4) NOT NULL,
  `spread_bps` DECIMAL(8,4) NOT NULL,
  `breakout_lookback_buy` INT NOT NULL,
  `breakout_lookback_sell` INT NOT NULL,
  `atr_multiplier` DECIMAL(8,4) NOT NULL,
  `request_json` JSON NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'running',
  `error_message` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_request_hash` (`request_hash`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `backtest_trades` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backtest_id` BIGINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(32) NOT NULL,
  `entry_ts` DATE NOT NULL,
  `entry_price` DECIMAL(18,6) NOT NULL,
  `exit_ts` DATE NOT NULL,
  `exit_price` DECIMAL(18,6) NOT NULL,
  `qty` BIGINT NOT NULL,
  `pnl_gross` DECIMAL(18,6) NOT NULL,
  `costs` DECIMAL(18,6) NOT NULL,
  `pnl_net` DECIMAL(18,6) NOT NULL,
  `exit_reason` VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backtest` (`backtest_id`),
  CONSTRAINT `fk_backtest_trades_bt` FOREIGN KEY (`backtest_id`) REFERENCES `backtests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `backtest_equity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backtest_id` BIGINT UNSIGNED NOT NULL,
  `ts` DATE NOT NULL,
  `equity` DECIMAL(18,6) NOT NULL,
  `drawdown` DECIMAL(18,6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backtest_equity` (`backtest_id`, `ts`),
  CONSTRAINT `fk_backtest_equity_bt` FOREIGN KEY (`backtest_id`) REFERENCES `backtests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `backtest_metrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backtest_id` BIGINT UNSIGNED NOT NULL,
  `cagr` DECIMAL(18,6) NOT NULL,
  `max_drawdown` DECIMAL(18,6) NOT NULL,
  `sharpe` DECIMAL(18,6) NOT NULL,
  `sortino` DECIMAL(18,6) NOT NULL,
  `win_rate` DECIMAL(18,6) NOT NULL,
  `profit_factor` DECIMAL(18,6) NOT NULL,
  `expectancy` DECIMAL(18,6) NOT NULL,
  `exposure` DECIMAL(18,6) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backtest_metrics` (`backtest_id`),
  CONSTRAINT `fk_backtest_metrics_bt` FOREIGN KEY (`backtest_id`) REFERENCES `backtests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_instrument (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    region CHAR(2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_local TINYINT(1) NOT NULL DEFAULT 0,
    is_cedear TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dim_instrument_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instrument_source_map (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instrument_id INT UNSIGNED NOT NULL,
    source ENUM('FINHUB','RAVA') NOT NULL,
    source_symbol VARCHAR(100) NOT NULL,
    extra JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_instrument_source (source, source_symbol),
    KEY idx_instrument_source_map_instrument_id (instrument_id),
    CONSTRAINT fk_instrument_source_map_instrument
        FOREIGN KEY (instrument_id) REFERENCES dim_instrument(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_calendar (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    day TINYINT UNSIGNED NOT NULL,
    week_of_year TINYINT UNSIGNED NOT NULL,
    is_trading_day TINYINT(1) NOT NULL DEFAULT 0,
    is_month_end TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dim_calendar_date (date),
    KEY idx_dim_calendar_year_month (year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staging_price_raw (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source ENUM('FINHUB','RAVA') NOT NULL,
    source_symbol VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    open DECIMAL(18,6) NULL,
    high DECIMAL(18,6) NULL,
    low DECIMAL(18,6) NULL,
    close DECIMAL(18,6) NULL,
    volume BIGINT UNSIGNED NULL,
    raw_payload MEDIUMTEXT NULL,
    ingested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staging_price_raw (source, source_symbol, date),
    KEY idx_staging_price_raw_ingested_at (ingested_at),
    KEY idx_staging_price_raw_source_symbol_date (source_symbol, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fact_price_daily (
    instrument_id INT UNSIGNED NOT NULL,
    calendar_id INT UNSIGNED NOT NULL,
    open DECIMAL(18,6) NULL,
    high DECIMAL(18,6) NULL,
    low DECIMAL(18,6) NULL,
    close DECIMAL(18,6) NULL,
    volume BIGINT UNSIGNED NULL,
    adj_close DECIMAL(18,6) NULL,
    source_primary ENUM('FINHUB','RAVA','MERGED') NOT NULL,
    last_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (instrument_id, calendar_id),
    KEY idx_fact_price_daily_calendar (calendar_id),
    CONSTRAINT fk_fact_price_daily_instrument
        FOREIGN KEY (instrument_id) REFERENCES dim_instrument(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fact_price_daily_calendar
        FOREIGN KEY (calendar_id) REFERENCES dim_calendar(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fact_indicator_daily (
    instrument_id INT UNSIGNED NOT NULL,
    calendar_id INT UNSIGNED NOT NULL,
    sma_20 DECIMAL(18,6) NULL,
    sma_50 DECIMAL(18,6) NULL,
    sma_200 DECIMAL(18,6) NULL,
    rsi_14 DECIMAL(6,2) NULL,
    volatility_20 DECIMAL(6,4) NULL,
    last_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (instrument_id, calendar_id),
    KEY idx_fact_indicator_daily_calendar (calendar_id),
    CONSTRAINT fk_fact_indicator_daily_instrument
        FOREIGN KEY (instrument_id) REFERENCES dim_instrument(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fact_indicator_daily_calendar
        FOREIGN KEY (calendar_id) REFERENCES dim_calendar(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fact_signal_daily (
    instrument_id INT UNSIGNED NOT NULL,
    calendar_id INT UNSIGNED NOT NULL,
    signal_type VARCHAR(50) NOT NULL,
    score DECIMAL(5,2) NOT NULL,
    signal_label VARCHAR(50) NOT NULL,
    details JSON NULL,
    last_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (instrument_id, calendar_id, signal_type),
    KEY idx_fact_signal_daily_calendar (calendar_id),
    CONSTRAINT fk_fact_signal_daily_instrument
        FOREIGN KEY (instrument_id) REFERENCES dim_instrument(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_fact_signal_daily_calendar
        FOREIGN KEY (calendar_id) REFERENCES dim_calendar(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etl_run_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('OK','ERROR') NOT NULL,
    rows_affected INT UNSIGNED NOT NULL DEFAULT 0,
    message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_etl_run_log_job_name_started_at (job_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


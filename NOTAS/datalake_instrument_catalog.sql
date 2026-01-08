-- Catálogo de instrumentos RAVA en DataLake (SERVING).
-- Ejecutar manualmente antes de habilitar el catálogo vía API. No se crea en runtime.

CREATE TABLE IF NOT EXISTS dl_instrument_catalog (
  symbol VARCHAR(32) NOT NULL,
  name VARCHAR(128) NULL,
  tipo VARCHAR(32) NULL,
  panel VARCHAR(64) NULL,
  mercado VARCHAR(64) NULL,
  currency VARCHAR(16) NULL,
  source VARCHAR(32) NULL,
  as_of DATETIME(6) NULL,
  price DECIMAL(18,4) NULL,
  var_pct DECIMAL(9,4) NULL,
  var_mtd DECIMAL(9,4) NULL,
  var_ytd DECIMAL(9,4) NULL,
  volume_nominal DECIMAL(20,4) NULL,
  volume_efectivo DECIMAL(20,4) NULL,
  anterior DECIMAL(18,4) NULL,
  apertura DECIMAL(18,4) NULL,
  maximo DECIMAL(18,4) NULL,
  minimo DECIMAL(18,4) NULL,
  operaciones INT NULL,
  meta_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (symbol),
  INDEX idx_asof (as_of),
  INDEX idx_tipo (tipo),
  INDEX idx_panel (panel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

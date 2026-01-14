USE `finhub`;

-- Alinea tipos de fechas a DATETIME según 002_create_tables.sql
ALTER TABLE `users`
  MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Agrega deleted_at solo si no existe y ajusta tipos de fecha en portfolios
SET @db := DATABASE();
SET @needs_deleted_at := (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'portfolios' AND column_name = 'deleted_at'
);
SET @sql := IF(
  @needs_deleted_at,
  'ALTER TABLE `portfolios` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `portfolios`
  MODIFY COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Limpieza de tablas de catálogo y latest
-- Ejecutar manualmente si se desea resetear/eliminar las tablas obsoletas.

TRUNCATE TABLE dl_instrument_catalog;
DROP TABLE IF EXISTS dl_instrument_catalog;
DROP TABLE IF EXISTS dl_price_latest;

-- ============================================================
-- Del Austral — Migración de base de datos (versión 11)
-- ============================================================
-- Agrega a "profesionales_legajos" una columna para el número
-- de legajo con formato LG-YYYY-NNN (año + correlativo).
-- Los profesionales ya existentes reciben un número automático.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE profesionales_legajos
    ADD COLUMN numero_legajo VARCHAR(20) NULL UNIQUE AFTER usuario_id;

-- Asignar números correlativos a los que ya existen,
-- usando el año de creación y un correlativo por año.
SET @anio = 0;
SET @contador = 0;

UPDATE profesionales_legajos pl
JOIN (
    SELECT id,
           YEAR(creado_en) AS anio,
           @contador := IF(@anio = YEAR(creado_en), @contador + 1, 1) AS correlativo,
           @anio := YEAR(creado_en) AS anio_actual
    FROM profesionales_legajos
    ORDER BY creado_en ASC
) ranked ON pl.id = ranked.id
SET pl.numero_legajo = CONCAT('LG-', ranked.anio, '-', LPAD(ranked.correlativo, 3, '0'));

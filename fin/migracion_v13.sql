-- ============================================================
-- Del Austral — Migración de base de datos (versión 13)
-- ============================================================
-- Agrega a "profesionales_legajos" las columnas de matrícula
-- nacional (M.N.) y provincial (M.P.), ambas opcionales. Se
-- usan para armar el sello/firma automática del profesional
-- (ej: "M.N. 2020 - M.P. 2080" si tiene las dos).
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE profesionales_legajos ADD COLUMN matricula_nacional VARCHAR(30) NULL AFTER especialidad;
ALTER TABLE profesionales_legajos ADD COLUMN matricula_provincial VARCHAR(30) NULL AFTER matricula_nacional;

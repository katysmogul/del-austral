-- ============================================================
-- Del Austral — Migración de base de datos (versión 12)
-- ============================================================
-- Agrega a "profesionales_legajos" una columna para guardar la
-- firma digital del profesional (como imagen PNG en base64),
-- ya sea dibujada en pantalla o subida como foto escaneada.
-- Se inserta automáticamente al pie de cada PDF exportado.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE profesionales_legajos ADD COLUMN firma_digital MEDIUMTEXT NULL AFTER telefono;

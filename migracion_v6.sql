-- ============================================================
-- Del Austral — Migración de base de datos (versión 6)
-- ============================================================
-- Suma: aviso al profesional cuando un paciente confirma o
-- cancela su turno desde el link público. El contador de
-- "consultas de hoy" no necesita columnas nuevas, se calcula
-- al vuelo comparando la hora de cada cita con la hora actual.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE citas ADD COLUMN revisada_por_profesional TINYINT(1) NOT NULL DEFAULT 1 AFTER confirmada_por_paciente;

-- Las citas que ya existían antes de esta migración se marcan
-- como "ya revisadas", para no generar avisos retroactivos de
-- cambios que pasaron antes de esta actualización.
UPDATE citas SET revisada_por_profesional = 1;

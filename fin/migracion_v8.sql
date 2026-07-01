-- ============================================================
-- Del Austral — Migración de base de datos (versión 8)
-- ============================================================
-- Agrega a "pacientes" una columna para guardar el nombre del
-- profesional anterior, cuando un legajo se recupera de la
-- papelera y se le asigna a un profesional distinto del que lo
-- tenía antes de eliminarlo. Sirve para que el nuevo profesional
-- vea de un vistazo, en la ficha del paciente, que ese legajo
-- viene de otro médico.
--
-- Se guarda el NOMBRE (texto), no el ID del profesional viejo,
-- porque ese profesional podría desactivarse en el futuro y
-- igual queremos que el dato siga siendo legible.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE pacientes ADD COLUMN recuperado_de_profesional VARCHAR(150) NULL AFTER sede_id;

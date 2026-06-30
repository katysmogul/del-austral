-- ============================================================
-- Del Austral — Migración de base de datos (versión 9)
-- ============================================================
-- Cambia la restricción de DNI único: antes era único en TODA
-- la tabla de pacientes, lo cual impedía que dos profesionales
-- distintos cargaran un paciente con el mismo DNI (algo que sí
-- puede pasar legítimamente, ya que cada profesional tiene sus
-- propios pacientes, completamente separados de los demás).
--
-- Ahora el DNI tiene que ser único solo DENTRO de los pacientes
-- de un mismo profesional — sigue sin poder cargarse el mismo
-- DNI dos veces para el mismo profesional, pero sí puede
-- repetirse entre profesionales distintos.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- Quitamos el índice único global sobre "dni". El nombre del
-- índice suele ser igual al de la columna ("dni"), pero si tu
-- base de datos lo llamó distinto y este comando da error,
-- entrá a phpMyAdmin → tabla "pacientes" → pestaña "Estructura"
-- → abajo, donde dice "Índices", buscá el índice de tipo
-- "UNIQUE" sobre la columna dni y anotá su nombre real para
-- reemplazarlo en la siguiente línea.
ALTER TABLE pacientes DROP INDEX dni;

-- ...y agregamos uno único compuesto (profesional_id + dni), que
-- es el que realmente queremos: único por profesional, no global.
ALTER TABLE pacientes ADD UNIQUE INDEX idx_dni_por_profesional (profesional_id, dni);

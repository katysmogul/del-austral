-- ============================================================
-- Del Austral — Migración de base de datos (versión 7)
-- ============================================================
-- Agrega a la papelera (legajos_eliminados) las columnas
-- profesional_id_original y sede_id_original, para que el
-- Desarrollador pueda filtrar la papelera por profesional/sede
-- y recuperar un legajo asignándolo a otro profesional.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
-- Los registros de papelera que ya existían antes de esta
-- migración van a intentar rellenarse automáticamente a partir
-- del JSON guardado (ver el UPDATE al final). Si por la versión
-- de tu MySQL ese paso no funciona, esos registros viejos
-- quedan en NULL — todavía se pueden ver, pero no aparecen al
-- filtrar la papelera por un profesional específico.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE legajos_eliminados ADD COLUMN profesional_id_original INT NULL AFTER paciente_id_original;
ALTER TABLE legajos_eliminados ADD COLUMN sede_id_original INT NULL AFTER profesional_id_original;

ALTER TABLE legajos_eliminados ADD INDEX idx_papelera_profesional (profesional_id_original);
ALTER TABLE legajos_eliminados ADD INDEX idx_papelera_sede (sede_id_original);

-- Intento de mejor esfuerzo: para los registros que ya existían,
-- rellenamos esas columnas a partir del JSON guardado, donde sea
-- posible (MySQL 5.7+/MariaDB 10.2+ con soporte de JSON_EXTRACT).
-- Si tu versión no soporta estas funciones, esta consulta puede
-- fallar — no afecta el resto de la migración, ya corrida arriba.
UPDATE legajos_eliminados
SET
    profesional_id_original = JSON_UNQUOTE(JSON_EXTRACT(datos_json, '$.profesional_id')),
    sede_id_original = JSON_UNQUOTE(JSON_EXTRACT(datos_json, '$.sede_id'))
WHERE profesional_id_original IS NULL;

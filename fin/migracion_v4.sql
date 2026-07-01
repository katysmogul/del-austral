-- ============================================================
-- Del Austral — Migración de base de datos (versión 4)
-- ============================================================
-- El sistema de acceso cambió de "patrón dibujado" a "PIN
-- numérico de 4 dígitos". Son datos distintos: un patrón
-- guardado (ej. la secuencia de puntos "0-1-2-4") nunca va a
-- coincidir con un PIN de 4 números, así que hay que vaciar los
-- accesos viejos para que el sistema vuelva a pedir crear un PIN.
--
-- IMPORTANTE: esto NO borra pacientes, sesiones, citas, adjuntos
-- ni ningún dato clínico. Solo borra las filas de la tabla
-- "usuarios" (los accesos), porque sus patrones ya no sirven.
-- Vas a tener que volver a crear tu PIN (y el de tu administrativa
-- si ya tenías una) la primera vez que entres después de esto.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- 4. Solo corré esto UNA VEZ. Si lo corrés de nuevo después de
--    haber creado tu PIN, vas a tener que volver a crearlo.
-- ============================================================

SET NAMES utf8mb4;

DELETE FROM usuarios;

-- El historial de cambios y todos los demás datos quedan intactos.
-- Al volver a entrar al sitio, el sistema va a mostrar la pantalla
-- de "creá tu PIN" como la primera vez.

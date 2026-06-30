-- ============================================================
-- Del Austral — REINICIO TOTAL DEL SISTEMA
-- ============================================================
-- Este script borra TODO: usuarios, sedes, clave de Desarrollador,
-- pacientes, sesiones, citas, archivos adjuntos (los registros en
-- la base, no los archivos físicos en el servidor), plantillas de
-- evolución, papelera, e historial de cambios.
--
-- Después de correr esto, el sistema vuelve a quedar como recién
-- instalado: la primera vez que entres te va a pedir crear la
-- clave de Desarrollador de nuevo.
--
-- ADVERTENCIA: esta acción NO SE PUEDE DESHACER. Si tenés
-- cualquier duda, hacé un backup antes (en phpMyAdmin: pestaña
-- "Exportar" → guardar el archivo .sql en tu computadora).
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
--
-- Nota técnica: usamos DELETE en vez de TRUNCATE porque algunos
-- hostings (MySQL/MariaDB) no permiten TRUNCATE sobre tablas
-- referenciadas por una clave foránea, ni siquiera con los
-- chequeos desactivados. DELETE sí funciona en todos los casos.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM historial_cambios;
DELETE FROM legajos_eliminados;
DELETE FROM archivos_adjuntos;
DELETE FROM citas;
DELETE FROM sesiones;
DELETE FROM pacientes;
DELETE FROM plantillas_evolucion;
DELETE FROM usuarios_sedes;
DELETE FROM usuarios;
DELETE FROM sedes;
DELETE FROM desarrollador;

-- Reiniciar los contadores de autoincremento, para que el
-- próximo paciente/usuario/sede que crees vuelva a empezar
-- desde el ID 1 (puramente cosmético, no es obligatorio).
ALTER TABLE historial_cambios AUTO_INCREMENT = 1;
ALTER TABLE legajos_eliminados AUTO_INCREMENT = 1;
ALTER TABLE archivos_adjuntos AUTO_INCREMENT = 1;
ALTER TABLE citas AUTO_INCREMENT = 1;
ALTER TABLE sesiones AUTO_INCREMENT = 1;
ALTER TABLE pacientes AUTO_INCREMENT = 1;
ALTER TABLE plantillas_evolucion AUTO_INCREMENT = 1;
ALTER TABLE usuarios AUTO_INCREMENT = 1;
ALTER TABLE sedes AUTO_INCREMENT = 1;
ALTER TABLE desarrollador AUTO_INCREMENT = 1;

-- Las obras sociales NO se borran (es un catálogo, no datos de
-- pacientes). Si también querés vaciar las que agregaste a mano
-- y volver a dejar solo las predefinidas, descomentá esta línea:
-- DELETE FROM obras_sociales WHERE es_predefinida = 0;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Listo. Al refrescar el sitio, debería pedirte crear la clave
-- de Desarrollador como si fuera la primera vez.
-- ============================================================

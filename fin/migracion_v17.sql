-- ============================================================
-- Del Austral — Migración de base de datos (versión 17)
-- ============================================================
-- Agrega "configuracion_institucion": una tabla de una sola
-- fila donde se guarda el nombre de la institución (por
-- defecto "Del Austral"), para que deje de estar escrito a
-- mano en cada archivo del sistema. Esto es lo que permite que
-- cada instalación (cada subcarpeta/cliente) tenga su propio
-- nombre, sin tocar código.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configuracion_institucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre_institucion VARCHAR(150) NOT NULL DEFAULT 'Del Austral',
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO configuracion_institucion (nombre_institucion) VALUES ('Del Austral');

-- ============================================================
-- Del Austral — Migración de base de datos (versión 3)
-- ============================================================
-- Suma: roles de usuario (profesional / administrativa) con
-- patrones propios, e historial de cambios (auditoría).
-- No borra ni modifica datos existentes. Es seguro ejecutarlo
-- aunque ya tengas legajos y un patrón configurado.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) USUARIOS (reemplaza el patrón único guardado en
--    "configuracion" por una tabla con uno o más usuarios,
--    cada uno con su propio patrón y rol)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre_completo VARCHAR(150) NOT NULL,
    rol ENUM('profesional', 'administrativa') NOT NULL DEFAULT 'profesional',
    patron_hash VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrar el patrón y nombre que ya tenías guardados en "configuracion"
-- hacia la nueva tabla "usuarios", como el primer usuario (profesional).
INSERT INTO usuarios (nombre_completo, rol, patron_hash)
SELECT
    COALESCE((SELECT valor FROM configuracion WHERE clave = 'nombre_profesional'), 'Profesional'),
    'profesional',
    (SELECT valor FROM configuracion WHERE clave = 'patron_hash')
WHERE
    (SELECT valor FROM configuracion WHERE clave = 'patron_hash') IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM usuarios WHERE rol = 'profesional');

-- ------------------------------------------------------------
-- 2) HISTORIAL DE CAMBIOS (auditoría)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historial_cambios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(150) NULL,
    accion VARCHAR(60) NOT NULL,
    entidad VARCHAR(60) NOT NULL,
    entidad_id INT NULL,
    descripcion VARCHAR(500) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_historial_entidad (entidad, entidad_id),
    INDEX idx_historial_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

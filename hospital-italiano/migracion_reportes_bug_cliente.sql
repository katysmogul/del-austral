-- ============================================================
-- Del Austral — Migración: reportes de bug del Apoderado
-- ============================================================
-- Corré esto en la base de datos de CADA institución cliente
-- que ya esté instalada (no hace falta para instalaciones
-- nuevas, que ya usan el database.sql actualizado).
--
-- Cómo aplicarlo (reemplazá nombre_base por el real):
-- mysql -u root -p nombre_base < migracion_reportes_bug_cliente.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS reportes_bug (
    id INT PRIMARY KEY AUTO_INCREMENT,
    titulo VARCHAR(150) NOT NULL,
    descripcion TEXT NOT NULL,
    severidad ENUM('visual', 'funcional', 'critico', 'sugerencia') NOT NULL DEFAULT 'funcional',
    sede_nombre VARCHAR(150) NULL,
    estado ENUM('nuevo', 'visto', 'en_curso', 'resuelto', 'no_resuelto') NOT NULL DEFAULT 'nuevo',
    respuesta_soporte TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

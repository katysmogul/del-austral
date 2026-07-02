-- ============================================================
-- Del Austral — Migración: salud del sistema y log de errores
-- ============================================================
-- Corré esto en la base de datos de CADA institución cliente
-- que ya esté instalada (no hace falta para instalaciones
-- nuevas, que ya usan el database.sql actualizado).
--
-- Cómo aplicarlo (reemplazá nombre_base por el real):
-- mysql -u root -p nombre_base < migracion_salud_sistema.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS salud_sistema (
    clave VARCHAR(60) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS errores_app (
    id INT PRIMARY KEY AUTO_INCREMENT,
    mensaje TEXT NOT NULL,
    archivo VARCHAR(255) NULL,
    linea INT NULL,
    url_solicitud VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

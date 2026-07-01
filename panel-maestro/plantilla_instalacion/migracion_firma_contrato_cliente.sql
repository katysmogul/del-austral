-- ============================================================
-- Del Austral — Migración: firma de contrato previa al alta
-- del Desarrollador
-- ============================================================
-- Corré esto en la base de datos de CADA institución cliente
-- que ya esté instalada (no hace falta para instalaciones
-- nuevas, que ya usan el database.sql actualizado).
--
-- Cómo aplicarlo (reemplazá nombre_base por el real):
-- mysql -u root -p nombre_base < migracion_firma_contrato_cliente.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS contrato_firma_cliente (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firma_png LONGTEXT NOT NULL,
    firmado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

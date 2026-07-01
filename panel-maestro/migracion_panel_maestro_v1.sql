-- ============================================================
-- Panel Maestro — Migración: agrega tabla de contratos
-- ============================================================
-- Corré esto en tu base panel_maestro_db existente (no hace
-- falta reimportar database_panel_maestro.sql desde cero).
--
-- Cómo aplicarlo:
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v1.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS contratos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    razon_social_cliente VARCHAR(200) NOT NULL,
    cuit_dni_cliente VARCHAR(30) NOT NULL,
    plazo_tipo ENUM('dias', 'meses', 'anios', 'indeterminado') NOT NULL DEFAULT 'indeterminado',
    plazo_cantidad INT NULL,
    modalidad_pago ENUM('mensual', 'anual') NOT NULL DEFAULT 'mensual',
    ram_gb INT NOT NULL DEFAULT 16,
    disco_gb INT NOT NULL DEFAULT 25,
    backup_horario VARCHAR(50) NOT NULL DEFAULT '3 a 5 A.M.',
    ubicacion_servidor VARCHAR(150) NOT NULL DEFAULT 'Santiago de Chile, Chile',
    fecha_contrato DATE NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

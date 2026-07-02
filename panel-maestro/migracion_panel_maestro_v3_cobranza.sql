-- ============================================================
-- Panel Maestro — Migración v3: sistema de cobranza
-- ============================================================
-- Corré esto en la base del panel maestro (panel_maestro_db o
-- el nombre que le hayas puesto).
--
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v3_cobranza.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE instituciones
    ADD COLUMN saldo_favor DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER notas,
    ADD COLUMN cuenta_titular VARCHAR(150) NULL AFTER saldo_favor,
    ADD COLUMN cuenta_banco VARCHAR(100) NULL AFTER cuenta_titular,
    ADD COLUMN cuenta_cuil VARCHAR(30) NULL AFTER cuenta_banco,
    ADD COLUMN cuenta_numero VARCHAR(60) NULL AFTER cuenta_cuil,
    ADD COLUMN cuenta_alias VARCHAR(60) NULL AFTER cuenta_numero;

CREATE TABLE IF NOT EXISTS cobros (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(10) NOT NULL DEFAULT 'ARS',
    periodo_desde DATE NULL,
    periodo_hasta DATE NULL,
    vencimiento DATE NOT NULL,
    estado ENUM('pendiente', 'comprobante_subido', 'aprobado', 'sin_acreditar', 'rechazado') NOT NULL DEFAULT 'pendiente',
    comprobante_nombre_original VARCHAR(255) NULL,
    comprobante_nombre_archivo VARCHAR(255) NULL,
    comprobante_subido_en TIMESTAMP NULL,
    notas_super_admin TEXT NULL,
    revisado_en TIMESTAMP NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimientos_saldo_favor (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    tipo ENUM('carga', 'aplicado_a_cobro') NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    cobro_id INT NULL,
    nota VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE,
    FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

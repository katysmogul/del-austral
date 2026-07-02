-- ============================================================
-- Panel Maestro — Migración v4: suspensión por falta de pago
-- ============================================================
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v4_suspension_pago.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE instituciones
    MODIFY COLUMN estado ENUM('activa', 'suspendida', 'suspendida_por_pago') NOT NULL DEFAULT 'activa';

-- Guarda qué cobro fue el motivo de la suspensión por falta de
-- pago, para poder reactivar automáticamente cuando ese cobro
-- puntual se apruebe.
ALTER TABLE instituciones
    ADD COLUMN suspendida_por_cobro_id INT NULL AFTER estado;

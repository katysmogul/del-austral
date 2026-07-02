-- ============================================================
-- Panel Maestro — Migración v3b: descuentos, recargo por mora
-- y anulación de cobros
-- ============================================================
-- Usar SOLO si ya corriste migracion_panel_maestro_v3_cobranza.sql
-- antes. Si es una instalación nueva, ya está todo en
-- database_panel_maestro.sql, no hace falta esto.
--
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v3b_cobranza.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE cobros
    ADD COLUMN monto_lista DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER institucion_id,
    ADD COLUMN descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER monto_lista,
    ADD COLUMN motivo_anulacion VARCHAR(255) NULL AFTER notas_super_admin,
    ADD COLUMN recargo_congelado_en DATE NULL AFTER motivo_anulacion,
    ADD COLUMN recargo_congelado_monto DECIMAL(12,2) NULL AFTER recargo_congelado_en,
    MODIFY COLUMN estado ENUM('pendiente', 'comprobante_subido', 'aprobado', 'sin_acreditar', 'rechazado', 'anulado') NOT NULL DEFAULT 'pendiente';

-- Para los cobros que ya existían antes de esta migración,
-- monto_lista queda en 0 por el DEFAULT — los actualizamos para
-- que coincida con el monto ya cobrado (sin descuento retroactivo).
UPDATE cobros SET monto_lista = monto WHERE monto_lista = 0;

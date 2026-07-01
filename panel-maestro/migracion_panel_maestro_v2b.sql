-- ============================================================
-- Panel Maestro — Migración v2b: solo columnas de firma
-- ============================================================
-- Usar esta SOLO si ya corriste la v2 antes (o parte de ella) y
-- te quedaron faltando las columnas de firma_apoderado_png,
-- firma_cliente_png y firma_cliente_sincronizada_en.
--
-- Cómo aplicarlo:
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v2b.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE contratos
    ADD COLUMN firma_apoderado_png LONGTEXT NULL AFTER preaviso_rescision_dias,
    ADD COLUMN firma_cliente_png LONGTEXT NULL AFTER firma_apoderado_png,
    ADD COLUMN firma_cliente_sincronizada_en TIMESTAMP NULL AFTER firma_cliente_png;

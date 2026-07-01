-- ============================================================
-- Panel Maestro — Migración v2: contrato completo (21 cláusulas + Anexos)
-- ============================================================
-- Corré esto en tu base panel_maestro_db existente, DESPUÉS de
-- haber aplicado migracion_panel_maestro_v1.sql.
--
-- Qué agrega:
--   - Precio del servicio (monto + moneda)
--   - Datos del PRESTADOR (titular y apoderado) editables por
--     institución, en vez de quedar fijos en el código PHP
--   - Tolerancia de mora en meses (antes era un número fijo
--     dentro del texto: "DOS (2) meses")
--   - Plazo de entrega de datos tras el cese, en días hábiles
--     (antes fijo en "SIETE (7) días")
--
-- Cómo aplicarlo:
-- mysql -u root -p panel_maestro_db < migracion_panel_maestro_v2.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE contratos
    ADD COLUMN precio_monto DECIMAL(12,2) NULL AFTER modalidad_pago,
    ADD COLUMN precio_moneda VARCHAR(10) NOT NULL DEFAULT 'ARS' AFTER precio_monto,

    ADD COLUMN prestador_titular_nombre VARCHAR(150) NOT NULL DEFAULT 'MONTERO, FABIANA KARINA' AFTER ubicacion_servidor,
    ADD COLUMN prestador_titular_cuil VARCHAR(30) NOT NULL DEFAULT '27-20746451-7' AFTER prestador_titular_nombre,
    ADD COLUMN prestador_apoderado_nombre VARCHAR(150) NOT NULL DEFAULT 'LORENZ MONTERO, ARIAN TAHIEL' AFTER prestador_titular_cuil,
    ADD COLUMN prestador_apoderado_cuil VARCHAR(30) NOT NULL DEFAULT '20-46143095-4' AFTER prestador_apoderado_nombre,
    ADD COLUMN prestador_marca VARCHAR(100) NOT NULL DEFAULT 'DEL AUSTRAL' AFTER prestador_apoderado_cuil,

    ADD COLUMN tolerancia_mora_meses INT NOT NULL DEFAULT 2 AFTER prestador_marca,
    ADD COLUMN plazo_entrega_datos_dias INT NOT NULL DEFAULT 7 AFTER tolerancia_mora_meses,
    ADD COLUMN preaviso_rescision_dias INT NOT NULL DEFAULT 30 AFTER plazo_entrega_datos_dias,

    ADD COLUMN firma_apoderado_png LONGTEXT NULL AFTER preaviso_rescision_dias,
    ADD COLUMN firma_cliente_png LONGTEXT NULL AFTER firma_apoderado_png,
    ADD COLUMN firma_cliente_sincronizada_en TIMESTAMP NULL AFTER firma_cliente_png;

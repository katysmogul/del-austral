-- ============================================================
-- Del Austral — Migración de base de datos (versión 16)
-- ============================================================
-- Agrega la papelera de resúmenes de derivación: el profesional
-- puede "Eliminar" (va a la papelera, recuperable después por
-- el Desarrollador, reasignable a otro profesional) o "Eliminar
-- para siempre" (borrado irreversible, sin pasar por papelera).
--
-- Reutiliza el mismo patrón que ya existe para pacientes
-- (legajos_eliminados): guarda profesional y sede original
-- para poder recuperar y reasignar correctamente.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS derivaciones_eliminadas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    derivacion_id_original INT NOT NULL,
    profesional_id_original INT NULL,
    sede_id_original INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    datos_json LONGTEXT NOT NULL,
    eliminado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_derivacion_eliminada_profesional (profesional_id_original),
    INDEX idx_derivacion_eliminada_sede (sede_id_original)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

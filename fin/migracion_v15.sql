-- ============================================================
-- Del Austral — Migración de base de datos (versión 15)
-- ============================================================
-- Amplía "Servicios Plus" con tres documentos nuevos:
--
-- 1. CONSTANCIA DE TRATAMIENTO PROLONGADO — reutiliza la tabla
--    "constancias" (igual token/vencimiento que la de asistencia).
-- 2. RECETA — reutiliza también "constancias", con campos
--    propios para medicamento/indicaciones.
-- 3. RESUMEN DE DERIVACIÓN — tabla nueva aparte, SIN token ni
--    vencimiento (va directo a otro profesional, no se valida
--    públicamente).
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- "tipo" distingue qué clase de documento es cada fila de
-- "constancias": 'asistencia' (la que ya existía), 'tratamiento'
-- o 'receta'. Los registros existentes quedan como 'asistencia'.
ALTER TABLE constancias ADD COLUMN tipo ENUM('asistencia','tratamiento','receta') NOT NULL DEFAULT 'asistencia' AFTER token;

-- Campos propios de "tratamiento prolongado".
ALTER TABLE constancias ADD COLUMN tratamiento_desde DATE NULL AFTER fecha_consulta;
ALTER TABLE constancias ADD COLUMN diagnostico TEXT NULL AFTER tratamiento_desde;

-- Campos propios de "receta".
ALTER TABLE constancias ADD COLUMN indicaciones TEXT NULL AFTER diagnostico;

ALTER TABLE constancias_historico ADD COLUMN tipo ENUM('asistencia','tratamiento','receta') NOT NULL DEFAULT 'asistencia' AFTER token;

CREATE TABLE IF NOT EXISTS resumenes_derivacion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profesional_id INT NOT NULL,
    sede_id INT NOT NULL,
    paciente_id INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    motivo_consulta TEXT NULL,
    diagnostico TEXT NULL,
    tratamiento_actual TEXT NULL,
    destinatario VARCHAR(200) NULL,
    observaciones TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

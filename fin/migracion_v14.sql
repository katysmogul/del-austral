-- ============================================================
-- Del Austral — Migración de base de datos (versión 14)
-- ============================================================
-- Agrega la tabla "constancias", para el nuevo "Servicios Plus
-- → Constancia médica": un justificante de asistencia exportable
-- a PDF, con un token público de validación que vence a los 90
-- días.
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS constancias (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(40) NOT NULL UNIQUE,
    profesional_id INT NOT NULL,
    sede_id INT NOT NULL,
    paciente_id INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    lugar_nacimiento VARCHAR(150) NULL,
    fecha_consulta DATE NOT NULL,
    destino TEXT NOT NULL,
    lugar_destino VARCHAR(200) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    vence_en DATE NOT NULL,
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL,
    INDEX idx_token (token),
    INDEX idx_vence_en (vence_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro mínimo que queda PARA SIEMPRE después de que la
-- constancia vence y se borra de "constancias" — solo para
-- auditoría interna del Desarrollador, nunca visible
-- públicamente. No guarda el contenido del certificado, solo
-- el rastro de que existió.
CREATE TABLE IF NOT EXISTS constancias_historico (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(40) NOT NULL,
    profesional_id INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    emitida_en TIMESTAMP NOT NULL,
    vencio_en DATE NOT NULL,
    INDEX idx_token_historico (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

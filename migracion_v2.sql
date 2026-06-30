-- ============================================================
-- Del Austral — Migración de base de datos (versión 2)
-- ============================================================
-- Este script SUMA tablas y columnas nuevas a una base que ya
-- tiene tus pacientes cargados. No borra ni modifica los datos
-- existentes. Es seguro ejecutarlo aunque ya tengas legajos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) CITAS (agenda) — independiente de "sesiones"
--    Una cita es algo agendado (puede cumplirse, cancelarse o
--    quedar pendiente). Una sesión es un registro de algo que
--    YA pasó. Antes "proxima_cita" vivía adentro de sesiones;
--    ahora tiene su propia tabla para poder agendar, cancelar
--    y marcar asistencia de forma prolija.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paciente_id INT NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NULL,
    motivo VARCHAR(255) NULL,
    estado ENUM('pendiente', 'atendida', 'cancelada', 'ausente') NOT NULL DEFAULT 'pendiente',
    notas TEXT NULL,
    sesion_generada_id INT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    INDEX idx_citas_fecha (fecha, hora),
    INDEX idx_citas_paciente (paciente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrar las "próximas citas" que ya existan dentro de sesiones
-- hacia la nueva tabla citas, para no perder esa información.
INSERT INTO citas (paciente_id, fecha, motivo, estado)
SELECT s.paciente_id, s.proxima_cita, 'Migrada automáticamente desde sesión anterior', 'pendiente'
FROM sesiones s
WHERE s.proxima_cita IS NOT NULL
  AND s.proxima_cita >= CURDATE()
  AND NOT EXISTS (
      SELECT 1 FROM citas c
      WHERE c.paciente_id = s.paciente_id AND c.fecha = s.proxima_cita
  );

-- ------------------------------------------------------------
-- 2) ARCHIVOS ADJUNTOS (PDF e imágenes ligados a un legajo
--    y, opcionalmente, a una sesión puntual)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS archivos_adjuntos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paciente_id INT NOT NULL,
    sesion_id INT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    tipo_mime VARCHAR(100) NOT NULL,
    tamanio_bytes INT NOT NULL,
    descripcion VARCHAR(255) NULL,
    subido_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE SET NULL,
    INDEX idx_adjuntos_paciente (paciente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3) PLANTILLAS DE EVOLUCIÓN (texto libre, reutilizable,
--    creadas por el profesional para agilizar las sesiones)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plantillas_evolucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL,
    contenido TEXT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4) Comentario informativo: la columna pacientes.actualizado_en
--    ya existe desde el inicio y se sigue usando para "Editar legajo".
--    No se requieren columnas nuevas en pacientes ni sesiones.
-- ------------------------------------------------------------

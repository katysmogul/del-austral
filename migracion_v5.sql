-- ============================================================
-- Del Austral — Migración de base de datos (versión 5)
-- ============================================================
-- Suma: sedes (sucursales), aislamiento de pacientes por
-- profesional, rol "desarrollador" por encima de todo, token de
-- confirmación de turno por el paciente, y migración de
-- pacientes entre sedes.
--
-- No borra pacientes, sesiones, citas ni adjuntos existentes.
-- Los pacientes y citas que ya tenías se asignan automáticamente
-- al primer usuario profesional que encuentre el script y a una
-- sede "Sede principal" creada para no dejar nada huérfano.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) SEDES (sucursales / lugares de atención)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sedes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sede por defecto para no dejar huérfano nada de lo que ya existe.
INSERT INTO sedes (nombre) 
SELECT 'Sede principal' 
WHERE NOT EXISTS (SELECT 1 FROM sedes WHERE nombre = 'Sede principal');

-- ------------------------------------------------------------
-- 2) RELACIÓN profesional ↔ sede (un profesional puede atender
--    en varias sedes; una sede tiene varios profesionales)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios_sedes (
    usuario_id INT NOT NULL,
    sede_id INT NOT NULL,
    PRIMARY KEY (usuario_id, sede_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asociar todos los usuarios profesionales/administrativos existentes
-- a la "Sede principal", para que no queden sin sede asignada.
INSERT INTO usuarios_sedes (usuario_id, sede_id)
SELECT u.id, (SELECT id FROM sedes WHERE nombre = 'Sede principal')
FROM usuarios u
WHERE NOT EXISTS (SELECT 1 FROM usuarios_sedes us WHERE us.usuario_id = u.id);

-- ------------------------------------------------------------
-- 3) ROL "desarrollador" — se agrega como valor nuevo del ENUM.
--    El desarrollador es el único que crea/desactiva usuarios.
-- ------------------------------------------------------------
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('desarrollador', 'profesional', 'administrativa') NOT NULL DEFAULT 'profesional';

-- ------------------------------------------------------------
-- 4) CONTRASEÑA DE DESARROLLADOR (vive aparte de "usuarios",
--    porque el desarrollador no entra eligiendo sede/profesional
--    como todos los demás, entra por una puerta separada).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS desarrollador (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clave_hash VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5) PACIENTES: agregar dueño (profesional_id) y sede (sede_id).
--    Aislamiento total: cada profesional ve solo sus pacientes.
-- ------------------------------------------------------------
ALTER TABLE pacientes ADD COLUMN profesional_id INT NULL AFTER id;
ALTER TABLE pacientes ADD COLUMN sede_id INT NULL AFTER profesional_id;

-- Asignar los pacientes existentes al primer profesional activo
-- que encuentre el sistema, y a la Sede principal, para no dejar
-- legajos huérfanos. Si tenés más de un profesional, vas a poder
-- reasignarlos manualmente después desde el sistema.
UPDATE pacientes
SET profesional_id = (SELECT id FROM usuarios WHERE rol = 'profesional' AND activo = 1 ORDER BY id ASC LIMIT 1)
WHERE profesional_id IS NULL;

UPDATE pacientes
SET sede_id = (SELECT id FROM sedes WHERE nombre = 'Sede principal')
WHERE sede_id IS NULL;

ALTER TABLE pacientes ADD CONSTRAINT fk_pacientes_profesional
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE SET NULL;
ALTER TABLE pacientes ADD CONSTRAINT fk_pacientes_sede
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE SET NULL;

ALTER TABLE pacientes ADD INDEX idx_pacientes_profesional (profesional_id);
ALTER TABLE pacientes ADD INDEX idx_pacientes_sede (sede_id);

-- ------------------------------------------------------------
-- 6) CITAS: agregar profesional dueño del turno (para validar
--    choques de horario sin tener que ir a buscarlo via paciente
--    cada vez) y token de confirmación pública (el paciente
--    confirma/cancela su turno sin necesidad de loguearse).
-- ------------------------------------------------------------
ALTER TABLE citas ADD COLUMN profesional_id INT NULL AFTER paciente_id;

UPDATE citas c
INNER JOIN pacientes p ON p.id = c.paciente_id
SET c.profesional_id = p.profesional_id
WHERE c.profesional_id IS NULL;

ALTER TABLE citas ADD CONSTRAINT fk_citas_profesional
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Índice clave para detectar choques: mismo profesional, misma
-- fecha y hora, entre turnos pendientes.
ALTER TABLE citas ADD INDEX idx_citas_choque (profesional_id, fecha, hora);

ALTER TABLE citas ADD COLUMN token_confirmacion VARCHAR(64) NULL AFTER notas;
ALTER TABLE citas ADD COLUMN confirmada_por_paciente TINYINT(1) NOT NULL DEFAULT 0 AFTER token_confirmacion;
ALTER TABLE citas ADD UNIQUE INDEX idx_citas_token (token_confirmacion);

-- ------------------------------------------------------------
-- 7) PLANTILLAS DE EVOLUCIÓN: ahora son por profesional, no
--    compartidas entre todos.
-- ------------------------------------------------------------
ALTER TABLE plantillas_evolucion ADD COLUMN profesional_id INT NULL AFTER id;

UPDATE plantillas_evolucion
SET profesional_id = (SELECT id FROM usuarios WHERE rol = 'profesional' AND activo = 1 ORDER BY id ASC LIMIT 1)
WHERE profesional_id IS NULL;

ALTER TABLE plantillas_evolucion ADD CONSTRAINT fk_plantillas_profesional
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE CASCADE;

-- ============================================================
-- Fin de la migración v5.
-- ============================================================

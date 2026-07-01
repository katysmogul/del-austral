-- ============================================================
-- Del Austral — Migración de base de datos (versión 10)
-- ============================================================
-- Agrega:
-- 1. Columnas de licencia en "usuarios": estado, duración e
--    inicio, para controlar si un profesional puede acceder.
-- 2. Tabla "profesionales_legajos" con los datos personales
--    completos del profesional (título, DNI, especialidad, etc.)
--
-- No borra ni modifica pacientes, sesiones, citas ni adjuntos.
--
-- Cómo aplicarlo:
-- 1. Entrá a phpMyAdmin → tu base de datos.
-- 2. Pestaña "SQL" (no "Importar").
-- 3. Pegá todo este archivo y ejecutá.
-- ============================================================

SET NAMES utf8mb4;

-- Sistema de licencias en la tabla de usuarios.
-- estado_licencia:
--   'activo'     → puede entrar normalmente
--   'suspendido' → la licencia por días venció automáticamente
--   'pausado'    → el Desarrollador lo pausó manualmente
--   'prohibido'  → el Desarrollador lo prohibió (ej: falta de pago)
-- licencia_dias: NULL = indeterminado (sin vencimiento por tiempo)
-- licencia_inicio: cuándo se activó la licencia actual
ALTER TABLE usuarios ADD COLUMN estado_licencia ENUM('activo','suspendido','pausado','prohibido') NOT NULL DEFAULT 'activo' AFTER activo;
ALTER TABLE usuarios ADD COLUMN licencia_dias SMALLINT UNSIGNED NULL AFTER estado_licencia;
ALTER TABLE usuarios ADD COLUMN licencia_inicio DATE NULL AFTER licencia_dias;

-- Los usuarios que ya existían quedan con estado 'activo'
-- y sin vencimiento (indeterminado), para no interrumpir nada.
UPDATE usuarios SET estado_licencia = 'activo', licencia_dias = NULL, licencia_inicio = CURDATE() WHERE rol = 'profesional';

-- Tabla de legajos de profesionales.
-- Cada fila corresponde a un usuario con rol 'profesional'.
CREATE TABLE IF NOT EXISTS profesionales_legajos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NOT NULL UNIQUE,
    titulo ENUM('Dr.','Dra.','Lic.','Tec.','Mg.','Prof.','Otro') NOT NULL DEFAULT 'Dr.',
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(80) NOT NULL,
    dni VARCHAR(20) NULL,
    fecha_nacimiento DATE NULL,
    lugar_nacimiento VARCHAR(150) NULL,
    especialidad VARCHAR(150) NULL,
    email VARCHAR(150) NULL,
    telefono VARCHAR(40) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Para los profesionales que ya existían, creamos un legajo
-- básico usando el nombre que ya tenían en la tabla usuarios,
-- para que aparezcan en la nueva vista sin tener que
-- completarlos manualmente desde cero.
INSERT INTO profesionales_legajos (usuario_id, titulo, nombre, apellido)
SELECT id,
       'Dr.',
       SUBSTRING_INDEX(nombre_completo, ' ', 1),
       SUBSTRING(nombre_completo, LOCATE(' ', nombre_completo) + 1)
FROM usuarios
WHERE rol = 'profesional'
ON DUPLICATE KEY UPDATE usuario_id = usuario_id;

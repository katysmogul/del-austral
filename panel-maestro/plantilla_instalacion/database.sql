-- ============================================================
-- Base de datos: Del Austral — Historial Clínico Digital
-- Importar este archivo desde phpMyAdmin (pestaña "Importar")
-- dentro de la base de datos que crees en cPanel.
--
-- Este esquema ya incluye TODO (sedes, múltiples profesionales,
-- rol desarrollador, confirmación de turnos, etc.) — es para una
-- instalación NUEVA, desde cero. Si ya tenías el sistema andando
-- con pacientes cargados, no uses este archivo: usá en cambio
-- las migraciones (migracion_v2.sql en adelante) en orden.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- ------------------------------------------------------------
-- Tabla: sedes (sucursales / lugares de atención)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion_institucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre_institucion VARCHAR(150) NOT NULL DEFAULT 'Del Austral',
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO configuracion_institucion (nombre_institucion) VALUES ('Del Austral');

CREATE TABLE IF NOT EXISTS sedes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: contrato_firma_cliente (firma del Desarrollador sobre
-- el contrato, requerida ANTES de poder crear la clave de
-- Desarrollador). Solo puede existir una fila: la firma es del
-- representante de esta institución, no por usuario individual.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contrato_firma_cliente (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firma_png LONGTEXT NOT NULL,
    firmado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: desarrollador (contraseña única, separada de "usuarios")
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS desarrollador (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clave_hash VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: usuarios (profesionales y administrativas; cada uno
-- con su propio PIN de 4 dígitos, hasheado)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre_completo VARCHAR(150) NOT NULL,
    rol ENUM('desarrollador', 'profesional', 'administrativa') NOT NULL DEFAULT 'profesional',
    patron_hash VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    estado_licencia ENUM('activo','suspendido','pausado','prohibido') NOT NULL DEFAULT 'activo',
    licencia_dias SMALLINT UNSIGNED NULL,
    licencia_inicio DATE NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: usuarios_sedes (en qué sede(s) atiende cada usuario)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios_sedes (
    usuario_id INT NOT NULL,
    sede_id INT NOT NULL,
    PRIMARY KEY (usuario_id, sede_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: obras_sociales (catálogo editable de obras sociales)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profesionales_legajos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NOT NULL UNIQUE,
    numero_legajo VARCHAR(20) NULL UNIQUE,
    titulo ENUM('Dr.','Dra.','Lic.','Tec.','Mg.','Prof.','Otro') NOT NULL DEFAULT 'Dr.',
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(80) NOT NULL,
    dni VARCHAR(20) NULL,
    fecha_nacimiento DATE NULL,
    lugar_nacimiento VARCHAR(150) NULL,
    especialidad VARCHAR(150) NULL,
    matricula_nacional VARCHAR(30) NULL,
    matricula_provincial VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    telefono VARCHAR(40) NULL,
    firma_digital MEDIUMTEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS obras_sociales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(120) NOT NULL UNIQUE,
    es_predefinida TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO obras_sociales (nombre, es_predefinida) VALUES
    ('Particular (sin obra social)', 1),
    ('OSDE', 1),
    ('Swiss Medical', 1),
    ('Galeno', 1),
    ('Medifé', 1),
    ('IOMA', 1),
    ('PAMI', 1),
    ('Unión Personal', 1),
    ('OSDEPYM', 1),
    ('Jerárquicos Salud', 1),
    ('Sancor Salud', 1),
    ('Seros', 1),
    ('Accord Salud', 1),
    ('ACA Salud', 1),
    ('Apross', 1),
    ('OSECAC', 1),
    ('Luis Pasteur', 1),
    ('Hospital Italiano (Plan de Salud)', 1)
ON DUPLICATE KEY UPDATE nombre = nombre;

-- ------------------------------------------------------------
-- Tabla: pacientes (legajo principal)
-- Cada paciente pertenece a UN profesional (aislamiento total
-- entre profesionales) y a UNA sede (puede migrarse después).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pacientes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profesional_id INT NULL,
    sede_id INT NULL,
    recuperado_de_profesional VARCHAR(150) NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo ENUM('Femenino', 'Masculino', 'Otro') NOT NULL,
    obra_social_id INT NULL,
    numero_afiliado VARCHAR(60) NULL,
    telefono VARCHAR(40) NULL,
    email VARCHAR(150) NULL,
    direccion VARCHAR(200) NULL,
    motivo_consulta TEXT NULL,
    patologia VARCHAR(255) NULL,
    sintomas TEXT NULL,
    observaciones_generales TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (sede_id) REFERENCES sedes(id) ON DELETE SET NULL,
    FOREIGN KEY (obra_social_id) REFERENCES obras_sociales(id) ON DELETE SET NULL,
    UNIQUE INDEX idx_dni_por_profesional (profesional_id, dni),
    INDEX idx_apellido_nombre (apellido, nombre),
    INDEX idx_obra_social (obra_social_id),
    INDEX idx_pacientes_profesional (profesional_id),
    INDEX idx_pacientes_sede (sede_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: sesiones (cada día/sesión de atención dentro de un legajo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sesiones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paciente_id INT NOT NULL,
    fecha_sesion DATE NOT NULL,
    descripcion TEXT NOT NULL,
    evolucion TEXT NULL,
    proxima_cita DATE NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    INDEX idx_paciente_fecha (paciente_id, fecha_sesion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: legajos_eliminados (papelera / base histórica)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS legajos_eliminados (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paciente_id_original INT NOT NULL,
    profesional_id_original INT NULL,
    sede_id_original INT NULL,
    nombre_completo VARCHAR(220) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    datos_json LONGTEXT NOT NULL,
    eliminado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dni_eliminado (dni),
    INDEX idx_papelera_profesional (profesional_id_original),
    INDEX idx_papelera_sede (sede_id_original)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: citas (agenda). El choque de horario se valida por
-- profesional_id + fecha + hora, sin importar la sede.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    paciente_id INT NOT NULL,
    profesional_id INT NULL,
    fecha DATE NOT NULL,
    hora TIME NULL,
    motivo VARCHAR(255) NULL,
    estado ENUM('pendiente', 'atendida', 'cancelada', 'ausente') NOT NULL DEFAULT 'pendiente',
    notas TEXT NULL,
    token_confirmacion VARCHAR(64) NULL,
    confirmada_por_paciente TINYINT(1) NOT NULL DEFAULT 0,
    revisada_por_profesional TINYINT(1) NOT NULL DEFAULT 1,
    sesion_generada_id INT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_citas_fecha (fecha, hora),
    INDEX idx_citas_paciente (paciente_id),
    INDEX idx_citas_choque (profesional_id, fecha, hora),
    UNIQUE INDEX idx_citas_token (token_confirmacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: archivos_adjuntos (PDF e imágenes ligados a un legajo)
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
-- Tabla: plantillas_evolucion (texto libre reutilizable,
-- cada profesional tiene las suyas, no se comparten)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plantillas_evolucion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profesional_id INT NULL,
    nombre VARCHAR(120) NOT NULL,
    contenido TEXT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (profesional_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: historial_cambios (auditoría de quién hizo qué)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS constancias (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(40) NOT NULL UNIQUE,
    tipo ENUM('asistencia','tratamiento','receta') NOT NULL DEFAULT 'asistencia',
    profesional_id INT NOT NULL,
    sede_id INT NOT NULL,
    paciente_id INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    lugar_nacimiento VARCHAR(150) NULL,
    fecha_consulta DATE NOT NULL,
    tratamiento_desde DATE NULL,
    diagnostico TEXT NULL,
    indicaciones TEXT NULL,
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

CREATE TABLE IF NOT EXISTS constancias_historico (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token VARCHAR(40) NOT NULL,
    tipo ENUM('asistencia','tratamiento','receta') NOT NULL DEFAULT 'asistencia',
    profesional_id INT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    emitida_en TIMESTAMP NOT NULL,
    vencio_en DATE NOT NULL,
    INDEX idx_token_historico (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS historial_cambios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NULL,
    usuario_nombre VARCHAR(150) NULL,
    accion VARCHAR(60) NOT NULL,
    entidad VARCHAR(60) NOT NULL,
    entidad_id INT NULL,
    descripcion VARCHAR(500) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_historial_entidad (entidad, entidad_id),
    INDEX idx_historial_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

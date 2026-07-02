-- ============================================================
-- Panel Maestro — Esquema de base de datos
-- ============================================================
-- Esta es una base de datos APARTE, exclusiva del panel
-- maestro — no tiene nada que ver con las bases de datos de
-- cada institución cliente (Del Austral, Hospital Regional,
-- etc.), que son completamente independientes entre sí.
--
-- Cómo instalarlo:
-- 1. Creá una base de datos nueva en MySQL para el panel
--    maestro (ej: panel_maestro_db).
-- 2. Importá este archivo en esa base.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS super_admin (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clave_hash VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instituciones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    carpeta VARCHAR(80) NOT NULL UNIQUE,
    db_nombre VARCHAR(80) NOT NULL UNIQUE,
    db_usuario VARCHAR(80) NOT NULL UNIQUE,
    estado ENUM('activa', 'suspendida', 'suspendida_por_pago') NOT NULL DEFAULT 'activa',
    suspendida_por_cobro_id INT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notas TEXT NULL,
    saldo_favor DECIMAL(12,2) NOT NULL DEFAULT 0,
    cuenta_titular VARCHAR(150) NULL,
    cuenta_banco VARCHAR(100) NULL,
    cuenta_cuil VARCHAR(30) NULL,
    cuenta_numero VARCHAR(60) NULL,
    cuenta_alias VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cobros (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    monto_lista DECIMAL(12,2) NOT NULL,
    descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    monto DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(10) NOT NULL DEFAULT 'ARS',
    periodo_desde DATE NULL,
    periodo_hasta DATE NULL,
    vencimiento DATE NOT NULL,
    estado ENUM('pendiente', 'comprobante_subido', 'aprobado', 'sin_acreditar', 'rechazado', 'anulado') NOT NULL DEFAULT 'pendiente',
    comprobante_nombre_original VARCHAR(255) NULL,
    comprobante_nombre_archivo VARCHAR(255) NULL,
    comprobante_subido_en TIMESTAMP NULL,
    notas_super_admin TEXT NULL,
    motivo_anulacion VARCHAR(255) NULL,
    recargo_congelado_en DATE NULL,
    recargo_congelado_monto DECIMAL(12,2) NULL,
    revisado_en TIMESTAMP NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimientos_saldo_favor (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    tipo ENUM('carga', 'aplicado_a_cobro') NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    cobro_id INT NULL,
    nota VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE,
    FOREIGN KEY (cobro_id) REFERENCES cobros(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contratos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    institucion_id INT NOT NULL,
    razon_social_cliente VARCHAR(200) NOT NULL,
    cuit_dni_cliente VARCHAR(30) NOT NULL,
    plazo_tipo ENUM('dias', 'meses', 'anios', 'indeterminado') NOT NULL DEFAULT 'indeterminado',
    plazo_cantidad INT NULL,
    modalidad_pago ENUM('mensual', 'anual') NOT NULL DEFAULT 'mensual',
    precio_monto DECIMAL(12,2) NULL,
    precio_moneda VARCHAR(10) NOT NULL DEFAULT 'ARS',
    ram_gb INT NOT NULL DEFAULT 16,
    disco_gb INT NOT NULL DEFAULT 25,
    backup_horario VARCHAR(50) NOT NULL DEFAULT '3 a 5 A.M.',
    ubicacion_servidor VARCHAR(150) NOT NULL DEFAULT 'Santiago de Chile, Chile',
    prestador_titular_nombre VARCHAR(150) NOT NULL DEFAULT 'MONTERO, FABIANA KARINA',
    prestador_titular_cuil VARCHAR(30) NOT NULL DEFAULT '27-20746451-7',
    prestador_apoderado_nombre VARCHAR(150) NOT NULL DEFAULT 'LORENZ MONTERO, ARIAN TAHIEL',
    prestador_apoderado_cuil VARCHAR(30) NOT NULL DEFAULT '20-46143095-4',
    prestador_marca VARCHAR(100) NOT NULL DEFAULT 'DEL AUSTRAL',
    tolerancia_mora_meses INT NOT NULL DEFAULT 2,
    plazo_entrega_datos_dias INT NOT NULL DEFAULT 7,
    preaviso_rescision_dias INT NOT NULL DEFAULT 30,
    firma_apoderado_png LONGTEXT NULL,
    firma_cliente_png LONGTEXT NULL,
    firma_cliente_sincronizada_en TIMESTAMP NULL,
    fecha_contrato DATE NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

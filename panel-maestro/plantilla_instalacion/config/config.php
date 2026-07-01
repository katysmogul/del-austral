<?php
/**
 * ============================================================
 * CONFIGURACIÓN DE BASE DE DATOS
 * ============================================================
 * Completá estos 4 datos con los que te dio cPanel al crear
 * la base de datos MySQL (sección "Bases de datos MySQL").
 *
 * DB_HOST   -> casi siempre es "localhost"
 * DB_NAME   -> el nombre completo, ej: "usuario_legajos"
 * DB_USER   -> el usuario completo, ej: "usuario_admin"
 * DB_PASS   -> la contraseña que elegiste para ese usuario
 * ============================================================
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'TU_USUARIO_legajos');
define('DB_USER', 'TU_USUARIO_admin');
define('DB_PASS', 'TU_CONTRASEÑA_AQUI');

/**
 * Clave secreta usada para firmar la sesión de acceso.
 * Cambiala por cualquier texto largo y random, una sola vez,
 * antes de subir el sitio. No la compartas.
 */
define('APP_SECRET', 'cambiar-esto-por-un-texto-largo-y-aleatorio-unico');

// ------------------------------------------------------------
// No es necesario tocar nada debajo de esta línea
// ------------------------------------------------------------

function obtenerConexion() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'No se pudo conectar a la base de datos. Revisá config.php con los datos reales de tu cPanel.',
                'detalle' => $e->getMessage()
            ]);
            exit;
        }
    }
    return $pdo;
}

session_start();

function requiereSesion() {
    if (empty($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sesión no válida. Iniciá sesión nuevamente.']);
        exit;
    }
}

/**
 * Corta la ejecución con 403 si el usuario logueado no es "profesional".
 * Usar en cualquier endpoint que exponga datos clínicos sensibles.
 */
function requiereRolProfesional() {
    requiereSesion();
    if (($_SESSION['rol'] ?? '') !== 'profesional') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Tu usuario no tiene permiso para acceder a esta información clínica.']);
        exit;
    }
}

function rolActual() {
    return $_SESSION['rol'] ?? null;
}

function esProfesional() {
    return rolActual() === 'profesional';
}

function esDesarrollador() {
    return rolActual() === 'desarrollador';
}

/**
 * Corta la ejecución con 403 si quien está logueado no es el
 * apoderado. Se usa para altas/bajas de usuarios y sedes.
 */
function requiereDesarrollador() {
    requiereSesion();
    if (!esDesarrollador()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Esta acción solo puede hacerla el apoderado del sistema.']);
        exit;
    }
}

/**
 * El "profesional activo" es el dueño de los pacientes que se
 * deben ver en esta sesión. Si quien entró es un profesional,
 * es su propio ID. Si es una administrativa, es el profesional
 * que eligió representar al momento de loguearse (guardado en
 * sesión como profesional_activo_id).
 */
function idProfesionalActivo() {
    if (esProfesional()) {
        return $_SESSION['usuario_id'] ?? null;
    }
    return $_SESSION['profesional_activo_id'] ?? null;
}

/**
 * Corta la ejecución con 400 si no hay un profesional activo
 * resuelto en la sesión (no debería pasar en uso normal, pero
 * protege contra estados de sesión inconsistentes).
 */
function requiereProfesionalActivo() {
    requiereSesion();
    if (!idProfesionalActivo()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No se pudo determinar a qué profesional pertenecen estos datos. Volvé a iniciar sesión.']);
        exit;
    }
}

/**
 * Registra una acción en el historial de cambios (auditoría).
 * $accion: 'crear' | 'editar' | 'eliminar' | etc.
 * $entidad: 'paciente' | 'sesion' | 'cita' | etc.
 */
function registrarAuditoria($pdo, $accion, $entidad, $entidadId, $descripcion = null) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO historial_cambios (usuario_id, usuario_nombre, accion, entidad, entidad_id, descripcion)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $_SESSION['usuario_id'] ?? null,
            $_SESSION['nombre_usuario'] ?? null,
            $accion,
            $entidad,
            $entidadId,
            $descripcion,
        ]);
    } catch (Exception $e) {
        // La auditoría nunca debe romper la operación principal.
    }
}

/**
 * Devuelve el nombre de la institución configurado para esta
 * instalación (ej: "Hospital Regional"). Si la tabla todavía no
 * existe (instalación vieja sin migrar) o está vacía, devuelve
 * "Del Austral" como respaldo, para que nada se rompa.
 * Se cachea en memoria durante la misma petición HTTP.
 */
function nombreInstitucion($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $pdo->query('SELECT nombre_institucion FROM configuracion_institucion LIMIT 1');
        $fila = $stmt->fetch();
        $cache = $fila && !empty($fila['nombre_institucion']) ? $fila['nombre_institucion'] : 'Del Austral';
    } catch (Exception $e) {
        $cache = 'Del Austral';
    }
    return $cache;
}


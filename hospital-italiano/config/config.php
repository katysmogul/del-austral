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
define('DB_NAME', 'inst_hospital_italiano_0d7f1b');
define('DB_USER', 'u_hospital_italiano_0d7f1b');
define('DB_PASS', 'e4c547ccac89e0256198540f7afcf8cd');

/**
 * Clave secreta usada para firmar la sesión de acceso.
 * Cambiala por cualquier texto largo y random, una sola vez,
 * antes de subir el sitio. No la compartas.
 */
define('APP_SECRET', '96d7b923599e9e070b8a8f603ca9b97485ad4f53f0ed93c6');

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

// Nombre propio de cookie de sesión, distinto del panel maestro
// y de cualquier otra institución que viva en el mismo dominio.
// Sin esto, PHP usa "PHPSESSID" para todos los sitios del mismo
// dominio, y loguearse en un panel pisaba la sesión de otro.
session_name('institucion_' . basename(dirname(__DIR__)) . '_sesion');
session_start();

/**
 * Guarda un error fatal o una excepción no capturada en la tabla
 * errores_app, para que el panel maestro pueda mostrarlo como
 * señal de salud del sistema sin depender del log de PHP del
 * hosting (que varía de un servidor a otro y a veces ni es
 * accesible). Nunca debe romper la ejecución si falla: si no se
 * puede conectar a la base, simplemente no se registra nada.
 */
function registrarErrorApp($mensaje, $archivo = null, $linea = null) {
    try {
        $pdo = obtenerConexion();
        $pdo->prepare('
            INSERT INTO errores_app (mensaje, archivo, linea, url_solicitud)
            VALUES (?, ?, ?, ?)
        ')->execute([
            mb_substr((string) $mensaje, 0, 2000),
            $archivo,
            $linea,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Si ni la propia captura de errores funciona, no hay
        // nada más que hacer acá — no relanzamos para no tapar
        // el error original con uno nuevo.
    }
}

set_exception_handler(function ($excepcion) {
    registrarErrorApp(
        get_class($excepcion) . ': ' . $excepcion->getMessage(),
        $excepcion->getFile(),
        $excepcion->getLine()
    );
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error inesperado. Ya quedó registrado para revisión.']);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        registrarErrorApp($error['message'], $error['file'], $error['line']);
        if (!headers_sent()) {
            // Si el endpoint que disparó este error tenía un
            // ob_start() propio abierto (como cobranza.php), hay
            // que descartar lo que tenga acumulado (el warning
            // que causó el error, mezclado con HTML) antes de
            // escribir el JSON — sino quedan los dos pegados y
            // rompen el JSON.parse del lado del navegador con
            // "unexpected character at line 1 column 1".
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Ocurrió un error inesperado. Ya quedó registrado para revisión.']);
        }
    }
});

/**
 * Marca en salud_sistema el momento de la actividad real más
 * reciente (login exitoso, o creación de una sesión clínica).
 * Se usa como señal de "esta institución sigue en uso" en el
 * panel de salud del sistema del panel maestro.
 */
function registrarActividadReciente($pdo) {
    try {
        $pdo->prepare('
            INSERT INTO salud_sistema (clave, valor)
            VALUES (\'ultima_actividad\', NOW())
            ON DUPLICATE KEY UPDATE valor = NOW()
        ')->execute();
    } catch (Throwable $e) {
        // No crítico: si falla, simplemente no se actualiza la señal.
    }
}

/**
 * El modo mantenimiento se controla desde el panel maestro con
 * un archivo compartido (mantenimiento.json) fuera de la carpeta
 * de esta institución, en la raíz común a todas las
 * instalaciones. Por eso se lee directo del filesystem, sin
 * conectarse a ninguna base de datos ajena.
 */
function mantenimientoActivo() {
    return file_exists(__DIR__ . '/../../mantenimiento.json');
}

/**
 * Corta la ejecución con 503 si el modo mantenimiento global
 * está activo, salvo que quien esté logueado (o intentando
 * loguearse) sea el Apoderado — que sigue teniendo acceso para
 * poder gestionar mientras el resto está bloqueado. Se llama al
 * principio de cada endpoint que sirve datos o permite login,
 * ANTES de requiereSesion(), para bloquear incluso a quien
 * todavía no inició sesión.
 */
function requiereSinMantenimiento() {
    if (!mantenimientoActivo()) return;
    if (($_SESSION['rol'] ?? '') === 'desarrollador') return;

    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'mantenimiento' => true,
        'error' => 'Estamos en mantenimiento. Por el momento no vas a poder ingresar mientras hacemos tareas de mantenimiento.',
    ]);
    exit;
}

/**
 * A diferencia del mantenimiento global, la suspensión por falta
 * de pago es POR institución: el propio panel maestro escribe un
 * archivo dentro de la carpeta de esta institución (no en la
 * raíz compartida), así que se lee directo de acá al lado.
 */
function suspendidaPorPagoActiva() {
    return file_exists(__DIR__ . '/../suspendida_por_pago.json');
}

/**
 * Corta la ejecución con 402 si la institución está suspendida
 * por falta de pago, salvo que quien esté logueado (o intentando
 * loguearse) sea el Apoderado — que siempre puede seguir
 * entrando para ver su deuda y subir el comprobante de pago.
 */
function requiereSinSuspensionPorPago() {
    if (!suspendidaPorPagoActiva()) return;
    if (($_SESSION['rol'] ?? '') === 'desarrollador') return;

    http_response_code(402);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'suspendida_por_pago' => true,
        'error' => 'El acceso está suspendido por falta de pago. El Apoderado puede ingresar para revisar la deuda y regularizar la situación.',
    ]);
    exit;
}

function requiereSesion() {
    if (empty($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sesión no válida. Iniciá sesión nuevamente.']);
        exit;
    }
    requiereSinMantenimiento();
    requiereSinSuspensionPorPago();
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


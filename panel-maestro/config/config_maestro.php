<?php
/**
 * ============================================================
 * CONFIGURACIÓN DEL PANEL MAESTRO
 * ============================================================
 * Este archivo tiene DOS conexiones distintas a MySQL:
 *
 * 1. La conexión normal (DB_*), para la base de datos PROPIA
 *    del panel maestro (la clave de Super Admin y el listado
 *    de instituciones creadas).
 *
 * 2. La conexión de ADMINISTRACIÓN (DB_ADMIN_*), con el
 *    usuario "panel_maestro" de MySQL que vos creaste, que
 *    tiene permiso para CREAR bases de datos y usuarios
 *    nuevos. Esta es la conexión más sensible de todo el
 *    sistema: solo se usa en el momento exacto de crear una
 *    institución nueva, nunca para nada más.
 *
 * IMPORTANTE: este archivo NUNCA debe subirse a un repositorio
 * git público ni compartirse. Ya está excluido en .gitignore.
 * ============================================================
 */

// --- Base de datos propia del panel maestro ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'panel_maestro_db');
define('DB_USER', 'panel_maestro_db_user');
define('DB_PASS', 'rfpF3P2004*');

// --- Usuario administrador de MySQL (creado en el Paso 1) ---
define('DB_ADMIN_HOST', 'localhost');
define('DB_ADMIN_USER', 'panel_maestro');
define('DB_ADMIN_PASS', 'rfpF3P2004*');

// Secreto propio del panel maestro — DEBE ser distinto al
// APP_SECRET de cualquier institución cliente.
define('APP_SECRET_MAESTRO', 'kimeyindia1');

// Ruta absoluta en el servidor donde viven las instalaciones
// de cada institución (normalmente la carpeta donde está este
// mismo panel-maestro, un nivel arriba).
// __DIR__ acá es .../panel-maestro/config, así que necesitamos
// subir DOS niveles para llegar a la raíz del proyecto
// principal (.../neptuno), que es donde viven las instituciones.
define('RUTA_INSTALACIONES', dirname(__DIR__, 2));

// URL base pública (sin barra final), para armar los links que
// se le muestran al Super Admin después de crear una institución.
define('URL_BASE', 'https://neptuno.delaustral.com');

session_start();

function obtenerConexionMaestro() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos del panel maestro.']);
        exit;
    }
}

/**
 * Conexión SOLO de administración (crear bases/usuarios). Se
 * conecta sin elegir ninguna base de datos puntual, porque va
 * a operar sobre el servidor MySQL en general.
 */
function obtenerConexionAdminMysql() {
    try {
        $dsn = 'mysql:host=' . DB_ADMIN_HOST . ';charset=utf8mb4';
        return new PDO($dsn, DB_ADMIN_USER, DB_ADMIN_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No se pudo conectar con el usuario administrador de MySQL. Revisá las credenciales en config.php.']);
        exit;
    }
}

function requiereSesionMaestro() {
    if (empty($_SESSION['super_admin_autenticado'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
        exit;
    }
}

/**
 * Se conecta puntualmente a la base de datos de UNA institución
 * cliente específica, leyendo sus credenciales desde el
 * config.php real que vive en su carpeta de instalación. Se usa
 * solo para tareas administrativas concretas (por ejemplo, leer
 * si el desarrollador ya firmó el contrato) — nunca para operar
 * sobre los datos clínicos de esa institución.
 *
 * Devuelve null si la carpeta, el config.php, o la conexión no
 * están disponibles, en vez de lanzar una excepción: este es un
 * mecanismo "best effort", no algo que deba tirar abajo una
 * request del panel maestro si una institución puntual tiene
 * problemas.
 */
function obtenerConexionInstitucionCliente($carpeta) {
    $rutaConfig = RUTA_INSTALACIONES . '/' . $carpeta . '/config/config.php';
    if (!file_exists($rutaConfig)) return null;

    // Leemos las credenciales del config.php real sin incluirlo
    // (incluirlo redefiniría constantes ya definidas por nuestro
    // propio config_maestro.php y rompería con un fatal error).
    $contenido = file_get_contents($rutaConfig);
    $extraer = function ($constante) use ($contenido) {
        if (preg_match("/define\('" . $constante . "',\s*'([^']*)'\)/", $contenido, $m)) {
            return $m[1];
        }
        return null;
    };
    $host = $extraer('DB_HOST');
    $nombre = $extraer('DB_NAME');
    $usuario = $extraer('DB_USER');
    $clave = $extraer('DB_PASS');
    if (!$host || !$nombre || !$usuario) return null;

    try {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $nombre . ';charset=utf8mb4';
        return new PDO($dsn, $usuario, $clave, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $e) {
        return null;
    }
}

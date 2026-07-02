<?php
require_once __DIR__ . '/config/config_maestro.php';
require_once __DIR__ . '/plantilla_contrato.php';
require_once __DIR__ . '/plantilla_factura.php';

// Si el hosting tiene display_errors activado, un warning o
// notice cualquiera se imprime como HTML ANTES del json_encode
// de más abajo, y eso rompe el JSON.parse del lado del panel con
// "el servidor devolvió una respuesta inesperada" — aunque la
// operación en sí pudo haber funcionado bien. Este buffer
// descarta cualquier salida accidental de ese tipo, para que la
// respuesta sea siempre JSON puro. Además, si ocurre un error
// fatal de PHP, lo capturamos y lo devolvemos como JSON legible
// en vez de una página en blanco o HTML de error.
ob_start();
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        ob_clean();
        echo json_encode([
            'ok' => false,
            'error' => 'Ocurrió un error interno en el servidor: ' . $error['message'] . ' (línea ' . $error['line'] . ' de ' . basename($error['file']) . ').',
        ]);
    }
});

$pdo = obtenerConexionMaestro();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

/**
 * Convierte "Hospital Regional" en un slug seguro para usar
 * como nombre de carpeta y base de identificadores de MySQL:
 * minúsculas, sin tildes, solo letras/números/guiones.
 */
/**
 * Confirma que la función exec() esté habilitada en este
 * servidor (algunos hostings la deshabilitan por seguridad).
 * Sin ella no se puede importar database.sql de forma robusta.
 */
function execDisponible() {
    if (!function_exists('exec')) return false;
    $deshabilitadas = explode(',', ini_get('disable_functions'));
    return !in_array('exec', array_map('trim', $deshabilitadas));
}

function generarSlug($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
    ]);
    $texto = preg_replace('/[^a-z0-9\s-]/', '', $texto);
    $texto = preg_replace('/[\s_]+/', '-', trim($texto));
    $texto = preg_replace('/-+/', '-', $texto);
    return trim($texto, '-');
}

/**
 * Copia recursiva de una carpeta completa (la plantilla de
 * instalación).
 */
function copiarCarpeta($origen, $destino) {
    if (!is_dir($destino)) mkdir($destino, 0755, true);
    $items = scandir($origen);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $rutaOrigen = "$origen/$item";
        $rutaDestino = "$destino/$item";
        if (is_dir($rutaOrigen)) {
            copiarCarpeta($rutaOrigen, $rutaDestino);
        } else {
            copy($rutaOrigen, $rutaDestino);
        }
    }
}

/**
 * Genera el contenido real de config/config.php para una
 * institución nueva: toma el config.php REAL de la plantilla
 * (con todas sus funciones intactas) y solo reemplaza las 5
 * líneas de credenciales al principio. Así, cualquier mejora
 * futura que se le haga a config.php en la plantilla se va a
 * reflejar en instituciones nuevas automáticamente, sin tener
 * que mantener una copia separada de toda esa lógica acá.
 */
function construirConfigInstitucion($dbNombre, $dbUsuario, $dbPassword, $appSecret) {
    $plantilla = file_get_contents(__DIR__ . '/plantilla_instalacion/config/config.php');

    $reemplazos = [
        "/define\('DB_HOST', '[^']*'\);/" => "define('DB_HOST', 'localhost');",
        "/define\('DB_NAME', '[^']*'\);/" => "define('DB_NAME', '" . addslashes($dbNombre) . "');",
        "/define\('DB_USER', '[^']*'\);/" => "define('DB_USER', '" . addslashes($dbUsuario) . "');",
        "/define\('DB_PASS', '[^']*'\);/" => "define('DB_PASS', '" . addslashes($dbPassword) . "');",
        "/define\('APP_SECRET', '[^']*'\);/" => "define('APP_SECRET', '" . addslashes($appSecret) . "');",
    ];

    foreach ($reemplazos as $patron => $reemplazo) {
        $plantilla = preg_replace($patron, $reemplazo, $plantilla, 1);
    }

    return $plantilla;
}

/**
 * Toma el array crudo "contrato" que llega del frontend (ya sea
 * al crear una institución o al editar un contrato existente) y
 * lo normaliza/valida, devolviendo un array listo para bindear
 * en una consulta SQL. Se usa en crear_institucion y en
 * editar_contrato para no duplicar las reglas de validación.
 */
function normalizarDatosContrato($contrato) {
    $plazoTipo = in_array($contrato['plazo_tipo'] ?? '', ['dias', 'meses', 'anios', 'indeterminado'])
        ? $contrato['plazo_tipo'] : 'indeterminado';
    $modalidadPago = in_array($contrato['modalidad_pago'] ?? '', ['mensual', 'anual'])
        ? $contrato['modalidad_pago'] : 'mensual';
    $plazoCantidad = $plazoTipo !== 'indeterminado' && !empty($contrato['plazo_cantidad'])
        ? (int) $contrato['plazo_cantidad'] : null;

    $precioMonto = (isset($contrato['precio_monto']) && $contrato['precio_monto'] !== '')
        ? (float) $contrato['precio_monto'] : null;

    return [
        'razon_social_cliente'        => trim($contrato['razon_social_cliente'] ?? ''),
        'cuit_dni_cliente'            => trim($contrato['cuit_dni_cliente'] ?? ''),
        'plazo_tipo'                  => $plazoTipo,
        'plazo_cantidad'              => $plazoCantidad,
        'modalidad_pago'              => $modalidadPago,
        'precio_monto'                => $precioMonto,
        'precio_moneda'               => trim($contrato['precio_moneda'] ?? '') ?: 'ARS',
        'ram_gb'                      => (int) ($contrato['ram_gb'] ?? 16),
        'disco_gb'                    => (int) ($contrato['disco_gb'] ?? 25),
        'backup_horario'              => trim($contrato['backup_horario'] ?? '') ?: '3 a 5 A.M.',
        'ubicacion_servidor'          => trim($contrato['ubicacion_servidor'] ?? '') ?: 'Santiago de Chile, Chile',
        'prestador_titular_nombre'    => trim($contrato['prestador_titular_nombre'] ?? '') ?: 'MONTERO, FABIANA KARINA',
        'prestador_titular_cuil'      => trim($contrato['prestador_titular_cuil'] ?? '') ?: '27-20746451-7',
        'prestador_apoderado_nombre'  => trim($contrato['prestador_apoderado_nombre'] ?? '') ?: 'LORENZ MONTERO, ARIAN TAHIEL',
        'prestador_apoderado_cuil'    => trim($contrato['prestador_apoderado_cuil'] ?? '') ?: '20-46143095-4',
        'prestador_marca'             => trim($contrato['prestador_marca'] ?? '') ?: 'DEL AUSTRAL',
        'tolerancia_mora_meses'       => (int) ($contrato['tolerancia_mora_meses'] ?? 2),
        'plazo_entrega_datos_dias'    => (int) ($contrato['plazo_entrega_datos_dias'] ?? 7),
        'preaviso_rescision_dias'     => (int) ($contrato['preaviso_rescision_dias'] ?? 30),
    ];
}

/**
 * Genera el HTML estático del contrato (sin la barra de
 * exportar a PDF, que no tiene sentido del lado cliente) y lo
 * guarda como contrato.html dentro de la carpeta de esa
 * institución, para que ver_contrato.php lo pueda servir sin
 * necesidad de tocar la base de datos del panel maestro.
 *
 * Se llama después de cualquier creación o edición de un
 * contrato. Si la carpeta de la institución no existe todavía
 * (no debería pasar, pero por si acaso) no hace nada.
 */
function sincronizarContratoCliente($pdo, $institucionId) {
    $stmt = $pdo->prepare('
        SELECT i.nombre AS nombre_institucion, i.carpeta, c.*
        FROM contratos c
        INNER JOIN instituciones i ON i.id = c.institucion_id
        WHERE c.institucion_id = ?
        ORDER BY c.id DESC LIMIT 1
    ');
    $stmt->execute([$institucionId]);
    $contrato = $stmt->fetch();
    if (!$contrato) return;

    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $contrato['carpeta'];
    if (!is_dir($rutaCarpeta)) return;

    $html = renderizarDocumentoContratoHTML($contrato, false);
    file_put_contents("$rutaCarpeta/contrato.html", $html);
}

/**
 * Genera (o regenera) el HTML de la factura de un cobro puntual
 * y la guarda en la carpeta de esa institución, dentro de
 * facturas/, con nombre basado en el período (o la fecha de
 * creación si no se especificó período). También actualiza el
 * índice de facturas de esa institución, para que
 * ver_facturas.php pueda listarlas todas navegables por mes.
 */
function sincronizarFacturaCliente($pdo, $cobroId) {
    $stmt = $pdo->prepare('
        SELECT c.*, i.nombre AS institucion_nombre, i.carpeta AS institucion_carpeta
        FROM cobros c INNER JOIN instituciones i ON i.id = c.institucion_id
        WHERE c.id = ?
    ');
    $stmt->execute([$cobroId]);
    $cobro = $stmt->fetch();
    if (!$cobro) return;

    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $cobro['institucion_carpeta'];
    if (!is_dir($rutaCarpeta)) return;

    $stmt = $pdo->prepare("SELECT SUM(monto) AS total FROM movimientos_saldo_favor WHERE cobro_id = ? AND tipo = 'aplicado_a_cobro'");
    $stmt->execute([$cobroId]);
    $cobro['saldo_favor_aplicado'] = (float) ($stmt->fetch()['total'] ?? 0);
    $cobro['recargo_mora_vigente'] = calcularRecargoMora((float) $cobro['monto'], $cobro['vencimiento'], $cobro['recargo_congelado_monto']);

    $rutaFacturas = "$rutaCarpeta/facturas";
    if (!is_dir($rutaFacturas)) mkdir($rutaFacturas, 0755, true);

    $claveMes = $cobro['periodo_desde'] ? substr($cobro['periodo_desde'], 0, 7) : substr($cobro['creado_en'], 0, 7);
    $nombreArchivo = "factura_{$claveMes}_{$cobro['id']}.html";

    file_put_contents("$rutaFacturas/$nombreArchivo", renderizarFacturaHTML($cobro));

    // Índice de todas las facturas de esta institución, para que
    // ver_facturas.php las pueda listar sin tener que adivinar
    // qué archivos existen.
    $rutaIndice = "$rutaFacturas/indice.json";
    $indice = file_exists($rutaIndice) ? json_decode(file_get_contents($rutaIndice), true) : [];
    if (!is_array($indice)) $indice = [];

    $indice = array_filter($indice, fn($f) => $f['cobro_id'] != $cobroId);
    $indice[] = [
        'cobro_id' => $cobro['id'],
        'archivo' => $nombreArchivo,
        'mes' => $claveMes,
        'monto' => (float) $cobro['monto'],
        'moneda' => $cobro['moneda'],
        'estado' => $cobro['estado'],
        'vencimiento' => $cobro['vencimiento'],
    ];
    usort($indice, fn($a, $b) => strcmp($b['mes'], $a['mes']));

    file_put_contents($rutaIndice, json_encode(array_values($indice), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ------------------------------------------------------------
// LOGIN DEL SUPER ADMIN (POST ?accion=login)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'login') {
    $clave = trim($input['clave'] ?? '');

    if (!isset($_SESSION['intentos_maestro'])) $_SESSION['intentos_maestro'] = 0;
    if (!isset($_SESSION['bloqueo_maestro_hasta'])) $_SESSION['bloqueo_maestro_hasta'] = 0;
    if (time() < $_SESSION['bloqueo_maestro_hasta']) {
        http_response_code(429);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá 30 segundos.']);
        exit;
    }

    $stmt = $pdo->query('SELECT clave_hash FROM super_admin LIMIT 1');
    $fila = $stmt->fetch();

    if (!$fila) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Todavía no se configuró la clave del Super Admin.']);
        exit;
    }

    if (!password_verify($clave . APP_SECRET_MAESTRO, $fila['clave_hash'])) {
        $_SESSION['intentos_maestro']++;
        if ($_SESSION['intentos_maestro'] >= 5) {
            $_SESSION['bloqueo_maestro_hasta'] = time() + 30;
            $_SESSION['intentos_maestro'] = 0;
        }
        http_response_code(401);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Clave incorrecta.']);
        exit;
    }

    $_SESSION['intentos_maestro'] = 0;
    $_SESSION['super_admin_autenticado'] = true;
    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// CONFIGURAR CLAVE INICIAL (solo si todavía no existe ninguna)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'configurar_clave_inicial') {
    $existe = $pdo->query('SELECT 1 FROM super_admin LIMIT 1')->fetch();
    if ($existe) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ya existe una clave configurada.']);
        exit;
    }
    $clave = trim($input['clave'] ?? '');
    if (mb_strlen($clave) < 6) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'La clave debe tener al menos 6 caracteres.']);
        exit;
    }
    $hash = password_hash($clave . APP_SECRET_MAESTRO, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO super_admin (clave_hash) VALUES (?)')->execute([$hash]);
    $_SESSION['super_admin_autenticado'] = true;
    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// ESTADO INICIAL (¿ya hay clave configurada?)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'estado') {
    $existe = $pdo->query('SELECT 1 FROM super_admin LIMIT 1')->fetch();
    ob_clean();
    echo json_encode(['ok' => true, 'clave_configurada' => (bool) $existe]);
    exit;
}

// ------------------------------------------------------------
// CERRAR SESIÓN
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'cerrar_sesion') {
    $_SESSION = [];
    session_destroy();
    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

// A partir de acá, todo requiere sesión de Super Admin.
requiereSesionMaestro();

// ------------------------------------------------------------
// LISTAR INSTITUCIONES (GET ?accion=listar)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar') {
    $stmt = $pdo->query('
        SELECT i.id, i.nombre, i.carpeta, i.estado, i.creado_en, i.saldo_favor,
               (SELECT COUNT(*) FROM contratos c WHERE c.institucion_id = i.id) > 0 AS tiene_contrato,
               (SELECT firma_apoderado_png IS NOT NULL FROM contratos c WHERE c.institucion_id = i.id ORDER BY c.id DESC LIMIT 1) AS firmado_apoderado,
               (SELECT firma_cliente_png IS NOT NULL FROM contratos c WHERE c.institucion_id = i.id ORDER BY c.id DESC LIMIT 1) AS firmado_cliente,
               (SELECT estado FROM cobros co WHERE co.institucion_id = i.id ORDER BY co.vencimiento DESC, co.id DESC LIMIT 1) AS ultimo_cobro_estado,
               (SELECT vencimiento FROM cobros co WHERE co.institucion_id = i.id ORDER BY co.vencimiento DESC, co.id DESC LIMIT 1) AS ultimo_cobro_vencimiento
        FROM instituciones i
        ORDER BY i.creado_en DESC
    ');
    ob_clean();
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// CREAR INSTITUCIÓN NUEVA (POST ?accion=crear_institucion)
// El paso más delicado de todo el sistema: crea la carpeta, la
// base de datos, el usuario MySQL dedicado, y el config.php.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear_institucion') {
    if (!execDisponible()) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Este servidor tiene deshabilitada la función exec() de PHP, necesaria para importar la base de datos de forma segura. Contactá a quien administre el servidor.']);
        exit;
    }

    $nombreInstitucion = trim($input['nombre'] ?? '');
    $carpetaPedida = trim($input['carpeta'] ?? '');

    if ($nombreInstitucion === '') {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre de la institución.']);
        exit;
    }

    $slug = $carpetaPedida !== '' ? generarSlug($carpetaPedida) : generarSlug($nombreInstitucion);
    if ($slug === '' || mb_strlen($slug) < 2) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'El nombre de carpeta no es válido. Usá solo letras, números y guiones.']);
        exit;
    }
    $reservadas = ['panel-maestro', 'api', 'assets', 'config', 'adjuntos'];
    if (in_array($slug, $reservadas)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ese nombre de carpeta está reservado, elegí otro.']);
        exit;
    }

    $rutaDestino = RUTA_INSTALACIONES . "/$slug";
    if (is_dir($rutaDestino)) {
        http_response_code(409);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => "Ya existe una carpeta llamada \"$slug\" en el servidor."]);
        exit;
    }

    $stmtCheck = $pdo->prepare('SELECT 1 FROM instituciones WHERE carpeta = ?');
    $stmtCheck->execute([$slug]);
    if ($stmtCheck->fetch()) {
        http_response_code(409);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ya existe una institución registrada con esa carpeta.']);
        exit;
    }

    $sufijo = substr(bin2hex(random_bytes(3)), 0, 6);
    $dbNombre = preg_replace('/[^a-z0-9_]/', '_', "inst_{$slug}_{$sufijo}");
    $dbUsuario = preg_replace('/[^a-z0-9_]/', '_', "u_{$slug}_{$sufijo}");
    $dbPassword = bin2hex(random_bytes(16));

    $pdoAdmin = obtenerConexionAdminMysql();
    $baseCreada = false;
    $usuarioCreado = false;

    try {
        // 1. Crear la base de datos.
        $pdoAdmin->exec("CREATE DATABASE `$dbNombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $baseCreada = true;

        // 2. Crear el usuario MySQL, limitado SOLO a esa base.
        $pdoAdmin->exec("CREATE USER '$dbUsuario'@'localhost' IDENTIFIED BY '$dbPassword'");
        $usuarioCreado = true;
        $pdoAdmin->exec("GRANT ALL PRIVILEGES ON `$dbNombre`.* TO '$dbUsuario'@'localhost'");
        $pdoAdmin->exec('FLUSH PRIVILEGES');

        // 3. Importar el esquema completo (database.sql) en la base
        // nueva. Usamos el cliente "mysql" real en vez de partir el
        // archivo a mano por punto y coma — partirlo a mano es
        // frágil: cualquier comentario, sentencia con BEGIN/END, o
        // string con ";" adentro rompe el resultado. El cliente
        // mysql interpreta el SQL de verdad, igual que cuando lo
        // corremos manualmente por terminal.
        //
        // La contraseña se pasa por un archivo de credenciales
        // temporal (--defaults-extra-file), no como argumento de
        // línea de comandos, para que nunca quede visible en la
        // lista de procesos del servidor (ps aux).
        $rutaSqlEsquema = __DIR__ . '/plantilla_instalacion/database.sql';
        $archivoCredenciales = tempnam(sys_get_temp_dir(), 'mysqlcred_');
        file_put_contents($archivoCredenciales, "[client]\nuser=$dbUsuario\npassword=$dbPassword\nhost=localhost\n");
        chmod($archivoCredenciales, 0600);

        $comando = 'mysql --defaults-extra-file=' . escapeshellarg($archivoCredenciales)
            . ' ' . escapeshellarg($dbNombre)
            . ' < ' . escapeshellarg($rutaSqlEsquema) . ' 2>&1';
        exec($comando, $salidaComando, $codigoSalida);
        unlink($archivoCredenciales);

        if ($codigoSalida !== 0) {
            throw new Exception('No se pudo importar el esquema de la base de datos: ' . implode(' ', $salidaComando));
        }

        $pdoNuevaBase = new PDO("mysql:host=localhost;dbname=$dbNombre;charset=utf8mb4", $dbUsuario, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // 4. Poner el nombre real de la institución en su propia base.
        $pdoNuevaBase->prepare('UPDATE configuracion_institucion SET nombre_institucion = ?')->execute([$nombreInstitucion]);

        // 5. Copiar la plantilla de archivos a la carpeta nueva.
        copiarCarpeta(__DIR__ . '/plantilla_instalacion', $rutaDestino);

        // 6. Generar el config/config.php real de esta institución.
        $appSecretInstitucion = bin2hex(random_bytes(24));
        file_put_contents("$rutaDestino/config/config.php", construirConfigInstitucion($dbNombre, $dbUsuario, $dbPassword, $appSecretInstitucion));

        // 7. Registrar la institución en la base del panel maestro.
        $pdo->prepare('INSERT INTO instituciones (nombre, carpeta, db_nombre, db_usuario) VALUES (?, ?, ?, ?)')
            ->execute([$nombreInstitucion, $slug, $dbNombre, $dbUsuario]);
        $institucionId = $pdo->lastInsertId();

        // 8. Si se completaron los datos del contrato, guardarlos.
        $contrato = $input['contrato'] ?? null;
        if (is_array($contrato) && !empty($contrato['razon_social_cliente']) && !empty($contrato['cuit_dni_cliente'])) {
            $c = normalizarDatosContrato($contrato);

            $pdo->prepare('
                INSERT INTO contratos
                (institucion_id, razon_social_cliente, cuit_dni_cliente, plazo_tipo, plazo_cantidad, modalidad_pago,
                 precio_monto, precio_moneda, ram_gb, disco_gb, backup_horario, ubicacion_servidor,
                 prestador_titular_nombre, prestador_titular_cuil, prestador_apoderado_nombre, prestador_apoderado_cuil, prestador_marca,
                 tolerancia_mora_meses, plazo_entrega_datos_dias, preaviso_rescision_dias, fecha_contrato)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
            ')->execute([
                $institucionId,
                $c['razon_social_cliente'],
                $c['cuit_dni_cliente'],
                $c['plazo_tipo'],
                $c['plazo_cantidad'],
                $c['modalidad_pago'],
                $c['precio_monto'],
                $c['precio_moneda'],
                $c['ram_gb'],
                $c['disco_gb'],
                $c['backup_horario'],
                $c['ubicacion_servidor'],
                $c['prestador_titular_nombre'],
                $c['prestador_titular_cuil'],
                $c['prestador_apoderado_nombre'],
                $c['prestador_apoderado_cuil'],
                $c['prestador_marca'],
                $c['tolerancia_mora_meses'],
                $c['plazo_entrega_datos_dias'],
                $c['preaviso_rescision_dias'],
            ]);

            sincronizarContratoCliente($pdo, $institucionId);
        }

        ob_clean();

        echo json_encode([
            'ok' => true,
            'institucion_id' => $institucionId,
            'carpeta' => $slug,
            'url' => URL_BASE . "/$slug/",
        ]);
    } catch (Exception $e) {
        // Si algo falló a mitad de camino, deshacemos lo que se
        // haya llegado a crear, para no dejar residuos sueltos.
        if ($usuarioCreado) { try { $pdoAdmin->exec("DROP USER IF EXISTS '$dbUsuario'@'localhost'"); } catch (Exception $e2) {} }
        if ($baseCreada) { try { $pdoAdmin->exec("DROP DATABASE IF EXISTS `$dbNombre`"); } catch (Exception $e2) {} }
        if (is_dir($rutaDestino)) { exec('rm -rf ' . escapeshellarg($rutaDestino)); }
        http_response_code(500);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No se pudo crear la institución: ' . $e->getMessage()]);
    }
    exit;
}

// ------------------------------------------------------------
// OBTENER DATOS DE UN CONTRATO (GET ?accion=obtener_contrato&id=)
// "id" es el id de la INSTITUCIÓN, no del contrato. Se usa para
// precargar el formulario de edición desde el panel.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'obtener_contrato') {
    $institucionId = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare('SELECT * FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$institucionId]);
    $contrato = $stmt->fetch();

    if (!$contrato) {
        ob_clean();
        echo json_encode(['ok' => true, 'datos' => null]);
        exit;
    }
    ob_clean();
    echo json_encode(['ok' => true, 'datos' => $contrato]);
    exit;
}

// ------------------------------------------------------------
// CREAR O EDITAR LOS DATOS DE UN CONTRATO YA EXISTENTE
// (POST ?accion=editar_contrato)
// Sirve tanto para instituciones que todavía no tienen contrato
// (lo crea) como para actualizar uno ya generado. El cliente
// manda el id de la INSTITUCIÓN, no el del contrato.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'editar_contrato') {
    $institucionId = $input['institucion_id'] ?? 0;
    $contrato = $input['contrato'] ?? null;

    if (!is_array($contrato) || empty($contrato['razon_social_cliente']) || empty($contrato['cuit_dni_cliente'])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Faltan datos obligatorios: razón social y CUIT/DNI del cliente.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $c = normalizarDatosContrato($contrato);

    $stmt = $pdo->prepare('SELECT id, fecha_contrato FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$institucionId]);
    $existente = $stmt->fetch();

    if ($existente) {
        // Ya tiene contrato: actualizamos esa misma fila, sin
        // tocar la fecha original de celebración del contrato.
        $pdo->prepare('
            UPDATE contratos SET
                razon_social_cliente = ?, cuit_dni_cliente = ?, plazo_tipo = ?, plazo_cantidad = ?, modalidad_pago = ?,
                precio_monto = ?, precio_moneda = ?, ram_gb = ?, disco_gb = ?, backup_horario = ?, ubicacion_servidor = ?,
                prestador_titular_nombre = ?, prestador_titular_cuil = ?, prestador_apoderado_nombre = ?, prestador_apoderado_cuil = ?, prestador_marca = ?,
                tolerancia_mora_meses = ?, plazo_entrega_datos_dias = ?, preaviso_rescision_dias = ?
            WHERE id = ?
        ')->execute([
            $c['razon_social_cliente'], $c['cuit_dni_cliente'], $c['plazo_tipo'], $c['plazo_cantidad'], $c['modalidad_pago'],
            $c['precio_monto'], $c['precio_moneda'], $c['ram_gb'], $c['disco_gb'], $c['backup_horario'], $c['ubicacion_servidor'],
            $c['prestador_titular_nombre'], $c['prestador_titular_cuil'], $c['prestador_apoderado_nombre'], $c['prestador_apoderado_cuil'], $c['prestador_marca'],
            $c['tolerancia_mora_meses'], $c['plazo_entrega_datos_dias'], $c['preaviso_rescision_dias'],
            $existente['id'],
        ]);
    } else {
        // No tenía contrato todavía: lo creamos con fecha de hoy.
        $pdo->prepare('
            INSERT INTO contratos
            (institucion_id, razon_social_cliente, cuit_dni_cliente, plazo_tipo, plazo_cantidad, modalidad_pago,
             precio_monto, precio_moneda, ram_gb, disco_gb, backup_horario, ubicacion_servidor,
             prestador_titular_nombre, prestador_titular_cuil, prestador_apoderado_nombre, prestador_apoderado_cuil, prestador_marca,
             tolerancia_mora_meses, plazo_entrega_datos_dias, preaviso_rescision_dias, fecha_contrato)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
        ')->execute([
            $institucionId,
            $c['razon_social_cliente'], $c['cuit_dni_cliente'], $c['plazo_tipo'], $c['plazo_cantidad'], $c['modalidad_pago'],
            $c['precio_monto'], $c['precio_moneda'], $c['ram_gb'], $c['disco_gb'], $c['backup_horario'], $c['ubicacion_servidor'],
            $c['prestador_titular_nombre'], $c['prestador_titular_cuil'], $c['prestador_apoderado_nombre'], $c['prestador_apoderado_cuil'], $c['prestador_marca'],
            $c['tolerancia_mora_meses'], $c['plazo_entrega_datos_dias'], $c['preaviso_rescision_dias'],
        ]);
    }

    sincronizarContratoCliente($pdo, $institucionId);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// FIRMAR COMO APODERADO (POST ?accion=firmar_apoderado)
// Guarda la firma dibujada por el Super Admin (en representación
// del apoderado de DEL AUSTRAL) sobre un contrato ya existente.
// Se vuelve a pedir en cada institución nueva, no se reutiliza.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'firmar_apoderado') {
    $institucionId = $input['institucion_id'] ?? 0;
    $firmaPng = trim($input['firma_png'] ?? '');

    if ($firmaPng === '' || !preg_match('/^data:image\/png;base64,/', $firmaPng)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'La firma no es válida. Volvé a dibujarla.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$institucionId]);
    $contrato = $stmt->fetch();
    if (!$contrato) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución todavía no tiene un contrato. Completá los datos del contrato primero.']);
        exit;
    }

    $pdo->prepare('UPDATE contratos SET firma_apoderado_png = ? WHERE id = ?')->execute([$firmaPng, $contrato['id']]);
    sincronizarContratoCliente($pdo, $institucionId);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// VERIFICAR / TRAER LA FIRMA DEL APODERADO DEL CLIENTE
// (GET ?accion=verificar_firma_cliente&id=)
// Se conecta puntualmente a la base de esa institución, y si el
// apoderado ya firmó el contrato ahí, copia esa firma a la
// base del panel maestro (caché) y regenera el contrato.html.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'verificar_firma_cliente') {
    $institucionId = $_GET['id'] ?? 0;

    $stmt = $pdo->prepare('SELECT carpeta FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $institucion = $stmt->fetch();
    if (!$institucion) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $stmtContrato = $pdo->prepare('SELECT id, firma_cliente_png FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmtContrato->execute([$institucionId]);
    $contrato = $stmtContrato->fetch();
    if (!$contrato) {
        ob_clean();
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'sin_contrato']);
        exit;
    }

    // Si ya lo teníamos en caché, no hace falta volver a conectar.
    if (!empty($contrato['firma_cliente_png'])) {
        ob_clean();
        echo json_encode(['ok' => true, 'firmado' => true, 'firma_png' => $contrato['firma_cliente_png']]);
        exit;
    }

    $pdoCliente = obtenerConexionInstitucionCliente($institucion['carpeta']);
    if (!$pdoCliente) {
        ob_clean();
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'sin_conexion']);
        exit;
    }

    try {
        $fila = $pdoCliente->query('SELECT firma_png FROM contrato_firma_cliente ORDER BY id ASC LIMIT 1')->fetch();
    } catch (PDOException $e) {
        // La tabla puede no existir todavía si la institución es
        // de antes de esta funcionalidad y no se migró.
        ob_clean();
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'tabla_no_disponible']);
        exit;
    }

    if (!$fila) {
        ob_clean();
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'no_firmado_aun']);
        exit;
    }

    $pdo->prepare('UPDATE contratos SET firma_cliente_png = ?, firma_cliente_sincronizada_en = NOW() WHERE id = ?')
        ->execute([$fila['firma_png'], $contrato['id']]);
    sincronizarContratoCliente($pdo, $institucionId);

    ob_clean();

    echo json_encode(['ok' => true, 'firmado' => true, 'firma_png' => $fila['firma_png']]);
    exit;
}

// ------------------------------------------------------------
// SUSPENDER / REACTIVAR INSTITUCIÓN (no borra nada, solo
// renombra el config.php para que el sitio deje de conectar)
// ------------------------------------------------------------
/**
 * Activa o desactiva la suspensión por falta de pago de una
 * institución, escribiendo (o borrando) el archivo de bloqueo en
 * su carpeta. A diferencia de la suspensión total ("suspendida"),
 * esta NUNCA toca config.php — el Apoderado siempre puede seguir
 * entrando para ver su deuda y pagar.
 */
function aplicarSuspensionPorPago($pdo, $institucionId, $carpeta, $activar, $cobroId = null) {
    $rutaSuspensionPago = RUTA_INSTALACIONES . '/' . $carpeta . '/suspendida_por_pago.json';

    if ($activar) {
        file_put_contents($rutaSuspensionPago, json_encode([
            'activo' => true,
            'cobro_id' => $cobroId,
            'activado_en' => date('Y-m-d H:i:s'),
        ]));
        $pdo->prepare("UPDATE instituciones SET estado = 'suspendida_por_pago', suspendida_por_cobro_id = ? WHERE id = ?")
            ->execute([$cobroId, $institucionId]);
    } else {
        if (file_exists($rutaSuspensionPago)) unlink($rutaSuspensionPago);
        $pdo->prepare("UPDATE instituciones SET estado = 'activa', suspendida_por_cobro_id = NULL WHERE id = ? AND estado = 'suspendida_por_pago'")
            ->execute([$institucionId]);
    }
}

if ($metodo === 'POST' && $accion === 'cambiar_estado_institucion') {
    $id = $input['id'] ?? 0;
    $nuevoEstado = $input['estado'] ?? '';
    $cobroId = $input['cobro_id'] ?? null;

    if (!in_array($nuevoEstado, ['activa', 'suspendida', 'suspendida_por_pago'])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT carpeta FROM instituciones WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $fila['carpeta'];
    $rutaConfig = "$rutaCarpeta/config/config.php";
    $rutaConfigSuspendido = "$rutaCarpeta/config/config.php.suspendido";

    // "suspendida" (bloqueo total, ni el Apoderado puede entrar):
    // se sigue haciendo renombrando config.php, como siempre.
    if ($nuevoEstado === 'suspendida' && file_exists($rutaConfig)) {
        rename($rutaConfig, $rutaConfigSuspendido);
    } elseif ($nuevoEstado !== 'suspendida' && file_exists($rutaConfigSuspendido)) {
        rename($rutaConfigSuspendido, $rutaConfig);
    }

    if ($nuevoEstado === 'suspendida_por_pago') {
        aplicarSuspensionPorPago($pdo, $id, $fila['carpeta'], true, $cobroId);
    } else {
        aplicarSuspensionPorPago($pdo, $id, $fila['carpeta'], false);
        $pdo->prepare('UPDATE instituciones SET estado = ? WHERE id = ?')->execute([$nuevoEstado, $id]);
    }

    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// BORRAR INSTITUCIÓN PARA SIEMPRE (POST ?accion=borrar_institucion)
// Irreversible: borra la carpeta de archivos, la base de datos,
// el usuario MySQL, y el registro. Exige que el Super Admin
// repita el nombre EXACTO de la carpeta como confirmación,
// para evitar un borrado accidental por un clic de más.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'borrar_institucion') {
    $id = $input['id'] ?? 0;
    $confirmacion = trim($input['confirmacion'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM instituciones WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    if ($confirmacion !== $fila['carpeta']) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'El texto de confirmación no coincide con el nombre de la carpeta. No se borró nada.']);
        exit;
    }

    $errores = [];

    // 1. Borrar la base de datos y el usuario MySQL.
    try {
        $pdoAdmin = obtenerConexionAdminMysql();
        $pdoAdmin->exec("DROP DATABASE IF EXISTS `{$fila['db_nombre']}`");
        $pdoAdmin->exec("DROP USER IF EXISTS '{$fila['db_usuario']}'@'localhost'");
        $pdoAdmin->exec('FLUSH PRIVILEGES');
    } catch (Exception $e) {
        $errores[] = 'Base de datos/usuario MySQL: ' . $e->getMessage();
    }

    // 2. Borrar la carpeta de archivos.
    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $fila['carpeta'];
    if (is_dir($rutaCarpeta)) {
        exec('rm -rf ' . escapeshellarg($rutaCarpeta), $salida, $codigoSalida);
        if ($codigoSalida !== 0) {
            $errores[] = 'No se pudo borrar la carpeta de archivos en el servidor.';
        }
    }

    // 3. Borrar el registro, incluso si algo de lo anterior falló
    // parcialmente — no tiene sentido dejarlo "fantasma" en la
    // lista si el Super Admin ya confirmó que quiere borrarlo.
    $pdo->prepare('DELETE FROM instituciones WHERE id = ?')->execute([$id]);

    if ($errores) {
        ob_clean();
        echo json_encode(['ok' => true, 'advertencia' => 'Se borró, pero con algunos problemas: ' . implode(' | ', $errores)]);
    } else {
        ob_clean();
        echo json_encode(['ok' => true]);
    }
    exit;
}

// ------------------------------------------------------------
// LISTAR REPORTES DE BUG DE TODAS LAS INSTITUCIONES
// (GET ?accion=listar_reportes_bug)
// Se conecta puntualmente a la base de CADA institución activa
// (igual mecanismo que verificar_firma_cliente) y junta todos
// los reportes en una sola lista, para que el Super Admin los
// vea todos juntos con filtro por institución en el frontend.
// Si una institución en particular no responde, simplemente se
// omite (no tira abajo el resto del listado).
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar_reportes_bug') {
    $instituciones = $pdo->query('SELECT id, nombre, carpeta FROM instituciones ORDER BY nombre ASC')->fetchAll();

    $todos = [];
    foreach ($instituciones as $inst) {
        $pdoCliente = obtenerConexionInstitucionCliente($inst['carpeta']);
        if (!$pdoCliente) continue;

        try {
            $reportes = $pdoCliente->query('SELECT * FROM reportes_bug ORDER BY creado_en DESC')->fetchAll();
        } catch (PDOException $e) {
            // Institución vieja sin la tabla todavía (falta migrar). Se omite.
            continue;
        }

        foreach ($reportes as $r) {
            $r['institucion_id'] = $inst['id'];
            $r['institucion_nombre'] = $inst['nombre'];
            $r['institucion_carpeta'] = $inst['carpeta'];
            $todos[] = $r;
        }
    }

    usort($todos, fn($a, $b) => strcmp($b['creado_en'], $a['creado_en']));

    ob_clean();

    echo json_encode(['ok' => true, 'datos' => $todos]);
    exit;
}

// ------------------------------------------------------------
// ACTUALIZAR ESTADO/RESPUESTA DE UN REPORTE DE BUG
// (POST ?accion=actualizar_reporte_bug)
// El reporte vive en la base de la institución, no en la del
// panel maestro, así que hay que conectarse puntualmente a ella
// para poder actualizarlo.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'actualizar_reporte_bug') {
    $institucionId = $input['institucion_id'] ?? 0;
    $reporteId = $input['reporte_id'] ?? 0;
    $estado = $input['estado'] ?? '';
    $respuesta = trim($input['respuesta_soporte'] ?? '');

    if (!in_array($estado, ['nuevo', 'visto', 'en_curso', 'resuelto', 'no_resuelto'])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT carpeta FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $institucion = $stmt->fetch();
    if (!$institucion) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $pdoCliente = obtenerConexionInstitucionCliente($institucion['carpeta']);
    if (!$pdoCliente) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No se pudo conectar con la base de esa institución en este momento.']);
        exit;
    }

    $pdoCliente->prepare('UPDATE reportes_bug SET estado = ?, respuesta_soporte = ? WHERE id = ?')
        ->execute([$estado, $respuesta ?: null, $reporteId]);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// BORRAR UN REPORTE DE BUG PUNTUAL
// (POST ?accion=borrar_reporte_bug)
// El Super Admin puede borrar cualquier reporte manualmente en
// cualquier momento, sin importar su estado. El borrado
// automático de los ya cerrados (resuelto/no_resuelto con más
// de 7 días) corre por separado, vía cron, en cada institución.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'borrar_reporte_bug') {
    $institucionId = $input['institucion_id'] ?? 0;
    $reporteId = $input['reporte_id'] ?? 0;

    $stmt = $pdo->prepare('SELECT carpeta FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $institucion = $stmt->fetch();
    if (!$institucion) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $pdoCliente = obtenerConexionInstitucionCliente($institucion['carpeta']);
    if (!$pdoCliente) {
        http_response_code(500);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No se pudo conectar con la base de esa institución en este momento.']);
        exit;
    }

    $pdoCliente->prepare('DELETE FROM reportes_bug WHERE id = ?')->execute([$reporteId]);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

/**
 * Consulta el certificado SSL del dominio raíz (URL_BASE) y
 * devuelve cuántos días faltan para que venza. Como todas las
 * instituciones viven bajo el mismo dominio (subcarpetas), el
 * certificado es el mismo para todas — se consulta una sola vez
 * y no por institución. Devuelve null si no se pudo determinar
 * (por ejemplo, si el dominio no responde en este momento).
 */
function diasRestantesCertificadoSSL() {
    static $cache = null;
    static $consultado = false;
    if ($consultado) return $cache;
    $consultado = true;

    $host = parse_url(URL_BASE, PHP_URL_HOST);
    if (!$host) return null;

    $contexto = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
    $cliente = @stream_socket_client("ssl://$host:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $contexto);
    if (!$cliente) return null;

    $parametros = stream_context_get_params($cliente);
    if (empty($parametros['options']['ssl']['peer_certificate'])) return null;

    $certificado = openssl_x509_parse($parametros['options']['ssl']['peer_certificate']);
    fclose($cliente);
    if (!$certificado || empty($certificado['validTo_time_t'])) return null;

    $cache = (int) floor(($certificado['validTo_time_t'] - time()) / 86400);
    return $cache;
}

/**
 * Junta las señales de salud de UNA institución, conectándose
 * puntualmente a su base (igual mecanismo que las firmas y los
 * reportes de bug). Devuelve conectado=false si no se pudo
 * conectar, en vez de tirar abajo el chequeo de las demás.
 */
function obtenerSaludInstitucion($pdo, $carpeta) {
    $salud = [];

    // Versión del sistema: se lee directo del filesystem, igual
    // que hace el propio cliente (compara los archivos reales
    // contra version.json), sin necesidad de conectarse a su base.
    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $carpeta;
    $rutaVersion = $rutaCarpeta . '/version.json';
    if (file_exists($rutaVersion)) {
        $referencia = json_decode(file_get_contents($rutaVersion), true);
        $hayDesactualizados = false;
        if (!empty($referencia['archivos_criticos'])) {
            foreach ($referencia['archivos_criticos'] as $rutaRelativa => $hashEsperado) {
                $rutaAbsoluta = $rutaCarpeta . '/' . $rutaRelativa;
                if (!file_exists($rutaAbsoluta) || md5_file($rutaAbsoluta) !== $hashEsperado) {
                    $hayDesactualizados = true;
                    break;
                }
            }
        }
        $salud['version_actual'] = $referencia['version'] ?? null;
        $salud['version_desactualizada'] = $hayDesactualizados;
    } else {
        $salud['version_actual'] = null;
        $salud['version_desactualizada'] = null;
    }

    $pdoCliente = obtenerConexionInstitucionCliente($carpeta);
    if (!$pdoCliente) {
        $salud['conectado'] = false;
        return $salud;
    }
    $salud['conectado'] = true;

    try {
        $filas = $pdoCliente->query('SELECT clave, valor FROM salud_sistema')->fetchAll();
        $mapa = [];
        foreach ($filas as $f) { $mapa[$f['clave']] = $f['valor']; }
        $salud['cron_constancias_ultima_corrida'] = $mapa['cron_constancias_ultima_corrida'] ?? null;
        $salud['cron_reportes_bug_ultima_corrida'] = $mapa['cron_reportes_bug_ultima_corrida'] ?? null;
        $salud['ultima_actividad'] = $mapa['ultima_actividad'] ?? null;
    } catch (PDOException $e) {
        // Tabla no existe todavía (institución sin migrar).
        $salud['cron_constancias_ultima_corrida'] = null;
        $salud['cron_reportes_bug_ultima_corrida'] = null;
        $salud['ultima_actividad'] = null;
    }

    try {
        $total = $pdoCliente->query('SELECT COUNT(*) AS t FROM errores_app')->fetch()['t'];
        $ultimoError = $pdoCliente->query('SELECT mensaje, creado_en FROM errores_app ORDER BY id DESC LIMIT 1')->fetch();
        $recientes = $pdoCliente->query("SELECT COUNT(*) AS t FROM errores_app WHERE creado_en > (NOW() - INTERVAL 24 HOUR)")->fetch()['t'];
        $salud['errores_total'] = (int) $total;
        $salud['errores_recientes_24h'] = (int) $recientes;
        $salud['ultimo_error_mensaje'] = $ultimoError['mensaje'] ?? null;
        $salud['ultimo_error_fecha'] = $ultimoError['creado_en'] ?? null;
    } catch (PDOException $e) {
        $salud['errores_total'] = 0;
        $salud['errores_recientes_24h'] = 0;
        $salud['ultimo_error_mensaje'] = null;
        $salud['ultimo_error_fecha'] = null;
    }

    return $salud;
}

// ------------------------------------------------------------
// SALUD DEL SISTEMA — TODAS LAS INSTITUCIONES
// (GET ?accion=salud_sistema)
// Junta, por institución: última corrida de cada cron, última
// actividad real, y errores recientes de la app. Además, los
// días restantes del certificado SSL del dominio (uno solo para
// todas, ya que comparten el mismo dominio raíz).
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'salud_sistema') {
    $instituciones = $pdo->query('SELECT id, nombre, carpeta FROM instituciones ORDER BY nombre ASC')->fetchAll();

    $resultado = [];
    foreach ($instituciones as $inst) {
        $salud = obtenerSaludInstitucion($pdo, $inst['carpeta']);
        $resultado[] = array_merge(
            ['institucion_id' => $inst['id'], 'institucion_nombre' => $inst['nombre']],
            $salud
        );
    }

    ob_clean();

    echo json_encode([
        'ok' => true,
        'dias_ssl' => diasRestantesCertificadoSSL(),
        'instituciones' => $resultado,
    ]);
    exit;
}

// ------------------------------------------------------------
// MODO MANTENIMIENTO GLOBAL
// ------------------------------------------------------------
// Se controla con un único archivo compartido en la raíz de
// RUTA_INSTALACIONES (fuera de la carpeta de cualquier
// institución en particular), que todas las instalaciones
// cliente pueden leer directamente del filesystem sin conectarse
// a ninguna base de datos ajena. El Apoderado sigue pudiendo
// entrar mientras está activo; el resto de los roles queda
// bloqueado con un mensaje claro.
// ------------------------------------------------------------
define('RUTA_MANTENIMIENTO', RUTA_INSTALACIONES . '/mantenimiento.json');

if ($metodo === 'GET' && $accion === 'obtener_mantenimiento') {
    $activo = file_exists(RUTA_MANTENIMIENTO);
    $desde = null;
    if ($activo) {
        $datos = json_decode(file_get_contents(RUTA_MANTENIMIENTO), true);
        $desde = $datos['activado_en'] ?? null;
    }
    ob_clean();
    echo json_encode(['ok' => true, 'activo' => $activo, 'activado_en' => $desde]);
    exit;
}

if ($metodo === 'POST' && $accion === 'activar_mantenimiento') {
    file_put_contents(RUTA_MANTENIMIENTO, json_encode([
        'activo' => true,
        'activado_en' => date('Y-m-d H:i:s'),
    ]));
    ob_clean();
    echo json_encode(['ok' => true, 'activo' => true]);
    exit;
}

if ($metodo === 'POST' && $accion === 'desactivar_mantenimiento') {
    if (file_exists(RUTA_MANTENIMIENTO)) {
        unlink(RUTA_MANTENIMIENTO);
    }
    ob_clean();
    echo json_encode(['ok' => true, 'activo' => false]);
    exit;
}

// ============================================================
// SISTEMA DE COBRANZA
// ============================================================

/**
 * Devuelve los datos de cuenta bancaria a mostrarle al Apoderado
 * para un cobro: los propios de la institución si los tiene
 * configurados, o los de CUENTA_DEFAULT si no.
 */
function obtenerCuentaParaInstitucion($institucion) {
    $default = CUENTA_DEFAULT;
    return [
        'titular' => $institucion['cuenta_titular'] ?: $default['titular'],
        'banco' => $institucion['cuenta_banco'] ?: $default['banco'],
        'cuil' => $institucion['cuenta_cuil'] ?: $default['cuil'],
        'numero' => $institucion['cuenta_numero'] ?: $default['numero'],
        'alias' => $institucion['cuenta_alias'] ?: $default['alias'],
    ];
}

/**
 * Sincroniza el estado de cobranza vigente de una institución a
 * un archivo estado_cobro.json en su propia carpeta, para que su
 * sistema (el Apoderado) lo pueda mostrar sin que el cliente
 * tenga que consultar nunca al panel maestro directamente — el
 * flujo de datos siempre va panel maestro → cliente.
 */
function sincronizarEstadoCobroCliente($pdo, $institucionId) {
    $stmt = $pdo->prepare('SELECT * FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $institucion = $stmt->fetch();
    if (!$institucion) return;

    $rutaCarpeta = RUTA_INSTALACIONES . '/' . $institucion['carpeta'];
    if (!is_dir($rutaCarpeta)) return;

    // Los cobros anulados no se muestran como "el cobro vigente"
    // — se excluyen de esta consulta, aunque siguen existiendo en
    // el historial del panel maestro.
    $stmt = $pdo->prepare("SELECT * FROM cobros WHERE institucion_id = ? AND estado != 'anulado' ORDER BY vencimiento DESC, id DESC LIMIT 1");
    $stmt->execute([$institucionId]);
    $ultimoCobro = $stmt->fetch();

    // Si el saldo a favor se aplicó a este cobro puntual, lo
    // avisamos explícitamente para que el Apoderado lo vea, aun
    // si ahora mismo el saldo restante es 0.
    $montoAplicadoAEsteCobro = 0;
    if ($ultimoCobro) {
        $stmt = $pdo->prepare("SELECT SUM(monto) AS total FROM movimientos_saldo_favor WHERE cobro_id = ? AND tipo = 'aplicado_a_cobro'");
        $stmt->execute([$ultimoCobro['id']]);
        $montoAplicadoAEsteCobro = (float) ($stmt->fetch()['total'] ?? 0);
    }

    $recargoVigente = $ultimoCobro
        ? calcularRecargoMora((float) $ultimoCobro['monto'], $ultimoCobro['vencimiento'], $ultimoCobro['recargo_congelado_monto'])
        : 0;

    $datos = [
        'saldo_favor' => (float) $institucion['saldo_favor'],
        'cuenta' => obtenerCuentaParaInstitucion($institucion),
        'cobro' => $ultimoCobro ? [
            'id' => $ultimoCobro['id'],
            'monto_lista' => (float) $ultimoCobro['monto_lista'],
            'descuento_pct' => (float) $ultimoCobro['descuento_pct'],
            'monto' => (float) $ultimoCobro['monto'],
            'recargo_mora_vigente' => $recargoVigente,
            'monto_con_recargo' => (float) $ultimoCobro['monto'] + ($ultimoCobro['recargo_congelado_monto'] === null ? $recargoVigente : 0),
            'moneda' => $ultimoCobro['moneda'],
            'vencimiento' => $ultimoCobro['vencimiento'],
            'estado' => $ultimoCobro['estado'],
            'comprobante_nombre_original' => $ultimoCobro['comprobante_nombre_original'],
            'notas_super_admin' => $ultimoCobro['notas_super_admin'],
            'saldo_favor_aplicado' => $montoAplicadoAEsteCobro,
        ] : null,
        'sincronizado_en' => date('Y-m-d H:i:s'),
    ];

    file_put_contents("$rutaCarpeta/estado_cobro.json", json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ------------------------------------------------------------
// OBTENER/EDITAR CUENTA BANCARIA DE UNA INSTITUCIÓN
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'obtener_cuenta_institucion') {
    $institucionId = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare('SELECT cuenta_titular, cuenta_banco, cuenta_cuil, cuenta_numero, cuenta_alias, saldo_favor FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }
    ob_clean();
    echo json_encode(['ok' => true, 'datos' => $fila, 'cuenta_default' => CUENTA_DEFAULT]);
    exit;
}

if ($metodo === 'POST' && $accion === 'editar_cuenta_institucion') {
    $institucionId = $input['institucion_id'] ?? 0;
    $pdo->prepare('
        UPDATE instituciones SET cuenta_titular = ?, cuenta_banco = ?, cuenta_cuil = ?, cuenta_numero = ?, cuenta_alias = ?
        WHERE id = ?
    ')->execute([
        trim($input['cuenta_titular'] ?? '') ?: null,
        trim($input['cuenta_banco'] ?? '') ?: null,
        trim($input['cuenta_cuil'] ?? '') ?: null,
        trim($input['cuenta_numero'] ?? '') ?: null,
        trim($input['cuenta_alias'] ?? '') ?: null,
        $institucionId,
    ]);
    sincronizarEstadoCobroCliente($pdo, $institucionId);
    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

// La constante RECARGO_MORA_DIARIO_PCT y la función
// calcularRecargoMora() viven en plantilla_factura.php (incluido
// arriba), para poder reutilizarlas también desde factura.php.

/**
 * Congela el recargo por mora vigente de un cobro (lo calcula una
 * última vez y lo guarda), y suma ese valor al monto final. Se
 * llama al aprobar, rechazar, marcar sin acreditar, o anular —
 * momentos en que el cobro deja de estar "corriendo" y su recargo
 * no debe seguir aumentando día a día.
 */
function congelarRecargoSiCorresponde($pdo, $cobro) {
    if ($cobro['recargo_congelado_en'] !== null) return; // ya estaba congelado

    $recargo = calcularRecargoMora((float) $cobro['monto'], $cobro['vencimiento']);
    if ($recargo > 0) {
        $pdo->prepare('
            UPDATE cobros SET recargo_congelado_en = CURDATE(), recargo_congelado_monto = ?, monto = monto + ?
            WHERE id = ?
        ')->execute([$recargo, $recargo, $cobro['id']]);
    } else {
        $pdo->prepare('UPDATE cobros SET recargo_congelado_en = CURDATE(), recargo_congelado_monto = 0 WHERE id = ?')
            ->execute([$cobro['id']]);
    }
}

// ------------------------------------------------------------
// GENERAR UN COBRO NUEVO
// (POST ?accion=generar_cobro)
// Si la institución tiene saldo a favor, se aplica como crédito
// acumulado: se descuenta del monto del cobro nuevo (hasta
// dejarlo en 0 si el saldo alcanza), sin importar si cubre el
// período completo o no. El descuento comercial (si se indica)
// se aplica sobre el monto de lista, ANTES del saldo a favor y
// antes de cualquier recargo por mora futuro.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'generar_cobro') {
    $institucionId = $input['institucion_id'] ?? 0;
    $montoLista = (float) ($input['monto_lista'] ?? 0);
    $descuentoPct = (float) ($input['descuento_pct'] ?? 0);
    $moneda = trim($input['moneda'] ?? 'ARS') ?: 'ARS';
    $vencimiento = trim($input['vencimiento'] ?? '');
    $periodoDesde = trim($input['periodo_desde'] ?? '') ?: null;
    $periodoHasta = trim($input['periodo_hasta'] ?? '') ?: null;

    if ($montoLista <= 0 || !$vencimiento) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Completá el monto y la fecha de vencimiento.']);
        exit;
    }
    if (!in_array($descuentoPct, [0, 5, 10, 15, 20, 30])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'El descuento tiene que ser 0, 5, 10, 15, 20 o 30%.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    $institucion = $stmt->fetch();
    if (!$institucion) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $montoConDescuento = $montoLista * (1 - $descuentoPct / 100);
    $montoFinal = $montoConDescuento;
    $saldoDisponible = (float) $institucion['saldo_favor'];
    $saldoAplicado = 0;

    if ($saldoDisponible > 0) {
        $saldoAplicado = min($saldoDisponible, $montoFinal);
        $montoFinal -= $saldoAplicado;
    }

    $pdo->prepare('
        INSERT INTO cobros (institucion_id, monto_lista, descuento_pct, monto, moneda, periodo_desde, periodo_hasta, vencimiento, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\')
    ')->execute([$institucionId, $montoLista, $descuentoPct, $montoFinal, $moneda, $periodoDesde, $periodoHasta, $vencimiento]);
    $cobroId = $pdo->lastInsertId();

    if ($saldoAplicado > 0) {
        $pdo->prepare('UPDATE instituciones SET saldo_favor = saldo_favor - ? WHERE id = ?')->execute([$saldoAplicado, $institucionId]);
        $pdo->prepare('
            INSERT INTO movimientos_saldo_favor (institucion_id, tipo, monto, cobro_id, nota)
            VALUES (?, \'aplicado_a_cobro\', ?, ?, \'Aplicado automáticamente al generar el cobro\')
        ')->execute([$institucionId, $saldoAplicado, $cobroId]);
    }

    sincronizarEstadoCobroCliente($pdo, $institucionId);
    sincronizarFacturaCliente($pdo, $cobroId);

    ob_clean();

    echo json_encode(['ok' => true, 'cobro_id' => $cobroId, 'saldo_aplicado' => $saldoAplicado, 'monto_final' => $montoFinal]);
    exit;
}

// ------------------------------------------------------------
// LISTAR COBROS DE TODAS LAS INSTITUCIONES
// (GET ?accion=listar_cobros)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar_cobros') {
    $filas = $pdo->query('
        SELECT c.*, i.nombre AS institucion_nombre, i.carpeta AS institucion_carpeta,
               i.estado AS institucion_estado, i.suspendida_por_cobro_id
        FROM cobros c
        INNER JOIN instituciones i ON i.id = c.institucion_id
        ORDER BY c.vencimiento DESC, c.id DESC
    ')->fetchAll();

    // Antes de devolver, refrescamos el estado de cada cobro que
    // sigue "pendiente" o "comprobante_subido" leyendo el archivo
    // que el propio cliente actualiza cuando sube un comprobante
    // (así el panel maestro se entera sin conectarse a su base).
    foreach ($filas as &$fila) {
        if (in_array($fila['estado'], ['pendiente', 'comprobante_subido'])) {
            $rutaComprobantes = RUTA_INSTALACIONES . '/' . $fila['institucion_carpeta'] . '/comprobantes_pago';
            $rutaEstadoLocal = RUTA_INSTALACIONES . '/' . $fila['institucion_carpeta'] . '/estado_cobro_subida.json';
            if (file_exists($rutaEstadoLocal)) {
                $subida = json_decode(file_get_contents($rutaEstadoLocal), true);
                if (($subida['cobro_id'] ?? null) == $fila['id'] && !empty($subida['nombre_archivo'])) {
                    $rutaArchivo = "$rutaComprobantes/{$subida['nombre_archivo']}";
                    if (file_exists($rutaArchivo) && $fila['estado'] !== 'comprobante_subido') {
                        $pdo->prepare('
                            UPDATE cobros SET estado = \'comprobante_subido\', comprobante_nombre_original = ?, comprobante_nombre_archivo = ?, comprobante_subido_en = ?
                            WHERE id = ?
                        ')->execute([$subida['nombre_original'] ?? $subida['nombre_archivo'], $subida['nombre_archivo'], $subida['subido_en'] ?? date('Y-m-d H:i:s'), $fila['id']]);
                        $fila['estado'] = 'comprobante_subido';
                        $fila['comprobante_nombre_original'] = $subida['nombre_original'] ?? $subida['nombre_archivo'];
                        $fila['comprobante_nombre_archivo'] = $subida['nombre_archivo'];
                    }
                }
            }
        }
    }

    ob_clean();

    echo json_encode(['ok' => true, 'datos' => array_map(function ($fila) {
        $fila['recargo_mora_vigente'] = calcularRecargoMora((float) $fila['monto'], $fila['vencimiento'], $fila['recargo_congelado_monto']);
        $fila['monto_con_recargo'] = (float) $fila['monto'] + $fila['recargo_mora_vigente'];
        return $fila;
    }, $filas)]);
    exit;
}

// ------------------------------------------------------------
// VER EL ARCHIVO DE COMPROBANTE DE UN COBRO
// (GET ?accion=ver_comprobante&id=)
// Sirve el archivo directo desde la carpeta de la institución.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'ver_comprobante') {
    $cobroId = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT c.comprobante_nombre_archivo, c.comprobante_nombre_original, i.carpeta
        FROM cobros c INNER JOIN instituciones i ON i.id = c.institucion_id
        WHERE c.id = ?
    ');
    $stmt->execute([$cobroId]);
    $fila = $stmt->fetch();
    if (!$fila || !$fila['comprobante_nombre_archivo']) {
        http_response_code(404);
        echo 'No hay comprobante para este cobro.';
        exit;
    }

    $rutaArchivo = RUTA_INSTALACIONES . '/' . $fila['carpeta'] . '/comprobantes_pago/' . $fila['comprobante_nombre_archivo'];
    if (!file_exists($rutaArchivo)) {
        http_response_code(404);
        echo 'El archivo del comprobante no se encuentra en el servidor.';
        exit;
    }

    $extension = strtolower(pathinfo($rutaArchivo, PATHINFO_EXTENSION));
    $mimes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    header('Content-Type: ' . ($mimes[$extension] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . basename($fila['comprobante_nombre_original']) . '"');
    readfile($rutaArchivo);
    exit;
}

// ------------------------------------------------------------
// CAMBIAR ESTADO DE UN COBRO (aprobar / sin acreditar / rechazar)
// (POST ?accion=resolver_cobro)
// Al aprobar, rechazar o marcar sin acreditar, el recargo por
// mora vigente en ese momento se congela: deja de crecer día a
// día, y queda fijo para la factura histórica.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'resolver_cobro') {
    $cobroId = $input['cobro_id'] ?? 0;
    $estado = $input['estado'] ?? '';
    $notas = trim($input['notas_super_admin'] ?? '');

    if (!in_array($estado, ['aprobado', 'sin_acreditar', 'rechazado', 'pendiente'])) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM cobros WHERE id = ?');
    $stmt->execute([$cobroId]);
    $cobro = $stmt->fetch();
    if (!$cobro) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ese cobro no existe.']);
        exit;
    }

    if (in_array($estado, ['aprobado', 'rechazado', 'sin_acreditar'])) {
        congelarRecargoSiCorresponde($pdo, $cobro);
    }

    $pdo->prepare('UPDATE cobros SET estado = ?, notas_super_admin = ?, revisado_en = NOW() WHERE id = ?')
        ->execute([$estado, $notas ?: null, $cobroId]);

    // Reactivación automática: si la institución estaba suspendida
    // por falta de pago A CAUSA de este mismo cobro, y ahora se
    // aprobó, se reactiva sola — sin que el Super Admin tenga que
    // acordarse de hacerlo a mano.
    if ($estado === 'aprobado') {
        $stmtInst = $pdo->prepare("SELECT carpeta, suspendida_por_cobro_id FROM instituciones WHERE id = ? AND estado = 'suspendida_por_pago'");
        $stmtInst->execute([$cobro['institucion_id']]);
        $instSuspendida = $stmtInst->fetch();
        if ($instSuspendida && (int) $instSuspendida['suspendida_por_cobro_id'] === (int) $cobroId) {
            aplicarSuspensionPorPago($pdo, $cobro['institucion_id'], $instSuspendida['carpeta'], false);
        }
    }

    sincronizarEstadoCobroCliente($pdo, $cobro['institucion_id']);
    sincronizarFacturaCliente($pdo, $cobroId);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// ANULAR UN COBRO (por dato erróneo, ej. monto o vencimiento mal
// puesto). Queda marcado como "anulado" en el historial — no se
// borra — pero deja de contar como deuda pendiente: si tenía
// saldo a favor aplicado, se le devuelve a la institución.
// (POST ?accion=anular_cobro)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'anular_cobro') {
    $cobroId = $input['cobro_id'] ?? 0;
    $motivo = trim($input['motivo'] ?? '');

    if (!$motivo) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Indicá el motivo de la anulación.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM cobros WHERE id = ?');
    $stmt->execute([$cobroId]);
    $cobro = $stmt->fetch();
    if (!$cobro) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ese cobro no existe.']);
        exit;
    }
    if ($cobro['estado'] === 'anulado') {
        http_response_code(409);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Ese cobro ya estaba anulado.']);
        exit;
    }
    if ($cobro['estado'] === 'aprobado') {
        http_response_code(409);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'No se puede anular un cobro ya aprobado. Si el pago está mal registrado, contactá soporte para revertirlo manualmente.']);
        exit;
    }

    // Si este cobro tenía saldo a favor aplicado, se lo devolvemos
    // a la institución — anular no debería hacerle perder crédito.
    $stmt = $pdo->prepare("SELECT SUM(monto) AS total FROM movimientos_saldo_favor WHERE cobro_id = ? AND tipo = 'aplicado_a_cobro'");
    $stmt->execute([$cobroId]);
    $saldoAplicadoOriginal = (float) ($stmt->fetch()['total'] ?? 0);

    if ($saldoAplicadoOriginal > 0) {
        $pdo->prepare('UPDATE instituciones SET saldo_favor = saldo_favor + ? WHERE id = ?')
            ->execute([$saldoAplicadoOriginal, $cobro['institucion_id']]);
        $pdo->prepare("
            INSERT INTO movimientos_saldo_favor (institucion_id, tipo, monto, cobro_id, nota)
            VALUES (?, 'carga', ?, ?, 'Devuelto por anulación del cobro')
        ")->execute([$cobro['institucion_id'], $saldoAplicadoOriginal, $cobroId]);
    }

    $pdo->prepare("UPDATE cobros SET estado = 'anulado', motivo_anulacion = ?, revisado_en = NOW() WHERE id = ?")
        ->execute([$motivo, $cobroId]);

    // Igual que al aprobar: si la institución estaba suspendida
    // por falta de pago a causa de este mismo cobro, y ahora se
    // anula (el cobro ya no existe como deuda real), se reactiva.
    $stmtInst = $pdo->prepare("SELECT carpeta, suspendida_por_cobro_id FROM instituciones WHERE id = ? AND estado = 'suspendida_por_pago'");
    $stmtInst->execute([$cobro['institucion_id']]);
    $instSuspendida = $stmtInst->fetch();
    if ($instSuspendida && (int) $instSuspendida['suspendida_por_cobro_id'] === (int) $cobroId) {
        aplicarSuspensionPorPago($pdo, $cobro['institucion_id'], $instSuspendida['carpeta'], false);
    }

    sincronizarEstadoCobroCliente($pdo, $cobro['institucion_id']);
    sincronizarFacturaCliente($pdo, $cobroId);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// CARGAR SALDO A FAVOR
// (POST ?accion=cargar_saldo_favor)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'cargar_saldo_favor') {
    $institucionId = $input['institucion_id'] ?? 0;
    $monto = (float) ($input['monto'] ?? 0);
    $nota = trim($input['nota'] ?? '');

    if ($monto <= 0) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'El monto tiene que ser mayor a cero.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $pdo->prepare('UPDATE instituciones SET saldo_favor = saldo_favor + ? WHERE id = ?')->execute([$monto, $institucionId]);
    $pdo->prepare('
        INSERT INTO movimientos_saldo_favor (institucion_id, tipo, monto, nota)
        VALUES (?, \'carga\', ?, ?)
    ')->execute([$institucionId, $monto, $nota ?: null]);

    sincronizarEstadoCobroCliente($pdo, $institucionId);

    ob_clean();

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
ob_clean();
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

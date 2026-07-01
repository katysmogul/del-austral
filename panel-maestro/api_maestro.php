<?php
require_once __DIR__ . '/config/config_maestro.php';
require_once __DIR__ . '/plantilla_contrato.php';
header('Content-Type: application/json; charset=utf-8');

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

// ------------------------------------------------------------
// LOGIN DEL SUPER ADMIN (POST ?accion=login)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'login') {
    $clave = trim($input['clave'] ?? '');

    if (!isset($_SESSION['intentos_maestro'])) $_SESSION['intentos_maestro'] = 0;
    if (!isset($_SESSION['bloqueo_maestro_hasta'])) $_SESSION['bloqueo_maestro_hasta'] = 0;
    if (time() < $_SESSION['bloqueo_maestro_hasta']) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Demasiados intentos. Esperá 30 segundos.']);
        exit;
    }

    $stmt = $pdo->query('SELECT clave_hash FROM super_admin LIMIT 1');
    $fila = $stmt->fetch();

    if (!$fila) {
        http_response_code(400);
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
        echo json_encode(['ok' => false, 'error' => 'Clave incorrecta.']);
        exit;
    }

    $_SESSION['intentos_maestro'] = 0;
    $_SESSION['super_admin_autenticado'] = true;
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
        echo json_encode(['ok' => false, 'error' => 'Ya existe una clave configurada.']);
        exit;
    }
    $clave = trim($input['clave'] ?? '');
    if (mb_strlen($clave) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La clave debe tener al menos 6 caracteres.']);
        exit;
    }
    $hash = password_hash($clave . APP_SECRET_MAESTRO, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO super_admin (clave_hash) VALUES (?)')->execute([$hash]);
    $_SESSION['super_admin_autenticado'] = true;
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// ESTADO INICIAL (¿ya hay clave configurada?)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'estado') {
    $existe = $pdo->query('SELECT 1 FROM super_admin LIMIT 1')->fetch();
    echo json_encode(['ok' => true, 'clave_configurada' => (bool) $existe]);
    exit;
}

// ------------------------------------------------------------
// CERRAR SESIÓN
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'cerrar_sesion') {
    $_SESSION = [];
    session_destroy();
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
        SELECT i.id, i.nombre, i.carpeta, i.estado, i.creado_en,
               (SELECT COUNT(*) FROM contratos c WHERE c.institucion_id = i.id) > 0 AS tiene_contrato,
               (SELECT firma_apoderado_png IS NOT NULL FROM contratos c WHERE c.institucion_id = i.id ORDER BY c.id DESC LIMIT 1) AS firmado_apoderado,
               (SELECT firma_cliente_png IS NOT NULL FROM contratos c WHERE c.institucion_id = i.id ORDER BY c.id DESC LIMIT 1) AS firmado_cliente
        FROM instituciones i
        ORDER BY i.creado_en DESC
    ');
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
        echo json_encode(['ok' => false, 'error' => 'Este servidor tiene deshabilitada la función exec() de PHP, necesaria para importar la base de datos de forma segura. Contactá a quien administre el servidor.']);
        exit;
    }

    $nombreInstitucion = trim($input['nombre'] ?? '');
    $carpetaPedida = trim($input['carpeta'] ?? '');

    if ($nombreInstitucion === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre de la institución.']);
        exit;
    }

    $slug = $carpetaPedida !== '' ? generarSlug($carpetaPedida) : generarSlug($nombreInstitucion);
    if ($slug === '' || mb_strlen($slug) < 2) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El nombre de carpeta no es válido. Usá solo letras, números y guiones.']);
        exit;
    }
    $reservadas = ['panel-maestro', 'api', 'assets', 'config', 'adjuntos'];
    if (in_array($slug, $reservadas)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ese nombre de carpeta está reservado, elegí otro.']);
        exit;
    }

    $rutaDestino = RUTA_INSTALACIONES . "/$slug";
    if (is_dir($rutaDestino)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Ya existe una carpeta llamada \"$slug\" en el servidor."]);
        exit;
    }

    $stmtCheck = $pdo->prepare('SELECT 1 FROM instituciones WHERE carpeta = ?');
    $stmtCheck->execute([$slug]);
    if ($stmtCheck->fetch()) {
        http_response_code(409);
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
        echo json_encode(['ok' => true, 'datos' => null]);
        exit;
    }
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
        echo json_encode(['ok' => false, 'error' => 'Faltan datos obligatorios: razón social y CUIT/DNI del cliente.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM instituciones WHERE id = ?');
    $stmt->execute([$institucionId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
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
        echo json_encode(['ok' => false, 'error' => 'La firma no es válida. Volvé a dibujarla.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$institucionId]);
    $contrato = $stmt->fetch();
    if (!$contrato) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Esa institución todavía no tiene un contrato. Completá los datos del contrato primero.']);
        exit;
    }

    $pdo->prepare('UPDATE contratos SET firma_apoderado_png = ? WHERE id = ?')->execute([$firmaPng, $contrato['id']]);
    sincronizarContratoCliente($pdo, $institucionId);

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
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $stmtContrato = $pdo->prepare('SELECT id, firma_cliente_png FROM contratos WHERE institucion_id = ? ORDER BY id DESC LIMIT 1');
    $stmtContrato->execute([$institucionId]);
    $contrato = $stmtContrato->fetch();
    if (!$contrato) {
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'sin_contrato']);
        exit;
    }

    // Si ya lo teníamos en caché, no hace falta volver a conectar.
    if (!empty($contrato['firma_cliente_png'])) {
        echo json_encode(['ok' => true, 'firmado' => true, 'firma_png' => $contrato['firma_cliente_png']]);
        exit;
    }

    $pdoCliente = obtenerConexionInstitucionCliente($institucion['carpeta']);
    if (!$pdoCliente) {
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'sin_conexion']);
        exit;
    }

    try {
        $fila = $pdoCliente->query('SELECT firma_png FROM contrato_firma_cliente ORDER BY id ASC LIMIT 1')->fetch();
    } catch (PDOException $e) {
        // La tabla puede no existir todavía si la institución es
        // de antes de esta funcionalidad y no se migró.
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'tabla_no_disponible']);
        exit;
    }

    if (!$fila) {
        echo json_encode(['ok' => true, 'firmado' => false, 'motivo' => 'no_firmado_aun']);
        exit;
    }

    $pdo->prepare('UPDATE contratos SET firma_cliente_png = ?, firma_cliente_sincronizada_en = NOW() WHERE id = ?')
        ->execute([$fila['firma_png'], $contrato['id']]);
    sincronizarContratoCliente($pdo, $institucionId);

    echo json_encode(['ok' => true, 'firmado' => true, 'firma_png' => $fila['firma_png']]);
    exit;
}

// ------------------------------------------------------------
// SUSPENDER / REACTIVAR INSTITUCIÓN (no borra nada, solo
// renombra el config.php para que el sitio deje de conectar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'cambiar_estado_institucion') {
    $id = $input['id'] ?? 0;
    $nuevoEstado = $input['estado'] ?? '';
    if (!in_array($nuevoEstado, ['activa', 'suspendida'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT carpeta FROM instituciones WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    $rutaConfig = RUTA_INSTALACIONES . '/' . $fila['carpeta'] . '/config/config.php';
    $rutaConfigSuspendido = RUTA_INSTALACIONES . '/' . $fila['carpeta'] . '/config/config.php.suspendido';

    if ($nuevoEstado === 'suspendida' && file_exists($rutaConfig)) {
        rename($rutaConfig, $rutaConfigSuspendido);
    } elseif ($nuevoEstado === 'activa' && file_exists($rutaConfigSuspendido)) {
        rename($rutaConfigSuspendido, $rutaConfig);
    }

    $pdo->prepare('UPDATE instituciones SET estado = ? WHERE id = ?')->execute([$nuevoEstado, $id]);
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
        echo json_encode(['ok' => false, 'error' => 'Esa institución no existe.']);
        exit;
    }

    if ($confirmacion !== $fila['carpeta']) {
        http_response_code(400);
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
        echo json_encode(['ok' => true, 'advertencia' => 'Se borró, pero con algunos problemas: ' . implode(' | ', $errores)]);
    } else {
        echo json_encode(['ok' => true]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

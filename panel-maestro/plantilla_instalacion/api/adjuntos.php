<?php
require_once __DIR__ . '/../config/config.php';
requiereSesion();
requiereProfesionalActivo();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$profesionalActivoId = idProfesionalActivo();

define('CARPETA_ADJUNTOS', __DIR__ . '/../adjuntos/');
define('TIPOS_PERMITIDOS', [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
]);
define('TAMANIO_MAXIMO', 15 * 1024 * 1024); // 15 MB

function pacienteEsDelProfesional($pdo, $pacienteId, $profesionalActivoId) {
    $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = ? AND profesional_id = ?');
    $stmt->execute([$pacienteId, $profesionalActivoId]);
    return (bool) $stmt->fetch();
}

/**
 * Convierte un valor de php.ini como "8M", "2G" o "512K" a bytes.
 * Si ya es un número plano, lo devuelve tal cual.
 */
function convertirAByte($valor) {
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '-1') return 0; // sin límite configurado
    $unidad = strtoupper(substr($valor, -1));
    $numero = (float) $valor;
    switch ($unidad) {
        case 'G': return (int) ($numero * 1024 * 1024 * 1024);
        case 'M': return (int) ($numero * 1024 * 1024);
        case 'K': return (int) ($numero * 1024);
        default: return (int) $valor;
    }
}

/**
 * Da un texto legible para humanos a partir de una cantidad de bytes.
 */
function formatearBytesLegible($bytes) {
    if ($bytes <= 0) return 'sin límite definido';
    if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

// ------------------------------------------------------------
// SUBIR ARCHIVO (POST ?accion=subir) — multipart/form-data
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'subir') {
    header('Content-Type: application/json; charset=utf-8');
    requiereRolProfesional();

    // Si el archivo (o el POST completo) superó los límites del
    // propio servidor (php.ini: upload_max_filesize / post_max_size),
    // PHP vacía $_FILES sin avisar el motivo real. Lo detectamos
    // comparando contra esos límites para poder dar un mensaje claro,
    // en vez de un error confuso de "no se recibió ningún archivo".
    if (empty($_FILES) && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $limitePost = convertirAByte(ini_get('post_max_size'));
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'El archivo es demasiado grande para este servidor (el límite actual es ' . formatearBytesLegible($limitePost) . '). Probá con un archivo más chico, o pedile a quien administre el hosting que aumente el límite de subida en PHP.',
        ]);
        exit;
    }

    if (empty($_FILES['archivo'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se recibió ningún archivo.']);
        exit;
    }
    $pacienteId = $_POST['paciente_id'] ?? 0;
    $sesionId = $_POST['sesion_id'] ?? null;
    $descripcion = trim($_POST['descripcion'] ?? '');

    if (!$pacienteId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el paciente al que pertenece el archivo.']);
        exit;
    }

    if (!pacienteEsDelProfesional($pdo, $pacienteId, $profesionalActivoId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }

    $archivo = $_FILES['archivo'];
    if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        $limiteUpload = convertirAByte(ini_get('upload_max_filesize'));
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'El archivo supera el límite de subida configurado en este servidor (' . formatearBytesLegible($limiteUpload) . '). Probá con un archivo más chico.',
        ]);
        exit;
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Error al subir el archivo. Intentá nuevamente.']);
        exit;
    }
    if ($archivo['size'] > TAMANIO_MAXIMO) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El archivo supera el tamaño máximo permitido (15 MB).']);
        exit;
    }

    if (!function_exists('finfo_open')) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'El servidor no tiene habilitada la extensión PHP "fileinfo", necesaria para verificar el tipo de archivo. Pedile a tu hosting que la active (suele estar en "Seleccionar versión de PHP" → extensiones).',
        ]);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo verificar el tipo de archivo en este servidor. Intentá nuevamente o avisá a soporte.']);
        exit;
    }
    $mimeReal = finfo_file($finfo, $archivo['tmp_name']);
    finfo_close($finfo);

    if ($mimeReal === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo leer el archivo subido. Probá nuevamente.']);
        exit;
    }

    if (!isset(TIPOS_PERMITIDOS[$mimeReal])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Tipo de archivo no permitido. Solo se aceptan PDF, JPG, PNG o WEBP.']);
        exit;
    }

    $extension = TIPOS_PERMITIDOS[$mimeReal];
    $nombreArchivo = bin2hex(random_bytes(16)) . '.' . $extension;
    $rutaDestino = CARPETA_ADJUNTOS . $nombreArchivo;

    if (!is_dir(CARPETA_ADJUNTOS)) {
        mkdir(CARPETA_ADJUNTOS, 0755, true);
    }

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO archivos_adjuntos (paciente_id, sesion_id, nombre_original, nombre_archivo, tipo_mime, tamanio_bytes, descripcion)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $pacienteId,
        $sesionId ?: null,
        $archivo['name'],
        $nombreArchivo,
        $mimeReal,
        $archivo['size'],
        $descripcion ?: null,
    ]);

    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId(), 'nombre_archivo' => $nombreArchivo]);
    exit;
}

// ------------------------------------------------------------
// LISTAR ADJUNTOS DE UN PACIENTE (GET ?accion=listar&paciente_id=X)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar') {
    header('Content-Type: application/json; charset=utf-8');
    requiereRolProfesional();
    $pacienteId = $_GET['paciente_id'] ?? 0;

    if (!pacienteEsDelProfesional($pdo, $pacienteId, $profesionalActivoId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM archivos_adjuntos WHERE paciente_id = ? ORDER BY subido_en DESC');
    $stmt->execute([$pacienteId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// DESCARGAR / VER ARCHIVO (GET ?accion=ver&id=X)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'ver') {
    requiereRolProfesional();
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT a.* FROM archivos_adjuntos a
        INNER JOIN pacientes p ON p.id = a.paciente_id
        WHERE a.id = ? AND p.profesional_id = ?
    ');
    $stmt->execute([$id, $profesionalActivoId]);
    $archivo = $stmt->fetch();

    if (!$archivo) {
        http_response_code(404);
        echo 'Archivo no encontrado.';
        exit;
    }

    $ruta = CARPETA_ADJUNTOS . $archivo['nombre_archivo'];
    if (!file_exists($ruta)) {
        http_response_code(404);
        echo 'El archivo ya no está disponible en el servidor.';
        exit;
    }

    header('Content-Type: ' . $archivo['tipo_mime']);
    header('Content-Disposition: inline; filename="' . basename($archivo['nombre_original']) . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}

// ------------------------------------------------------------
// ELIMINAR ADJUNTO (POST ?accion=eliminar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'eliminar') {
    header('Content-Type: application/json; charset=utf-8');
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? 0;

    $stmt = $pdo->prepare('
        SELECT a.* FROM archivos_adjuntos a
        INNER JOIN pacientes p ON p.id = a.paciente_id
        WHERE a.id = ? AND p.profesional_id = ?
    ');
    $stmt->execute([$id, $profesionalActivoId]);
    $archivo = $stmt->fetch();

    if (!$archivo) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Archivo no encontrado.']);
        exit;
    }

    $ruta = CARPETA_ADJUNTOS . $archivo['nombre_archivo'];
    if (file_exists($ruta)) {
        unlink($ruta);
    }

    $stmtBorrar = $pdo->prepare('DELETE FROM archivos_adjuntos WHERE id = ?');
    $stmtBorrar->execute([$id]);

    echo json_encode(['ok' => true]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

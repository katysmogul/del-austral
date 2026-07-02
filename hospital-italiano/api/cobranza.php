<?php
/**
 * Cobranza — lado cliente. El Apoderado consulta su estado de
 * pago (leído del archivo estado_cobro.json que sincroniza el
 * panel maestro) y sube el comprobante correspondiente. El
 * archivo queda en la carpeta de esta misma institución; el
 * panel maestro lo detecta leyendo estado_cobro_subida.json la
 * próxima vez que se actualiza el listado de cobros — el flujo
 * de datos entre ambos sistemas siempre va panel maestro →
 * cliente, nunca al revés, así que este archivo NUNCA llama al
 * panel maestro directamente.
 */
require_once __DIR__ . '/../config/config.php';

// Si el propio PHP del hosting tiene display_errors activado, un
// warning o notice cualquiera se imprime como HTML ANTES del
// json_encode de más abajo, y eso rompe el JSON.parse del lado
// del navegador con el error "unexpected character at line 1
// column 1" — aunque la operación en sí haya funcionado bien.
// Este buffer descarta cualquier salida accidental de ese tipo,
// para que la respuesta sea siempre JSON puro.
ob_start();
header('Content-Type: application/json; charset=utf-8');

/**
 * Descarta cualquier salida acumulada en el buffer (warnings,
 * notices) antes de imprimir el JSON real, y termina la
 * ejecución. Se usa en cada punto de salida del archivo en vez
 * de "echo json_encode(...); exit;" directo.
 */
function responderJson($datos) {
    ob_clean();
    echo json_encode($datos);
    exit;
}

requiereSesion();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

define('CARPETA_COMPROBANTES', __DIR__ . '/../comprobantes_pago/');
define('RUTA_ESTADO_COBRO', __DIR__ . '/../estado_cobro.json');
define('RUTA_ESTADO_COBRO_SUBIDA', __DIR__ . '/../estado_cobro_subida.json');
define('TIPOS_PERMITIDOS_COMPROBANTE', [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
]);
define('TAMANIO_MAXIMO_COMPROBANTE', 15 * 1024 * 1024); // 15 MB

// ------------------------------------------------------------
// OBTENER ESTADO DE COBRO (GET ?accion=obtener_estado_cobro)
// Cualquier rol autenticado puede verlo (para que el aviso de
// "hay que pagar" o "suspendido por falta de pago" se pueda
// mostrar en cualquier pantalla si hace falta), pero solo el
// Apoderado puede subir el comprobante.
// ------------------------------------------------------------
if ($accion === 'obtener_estado_cobro') {
    if (!file_exists(RUTA_ESTADO_COBRO)) {
        responderJson(['ok' => true, 'datos' => null]);
    }
    $datos = json_decode(file_get_contents(RUTA_ESTADO_COBRO), true);
    responderJson(['ok' => true, 'datos' => $datos]);
}

// ------------------------------------------------------------
// SUBIR COMPROBANTE DE PAGO (POST ?accion=subir_comprobante)
// Solo el Apoderado.
// ------------------------------------------------------------
if ($accion === 'subir_comprobante') {
    requiereDesarrollador();

    $cobroId = $_POST['cobro_id'] ?? 0;
    if (!$cobroId) {
        http_response_code(400);
        responderJson(['ok' => false, 'error' => 'Falta indicar a qué cobro corresponde el comprobante.']);
    }

    if (empty($_FILES['comprobante'])) {
        http_response_code(400);
        responderJson(['ok' => false, 'error' => 'No se recibió ningún archivo.']);
    }

    $archivo = $_FILES['comprobante'];
    if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        http_response_code(413);
        responderJson(['ok' => false, 'error' => 'El archivo supera el límite de subida configurado en este servidor. Probá con un archivo más chico.']);
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        responderJson(['ok' => false, 'error' => 'Error al subir el archivo. Intentá nuevamente.']);
    }
    if ($archivo['size'] > TAMANIO_MAXIMO_COMPROBANTE) {
        http_response_code(400);
        responderJson(['ok' => false, 'error' => 'El archivo supera el tamaño máximo permitido (15 MB).']);
    }

    if (!function_exists('finfo_open')) {
        http_response_code(500);
        responderJson(['ok' => false, 'error' => 'El servidor no tiene habilitada la extensión PHP "fileinfo", necesaria para verificar el tipo de archivo.']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo ? finfo_file($finfo, $archivo['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);

    if ($mimeReal === false || !isset(TIPOS_PERMITIDOS_COMPROBANTE[$mimeReal])) {
        http_response_code(400);
        responderJson(['ok' => false, 'error' => 'Tipo de archivo no permitido. Solo se aceptan PDF, JPG o PNG.']);
    }

    $extension = TIPOS_PERMITIDOS_COMPROBANTE[$mimeReal];
    $nombreArchivo = 'comprobante_' . $cobroId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

    if (!is_dir(CARPETA_COMPROBANTES)) {
        mkdir(CARPETA_COMPROBANTES, 0755, true);
    }

    if (!move_uploaded_file($archivo['tmp_name'], CARPETA_COMPROBANTES . $nombreArchivo)) {
        http_response_code(500);
        responderJson(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
    }

    // Este archivo es lo que el panel maestro va a leer para
    // enterarse de que hay un comprobante nuevo esperando
    // revisión, sin que este sistema tenga que llamarlo.
    file_put_contents(RUTA_ESTADO_COBRO_SUBIDA, json_encode([
        'cobro_id' => (int) $cobroId,
        'nombre_original' => $archivo['name'],
        'nombre_archivo' => $nombreArchivo,
        'subido_en' => date('Y-m-d H:i:s'),
    ]));

    registrarActividadReciente($pdo);

    responderJson(['ok' => true]);
}

http_response_code(400);
responderJson(['ok' => false, 'error' => 'Solicitud no válida.']);

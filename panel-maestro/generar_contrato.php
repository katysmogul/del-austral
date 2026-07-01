<?php
require_once __DIR__ . '/config/config_maestro.php';
require_once __DIR__ . '/plantilla_contrato.php';

if (empty($_SESSION['super_admin_autenticado'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
    <h2>No tenés permiso para ver este documento</h2>
    </body></html>';
    exit;
}

$pdo = obtenerConexionMaestro();
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare('
    SELECT i.nombre AS nombre_institucion, c.*
    FROM contratos c
    INNER JOIN instituciones i ON i.id = c.institucion_id
    WHERE c.institucion_id = ?
    ORDER BY c.id DESC LIMIT 1
');
$stmt->execute([$id]);
$contrato = $stmt->fetch();

if (!$contrato) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>No encontrado</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
    <h2>Esta institución no tiene un contrato registrado</h2>
    <p style="color:#666;">Completá los datos del contrato al crear la institución, o desde el listado.</p>
    </body></html>';
    exit;
}

echo renderizarDocumentoContratoHTML($contrato, true);

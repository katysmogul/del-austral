<?php
require_once __DIR__ . '/config/config_maestro.php';
require_once __DIR__ . '/plantilla_factura.php';

if (empty($_SESSION['super_admin_autenticado'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
    <h2>No tenés permiso para ver este documento</h2>
    </body></html>';
    exit;
}

$pdo = obtenerConexionMaestro();
$cobroId = $_GET['id'] ?? 0;

$stmt = $pdo->prepare('
    SELECT c.*, i.nombre AS institucion_nombre
    FROM cobros c INNER JOIN instituciones i ON i.id = c.institucion_id
    WHERE c.id = ?
');
$stmt->execute([$cobroId]);
$cobro = $stmt->fetch();

if (!$cobro) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>No encontrado</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
    <h2>Ese cobro no existe</h2>
    </body></html>';
    exit;
}

$stmt = $pdo->prepare("SELECT SUM(monto) AS total FROM movimientos_saldo_favor WHERE cobro_id = ? AND tipo = 'aplicado_a_cobro'");
$stmt->execute([$cobroId]);
$cobro['saldo_favor_aplicado'] = (float) ($stmt->fetch()['total'] ?? 0);
$cobro['recargo_mora_vigente'] = calcularRecargoMora((float) $cobro['monto'], $cobro['vencimiento'], $cobro['recargo_congelado_monto']);

echo renderizarFacturaHTML($cobro);

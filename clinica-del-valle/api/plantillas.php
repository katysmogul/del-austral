<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();
requiereProfesionalActivo();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$profesionalActivoId = idProfesionalActivo();

if ($metodo === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM plantillas_evolucion WHERE profesional_id = ? ORDER BY nombre ASC');
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

if ($metodo === 'POST' && $accion === 'crear') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $nombre = trim($d['nombre'] ?? '');
    $contenido = trim($d['contenido'] ?? '');
    if ($nombre === '' || $contenido === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La plantilla necesita un nombre y un contenido.']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO plantillas_evolucion (profesional_id, nombre, contenido) VALUES (?, ?, ?)');
    $stmt->execute([$profesionalActivoId, $nombre, $contenido]);
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

if ($metodo === 'POST' && $accion === 'actualizar') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el ID de la plantilla.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE plantillas_evolucion SET nombre = ?, contenido = ? WHERE id = ? AND profesional_id = ?');
    $stmt->execute([trim($d['nombre']), trim($d['contenido']), $d['id'], $profesionalActivoId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($metodo === 'POST' && $accion === 'eliminar') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $stmt = $pdo->prepare('DELETE FROM plantillas_evolucion WHERE id = ? AND profesional_id = ?');
    $stmt->execute([$d['id'] ?? 0, $profesionalActivoId]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

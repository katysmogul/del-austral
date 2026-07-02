<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $stmt = $pdo->query('SELECT id, nombre FROM obras_sociales ORDER BY es_predefinida DESC, nombre ASC');
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

if ($metodo === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $nombre = trim($input['nombre'] ?? '');
    if ($nombre === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El nombre de la obra social no puede estar vacío.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO obras_sociales (nombre, es_predefinida) VALUES (?, 0)');
        $stmt->execute([$nombre]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId(), 'nombre' => $nombre]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Ya existe: la devolvemos igual para que el front la pueda usar
            $stmt = $pdo->prepare('SELECT id, nombre FROM obras_sociales WHERE nombre = ?');
            $stmt->execute([$nombre]);
            $fila = $stmt->fetch();
            echo json_encode(['ok' => true, 'id' => $fila['id'], 'nombre' => $fila['nombre'], 'ya_existia' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error al guardar la obra social.']);
        }
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);

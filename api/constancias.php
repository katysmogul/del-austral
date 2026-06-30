<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();
requiereProfesionalActivo();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$profesionalActivoId = idProfesionalActivo();
$sedeActivaId = (int) ($_SESSION['sede_id'] ?? 0);

/**
 * Genera un token aleatorio, legible para tipear a mano si hace
 * falta (sin caracteres ambiguos como 0/O o 1/I/l), con formato
 * tipo XXXX-XXXX-XXXX.
 */
function generarTokenConstancia($pdo) {
    $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $partes = [];
        for ($p = 0; $p < 3; $p++) {
            $parte = '';
            for ($i = 0; $i < 4; $i++) {
                $parte .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $partes[] = $parte;
        }
        $token = implode('-', $partes);
        $stmt = $pdo->prepare('SELECT 1 FROM constancias WHERE token = ?');
        $stmt->execute([$token]);
    } while ($stmt->fetch());
    return $token;
}

// ------------------------------------------------------------
// CREAR CONSTANCIA (POST ?accion=crear)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear') {
    $d = json_decode(file_get_contents('php://input'), true);

    $pacienteId = $d['paciente_id'] ?? null;
    $nombreCompleto = trim($d['nombre_completo'] ?? '');
    $dni = trim($d['dni'] ?? '');
    $lugarNacimiento = trim($d['lugar_nacimiento'] ?? '') ?: null;
    $lugarTrabajo = trim($d['lugar_trabajo'] ?? '');

    // Si viene de un legajo existente, traemos sus datos reales
    // (no confiamos en lo que mande el frontend para esos campos).
    if ($pacienteId) {
        $stmtPac = $pdo->prepare('SELECT nombre, apellido, dni, direccion FROM pacientes WHERE id = ? AND profesional_id = ?');
        $stmtPac->execute([$pacienteId, $profesionalActivoId]);
        $paciente = $stmtPac->fetch();
        if (!$paciente) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Ese paciente no existe o no te pertenece.']);
            exit;
        }
        $nombreCompleto = $paciente['nombre'] . ' ' . $paciente['apellido'];
        $dni = $paciente['dni'];
        // El legajo de paciente no tiene lugar de nacimiento como
        // campo propio en este sistema, así que se completa a mano
        // igual que el lugar de trabajo si el profesional lo carga.
        $lugarNacimiento = trim($d['lugar_nacimiento'] ?? '') ?: null;
    }

    if ($nombreCompleto === '' || $dni === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan el nombre completo y el DNI del paciente.']);
        exit;
    }
    if (!$sedeActivaId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se detectó la sede activa de tu sesión.']);
        exit;
    }

    $destino = $lugarTrabajo !== ''
        ? 'a fin de ser presentado ante las autoridades de ' . $lugarTrabajo
        : 'a fin de ser presentado ante las autoridades que lo requieran';

    $token = generarTokenConstancia($pdo);
    $venceEn = date('Y-m-d', strtotime('+90 days'));

    $stmt = $pdo->prepare('
        INSERT INTO constancias
        (token, profesional_id, sede_id, paciente_id, nombre_completo, dni, lugar_nacimiento, fecha_consulta, destino, lugar_destino, vence_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?)
    ');
    $stmt->execute([$token, $profesionalActivoId, $sedeActivaId, $pacienteId, $nombreCompleto, $dni, $lugarNacimiento, $destino, $lugarTrabajo ?: null, $venceEn]);
    $nuevoId = $pdo->lastInsertId();

    registrarAuditoria($pdo, 'crear', 'constancia', $nuevoId, "Se emitió una constancia de asistencia para \"$nombreCompleto\".");
    echo json_encode(['ok' => true, 'id' => $nuevoId, 'token' => $token]);
    exit;
}

// ------------------------------------------------------------
// LISTAR CONSTANCIAS DEL PROFESIONAL (GET ?accion=listar)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar') {
    $stmt = $pdo->prepare('
        SELECT id, token, nombre_completo, dni, fecha_consulta, creado_en, vence_en
        FROM constancias
        WHERE profesional_id = ?
        ORDER BY creado_en DESC
        LIMIT 100
    ');
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

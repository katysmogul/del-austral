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
// CREAR CONSTANCIA (POST ?accion=crear) — soporta tres tipos:
// 'asistencia' (la original), 'tratamiento' (tratamiento
// prolongado) y 'receta'. Las tres comparten token público y
// vencimiento a 90 días.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear') {
    $d = json_decode(file_get_contents('php://input'), true);

    $tipo = $d['tipo'] ?? 'asistencia';
    if (!in_array($tipo, ['asistencia', 'tratamiento', 'receta'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Tipo de constancia no válido.']);
        exit;
    }

    $pacienteId = $d['paciente_id'] ?? null;
    $nombreCompleto = trim($d['nombre_completo'] ?? '');
    $dni = trim($d['dni'] ?? '');
    $lugarNacimiento = trim($d['lugar_nacimiento'] ?? '') ?: null;
    $lugarTrabajo = trim($d['lugar_trabajo'] ?? '');
    $tratamientoDesde = $d['tratamiento_desde'] ?? null ?: null;
    $diagnostico = trim($d['diagnostico'] ?? '') ?: null;
    $indicaciones = trim($d['indicaciones'] ?? '') ?: null;

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
    if ($tipo === 'tratamiento' && !$tratamientoDesde) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Indicá desde cuándo está en tratamiento.']);
        exit;
    }
    if ($tipo === 'receta' && !$indicaciones) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Completá las indicaciones de la receta.']);
        exit;
    }

    $destino = $lugarTrabajo !== ''
        ? 'a fin de ser presentado ante las autoridades de ' . $lugarTrabajo
        : 'a fin de ser presentado ante las autoridades que lo requieran';

    $token = generarTokenConstancia($pdo);
    $venceEn = date('Y-m-d', strtotime('+90 days'));

    $stmt = $pdo->prepare('
        INSERT INTO constancias
        (token, tipo, profesional_id, sede_id, paciente_id, nombre_completo, dni, lugar_nacimiento, fecha_consulta, tratamiento_desde, diagnostico, indicaciones, destino, lugar_destino, vence_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $token, $tipo, $profesionalActivoId, $sedeActivaId, $pacienteId, $nombreCompleto, $dni, $lugarNacimiento,
        $tratamientoDesde, $diagnostico, $indicaciones, $destino, $lugarTrabajo ?: null, $venceEn,
    ]);
    $nuevoId = $pdo->lastInsertId();

    $etiquetasTipo = ['asistencia' => 'una constancia de asistencia', 'tratamiento' => 'una constancia de tratamiento prolongado', 'receta' => 'una receta'];
    registrarAuditoria($pdo, 'crear', 'constancia', $nuevoId, "Se emitió " . $etiquetasTipo[$tipo] . " para \"$nombreCompleto\".");
    echo json_encode(['ok' => true, 'id' => $nuevoId, 'token' => $token]);
    exit;
}

// ------------------------------------------------------------
// LISTAR CONSTANCIAS DEL PROFESIONAL (GET ?accion=listar)
// Opcionalmente filtra por tipo (?tipo=asistencia|tratamiento|receta)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar') {
    $tipoFiltro = $_GET['tipo'] ?? '';
    $sql = 'SELECT id, token, tipo, nombre_completo, dni, fecha_consulta, creado_en, vence_en FROM constancias WHERE profesional_id = ?';
    $params = [$profesionalActivoId];
    if (in_array($tipoFiltro, ['asistencia', 'tratamiento', 'receta'])) {
        $sql .= ' AND tipo = ?';
        $params[] = $tipoFiltro;
    }
    $sql .= ' ORDER BY creado_en DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// CREAR RESUMEN DE DERIVACIÓN (POST ?accion=crear_derivacion)
// Sin token ni vencimiento: va directo a otro profesional o
// institución, no se valida públicamente.
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear_derivacion') {
    $d = json_decode(file_get_contents('php://input'), true);

    $pacienteId = $d['paciente_id'] ?? null;
    $nombreCompleto = trim($d['nombre_completo'] ?? '');
    $dni = trim($d['dni'] ?? '');
    $motivoConsulta = trim($d['motivo_consulta'] ?? '') ?: null;
    $diagnostico = trim($d['diagnostico'] ?? '') ?: null;
    $tratamientoActual = trim($d['tratamiento_actual'] ?? '') ?: null;
    $destinatario = trim($d['destinatario'] ?? '') ?: null;
    $observaciones = trim($d['observaciones'] ?? '') ?: null;

    if ($pacienteId) {
        $stmtPac = $pdo->prepare('SELECT nombre, apellido, dni FROM pacientes WHERE id = ? AND profesional_id = ?');
        $stmtPac->execute([$pacienteId, $profesionalActivoId]);
        $paciente = $stmtPac->fetch();
        if (!$paciente) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Ese paciente no existe o no te pertenece.']);
            exit;
        }
        $nombreCompleto = $paciente['nombre'] . ' ' . $paciente['apellido'];
        $dni = $paciente['dni'];
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

    $stmt = $pdo->prepare('
        INSERT INTO resumenes_derivacion
        (profesional_id, sede_id, paciente_id, nombre_completo, dni, motivo_consulta, diagnostico, tratamiento_actual, destinatario, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$profesionalActivoId, $sedeActivaId, $pacienteId, $nombreCompleto, $dni, $motivoConsulta, $diagnostico, $tratamientoActual, $destinatario, $observaciones]);
    $nuevoId = $pdo->lastInsertId();

    registrarAuditoria($pdo, 'crear', 'derivacion', $nuevoId, "Se generó un resumen de derivación para \"$nombreCompleto\".");
    echo json_encode(['ok' => true, 'id' => $nuevoId]);
    exit;
}

// ------------------------------------------------------------
// LISTAR RESÚMENES DE DERIVACIÓN (GET ?accion=listar_derivaciones)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar_derivaciones') {
    $stmt = $pdo->prepare('
        SELECT id, nombre_completo, dni, destinatario, creado_en
        FROM resumenes_derivacion
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

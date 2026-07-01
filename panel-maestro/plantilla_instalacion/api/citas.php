<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();
requiereProfesionalActivo();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$profesionalActivoId = idProfesionalActivo();

function calcularEdadCita($fechaNacimiento) {
    try {
        $nacimiento = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        return $hoy->diff($nacimiento)->y;
    } catch (Exception $e) {
        return null;
    }
}

function pacientePerteneceAlProfesional($pdo, $pacienteId, $profesionalActivoId) {
    $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = ? AND profesional_id = ?');
    $stmt->execute([$pacienteId, $profesionalActivoId]);
    return (bool) $stmt->fetch();
}

/**
 * Devuelve la cita en choque (si existe) para ese profesional,
 * fecha y hora exactas, entre citas pendientes. Si se pasa
 * $excluirCitaId, esa cita no se cuenta (permite editar sin
 * chocar contra sí misma).
 */
function hayChoqueDeHorario($pdo, $profesionalId, $fecha, $hora, $excluirCitaId = null) {
    if (!$hora) return false;
    $sql = '
        SELECT c.id, p.nombre, p.apellido FROM citas c
        INNER JOIN pacientes p ON p.id = c.paciente_id
        WHERE c.profesional_id = ? AND c.fecha = ? AND c.hora = ? AND c.estado = "pendiente"
    ';
    $params = [$profesionalId, $fecha, $hora];
    if ($excluirCitaId) {
        $sql .= ' AND c.id != ?';
        $params[] = $excluirCitaId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

// ------------------------------------------------------------
// CREAR CITA (POST ?accion=crear)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['paciente_id']) || empty($d['fecha'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos de la cita (paciente y fecha son obligatorios).']);
        exit;
    }

    if (!pacientePerteneceAlProfesional($pdo, $d['paciente_id'], $profesionalActivoId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }

    $hora = $d['hora'] ?: null;
    $choque = hayChoqueDeHorario($pdo, $profesionalActivoId, $d['fecha'], $hora);
    if ($choque) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Ese horario ya está ocupado con {$choque['nombre']} {$choque['apellido']}. Elegí otro horario."]);
        exit;
    }

    $token = bin2hex(random_bytes(20));

    $stmt = $pdo->prepare('
        INSERT INTO citas (paciente_id, profesional_id, fecha, hora, motivo, notas, estado, token_confirmacion)
        VALUES (?, ?, ?, ?, ?, ?, "pendiente", ?)
    ');
    $stmt->execute([
        $d['paciente_id'],
        $profesionalActivoId,
        $d['fecha'],
        $hora,
        $d['motivo'] ?? null,
        $d['notas'] ?? null,
        $token,
    ]);
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId(), 'token_confirmacion' => $token]);
    exit;
}

// ------------------------------------------------------------
// ACTUALIZAR CITA (POST ?accion=actualizar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'actualizar') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el ID de la cita.']);
        exit;
    }

    $stmtCheck = $pdo->prepare('SELECT * FROM citas WHERE id = ? AND profesional_id = ?');
    $stmtCheck->execute([$d['id'], $profesionalActivoId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cita no encontrada.']);
        exit;
    }

    $hora = $d['hora'] ?: null;
    $choque = hayChoqueDeHorario($pdo, $profesionalActivoId, $d['fecha'], $hora, $d['id']);
    if ($choque) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Ese horario ya está ocupado con {$choque['nombre']} {$choque['apellido']}. Elegí otro horario."]);
        exit;
    }

    $stmt = $pdo->prepare('
        UPDATE citas SET fecha = ?, hora = ?, motivo = ?, notas = ?, estado = ?
        WHERE id = ?
    ');
    $stmt->execute([
        $d['fecha'],
        $hora,
        $d['motivo'] ?? null,
        $d['notas'] ?? null,
        $d['estado'] ?? 'pendiente',
        $d['id'],
    ]);
    echo json_encode(['ok' => true, 'mensaje' => 'Cita actualizada.']);
    exit;
}

// ------------------------------------------------------------
// CAMBIAR SOLO EL ESTADO (POST ?accion=cambiar_estado)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'cambiar_estado') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['id']) || empty($d['estado'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos.']);
        exit;
    }
    if (!in_array($d['estado'], ['pendiente', 'atendida', 'cancelada', 'ausente'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $stmtCheck = $pdo->prepare('SELECT 1 FROM citas WHERE id = ? AND profesional_id = ?');
    $stmtCheck->execute([$d['id'], $profesionalActivoId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cita no encontrada.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE citas SET estado = ? WHERE id = ?');
    $stmt->execute([$d['estado'], $d['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// ELIMINAR CITA (POST ?accion=eliminar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'eliminar') {
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? 0;

    $stmtCheck = $pdo->prepare('SELECT 1 FROM citas WHERE id = ? AND profesional_id = ?');
    $stmtCheck->execute([$id, $profesionalActivoId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cita no encontrada.']);
        exit;
    }

    $pdo->prepare('DELETE FROM citas WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// LISTAR PRÓXIMAS CITAS (GET ?accion=proximas&dias=7)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'proximas') {
    $dias = (int) ($_GET['dias'] ?? 7);
    $stmt = $pdo->prepare("
        SELECT c.*, p.nombre, p.apellido, p.telefono
        FROM citas c
        INNER JOIN pacientes p ON p.id = c.paciente_id
        WHERE c.profesional_id = ?
          AND c.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND c.estado = 'pendiente'
        ORDER BY c.fecha ASC, c.hora ASC
    ");
    $stmt->execute([$profesionalActivoId, $dias]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// LISTAR CITAS POR RANGO (GET ?accion=rango&desde=&hasta=)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'rango') {
    $desde = $_GET['desde'] ?? date('Y-m-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-t');
    $stmt = $pdo->prepare('
        SELECT c.*, p.nombre, p.apellido, p.telefono
        FROM citas c
        INNER JOIN pacientes p ON p.id = c.paciente_id
        WHERE c.profesional_id = ? AND c.fecha BETWEEN ? AND ?
        ORDER BY c.fecha ASC, c.hora ASC
    ');
    $stmt->execute([$profesionalActivoId, $desde, $hasta]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// CITAS DE UN PACIENTE (GET ?accion=por_paciente&paciente_id=X)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'por_paciente') {
    $id = $_GET['paciente_id'] ?? 0;

    if (!pacientePerteneceAlProfesional($pdo, $id, $profesionalActivoId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Paciente no encontrado.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM citas WHERE paciente_id = ? ORDER BY fecha DESC, hora DESC');
    $stmt->execute([$id]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// PACIENTES INACTIVOS (GET ?accion=inactivos&dias=30)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'inactivos') {
    requiereRolProfesional();
    $dias = (int) ($_GET['dias'] ?? 30);
    $stmt = $pdo->prepare("
        SELECT p.id, p.nombre, p.apellido, p.dni, p.telefono,
               MAX(s.fecha_sesion) AS ultima_sesion,
               p.creado_en
        FROM pacientes p
        LEFT JOIN sesiones s ON s.paciente_id = p.id
        WHERE p.profesional_id = ?
        GROUP BY p.id
        HAVING (ultima_sesion IS NOT NULL AND ultima_sesion < DATE_SUB(CURDATE(), INTERVAL ? DAY))
            OR (ultima_sesion IS NULL AND p.creado_en < DATE_SUB(NOW(), INTERVAL ? DAY))
        ORDER BY ultima_sesion ASC
    ");
    $stmt->execute([$profesionalActivoId, $dias, $dias]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// CUMPLEAÑOS PRÓXIMOS (GET ?accion=cumpleanios&dias=14)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'cumpleanios') {
    $dias = (int) ($_GET['dias'] ?? 14);
    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido, telefono, fecha_nacimiento,
               DATE_FORMAT(fecha_nacimiento, '%m-%d') AS mes_dia
        FROM pacientes
        WHERE profesional_id = ?
        HAVING mes_dia BETWEEN DATE_FORMAT(CURDATE(), '%m-%d') AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL ? DAY), '%m-%d')
            OR (DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL ? DAY), '%m-%d') < DATE_FORMAT(CURDATE(), '%m-%d')
                AND (mes_dia >= DATE_FORMAT(CURDATE(), '%m-%d') OR mes_dia <= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL ? DAY), '%m-%d')))
        ORDER BY mes_dia ASC
    ");
    $stmt->execute([$profesionalActivoId, $dias, $dias, $dias]);
    $resultados = $stmt->fetchAll();
    foreach ($resultados as &$r) {
        $r['edad_que_cumple'] = calcularEdadCita($r['fecha_nacimiento']) + 1;
    }
    echo json_encode(['ok' => true, 'datos' => $resultados]);
    exit;
}

// ------------------------------------------------------------
// AVISOS SIN REVISAR (GET ?accion=avisos_pendientes)
// Cuenta cuántas citas tienen un cambio del paciente (confirmó
// o canceló) que el profesional todavía no vio.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'avisos_pendientes') {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS total FROM citas
        WHERE profesional_id = ? AND revisada_por_profesional = 0
    ');
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true, 'total' => (int) $stmt->fetch()['total']]);
    exit;
}

// ------------------------------------------------------------
// DETALLE DE AVISOS SIN REVISAR (GET ?accion=listar_avisos)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'listar_avisos') {
    $stmt = $pdo->prepare("
        SELECT c.id, c.fecha, c.hora, c.estado, c.confirmada_por_paciente, p.nombre, p.apellido
        FROM citas c
        INNER JOIN pacientes p ON p.id = c.paciente_id
        WHERE c.profesional_id = ? AND c.revisada_por_profesional = 0
        ORDER BY c.fecha ASC, c.hora ASC
    ");
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// MARCAR AVISOS COMO VISTOS (POST ?accion=marcar_avisos_vistos)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'marcar_avisos_vistos') {
    $stmt = $pdo->prepare('UPDATE citas SET revisada_por_profesional = 1 WHERE profesional_id = ?');
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// RESUMEN DE HOY (GET ?accion=resumen_hoy)
// Cuántas consultas quedan por pasar hoy (la hora de la cita
// todavía no llegó) y a qué hora es la próxima.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'resumen_hoy') {
    $stmt = $pdo->prepare("
        SELECT hora FROM citas
        WHERE profesional_id = ? AND fecha = CURDATE() AND estado = 'pendiente'
        ORDER BY hora ASC
    ");
    $stmt->execute([$profesionalActivoId]);
    $todasHoy = $stmt->fetchAll();

    $horaActual = date('H:i:s');
    $restantes = array_filter($todasHoy, function ($c) use ($horaActual) {
        // Una cita sin hora especificada se cuenta como "restante"
        // todo el día, ya que no hay forma de saber si ya pasó.
        return $c['hora'] === null || $c['hora'] >= $horaActual;
    });

    echo json_encode([
        'ok' => true,
        'total_hoy' => count($todasHoy),
        'restantes_hoy' => count($restantes),
        'proxima_hora' => !empty($restantes) ? reset($restantes)['hora'] : null,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

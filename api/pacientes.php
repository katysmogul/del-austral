<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();
requiereProfesionalActivo();

$pdo = obtenerConexion();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';
$profesionalActivoId = idProfesionalActivo();

function validarPaciente($d) {
    $errores = [];
    if (empty($d['nombre'])) $errores[] = 'El nombre es obligatorio.';
    if (empty($d['apellido'])) $errores[] = 'El apellido es obligatorio.';
    if (empty($d['dni'])) $errores[] = 'El DNI es obligatorio.';
    if (empty($d['fecha_nacimiento'])) $errores[] = 'La fecha de nacimiento es obligatoria.';
    if (empty($d['sexo']) || !in_array($d['sexo'], ['Femenino', 'Masculino', 'Otro'])) $errores[] = 'El sexo es obligatorio.';
    return $errores;
}

function calcularEdad($fechaNacimiento) {
    try {
        $nacimiento = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        return $hoy->diff($nacimiento)->y;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Quita los campos clínicos sensibles de un registro de paciente
 * cuando quien consulta no es el profesional.
 */
function filtrarSegunRol($paciente) {
    if (esProfesional()) {
        return $paciente;
    }
    unset(
        $paciente['motivo_consulta'],
        $paciente['patologia'],
        $paciente['sintomas'],
        $paciente['observaciones_generales'],
        $paciente['sesiones']
    );
    return $paciente;
}

/**
 * Confirma que el paciente con este ID pertenece al profesional
 * activo de la sesión. Si no, corta con 404 (no se revela ni
 * siquiera que el paciente existe, para no filtrar información
 * de otros profesionales).
 */
function obtenerPacienteDelProfesionalActivo($pdo, $id, $profesionalActivoId) {
    $stmt = $pdo->prepare('
        SELECT p.*, o.nombre AS obra_social_nombre, s.nombre AS sede_nombre
        FROM pacientes p
        LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
        LEFT JOIN sedes s ON s.id = p.sede_id
        WHERE p.id = ? AND p.profesional_id = ?
    ');
    $stmt->execute([$id, $profesionalActivoId]);
    $paciente = $stmt->fetch();
    if (!$paciente) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Legajo no encontrado.']);
        exit;
    }
    return $paciente;
}

// ------------------------------------------------------------
// CREAR LEGAJO  (POST ?accion=crear)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'crear') {
    $d = json_decode(file_get_contents('php://input'), true);
    $errores = validarPaciente($d);
    if (!empty($errores)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errores)]);
        exit;
    }

    $esAdmin = !esProfesional();
    $sedeId = $_SESSION['sede_id'] ?? null;

    try {
        $stmt = $pdo->prepare('
            INSERT INTO pacientes
            (profesional_id, sede_id, nombre, apellido, dni, fecha_nacimiento, sexo, obra_social_id, numero_afiliado,
             telefono, email, direccion, motivo_consulta, patologia, sintomas, observaciones_generales)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $profesionalActivoId,
            $sedeId,
            trim($d['nombre']),
            trim($d['apellido']),
            trim($d['dni']),
            $d['fecha_nacimiento'],
            $d['sexo'],
            $d['obra_social_id'] ?: null,
            $d['numero_afiliado'] ?? null,
            $d['telefono'] ?? null,
            $d['email'] ?? null,
            $d['direccion'] ?? null,
            $esAdmin ? null : ($d['motivo_consulta'] ?? null),
            $esAdmin ? null : ($d['patologia'] ?? null),
            $esAdmin ? null : ($d['sintomas'] ?? null),
            $esAdmin ? null : ($d['observaciones_generales'] ?? null),
        ]);
        $id = $pdo->lastInsertId();

        if (!$esAdmin && !empty($d['sesiones']) && is_array($d['sesiones'])) {
            $stmtS = $pdo->prepare('INSERT INTO sesiones (paciente_id, fecha_sesion, descripcion, evolucion, proxima_cita) VALUES (?, ?, ?, ?, ?)');
            $stmtC = $pdo->prepare("INSERT INTO citas (paciente_id, profesional_id, fecha, motivo, estado) VALUES (?, ?, ?, 'Próxima cita agendada desde sesión anterior', 'pendiente')");
            foreach ($d['sesiones'] as $s) {
                if (empty($s['fecha_sesion']) || empty($s['descripcion'])) continue;
                $stmtS->execute([$id, $s['fecha_sesion'], $s['descripcion'], $s['evolucion'] ?? null, $s['proxima_cita'] ?? null]);
                if (!empty($s['proxima_cita'])) {
                    $stmtC->execute([$id, $profesionalActivoId, $s['proxima_cita']]);
                }
            }
        }

        registrarAuditoria($pdo, 'crear', 'paciente', $id, "Se creó el legajo de {$d['nombre']} {$d['apellido']}.");

        echo json_encode(['ok' => true, 'id' => $id, 'mensaje' => 'Legajo creado correctamente.']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Ya existe un legajo con ese DNI.']);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error al guardar el legajo.']);
        }
    }
    exit;
}

// ------------------------------------------------------------
// AGREGAR SESIÓN A LEGAJO EXISTENTE (POST ?accion=agregar_sesion)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'agregar_sesion') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['paciente_id']) || empty($d['fecha_sesion']) || empty($d['descripcion'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos de la sesión.']);
        exit;
    }

    obtenerPacienteDelProfesionalActivo($pdo, $d['paciente_id'], $profesionalActivoId);

    $stmt = $pdo->prepare('INSERT INTO sesiones (paciente_id, fecha_sesion, descripcion, evolucion, proxima_cita) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$d['paciente_id'], $d['fecha_sesion'], $d['descripcion'], $d['evolucion'] ?? null, $d['proxima_cita'] ?? null]);

    if (!empty($d['proxima_cita'])) {
        $stmtC = $pdo->prepare("INSERT INTO citas (paciente_id, profesional_id, fecha, motivo, estado) VALUES (?, ?, ?, 'Próxima cita agendada desde sesión anterior', 'pendiente')");
        $stmtC->execute([$d['paciente_id'], $profesionalActivoId, $d['proxima_cita']]);
    }

    registrarAuditoria($pdo, 'crear', 'sesion', $pdo->lastInsertId(), "Se agregó una sesión del " . $d['fecha_sesion'] . ".");

    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ------------------------------------------------------------
// EDITAR SESIÓN EXISTENTE (POST ?accion=editar_sesion)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'editar_sesion') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['id']) || empty($d['fecha_sesion']) || empty($d['descripcion'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Faltan datos de la sesión.']);
        exit;
    }

    $stmtCheck = $pdo->prepare('
        SELECT s.id FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE s.id = ? AND p.profesional_id = ?
    ');
    $stmtCheck->execute([$d['id'], $profesionalActivoId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Sesión no encontrada.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE sesiones SET fecha_sesion = ?, descripcion = ?, evolucion = ? WHERE id = ?');
    $stmt->execute([$d['fecha_sesion'], $d['descripcion'], $d['evolucion'] ?? null, $d['id']]);

    registrarAuditoria($pdo, 'editar', 'sesion', $d['id'], "Se editó una sesión del " . $d['fecha_sesion'] . ".");

    echo json_encode(['ok' => true, 'mensaje' => 'Sesión actualizada.']);
    exit;
}

// ------------------------------------------------------------
// ELIMINAR SESIÓN (POST ?accion=eliminar_sesion)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'eliminar_sesion') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $idSesion = $d['id'] ?? 0;

    $stmtCheck = $pdo->prepare('
        SELECT s.id, s.fecha_sesion FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE s.id = ? AND p.profesional_id = ?
    ');
    $stmtCheck->execute([$idSesion, $profesionalActivoId]);
    $sesion = $stmtCheck->fetch();
    if (!$sesion) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Sesión no encontrada.']);
        exit;
    }

    $pdo->prepare('DELETE FROM sesiones WHERE id = ?')->execute([$idSesion]);
    registrarAuditoria($pdo, 'eliminar', 'sesion', $idSesion, "Se eliminó una sesión del " . $sesion['fecha_sesion'] . ".");

    echo json_encode(['ok' => true, 'mensaje' => 'Sesión eliminada.']);
    exit;
}

// ------------------------------------------------------------
// ACTUALIZAR DATOS DE LEGAJO (POST ?accion=actualizar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'actualizar') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    if (empty($d['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta el ID del legajo.']);
        exit;
    }
    $errores = validarPaciente($d);
    if (!empty($errores)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errores)]);
        exit;
    }

    obtenerPacienteDelProfesionalActivo($pdo, $d['id'], $profesionalActivoId);

    try {
        $stmt = $pdo->prepare('
            UPDATE pacientes SET
                nombre = ?, apellido = ?, dni = ?, fecha_nacimiento = ?, sexo = ?,
                obra_social_id = ?, numero_afiliado = ?, telefono = ?, email = ?, direccion = ?,
                motivo_consulta = ?, patologia = ?, sintomas = ?, observaciones_generales = ?
            WHERE id = ? AND profesional_id = ?
        ');
        $stmt->execute([
            trim($d['nombre']), trim($d['apellido']), trim($d['dni']), $d['fecha_nacimiento'], $d['sexo'],
            $d['obra_social_id'] ?: null, $d['numero_afiliado'] ?? null, $d['telefono'] ?? null,
            $d['email'] ?? null, $d['direccion'] ?? null, $d['motivo_consulta'] ?? null,
            $d['patologia'] ?? null, $d['sintomas'] ?? null, $d['observaciones_generales'] ?? null,
            $d['id'], $profesionalActivoId
        ]);
        registrarAuditoria($pdo, 'editar', 'paciente', $d['id'], "Se editaron los datos de {$d['nombre']} {$d['apellido']}.");
        echo json_encode(['ok' => true, 'mensaje' => 'Legajo actualizado.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error al actualizar. Verificá que el DNI no esté repetido.']);
    }
    exit;
}

// ------------------------------------------------------------
// MIGRAR PACIENTE A OTRA SEDE (POST ?accion=migrar_sede)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'migrar_sede') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? 0;
    $sedeNuevaId = $d['sede_id'] ?? 0;

    $paciente = obtenerPacienteDelProfesionalActivo($pdo, $id, $profesionalActivoId);

    $stmtSede = $pdo->prepare('SELECT nombre FROM sedes WHERE id = ? AND activa = 1');
    $stmtSede->execute([$sedeNuevaId]);
    $sedeNueva = $stmtSede->fetch();
    if (!$sedeNueva) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Sede no válida.']);
        exit;
    }

    $pdo->prepare('UPDATE pacientes SET sede_id = ? WHERE id = ?')->execute([$sedeNuevaId, $id]);
    registrarAuditoria($pdo, 'editar', 'paciente', $id, "Se migró a {$paciente['nombre']} {$paciente['apellido']} a la sede \"{$sedeNueva['nombre']}\".");

    echo json_encode(['ok' => true, 'mensaje' => 'Paciente migrado a la nueva sede.']);
    exit;
}

// ------------------------------------------------------------
// LISTAR SEDES DISPONIBLES PARA MIGRAR (GET ?accion=sedes_disponibles)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'sedes_disponibles') {
    $stmt = $pdo->prepare('
        SELECT s.id, s.nombre FROM sedes s
        INNER JOIN usuarios_sedes us ON us.sede_id = s.id
        WHERE us.usuario_id = ? AND s.activa = 1
        ORDER BY s.nombre ASC
    ');
    $stmt->execute([$profesionalActivoId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// BUSCAR LEGAJOS (GET ?accion=buscar&tipo=dni|nombre|fecha|obra_social|sede&...)
// Siempre acotado al profesional_id activo de la sesión.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'buscar') {
    $tipo = $_GET['tipo'] ?? '';
    $sqlBase = '
        SELECT p.*, o.nombre AS obra_social_nombre, s.nombre AS sede_nombre
        FROM pacientes p
        LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
        LEFT JOIN sedes s ON s.id = p.sede_id
    ';
    $condiciones = ['p.profesional_id = ?'];
    $params = [$profesionalActivoId];

    if ($tipo === 'dni') {
        $condiciones[] = 'p.dni LIKE ?';
        $params[] = '%' . trim($_GET['valor'] ?? '') . '%';
    } elseif ($tipo === 'nombre') {
        $condiciones[] = '(p.nombre LIKE ? OR p.apellido LIKE ? OR CONCAT(p.nombre, " ", p.apellido) LIKE ?)';
        $valor = '%' . trim($_GET['valor'] ?? '') . '%';
        $params[] = $valor; $params[] = $valor; $params[] = $valor;
    } elseif ($tipo === 'fecha') {
        $desde = $_GET['desde'] ?? null;
        $hasta = $_GET['hasta'] ?? null;
        $sqlBase = '
            SELECT DISTINCT p.*, o.nombre AS obra_social_nombre, s.nombre AS sede_nombre
            FROM pacientes p
            LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
            LEFT JOIN sedes s ON s.id = p.sede_id
            INNER JOIN sesiones se ON se.paciente_id = p.id
        ';
        if ($desde) { $condiciones[] = 'se.fecha_sesion >= ?'; $params[] = $desde; }
        if ($hasta) { $condiciones[] = 'se.fecha_sesion <= ?'; $params[] = $hasta; }
    } elseif ($tipo === 'obra_social') {
        $condiciones[] = 'p.obra_social_id = ?';
        $params[] = $_GET['obra_social_id'] ?? 0;
    } elseif ($tipo === 'sede') {
        $condiciones[] = 'p.sede_id = ?';
        $params[] = $_GET['sede_id'] ?? 0;
    }

    $sql = $sqlBase . ' WHERE ' . implode(' AND ', $condiciones) . ' ORDER BY p.apellido ASC, p.nombre ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll();

    foreach ($resultados as &$r) {
        $r['edad'] = calcularEdad($r['fecha_nacimiento']);
        $r = filtrarSegunRol($r);
    }

    echo json_encode(['ok' => true, 'datos' => $resultados, 'total' => count($resultados)]);
    exit;
}

// ------------------------------------------------------------
// VER DETALLE DE UN LEGAJO (GET ?accion=detalle&id=X)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'detalle') {
    $id = $_GET['id'] ?? 0;
    $paciente = obtenerPacienteDelProfesionalActivo($pdo, $id, $profesionalActivoId);
    $paciente['edad'] = calcularEdad($paciente['fecha_nacimiento']);

    if (esProfesional()) {
        $stmtSesiones = $pdo->prepare('SELECT * FROM sesiones WHERE paciente_id = ? ORDER BY fecha_sesion DESC');
        $stmtSesiones->execute([$id]);
        $paciente['sesiones'] = $stmtSesiones->fetchAll();
    }

    $paciente = filtrarSegunRol($paciente);

    echo json_encode(['ok' => true, 'datos' => $paciente]);
    exit;
}

// ------------------------------------------------------------
// ELIMINAR LEGAJO (POST ?accion=eliminar)
// ------------------------------------------------------------
if ($metodo === 'POST' && $accion === 'eliminar') {
    requiereRolProfesional();
    $d = json_decode(file_get_contents('php://input'), true);
    $id = $d['id'] ?? 0;

    $paciente = obtenerPacienteDelProfesionalActivo($pdo, $id, $profesionalActivoId);

    $stmtSesiones = $pdo->prepare('SELECT * FROM sesiones WHERE paciente_id = ?');
    $stmtSesiones->execute([$id]);
    $paciente['sesiones'] = $stmtSesiones->fetchAll();

    $pdo->beginTransaction();
    try {
        $stmtArchivo = $pdo->prepare('
            INSERT INTO legajos_eliminados (paciente_id_original, profesional_id_original, sede_id_original, nombre_completo, dni, datos_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmtArchivo->execute([
            $id,
            $paciente['profesional_id'],
            $paciente['sede_id'],
            $paciente['nombre'] . ' ' . $paciente['apellido'],
            $paciente['dni'],
            json_encode($paciente, JSON_UNESCAPED_UNICODE)
        ]);

        $stmtBorrar = $pdo->prepare('DELETE FROM pacientes WHERE id = ?');
        $stmtBorrar->execute([$id]);

        $pdo->commit();
        registrarAuditoria($pdo, 'eliminar', 'paciente', $id, "Se eliminó el legajo de {$paciente['nombre']} {$paciente['apellido']}.");
        echo json_encode(['ok' => true, 'mensaje' => 'Legajo eliminado del sistema activo. Queda resguardado en la base histórica.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el legajo.']);
    }
    exit;
}

// ------------------------------------------------------------
// VER PAPELERA / BASE HISTÓRICA (GET ?accion=papelera)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'papelera') {
    requiereRolProfesional();
    $stmt = $pdo->query('SELECT id, paciente_id_original, nombre_completo, dni, eliminado_en FROM legajos_eliminados ORDER BY eliminado_en DESC');
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// VER DETALLE DE UN LEGAJO ELIMINADO (GET ?accion=papelera_detalle&id=X)
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'papelera_detalle') {
    requiereRolProfesional();
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare('SELECT * FROM legajos_eliminados WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Registro no encontrado.']);
        exit;
    }
    $fila['datos'] = json_decode($fila['datos_json'], true);
    unset($fila['datos_json']);
    echo json_encode(['ok' => true, 'datos' => $fila]);
    exit;
}

// ------------------------------------------------------------
// EXPORTACIÓN MASIVA (GET ?accion=exportar_todo) — descarga un
// único archivo .json con todos los legajos activos del
// profesional, cada uno con sus sesiones, citas y la lista de
// adjuntos (metadata, no los archivos físicos en sí). Pensado
// como backup propio, fuera de la base de datos.
// ------------------------------------------------------------
if ($metodo === 'GET' && $accion === 'exportar_todo') {
    requiereRolProfesional();

    $stmtPacientes = $pdo->prepare('
        SELECT p.*, o.nombre AS obra_social_nombre, s.nombre AS sede_nombre
        FROM pacientes p
        LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
        LEFT JOIN sedes s ON s.id = p.sede_id
        WHERE p.profesional_id = ?
        ORDER BY p.apellido ASC, p.nombre ASC
    ');
    $stmtPacientes->execute([$profesionalActivoId]);
    $pacientes = $stmtPacientes->fetchAll();

    $stmtSesiones = $pdo->prepare('SELECT * FROM sesiones WHERE paciente_id = ? ORDER BY fecha_sesion ASC');
    $stmtCitas = $pdo->prepare('SELECT fecha, hora, motivo, estado, notas, confirmada_por_paciente FROM citas WHERE paciente_id = ? ORDER BY fecha ASC');
    $stmtAdjuntos = $pdo->prepare('SELECT nombre_original, tipo_mime, tamanio_bytes, descripcion, subido_en FROM archivos_adjuntos WHERE paciente_id = ?');

    foreach ($pacientes as &$p) {
        $p['edad'] = calcularEdad($p['fecha_nacimiento']);
        $stmtSesiones->execute([$p['id']]);
        $p['sesiones'] = $stmtSesiones->fetchAll();
        $stmtCitas->execute([$p['id']]);
        $p['citas'] = $stmtCitas->fetchAll();
        $stmtAdjuntos->execute([$p['id']]);
        $p['adjuntos'] = $stmtAdjuntos->fetchAll();
    }

    $exportacion = [
        'sistema' => 'Del Austral',
        'tipo' => 'Exportación completa de legajos',
        'generado_en' => date('Y-m-d H:i:s'),
        'total_pacientes' => count($pacientes),
        'pacientes' => $pacientes,
    ];

    $nombreArchivo = 'del-austral-export-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    echo json_encode($exportacion, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

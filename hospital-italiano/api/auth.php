<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = obtenerConexion();
$input = json_decode(file_get_contents('php://input'), true);
$accion = $input['accion'] ?? '';

const LARGO_PIN = 4;

function obtenerUsuariosActivosPorSede($pdo, $sedeId) {
    $stmt = $pdo->prepare('
        SELECT u.* FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE us.sede_id = ? AND u.activo = 1 AND u.rol IN ("profesional", "administrativa")
        ORDER BY u.rol ASC, u.nombre_completo ASC
    ');
    $stmt->execute([$sedeId]);
    return $stmt->fetchAll();
}

function pinValido($pin) {
    return is_string($pin) && preg_match('/^\d{' . LARGO_PIN . '}$/', $pin);
}

function generarNumeroLegajo($pdo) {
    $anio = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM profesionales_legajos WHERE numero_legajo LIKE ?");
    $stmt->execute(["LG-$anio-%"]);
    $total = (int) $stmt->fetch()['total'];
    return "LG-$anio-" . str_pad($total + 1, 3, '0', STR_PAD_LEFT);
}

/**
 * Genera un sello automático tipo texto (nombre + título +
 * matrícula) como imagen SVG en base64, similar a un sello de
 * profesional escaneado pero generado al instante. Sirve como
 * firma de partida; el profesional puede reemplazarla después
 * dibujando o subiendo su propia firma/sello real.
 */
function generarSelloAutomatico($titulo, $nombre, $apellido, $especialidad, $matriculaNacional, $matriculaProvincial) {
    // mb_strtoupper respeta tildes y caracteres UTF-8 (strtoupper
    // normal corrompe letras como "ó" o "í" porque procesa byte
    // por byte). Si por algún motivo mbstring no está disponible
    // en el hosting, usamos el texto tal cual en vez de romper.
    $aMayusculas = function_exists('mb_strtoupper')
        ? fn($t) => mb_strtoupper($t, 'UTF-8')
        : fn($t) => strtoupper($t);

    $nombreCompleto = htmlspecialchars("$titulo $nombre $apellido", ENT_QUOTES);
    $tituloLimpio = trim(str_replace('.', '', $titulo));
    $tituloEspecialidad = $especialidad ? $especialidad : ($tituloLimpio . (str_contains($titulo, 'Dr') ? ' MÉDICO' : ''));
    $tituloEspecialidad = trim($aMayusculas($tituloEspecialidad));
    $tituloEspecialidad = htmlspecialchars($tituloEspecialidad, ENT_QUOTES);

    $lineaMatricula = '';
    if ($matriculaNacional && $matriculaProvincial) {
        $lineaMatricula = "M.N. $matriculaNacional - M.P. $matriculaProvincial";
    } elseif ($matriculaNacional) {
        $lineaMatricula = "M.N. $matriculaNacional";
    } elseif ($matriculaProvincial) {
        $lineaMatricula = "M.P. $matriculaProvincial";
    }
    $lineaMatricula = htmlspecialchars($lineaMatricula, ENT_QUOTES);

    $alturaTotal = $lineaMatricula ? 130 : 100;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="460" height="' . $alturaTotal . '" viewBox="0 0 460 ' . $alturaTotal . '"><!--sello-automatico-->'
        . '<text x="230" y="44" text-anchor="middle" font-family="Georgia, \'Times New Roman\', serif" font-style="italic" font-size="34" fill="#1C2421">' . $nombreCompleto . '</text>'
        . ($tituloEspecialidad ? '<text x="230" y="74" text-anchor="middle" font-family="Arial, sans-serif" font-size="17" letter-spacing="1" fill="#1C2421">' . $tituloEspecialidad . '</text>' : '')
        . ($lineaMatricula ? '<text x="230" y="102" text-anchor="middle" font-family="Arial, sans-serif" font-size="17" letter-spacing="1" fill="#1C2421">' . $lineaMatricula . '</text>' : '')
        . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function aplicarBloqueoFuerzaBruta($claveSesionIntentos, $claveSesionBloqueo) {
    if (!isset($_SESSION[$claveSesionIntentos])) $_SESSION[$claveSesionIntentos] = 0;
    if (!isset($_SESSION[$claveSesionBloqueo])) $_SESSION[$claveSesionBloqueo] = 0;

    if (time() < $_SESSION[$claveSesionBloqueo]) {
        $espera = $_SESSION[$claveSesionBloqueo] - time();
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => "Demasiados intentos. Esperá $espera segundos."]);
        exit;
    }
}

function registrarIntentoFallido($claveSesionIntentos, $claveSesionBloqueo) {
    $_SESSION[$claveSesionIntentos]++;
    if ($_SESSION[$claveSesionIntentos] >= 5) {
        $_SESSION[$claveSesionBloqueo] = time() + 30;
        $_SESSION[$claveSesionIntentos] = 0;
    }
}

// ------------------------------------------------------------
// ESTADO INICIAL: en qué etapa de configuración está el sistema.
// ------------------------------------------------------------
if ($accion === 'estado') {
    $nombreInst = nombreInstitucion($pdo);
    $mantenimiento = mantenimientoActivo();
    $suspendidaPorPago = suspendidaPorPagoActiva();
    $hayDesarrollador = $pdo->query('SELECT COUNT(*) AS t FROM desarrollador')->fetch()['t'] > 0;

    if (!$hayDesarrollador) {
        $hayFirma = $pdo->query('SELECT COUNT(*) AS t FROM contrato_firma_cliente')->fetch()['t'] > 0;
        if (!$hayFirma) {
            echo json_encode(['ok' => true, 'etapa' => 'falta_firma_contrato', 'nombre_institucion' => $nombreInst, 'mantenimiento_activo' => $mantenimiento, 'suspendida_por_pago_activa' => $suspendidaPorPago]);
            exit;
        }
        echo json_encode(['ok' => true, 'etapa' => 'sin_desarrollador', 'largo_pin' => LARGO_PIN, 'nombre_institucion' => $nombreInst, 'mantenimiento_activo' => $mantenimiento, 'suspendida_por_pago_activa' => $suspendidaPorPago]);
        exit;
    }

    $sedesConUsuarios = $pdo->query('
        SELECT COUNT(*) AS t FROM sedes s
        INNER JOIN usuarios_sedes us ON us.sede_id = s.id
        INNER JOIN usuarios u ON u.id = us.usuario_id AND u.activo = 1
        WHERE s.activa = 1
    ')->fetch()['t'];

    if ($sedesConUsuarios == 0) {
        echo json_encode(['ok' => true, 'etapa' => 'sin_sedes_o_usuarios', 'largo_pin' => LARGO_PIN, 'nombre_institucion' => $nombreInst, 'mantenimiento_activo' => $mantenimiento, 'suspendida_por_pago_activa' => $suspendidaPorPago]);
        exit;
    }

    echo json_encode(['ok' => true, 'etapa' => 'listo', 'largo_pin' => LARGO_PIN, 'nombre_institucion' => $nombreInst, 'mantenimiento_activo' => $mantenimiento, 'suspendida_por_pago_activa' => $suspendidaPorPago]);
    exit;
}

// ------------------------------------------------------------
// LISTAR SEDES ACTIVAS (paso 1 del login normal).
// ------------------------------------------------------------
if ($accion === 'listar_sedes_login') {
    $stmt = $pdo->query('
        SELECT s.id, s.nombre FROM sedes s
        INNER JOIN usuarios_sedes us ON us.sede_id = s.id
        INNER JOIN usuarios u ON u.id = us.usuario_id AND u.activo = 1
        WHERE s.activa = 1
        GROUP BY s.id
        ORDER BY s.nombre ASC
    ');
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// LISTAR USUARIOS DE UNA SEDE (paso 2 del login normal).
// ------------------------------------------------------------
if ($accion === 'listar_usuarios_sede_login') {
    $sedeId = $input['sede_id'] ?? 0;
    $usuarios = obtenerUsuariosActivosPorSede($pdo, $sedeId);
    $datos = array_map(function ($u) {
        return ['id' => $u['id'], 'nombre_completo' => $u['nombre_completo'], 'rol' => $u['rol']];
    }, $usuarios);
    echo json_encode(['ok' => true, 'datos' => $datos]);
    exit;
}

// ------------------------------------------------------------
// LISTAR PROFESIONALES DE UNA SEDE (para que la administrativa
// elija a quién representa, dentro del paso 3 del login).
// ------------------------------------------------------------
if ($accion === 'listar_profesionales_sede_login') {
    $sedeId = $input['sede_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT u.id, u.nombre_completo FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
        ORDER BY u.nombre_completo ASC
    ');
    $stmt->execute([$sedeId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// VERIFICAR PIN (paso 3 del login normal).
// ------------------------------------------------------------
if ($accion === 'verificar') {
    requiereSinMantenimiento();
    requiereSinSuspensionPorPago();
    aplicarBloqueoFuerzaBruta('intentos_fallidos', 'bloqueado_hasta');

    $sedeId = $input['sede_id'] ?? 0;
    $usuarioId = $input['usuario_id'] ?? 0;
    $pin = trim($input['pin'] ?? '');
    $profesionalActivoId = $input['profesional_activo_id'] ?? null;

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? AND activo = 1');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($pin . APP_SECRET, $usuario['patron_hash'])) {
        registrarIntentoFallido('intentos_fallidos', 'bloqueado_hasta');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'PIN incorrecto.']);
        exit;
    }

    $stmtSede = $pdo->prepare('SELECT 1 FROM usuarios_sedes WHERE usuario_id = ? AND sede_id = ?');
    $stmtSede->execute([$usuarioId, $sedeId]);
    if (!$stmtSede->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Este usuario no pertenece a la sede elegida.']);
        exit;
    }

    // Verificar licencia para profesionales: si venció el período
    // contratado, bloqueamos el acceso y marcamos como suspendido.
    if ($usuario['rol'] === 'profesional') {
        $estadoLicencia = $usuario['estado_licencia'] ?? 'activo';

        // Si tiene días definidos, chequear si el período venció.
        if ($estadoLicencia === 'activo' && !empty($usuario['licencia_dias']) && !empty($usuario['licencia_inicio'])) {
            $inicio = new DateTime($usuario['licencia_inicio']);
            $vencimiento = clone $inicio;
            $vencimiento->modify('+' . (int) $usuario['licencia_dias'] . ' days');
            if (new DateTime() > $vencimiento) {
                $estadoLicencia = 'suspendido';
                $pdo->prepare('UPDATE usuarios SET estado_licencia = "suspendido" WHERE id = ?')->execute([$usuarioId]);
            }
        }

        if ($estadoLicencia !== 'activo') {
            $mensajes = [
                'suspendido' => 'Tu licencia de acceso venció. Comunicarte con el administrador del sistema para renovarla.',
                'pausado'    => 'Tu cuenta está pausada temporalmente. Comunicarte con el administrador del sistema.',
                'prohibido'  => 'Tu cuenta está inhabilitada. Comunicarte con el administrador del sistema.',
            ];
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => $mensajes[$estadoLicencia] ?? 'No podés acceder al sistema en este momento.']);
            exit;
        }
    }

    $_SESSION['autenticado'] = true;
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['nombre_usuario'] = $usuario['nombre_completo'];
    $_SESSION['rol'] = $usuario['rol'];
    $_SESSION['sede_id'] = (int) $sedeId;
    $_SESSION['intentos_fallidos'] = 0;

    registrarActividadReciente($pdo);

    $nombreParaMostrar = $usuario['nombre_completo'];

    if ($usuario['rol'] === 'administrativa') {
        if (!$profesionalActivoId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta indicar a qué profesional representa esta administrativa.']);
            exit;
        }
        $stmtProf = $pdo->prepare('
            SELECT u.nombre_completo FROM usuarios u
            INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
            WHERE u.id = ? AND u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
        ');
        $stmtProf->execute([$profesionalActivoId, $sedeId]);
        $profesionalRepresentado = $stmtProf->fetch();
        if (!$profesionalRepresentado) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'El profesional elegido no pertenece a esta sede.']);
            exit;
        }
        $_SESSION['profesional_activo_id'] = (int) $profesionalActivoId;
        // En pantalla se muestra el nombre del profesional, nunca el
        // de la administrativa, para que no se "mezclen" las cuentas
        // visualmente. Internamente (auditoría, etc.) se sigue
        // registrando que fue la administrativa quien actuó.
        $nombreParaMostrar = $profesionalRepresentado['nombre_completo'];
    }

    echo json_encode([
        'ok' => true,
        'nombre_usuario' => $nombreParaMostrar,
        'rol' => $usuario['rol'],
    ]);
    exit;
}

// ============================================================
// FIRMA DEL CONTRATO (paso obligatorio antes de crear la clave
// de Apoderado). Solo puede registrarse una vez.
// ============================================================

if ($accion === 'firmar_contrato_cliente') {
    $hay = $pdo->query('SELECT COUNT(*) AS t FROM contrato_firma_cliente')->fetch()['t'];
    if ($hay > 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'El contrato ya fue firmado.']);
        exit;
    }
    $hayDesarrollador = $pdo->query('SELECT COUNT(*) AS t FROM desarrollador')->fetch()['t'];
    if ($hayDesarrollador > 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'La cuenta de Apoderado ya fue creada; la firma del contrato ya no corresponde en esta etapa.']);
        exit;
    }

    $firmaPng = trim($input['firma_png'] ?? '');
    if ($firmaPng === '' || !preg_match('/^data:image\/png;base64,/', $firmaPng)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La firma no es válida. Volvé a dibujarla.']);
        exit;
    }

    $pdo->prepare('INSERT INTO contrato_firma_cliente (firma_png) VALUES (?)')->execute([$firmaPng]);
    echo json_encode(['ok' => true]);
    exit;
}

// ============================================================
// APODERADO
// ============================================================

if ($accion === 'crear_desarrollador') {
    $hayFirma = $pdo->query('SELECT COUNT(*) AS t FROM contrato_firma_cliente')->fetch()['t'];
    if ($hayFirma == 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Para crear la clave de Apoderado primero hay que firmar el contrato de servicio.']);
        exit;
    }

    $hay = $pdo->query('SELECT COUNT(*) AS t FROM desarrollador')->fetch()['t'];
    if ($hay > 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe una clave de apoderado configurada.']);
        exit;
    }
    $clave = trim($input['clave'] ?? '');
    if (!pinValido($clave)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La clave debe tener exactamente ' . LARGO_PIN . ' números.']);
        exit;
    }
    $hash = password_hash($clave . APP_SECRET, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO desarrollador (clave_hash) VALUES (?)')->execute([$hash]);

    $_SESSION['autenticado'] = true;
    $_SESSION['usuario_id'] = null;
    $_SESSION['nombre_usuario'] = 'Apoderado';
    $_SESSION['rol'] = 'desarrollador';

    echo json_encode(['ok' => true, 'rol' => 'desarrollador', 'nombre_usuario' => 'Apoderado']);
    exit;
}

if ($accion === 'verificar_desarrollador') {
    aplicarBloqueoFuerzaBruta('dev_intentos_fallidos', 'dev_bloqueado_hasta');

    $clave = trim($input['clave'] ?? '');
    $fila = $pdo->query('SELECT * FROM desarrollador ORDER BY id ASC LIMIT 1')->fetch();

    if (!$fila || !password_verify($clave . APP_SECRET, $fila['clave_hash'])) {
        registrarIntentoFallido('dev_intentos_fallidos', 'dev_bloqueado_hasta');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Clave incorrecta.']);
        exit;
    }

    $_SESSION['autenticado'] = true;
    $_SESSION['usuario_id'] = null;
    $_SESSION['nombre_usuario'] = 'Apoderado';
    $_SESSION['rol'] = 'desarrollador';
    $_SESSION['dev_intentos_fallidos'] = 0;

    echo json_encode(['ok' => true, 'rol' => 'desarrollador', 'nombre_usuario' => 'Apoderado']);
    exit;
}

// ------------------------------------------------------------
// GESTIÓN DE SEDES (solo apoderado)
// ------------------------------------------------------------
if ($accion === 'crear_sede') {
    requiereDesarrollador();
    $nombre = trim($input['nombre'] ?? '');
    if ($nombre === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre de la sede.']);
        exit;
    }

    // Si ya existe una sede con ese nombre pero está desactivada,
    // la reactivamos en vez de fallar por el nombre duplicado.
    $stmtExistente = $pdo->prepare('SELECT id, activa FROM sedes WHERE nombre = ?');
    $stmtExistente->execute([$nombre]);
    $existente = $stmtExistente->fetch();

    if ($existente && $existente['activa']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe una sede activa con ese nombre.']);
        exit;
    }

    if ($existente && !$existente['activa']) {
        $pdo->prepare('UPDATE sedes SET activa = 1 WHERE id = ?')->execute([$existente['id']]);
        registrarAuditoria($pdo, 'crear', 'sede', $existente['id'], "Se reactivó la sede \"$nombre\".");
        echo json_encode(['ok' => true, 'id' => $existente['id']]);
        exit;
    }

    $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)')->execute([$nombre]);
    registrarAuditoria($pdo, 'crear', 'sede', $pdo->lastInsertId(), "Se creó la sede \"$nombre\".");
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    exit;
}

// ------------------------------------------------------------
// RENOMBRAR SEDE — solo apoderado. Como pacientes y
// profesionales se vinculan por sede_id (no por el nombre como
// texto copiado), cambiar el nombre aquí actualiza automática-
// mente cómo se ve en todos lados, sin tocar ni perder ningún
// legajo existente.
// ------------------------------------------------------------
// ------------------------------------------------------------
// CAMBIAR NOMBRE DE LA INSTITUCIÓN — solo apoderado. Este
// nombre reemplaza "Del Austral" en toda la interfaz (título,
// login, PDF exportados, app instalable).
// ------------------------------------------------------------
if ($accion === 'cambiar_nombre_institucion') {
    requiereDesarrollador();
    $nombreNuevo = trim($input['nombre'] ?? '');

    if ($nombreNuevo === '' || mb_strlen($nombreNuevo, 'UTF-8') > 150) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingresá un nombre válido (hasta 150 caracteres).']);
        exit;
    }

    $existe = $pdo->query('SELECT id FROM configuracion_institucion LIMIT 1')->fetch();
    if ($existe) {
        $pdo->prepare('UPDATE configuracion_institucion SET nombre_institucion = ? WHERE id = ?')->execute([$nombreNuevo, $existe['id']]);
    } else {
        $pdo->prepare('INSERT INTO configuracion_institucion (nombre_institucion) VALUES (?)')->execute([$nombreNuevo]);
    }

    registrarAuditoria($pdo, 'editar', 'institucion', 0, "Se cambió el nombre de la institución a \"$nombreNuevo\".");
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'renombrar_sede') {
    requiereDesarrollador();
    $id = $input['id'] ?? 0;
    $nombreNuevo = trim($input['nombre'] ?? '');

    if ($nombreNuevo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingresá el nuevo nombre de la sede.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT nombre FROM sedes WHERE id = ?');
    $stmt->execute([$id]);
    $sedeActual = $stmt->fetch();
    if (!$sedeActual) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Esa sede no existe.']);
        exit;
    }

    $stmtDuplicado = $pdo->prepare('SELECT id FROM sedes WHERE nombre = ? AND activa = 1 AND id != ?');
    $stmtDuplicado->execute([$nombreNuevo, $id]);
    if ($stmtDuplicado->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe otra sede activa con ese nombre.']);
        exit;
    }

    $pdo->prepare('UPDATE sedes SET nombre = ? WHERE id = ?')->execute([$nombreNuevo, $id]);
    registrarAuditoria($pdo, 'editar', 'sede', $id, "Se renombró la sede de \"{$sedeActual['nombre']}\" a \"$nombreNuevo\".");
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'listar_sedes') {
    requiereDesarrollador();
    $stmt = $pdo->query('SELECT * FROM sedes WHERE activa = 1 ORDER BY nombre ASC');
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

if ($accion === 'desactivar_sede') {
    requiereDesarrollador();
    $id = $input['id'] ?? 0;
    $pdo->prepare('UPDATE sedes SET activa = 0 WHERE id = ?')->execute([$id]);
    registrarAuditoria($pdo, 'desactivar', 'sede', $id, 'Se desactivó una sede.');
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// PAPELERA (APODERADO): listar profesionales de una sede,
// para elegir de quién ver la papelera y a quién reasignar.
// (Reutiliza la misma idea que listar_profesionales_sede_login,
// pero accesible solo para el Apoderado.)
// ------------------------------------------------------------
if ($accion === 'listar_profesionales_sede_dev') {
    requiereDesarrollador();
    $sedeId = $input['sede_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT u.id, u.nombre_completo FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
        ORDER BY u.nombre_completo ASC
    ');
    $stmt->execute([$sedeId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// PAPELERA (APODERADO): ver los legajos eliminados de un
// profesional específico (GET vía POST con accion en el body,
// igual que el resto de este archivo).
// ------------------------------------------------------------
if ($accion === 'listar_papelera_dev') {
    requiereDesarrollador();
    $profesionalId = $input['profesional_id'] ?? 0;
    $sedeId = $input['sede_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT id, paciente_id_original, nombre_completo, dni, eliminado_en, sede_id_original
        FROM legajos_eliminados
        WHERE profesional_id_original = ? AND sede_id_original = ?
        ORDER BY eliminado_en DESC
    ');
    $stmt->execute([$profesionalId, $sedeId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// PAPELERA (APODERADO): recuperar un legajo eliminado,
// asignándolo a un profesional de la MISMA sede donde estaba
// originalmente (no se permite recuperar hacia otra sede, para
// evitar que un paciente "viaje" de sede sin que el Apoderado
// lo haga a propósito desde la ficha del paciente ya recuperado).
// ------------------------------------------------------------
if ($accion === 'recuperar_legajo_dev') {
    requiereDesarrollador();
    $idPapelera = $input['id'] ?? 0;
    $nuevoProfesionalId = $input['profesional_id'] ?? 0;

    $stmt = $pdo->prepare('SELECT * FROM legajos_eliminados WHERE id = ?');
    $stmt->execute([$idPapelera]);
    $registro = $stmt->fetch();
    if (!$registro) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese registro de la papelera no existe.']);
        exit;
    }

    $sedeOriginalId = $registro['sede_id_original'];

    // Confirmar que el nuevo profesional realmente atienda en esa misma sede.
    $stmtCheck = $pdo->prepare('
        SELECT u.nombre_completo FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE u.id = ? AND u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
    ');
    $stmtCheck->execute([$nuevoProfesionalId, $sedeOriginalId]);
    $nuevoProfesional = $stmtCheck->fetch();
    if (!$nuevoProfesional) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ese profesional no atiende en la sede donde estaba este legajo.']);
        exit;
    }

    $datos = json_decode($registro['datos_json'], true);
    $sesionesGuardadas = $datos['sesiones'] ?? [];
    unset($datos['sesiones'], $datos['edad']); // no son columnas de la tabla pacientes

    // Si el legajo se está asignando a un profesional DISTINTO del
    // que lo tenía antes de eliminarlo, guardamos el nombre del
    // profesional original para que quede visible en la ficha.
    $nombreProfesionalAnterior = null;
    $profesionalOriginalId = $registro['profesional_id_original'];
    if ($profesionalOriginalId && (int) $profesionalOriginalId !== (int) $nuevoProfesionalId) {
        $stmtAnterior = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = ?');
        $stmtAnterior->execute([$profesionalOriginalId]);
        $filaAnterior = $stmtAnterior->fetch();
        if ($filaAnterior) {
            $nombreProfesionalAnterior = $filaAnterior['nombre_completo'];
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO pacientes
            (profesional_id, sede_id, recuperado_de_profesional, nombre, apellido, dni, fecha_nacimiento, sexo, obra_social_id, numero_afiliado,
             telefono, email, direccion, motivo_consulta, patologia, sintomas, observaciones_generales)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $nuevoProfesionalId,
            $sedeOriginalId,
            $nombreProfesionalAnterior,
            $datos['nombre'] ?? '',
            $datos['apellido'] ?? '',
            $datos['dni'] ?? '',
            $datos['fecha_nacimiento'] ?? null,
            $datos['sexo'] ?? 'Otro',
            $datos['obra_social_id'] ?? null,
            $datos['numero_afiliado'] ?? null,
            $datos['telefono'] ?? null,
            $datos['email'] ?? null,
            $datos['direccion'] ?? null,
            $datos['motivo_consulta'] ?? null,
            $datos['patologia'] ?? null,
            $datos['sintomas'] ?? null,
            $datos['observaciones_generales'] ?? null,
        ]);
        $nuevoPacienteId = $pdo->lastInsertId();

        if (!empty($sesionesGuardadas) && is_array($sesionesGuardadas)) {
            $stmtSesion = $pdo->prepare('INSERT INTO sesiones (paciente_id, fecha_sesion, descripcion, evolucion, proxima_cita) VALUES (?, ?, ?, ?, ?)');
            foreach ($sesionesGuardadas as $s) {
                $stmtSesion->execute([
                    $nuevoPacienteId,
                    $s['fecha_sesion'] ?? date('Y-m-d'),
                    $s['descripcion'] ?? '',
                    $s['evolucion'] ?? null,
                    $s['proxima_cita'] ?? null,
                ]);
            }
        }

        $pdo->prepare('DELETE FROM legajos_eliminados WHERE id = ?')->execute([$idPapelera]);

        $pdo->commit();
        registrarAuditoria(
            $pdo, 'crear', 'paciente', $nuevoPacienteId,
            "Se recuperó de la papelera el legajo de {$datos['nombre']} {$datos['apellido']}, asignado a {$nuevoProfesional['nombre_completo']}."
        );
        echo json_encode(['ok' => true, 'id' => $nuevoPacienteId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo recuperar el legajo.']);
    }
    exit;
}

// ------------------------------------------------------------
// PAPELERA DE DERIVACIONES (APODERADO): ver los resúmenes
// de derivación eliminados de un profesional, filtrados por
// sede (mismo patrón que la papelera de pacientes).
// ------------------------------------------------------------
if ($accion === 'listar_papelera_derivaciones_dev') {
    requiereDesarrollador();
    $profesionalId = $input['profesional_id'] ?? 0;
    $sedeId = $input['sede_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT id, derivacion_id_original, nombre_completo, dni, eliminado_en, sede_id_original
        FROM derivaciones_eliminadas
        WHERE profesional_id_original = ? AND sede_id_original = ?
        ORDER BY eliminado_en DESC
    ');
    $stmt->execute([$profesionalId, $sedeId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// RECUPERAR RESUMEN DE DERIVACIÓN (APODERADO): reasigna a
// un profesional de la misma sede donde estaba originalmente.
// ------------------------------------------------------------
if ($accion === 'recuperar_derivacion_dev') {
    requiereDesarrollador();
    $idPapelera = $input['id'] ?? 0;
    $nuevoProfesionalId = $input['profesional_id'] ?? 0;

    $stmt = $pdo->prepare('SELECT * FROM derivaciones_eliminadas WHERE id = ?');
    $stmt->execute([$idPapelera]);
    $registro = $stmt->fetch();
    if (!$registro) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese registro de la papelera no existe.']);
        exit;
    }

    $sedeOriginalId = $registro['sede_id_original'];

    $stmtCheck = $pdo->prepare('
        SELECT u.nombre_completo FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE u.id = ? AND u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
    ');
    $stmtCheck->execute([$nuevoProfesionalId, $sedeOriginalId]);
    $nuevoProfesional = $stmtCheck->fetch();
    if (!$nuevoProfesional) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ese profesional no atiende en la sede donde estaba este resumen.']);
        exit;
    }

    $datos = json_decode($registro['datos_json'], true);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO resumenes_derivacion
            (profesional_id, sede_id, paciente_id, nombre_completo, dni, motivo_consulta, diagnostico, tratamiento_actual, destinatario, observaciones)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $nuevoProfesionalId,
            $sedeOriginalId,
            $datos['paciente_id'] ?? null,
            $datos['nombre_completo'] ?? '',
            $datos['dni'] ?? '',
            $datos['motivo_consulta'] ?? null,
            $datos['diagnostico'] ?? null,
            $datos['tratamiento_actual'] ?? null,
            $datos['destinatario'] ?? null,
            $datos['observaciones'] ?? null,
        ]);
        $nuevoId = $pdo->lastInsertId();

        $pdo->prepare('DELETE FROM derivaciones_eliminadas WHERE id = ?')->execute([$idPapelera]);

        $pdo->commit();
        registrarAuditoria(
            $pdo, 'crear', 'derivacion', $nuevoId,
            "Se recuperó de la papelera el resumen de derivación de {$datos['nombre_completo']}, asignado a {$nuevoProfesional['nombre_completo']}."
        );
        echo json_encode(['ok' => true, 'id' => $nuevoId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo recuperar el resumen.']);
    }
    exit;
}

// ------------------------------------------------------------
// BUSCAR PROFESIONALES (APODERADO) — búsqueda unificada
// por nombre, apellido, DNI o número de legajo.
// ------------------------------------------------------------
if ($accion === 'buscar_profesionales') {
    requiereDesarrollador();
    $q = trim($input['q'] ?? '');
    if ($q === '') {
        echo json_encode(['ok' => true, 'datos' => []]);
        exit;
    }
    $like = "%$q%";
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre_completo, u.activo, u.estado_licencia, u.creado_en,
               u.licencia_dias, u.licencia_inicio,
               CASE WHEN u.licencia_dias IS NULL THEN NULL
                    ELSE DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY)
               END AS licencia_vencimiento,
               pl.numero_legajo, pl.titulo, pl.especialidad, pl.dni
        FROM usuarios u
        INNER JOIN profesionales_legajos pl ON pl.usuario_id = u.id
        WHERE u.rol = 'profesional'
          AND (
              u.nombre_completo LIKE ?
              OR pl.nombre LIKE ?
              OR pl.apellido LIKE ?
              OR pl.dni LIKE ?
              OR pl.numero_legajo LIKE ?
          )
        ORDER BY pl.apellido ASC, pl.nombre ASC
        LIMIT 30
    ");
    $stmt->execute([$like, $like, $like, $like, $like]);
    $resultados = $stmt->fetchAll();

    $stmtSedes = $pdo->prepare('SELECT s.id, s.nombre FROM sedes s INNER JOIN usuarios_sedes us ON us.sede_id = s.id WHERE us.usuario_id = ?');
    foreach ($resultados as &$r) {
        $stmtSedes->execute([$r['id']]);
        $r['sedes'] = $stmtSedes->fetchAll();
    }

    echo json_encode(['ok' => true, 'datos' => $resultados]);
    exit;
}

// ------------------------------------------------------------
// PROFESIONALES DESACTIVADOS (APODERADO): listar usuarios
// con rol profesional que ya no tienen acceso (activo = 0), para
// poder ver y reasignar los legajos que les quedaron a cargo.
// ------------------------------------------------------------
if ($accion === 'listar_profesionales_desactivados') {
    requiereDesarrollador();
    $stmt = $pdo->query("
        SELECT u.id, u.nombre_completo,
               (SELECT COUNT(*) FROM pacientes p WHERE p.profesional_id = u.id) AS total_pacientes
        FROM usuarios u
        WHERE u.rol = 'profesional' AND u.activo = 0
        HAVING total_pacientes > 0
        ORDER BY u.nombre_completo ASC
    ");
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// LEGAJOS ACTIVOS DE UN PROFESIONAL DESACTIVADO (APODERADO)
// ------------------------------------------------------------
if ($accion === 'listar_legajos_huerfanos') {
    requiereDesarrollador();
    $profesionalId = $input['profesional_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT p.id, p.nombre, p.apellido, p.dni, p.sede_id, s.nombre AS sede_nombre
        FROM pacientes p
        LEFT JOIN sedes s ON s.id = p.sede_id
        WHERE p.profesional_id = ?
        ORDER BY p.apellido ASC, p.nombre ASC
    ');
    $stmt->execute([$profesionalId]);
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// REASIGNAR LEGAJO HUÉRFANO (APODERADO): transfiere un
// paciente activo a otro profesional de la MISMA sede donde
// está el paciente (misma regla que la papelera: no se permite
// cambiar de sede de paso, solo de profesional).
// ------------------------------------------------------------
if ($accion === 'reasignar_legajo_huerfano') {
    requiereDesarrollador();
    $pacienteId = $input['paciente_id'] ?? 0;
    $nuevoProfesionalId = $input['profesional_id'] ?? 0;

    $stmtPaciente = $pdo->prepare('SELECT * FROM pacientes WHERE id = ?');
    $stmtPaciente->execute([$pacienteId]);
    $paciente = $stmtPaciente->fetch();
    if (!$paciente) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese paciente no existe.']);
        exit;
    }

    $stmtCheck = $pdo->prepare('
        SELECT u.nombre_completo FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE u.id = ? AND u.rol = "profesional" AND u.activo = 1 AND us.sede_id = ?
    ');
    $stmtCheck->execute([$nuevoProfesionalId, $paciente['sede_id']]);
    $nuevoProfesional = $stmtCheck->fetch();
    if (!$nuevoProfesional) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ese profesional no atiende en la sede de este paciente.']);
        exit;
    }

    // Si el profesional anterior queda registrado, lo guardamos
    // como "recuperado_de_profesional" igual que en la papelera,
    // para que quede el mismo aviso en la ficha del paciente.
    $nombreProfesionalAnterior = null;
    if ($paciente['profesional_id'] && (int) $paciente['profesional_id'] !== (int) $nuevoProfesionalId) {
        $stmtAnterior = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = ?');
        $stmtAnterior->execute([$paciente['profesional_id']]);
        $filaAnterior = $stmtAnterior->fetch();
        if ($filaAnterior) $nombreProfesionalAnterior = $filaAnterior['nombre_completo'];
    }

    try {
        $pdo->prepare('UPDATE pacientes SET profesional_id = ?, recuperado_de_profesional = ? WHERE id = ?')
            ->execute([$nuevoProfesionalId, $nombreProfesionalAnterior, $pacienteId]);

        registrarAuditoria(
            $pdo, 'editar', 'paciente', $pacienteId,
            "Se transfirió el legajo de {$paciente['nombre']} {$paciente['apellido']} a {$nuevoProfesional['nombre_completo']} (profesional anterior desactivado)."
        );
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'El profesional elegido ya tiene un paciente con ese mismo DNI.']);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo transferir el legajo.']);
        }
    }
    exit;
}

// ------------------------------------------------------------
// CONFIGURACIÓN INICIAL: crea la primera sede y el primer
// profesional en un solo paso, justo después de crear la
// clave de Apoderado.
// ------------------------------------------------------------
if ($accion === 'crear_setup_inicial') {
    requiereDesarrollador();
    $nombreSede = trim($input['nombre_sede'] ?? '');
    $nombreProfesional = trim($input['nombre_profesional'] ?? '');
    $pin = trim($input['pin'] ?? '');

    if ($nombreSede === '' || $nombreProfesional === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Completá el nombre de la sede y del profesional.']);
        exit;
    }
    if (!pinValido($pin)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El PIN debe tener exactamente ' . LARGO_PIN . ' números.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)')->execute([$nombreSede]);
        $sedeId = $pdo->lastInsertId();

        $hash = password_hash($pin . APP_SECRET, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO usuarios (nombre_completo, rol, patron_hash) VALUES (?, "profesional", ?)')
            ->execute([$nombreProfesional, $hash]);
        $usuarioId = $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO usuarios_sedes (usuario_id, sede_id) VALUES (?, ?)')->execute([$usuarioId, $sedeId]);

        $pdo->commit();
        registrarAuditoria($pdo, 'crear', 'sede', $sedeId, "Configuración inicial: sede \"$nombreSede\" y profesional \"$nombreProfesional\".");
        echo json_encode(['ok' => true, 'sede_id' => $sedeId, 'usuario_id' => $usuarioId]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo completar la configuración inicial.']);
    }
    exit;
}

// ------------------------------------------------------------
// CREAR USUARIO (profesional o administrativa) — solo apoderado
// ------------------------------------------------------------
// ------------------------------------------------------------
// CREAR PROFESIONAL (legajo completo) — solo apoderado.
// Reemplaza el alta simple anterior: ahora incluye datos
// personales, especialidad, sede(s), PIN y tipo de licencia.
// Para administrativas se mantiene el alta simple de antes.
// ------------------------------------------------------------
if ($accion === 'crear_usuario') {
    requiereDesarrollador();
    $rol = $input['rol'] ?? '';
    $pin = trim($input['pin'] ?? '');
    $sedeIds = $input['sede_ids'] ?? [];

    if (!in_array($rol, ['profesional', 'administrativa'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Rol no válido.']);
        exit;
    }
    if (!pinValido($pin)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El PIN debe tener exactamente ' . LARGO_PIN . ' números.']);
        exit;
    }
    if (empty($sedeIds) || !is_array($sedeIds)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Elegí al menos una sede.']);
        exit;
    }

    if ($rol === 'profesional') {
        $titulo = $input['titulo'] ?? 'Dr.';
        $nombre = trim($input['nombre'] ?? '');
        $apellido = trim($input['apellido'] ?? '');
        $dni = trim($input['dni'] ?? '') ?: null;
        $fechaNac = $input['fecha_nacimiento'] ?? null ?: null;
        $lugarNac = trim($input['lugar_nacimiento'] ?? '') ?: null;
        $especialidad = trim($input['especialidad'] ?? '') ?: null;
        $matriculaNacional = trim($input['matricula_nacional'] ?? '') ?: null;
        $matriculaProvincial = trim($input['matricula_provincial'] ?? '') ?: null;
        $email = trim($input['email'] ?? '') ?: null;
        $telefono = trim($input['telefono'] ?? '') ?: null;
        $licenciaDias = $input['licencia_dias'] !== '' ? (int) $input['licencia_dias'] : null;

        if ($nombre === '' || $apellido === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre y apellido del profesional.']);
            exit;
        }

        $titulos = ['Dr.', 'Dra.', 'Lic.', 'Tec.', 'Mg.', 'Prof.', 'Otro'];
        if (!in_array($titulo, $titulos)) $titulo = 'Dr.';

        $nombreCompleto = "$titulo $nombre $apellido";
        $hash = password_hash($pin . APP_SECRET, PASSWORD_BCRYPT);
        $selloAutomatico = generarSelloAutomatico($titulo, $nombre, $apellido, $especialidad, $matriculaNacional, $matriculaProvincial);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre_completo, rol, patron_hash, estado_licencia, licencia_dias, licencia_inicio) VALUES (?, "profesional", ?, "activo", ?, CURDATE())');
            $stmt->execute([$nombreCompleto, $hash, $licenciaDias]);
            $nuevoId = $pdo->lastInsertId();

            $numeroLegajo = generarNumeroLegajo($pdo);
            $stmtLegajo = $pdo->prepare('
                INSERT INTO profesionales_legajos
                (usuario_id, numero_legajo, titulo, nombre, apellido, dni, fecha_nacimiento, lugar_nacimiento, especialidad, matricula_nacional, matricula_provincial, email, telefono, firma_digital)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtLegajo->execute([$nuevoId, $numeroLegajo, $titulo, $nombre, $apellido, $dni, $fechaNac, $lugarNac, $especialidad, $matriculaNacional, $matriculaProvincial, $email, $telefono, $selloAutomatico]);

            $stmtSede = $pdo->prepare('INSERT INTO usuarios_sedes (usuario_id, sede_id) VALUES (?, ?)');
            foreach ($sedeIds as $sid) $stmtSede->execute([$nuevoId, $sid]);

            $pdo->commit();
            registrarAuditoria($pdo, 'crear', 'usuario', $nuevoId, "Se creó el legajo del profesional \"$nombreCompleto\".");
            echo json_encode(['ok' => true, 'id' => $nuevoId]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo crear el legajo.']);
        }
    } else {
        // Administrativa: alta simple como antes
        $nombre = trim($input['nombre_completo'] ?? '');
        if ($nombre === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre de la administrativa.']);
            exit;
        }
        $hash = password_hash($pin . APP_SECRET, PASSWORD_BCRYPT);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre_completo, rol, patron_hash) VALUES (?, "administrativa", ?)');
            $stmt->execute([$nombre, $hash]);
            $nuevoId = $pdo->lastInsertId();
            $stmtSede = $pdo->prepare('INSERT INTO usuarios_sedes (usuario_id, sede_id) VALUES (?, ?)');
            foreach ($sedeIds as $sid) $stmtSede->execute([$nuevoId, $sid]);
            $pdo->commit();
            registrarAuditoria($pdo, 'crear', 'usuario', $nuevoId, "Se creó la administrativa \"$nombre\".");
            echo json_encode(['ok' => true, 'id' => $nuevoId]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo crear la administrativa.']);
        }
    }
    exit;
}

// ------------------------------------------------------------
// OBTENER LEGAJO DE UN PROFESIONAL — Apoderado y el propio
// profesional pueden consultar.
// ------------------------------------------------------------
// ------------------------------------------------------------
// EDITAR LEGAJO DE PROFESIONAL — solo apoderado. Actualiza
// los datos personales y mantiene sincronizado el nombre que
// se muestra en el login (nombre_completo de usuarios).
// ------------------------------------------------------------
// ------------------------------------------------------------
// GUARDAR FIRMA DIGITAL — el propio profesional sube o dibuja
// su firma (PNG en base64), que se inserta automáticamente al
// pie de cada PDF que exporte de sus pacientes.
// ------------------------------------------------------------
if ($accion === 'guardar_firma_digital') {
    requiereSesion();
    if (($_SESSION['rol'] ?? '') !== 'profesional') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo un profesional puede guardar su propia firma.']);
        exit;
    }
    $usuarioId = (int) $_SESSION['usuario_id'];
    $firmaBase64 = $input['firma'] ?? '';

    if ($firmaBase64 === '') {
        // Permite borrar la firma actual mandando un valor vacío.
        $pdo->prepare('UPDATE profesionales_legajos SET firma_digital = NULL WHERE usuario_id = ?')->execute([$usuarioId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,([A-Za-z0-9+\/=]+)$/', $firmaBase64, $coincidencia)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El archivo tiene que ser una imagen PNG o JPEG válida.']);
        exit;
    }

    // Límite de ~2MB en base64 (≈1.5MB de imagen real), de sobra
    // para una firma y evita que alguien guarde algo gigante.
    if (strlen($firmaBase64) > 2 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'La imagen de la firma es demasiado grande.']);
        exit;
    }

    $pdo->prepare('UPDATE profesionales_legajos SET firma_digital = ? WHERE usuario_id = ?')->execute([$firmaBase64, $usuarioId]);
    registrarAuditoria($pdo, 'editar', 'usuario', $usuarioId, 'Actualizó su firma digital.');
    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'editar_legajo_profesional') {
    requiereDesarrollador();
    $usuarioId = (int) ($input['usuario_id'] ?? 0);
    $titulo = $input['titulo'] ?? 'Dr.';
    $nombre = trim($input['nombre'] ?? '');
    $apellido = trim($input['apellido'] ?? '');
    $dni = trim($input['dni'] ?? '') ?: null;
    $fechaNac = $input['fecha_nacimiento'] ?? null ?: null;
    $lugarNac = trim($input['lugar_nacimiento'] ?? '') ?: null;
    $especialidad = trim($input['especialidad'] ?? '') ?: null;
    $matriculaNacional = trim($input['matricula_nacional'] ?? '') ?: null;
    $matriculaProvincial = trim($input['matricula_provincial'] ?? '') ?: null;
    $email = trim($input['email'] ?? '') ?: null;
    $telefono = trim($input['telefono'] ?? '') ?: null;

    if ($nombre === '' || $apellido === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ingresá el nombre y apellido del profesional.']);
        exit;
    }

    $titulos = ['Dr.', 'Dra.', 'Lic.', 'Tec.', 'Mg.', 'Prof.', 'Otro'];
    if (!in_array($titulo, $titulos)) $titulo = 'Dr.';

    $stmtCheck = $pdo->prepare('SELECT id, firma_digital FROM profesionales_legajos WHERE usuario_id = ?');
    $stmtCheck->execute([$usuarioId]);
    $legajoActual = $stmtCheck->fetch();
    if (!$legajoActual) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese legajo no existe.']);
        exit;
    }

    // Solo regeneramos el sello automático si la firma actual es
    // VACÍA o sigue siendo el sello automático (no si el profesional
    // ya cargó su propia firma manual/escaneada, para no pisarla).
    // El sello automático siempre es un SVG (las firmas dibujadas o
    // subidas son PNG/JPEG), así que primero confirmamos que sea un
    // SVG antes de decodificarlo y buscar la marca interna.
    $firmaActual = $legajoActual['firma_digital'] ?? '';
    $esSvg = is_string($firmaActual) && str_starts_with($firmaActual, 'data:image/svg+xml;base64,');
    $esSelloAutomaticoOVacio = $firmaActual === '' || $firmaActual === null
        || ($esSvg && str_contains(base64_decode(substr($firmaActual, strlen('data:image/svg+xml;base64,'))) ?: '', 'sello-automatico'));

    $pdo->beginTransaction();
    try {
        if ($esSelloAutomaticoOVacio) {
            $selloNuevo = generarSelloAutomatico($titulo, $nombre, $apellido, $especialidad, $matriculaNacional, $matriculaProvincial);
            $stmt = $pdo->prepare('
                UPDATE profesionales_legajos
                SET titulo = ?, nombre = ?, apellido = ?, dni = ?, fecha_nacimiento = ?,
                    lugar_nacimiento = ?, especialidad = ?, matricula_nacional = ?, matricula_provincial = ?,
                    email = ?, telefono = ?, firma_digital = ?
                WHERE usuario_id = ?
            ');
            $stmt->execute([$titulo, $nombre, $apellido, $dni, $fechaNac, $lugarNac, $especialidad, $matriculaNacional, $matriculaProvincial, $email, $telefono, $selloNuevo, $usuarioId]);
        } else {
            $stmt = $pdo->prepare('
                UPDATE profesionales_legajos
                SET titulo = ?, nombre = ?, apellido = ?, dni = ?, fecha_nacimiento = ?,
                    lugar_nacimiento = ?, especialidad = ?, matricula_nacional = ?, matricula_provincial = ?,
                    email = ?, telefono = ?
                WHERE usuario_id = ?
            ');
            $stmt->execute([$titulo, $nombre, $apellido, $dni, $fechaNac, $lugarNac, $especialidad, $matriculaNacional, $matriculaProvincial, $email, $telefono, $usuarioId]);
        }

        $nombreCompleto = "$titulo $nombre $apellido";
        $pdo->prepare('UPDATE usuarios SET nombre_completo = ? WHERE id = ?')->execute([$nombreCompleto, $usuarioId]);

        $pdo->commit();
        registrarAuditoria($pdo, 'editar', 'usuario', $usuarioId, "Se editó el legajo de \"$nombreCompleto\".");
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Ya existe un profesional con ese DNI.']);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el legajo.']);
        }
    }
    exit;
}

if ($accion === 'obtener_legajo_profesional') {
    requiereSesion();
    $rol = $_SESSION['rol'] ?? '';
    $usuarioId = (int) ($input['usuario_id'] ?? 0);

    // El profesional solo puede ver su propio legajo.
    if ($rol === 'profesional') {
        $usuarioId = (int) $_SESSION['usuario_id'];
    } elseif ($rol !== 'desarrollador') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT pl.*, u.estado_licencia, u.licencia_dias, u.licencia_inicio, u.activo,
               CASE
                 WHEN u.licencia_dias IS NULL THEN NULL
                 ELSE DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY)
               END AS licencia_vencimiento
        FROM profesionales_legajos pl
        INNER JOIN usuarios u ON u.id = pl.usuario_id
        WHERE pl.usuario_id = ?
    ');
    $stmt->execute([$usuarioId]);
    $legajo = $stmt->fetch();
    if (!$legajo) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Legajo no encontrado.']);
        exit;
    }
    echo json_encode(['ok' => true, 'datos' => $legajo]);
    exit;
}

// ------------------------------------------------------------
// ACTUALIZAR LICENCIA — solo apoderado.
// Permite activar (con duración), pausar, prohibir el acceso.
// ------------------------------------------------------------
if ($accion === 'actualizar_licencia') {
    requiereDesarrollador();
    $usuarioId = $input['usuario_id'] ?? 0;
    $nuevoEstado = $input['estado'] ?? '';
    $diasLicencia = isset($input['dias']) && $input['dias'] !== '' ? (int) $input['dias'] : null;

    $estadosValidos = ['activo', 'pausado', 'prohibido'];
    if (!in_array($nuevoEstado, $estadosValidos)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Estado no válido.']);
        exit;
    }

    $licenciaInicio = $nuevoEstado === 'activo' ? date('Y-m-d') : null;
    $pdo->prepare('UPDATE usuarios SET estado_licencia = ?, licencia_dias = ?, licencia_inicio = ? WHERE id = ?')
        ->execute([$nuevoEstado, $nuevoEstado === 'activo' ? $diasLicencia : null, $licenciaInicio, $usuarioId]);

    registrarAuditoria($pdo, 'editar', 'usuario', $usuarioId, "Se cambió el estado de licencia a \"$nuevoEstado\"" . ($licenciaInicio && $diasLicencia ? " por $diasLicencia días." : "."));
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// LISTAR USUARIOS (con sus sedes) — solo apoderado.
// Solo se muestran los activos: los desactivados quedan
// fuera de este listado (pero siguen existiendo en la base,
// así que el historial de cambios sigue siendo legible).
// ------------------------------------------------------------
if ($accion === 'listar_usuarios') {
    requiereDesarrollador();
    $stmt = $pdo->query("
        SELECT u.id, u.nombre_completo, u.rol, u.activo, u.creado_en,
               u.estado_licencia, u.licencia_dias, u.licencia_inicio,
               CASE
                 WHEN u.licencia_dias IS NULL THEN NULL
                 ELSE DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY)
               END AS licencia_vencimiento,
               pl.titulo, pl.especialidad
        FROM usuarios u
        LEFT JOIN profesionales_legajos pl ON pl.usuario_id = u.id
        WHERE u.activo = 1
        ORDER BY u.creado_en ASC
    ");
    $usuarios = $stmt->fetchAll();

    $stmtSedes = $pdo->prepare('SELECT s.id, s.nombre FROM sedes s INNER JOIN usuarios_sedes us ON us.sede_id = s.id WHERE us.usuario_id = ?');
    foreach ($usuarios as &$u) {
        $stmtSedes->execute([$u['id']]);
        $u['sedes'] = $stmtSedes->fetchAll();
    }

    echo json_encode(['ok' => true, 'datos' => $usuarios]);
    exit;
}

// ------------------------------------------------------------
// DESACTIVAR USUARIO — solo apoderado
// ------------------------------------------------------------
if ($accion === 'desactivar_usuario') {
    requiereDesarrollador();
    $idDesactivar = $input['id'] ?? 0;
    $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$idDesactivar]);
    registrarAuditoria($pdo, 'desactivar', 'usuario', $idDesactivar, 'Se desactivó el acceso de un usuario.');
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// REACTIVAR USUARIO — solo apoderado. Le devuelve el
// acceso a alguien al que se le había quitado con "Quitar
// acceso". No toca licencia ni sedes, esas se gestionan aparte.
// ------------------------------------------------------------
if ($accion === 'reactivar_usuario') {
    requiereDesarrollador();
    $idReactivar = $input['id'] ?? 0;
    $stmt = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = ?');
    $stmt->execute([$idReactivar]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese usuario no existe.']);
        exit;
    }
    $pdo->prepare('UPDATE usuarios SET activo = 1 WHERE id = ?')->execute([$idReactivar]);
    registrarAuditoria($pdo, 'editar', 'usuario', $idReactivar, "Se restauró el acceso de \"{$fila['nombre_completo']}\".");
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// CAMBIAR PIN — solo apoderado. Reasigna el PIN de acceso
// de un usuario, sin tener que desactivarlo y crearlo de nuevo.
// ------------------------------------------------------------
if ($accion === 'cambiar_pin_usuario') {
    requiereDesarrollador();
    $usuarioId = $input['usuario_id'] ?? 0;
    $pin = trim($input['pin'] ?? '');

    if (!pinValido($pin)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El PIN debe tener exactamente ' . LARGO_PIN . ' números.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ese usuario no existe.']);
        exit;
    }

    $hash = password_hash($pin . APP_SECRET, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE usuarios SET patron_hash = ? WHERE id = ?')->execute([$hash, $usuarioId]);
    registrarAuditoria($pdo, 'editar', 'usuario', $usuarioId, "Se cambió el PIN de acceso de \"{$fila['nombre_completo']}\".");
    echo json_encode(['ok' => true]);
    exit;
}

// ------------------------------------------------------------
// PREVISUALIZAR CAMBIO DE SEDES — solo apoderado.
// Antes de aplicar el cambio real, le dice al Apoderado
// cuántos legajos se van a borrar definitivamente por cada
// sede que se le esté quitando al usuario.
// ------------------------------------------------------------
if ($accion === 'previsualizar_cambio_sedes') {
    requiereDesarrollador();
    $usuarioId = $input['usuario_id'] ?? 0;
    $sedeIdsNuevas = $input['sede_ids'] ?? [];

    $stmtActuales = $pdo->prepare('SELECT sede_id FROM usuarios_sedes WHERE usuario_id = ?');
    $stmtActuales->execute([$usuarioId]);
    $sedeIdsActuales = array_column($stmtActuales->fetchAll(), 'sede_id');

    $sedeIdsQueSeQuitan = array_diff($sedeIdsActuales, $sedeIdsNuevas);

    $detalle = [];
    $totalPacientesABorrar = 0;
    if (!empty($sedeIdsQueSeQuitan)) {
        $stmtSede = $pdo->prepare('SELECT nombre FROM sedes WHERE id = ?');
        $stmtConteo = $pdo->prepare('SELECT COUNT(*) AS t FROM pacientes WHERE profesional_id = ? AND sede_id = ?');
        foreach ($sedeIdsQueSeQuitan as $sedeId) {
            $stmtSede->execute([$sedeId]);
            $nombreSede = $stmtSede->fetch()['nombre'] ?? 'Sede desconocida';
            $stmtConteo->execute([$usuarioId, $sedeId]);
            $cantidad = (int) $stmtConteo->fetch()['t'];
            $totalPacientesABorrar += $cantidad;
            $detalle[] = ['sede_id' => (int) $sedeId, 'nombre' => $nombreSede, 'pacientes' => $cantidad];
        }
    }

    echo json_encode(['ok' => true, 'sedes_que_se_quitan' => $detalle, 'total_pacientes_a_borrar' => $totalPacientesABorrar]);
    exit;
}

// ------------------------------------------------------------
// ACTUALIZAR SEDES DE UN USUARIO — solo apoderado.
// Aplica el nuevo conjunto de sedes. Si se le quita una sede
// donde el usuario es profesional y tenía pacientes, esos
// legajos (con sus sesiones, citas y adjuntos) se eliminan
// definitivamente, sin pasar por la papelera.
// ------------------------------------------------------------
if ($accion === 'actualizar_sedes_usuario') {
    requiereDesarrollador();
    $usuarioId = $input['usuario_id'] ?? 0;
    $sedeIdsNuevas = $input['sede_ids'] ?? [];

    if (empty($sedeIdsNuevas) || !is_array($sedeIdsNuevas)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Elegí al menos una sede para esta persona.']);
        exit;
    }

    $stmtUsuario = $pdo->prepare('SELECT nombre_completo, rol FROM usuarios WHERE id = ?');
    $stmtUsuario->execute([$usuarioId]);
    $usuario = $stmtUsuario->fetch();
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado.']);
        exit;
    }

    $stmtActuales = $pdo->prepare('SELECT sede_id FROM usuarios_sedes WHERE usuario_id = ?');
    $stmtActuales->execute([$usuarioId]);
    $sedeIdsActuales = array_column($stmtActuales->fetchAll(), 'sede_id');
    $sedeIdsQueSeQuitan = array_diff($sedeIdsActuales, $sedeIdsNuevas);

    $pdo->beginTransaction();
    try {
        // Si es profesional, borrar definitivamente sus legajos
        // de cada sede que se le esté quitando.
        $pacientesBorrados = 0;
        if ($usuario['rol'] === 'profesional' && !empty($sedeIdsQueSeQuitan)) {
            $stmtPacientesSede = $pdo->prepare('SELECT id FROM pacientes WHERE profesional_id = ? AND sede_id = ?');
            $stmtBorrarPaciente = $pdo->prepare('DELETE FROM pacientes WHERE id = ?');
            foreach ($sedeIdsQueSeQuitan as $sedeId) {
                $stmtPacientesSede->execute([$usuarioId, $sedeId]);
                $pacientesDeEstaSede = $stmtPacientesSede->fetchAll();
                foreach ($pacientesDeEstaSede as $p) {
                    // Las sesiones, citas y adjuntos de cada paciente se
                    // borran en cascada por las claves foráneas (ON DELETE CASCADE).
                    $stmtBorrarPaciente->execute([$p['id']]);
                    $pacientesBorrados++;
                }
            }
        }

        $pdo->prepare('DELETE FROM usuarios_sedes WHERE usuario_id = ?')->execute([$usuarioId]);
        $stmtInsertSede = $pdo->prepare('INSERT INTO usuarios_sedes (usuario_id, sede_id) VALUES (?, ?)');
        foreach ($sedeIdsNuevas as $sedeId) {
            $stmtInsertSede->execute([$usuarioId, $sedeId]);
        }

        $pdo->commit();

        $descripcion = "Se actualizaron las sedes de \"{$usuario['nombre_completo']}\".";
        if ($pacientesBorrados > 0) {
            $descripcion .= " Se eliminaron $pacientesBorrados legajo(s) de forma definitiva por quitarle acceso a su sede.";
        }
        registrarAuditoria($pdo, 'editar', 'usuario', $usuarioId, $descripcion);

        echo json_encode(['ok' => true, 'pacientes_borrados' => $pacientesBorrados]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar las sedes del usuario.']);
    }
    exit;
}

// ------------------------------------------------------------
// CAMBIAR MI PROPIO PIN (cualquier usuario logueado, incluido
// el apoderado)
// ------------------------------------------------------------
if ($accion === 'cambiar') {
    requiereSesion();
    $pinNuevo = trim($input['pin_nuevo'] ?? '');
    if (!pinValido($pinNuevo)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El PIN debe tener exactamente ' . LARGO_PIN . ' números.']);
        exit;
    }
    $hash = password_hash($pinNuevo . APP_SECRET, PASSWORD_BCRYPT);
    if (esDesarrollador()) {
        $pdo->prepare('UPDATE desarrollador SET clave_hash = ?')->execute([$hash]);
    } else {
        $pdo->prepare('UPDATE usuarios SET patron_hash = ? WHERE id = ?')->execute([$hash, $_SESSION['usuario_id']]);
    }
    echo json_encode(['ok' => true, 'mensaje' => 'PIN actualizado.']);
    exit;
}

// ============================================================
// REPORTES DE BUG (solo Apoderado). El Apoderado puede crear
// reportes y ver el estado de los suyos; la gestión de estado y
// respuesta se hace desde el panel maestro, que se conecta
// puntualmente a esta misma base para leer y actualizar.
// ============================================================

if ($accion === 'crear_reporte_bug') {
    requiereDesarrollador();

    $titulo = trim($input['titulo'] ?? '');
    $descripcion = trim($input['descripcion'] ?? '');
    $severidad = $input['severidad'] ?? 'funcional';
    $sedeNombre = trim($input['sede_nombre'] ?? '') ?: null;

    if ($titulo === '' || $descripcion === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Completá el título y la descripción del problema.']);
        exit;
    }
    if (!in_array($severidad, ['visual', 'funcional', 'critico', 'sugerencia'])) {
        $severidad = 'funcional';
    }

    $pdo->prepare('
        INSERT INTO reportes_bug (titulo, descripcion, severidad, sede_nombre)
        VALUES (?, ?, ?, ?)
    ')->execute([$titulo, $descripcion, $severidad, $sedeNombre]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($accion === 'listar_mis_reportes_bug') {
    requiereDesarrollador();
    $stmt = $pdo->query('SELECT * FROM reportes_bug ORDER BY creado_en DESC LIMIT 100');
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

// ------------------------------------------------------------
// QUIÉN SOY
// ------------------------------------------------------------
if ($accion === 'quien_soy') {
    requiereSesion();
    $nombreParaMostrar = $_SESSION['nombre_usuario'] ?? '';
    if (($_SESSION['rol'] ?? '') === 'administrativa' && !empty($_SESSION['profesional_activo_id'])) {
        $stmt = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = ?');
        $stmt->execute([$_SESSION['profesional_activo_id']]);
        $fila = $stmt->fetch();
        if ($fila) $nombreParaMostrar = $fila['nombre_completo'];
    }
    echo json_encode([
        'ok' => true,
        'nombre_usuario' => $nombreParaMostrar,
        'rol' => $_SESSION['rol'] ?? '',
    ]);
    exit;
}

if ($accion === 'cerrar_sesion') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);

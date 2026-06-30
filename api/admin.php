<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
requiereSesion();

$pdo = obtenerConexion();
$accion = $_GET['accion'] ?? '';

// ------------------------------------------------------------
// HISTORIAL DE CAMBIOS (GET ?accion=historial&pagina=1)
// Solo se muestran cambios sobre pacientes/sesiones que
// pertenecen al profesional activo.
// ------------------------------------------------------------
if ($accion === 'historial') {
    requiereSesion();
    $rol = $_SESSION['rol'] ?? '';
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $porPagina = 40;
    $offset = ($pagina - 1) * $porPagina;
    $soloEntidad = $_GET['entidad'] ?? '';

    if ($rol === 'desarrollador') {
        // El Desarrollador ve TODO el historial, sin acotar a
        // ningún profesional. Puede filtrar opcionalmente por
        // tipo de entidad (ej: "usuario" para ver solo altas,
        // bajas y cambios de licencia de profesionales/administrativas).
        $filtroEntidad = $soloEntidad !== '' ? 'WHERE h.entidad = ?' : '';
        $params = $soloEntidad !== '' ? [$soloEntidad] : [];

        $sql = "SELECT h.* FROM historial_cambios h $filtroEntidad ORDER BY h.creado_en DESC LIMIT $porPagina OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $datos = $stmt->fetchAll();

        $sqlTotal = "SELECT COUNT(*) AS total FROM historial_cambios h $filtroEntidad";
        $stmtTotal = $pdo->prepare($sqlTotal);
        $stmtTotal->execute($params);
        $total = $stmtTotal->fetch()['total'];

        echo json_encode(['ok' => true, 'datos' => $datos, 'total' => (int) $total, 'pagina' => $pagina]);
        exit;
    }

    requiereRolProfesional();
    $profesionalActivoId = idProfesionalActivo();

    $sql = "
        SELECT h.* FROM historial_cambios h
        WHERE
            (h.entidad = 'paciente' AND h.entidad_id IN (SELECT id FROM pacientes WHERE profesional_id = ?))
            OR (h.entidad = 'sesion' AND h.entidad_id IN (
                SELECT s.id FROM sesiones s INNER JOIN pacientes p ON p.id = s.paciente_id WHERE p.profesional_id = ?
            ))
            OR h.usuario_id = ?
        ORDER BY h.creado_en DESC
        LIMIT $porPagina OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$profesionalActivoId, $profesionalActivoId, $_SESSION['usuario_id']]);
    $datos = $stmt->fetchAll();

    $sqlTotal = "
        SELECT COUNT(*) AS total FROM historial_cambios h
        WHERE
            (h.entidad = 'paciente' AND h.entidad_id IN (SELECT id FROM pacientes WHERE profesional_id = ?))
            OR (h.entidad = 'sesion' AND h.entidad_id IN (
                SELECT s.id FROM sesiones s INNER JOIN pacientes p ON p.id = s.paciente_id WHERE p.profesional_id = ?
            ))
            OR h.usuario_id = ?
    ";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute([$profesionalActivoId, $profesionalActivoId, $_SESSION['usuario_id']]);
    $total = $stmtTotal->fetch()['total'];

    echo json_encode(['ok' => true, 'datos' => $datos, 'total' => (int)$total, 'pagina' => $pagina]);
    exit;
}

// ------------------------------------------------------------
// DASHBOARD DE ESTADÍSTICAS (GET ?accion=estadisticas)
// Acotado siempre al profesional activo.
// ------------------------------------------------------------
if ($accion === 'estadisticas') {
    requiereRolProfesional();
    $profesionalActivoId = idProfesionalActivo();
    $stmtTotal = $pdo->prepare('SELECT COUNT(*) AS t FROM pacientes WHERE profesional_id = ?');
    $stmtTotal->execute([$profesionalActivoId]);
    $totalPacientes = $stmtTotal->fetch()['t'];

    $stmtObras = $pdo->prepare('
        SELECT COALESCE(o.nombre, "Sin especificar") AS nombre, COUNT(*) AS total
        FROM pacientes p
        LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
        WHERE p.profesional_id = ?
        GROUP BY o.id
        ORDER BY total DESC
        LIMIT 8
    ');
    $stmtObras->execute([$profesionalActivoId]);
    $porObraSocial = $stmtObras->fetchAll();

    $stmtSesionesMes = $pdo->prepare("
        SELECT COUNT(*) AS t FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE p.profesional_id = ? AND s.fecha_sesion BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
    ");
    $stmtSesionesMes->execute([$profesionalActivoId]);
    $sesionesEsteMes = $stmtSesionesMes->fetch()['t'];

    $stmtSesionesMesAnterior = $pdo->prepare("
        SELECT COUNT(*) AS t FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE p.profesional_id = ?
          AND s.fecha_sesion BETWEEN DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
              AND LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
    ");
    $stmtSesionesMesAnterior->execute([$profesionalActivoId]);
    $sesionesMesAnterior = $stmtSesionesMesAnterior->fetch()['t'];

    $stmtCitasEstado = $pdo->prepare("
        SELECT estado, COUNT(*) AS total FROM citas
        WHERE profesional_id = ? AND fecha BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
        GROUP BY estado
    ");
    $stmtCitasEstado->execute([$profesionalActivoId]);
    $citasPorEstadoEsteMes = $stmtCitasEstado->fetchAll();

    $stmtNuevos = $pdo->prepare("
        SELECT COUNT(*) AS t FROM pacientes
        WHERE profesional_id = ? AND creado_en BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
    ");
    $stmtNuevos->execute([$profesionalActivoId]);
    $pacientesNuevosEsteMes = $stmtNuevos->fetch()['t'];

    $stmtUltimos6 = $pdo->prepare("
        SELECT DATE_FORMAT(s.fecha_sesion, '%Y-%m') AS mes, COUNT(*) AS total
        FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE p.profesional_id = ? AND s.fecha_sesion >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mes
        ORDER BY mes ASC
    ");
    $stmtUltimos6->execute([$profesionalActivoId]);
    $sesionesUltimos6Meses = $stmtUltimos6->fetchAll();

    echo json_encode([
        'ok' => true,
        'datos' => [
            'total_pacientes' => (int) $totalPacientes,
            'pacientes_nuevos_mes' => (int) $pacientesNuevosEsteMes,
            'por_obra_social' => $porObraSocial,
            'sesiones_este_mes' => (int) $sesionesEsteMes,
            'sesiones_mes_anterior' => (int) $sesionesMesAnterior,
            'citas_por_estado_mes' => $citasPorEstadoEsteMes,
            'sesiones_ultimos_6_meses' => $sesionesUltimos6Meses,
        ],
    ]);
    exit;
}

// ------------------------------------------------------------
// REPORTES POR SEDE (GET ?accion=reporte_sedes) — solo
// desarrollador. Resumen agregado de cada sede activa: cuántos
// profesionales atienden ahí, total de pacientes, sesiones de
// este mes y citas por estado de este mes.
// ------------------------------------------------------------
// ------------------------------------------------------------
// LICENCIAS POR VENCER (GET ?accion=licencias_por_vencer) —
// solo desarrollador. Profesionales activos cuya licencia
// vence dentro de los próximos 7 días, para tener el aviso a
// tiempo y no que la suspensión los agarre de sorpresa.
// ------------------------------------------------------------
if ($accion === 'licencias_por_vencer') {
    requiereDesarrollador();
    $stmt = $pdo->query("
        SELECT u.id, u.nombre_completo,
               DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY) AS vencimiento,
               DATEDIFF(DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY), CURDATE()) AS dias_restantes
        FROM usuarios u
        WHERE u.rol = 'profesional' AND u.activo = 1 AND u.estado_licencia = 'activo'
          AND u.licencia_dias IS NOT NULL
          AND DATE_ADD(u.licencia_inicio, INTERVAL u.licencia_dias DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY vencimiento ASC
    ");
    echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
    exit;
}

if ($accion === 'reporte_sedes') {
    requiereDesarrollador();

    $stmtSedes = $pdo->query('SELECT id, nombre FROM sedes WHERE activa = 1 ORDER BY nombre ASC');
    $sedes = $stmtSedes->fetchAll();

    $stmtProfesionales = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id) AS t FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE us.sede_id = ? AND u.rol = 'profesional' AND u.activo = 1
    ");
    $stmtAdministrativas = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id) AS t FROM usuarios u
        INNER JOIN usuarios_sedes us ON us.usuario_id = u.id
        WHERE us.sede_id = ? AND u.rol = 'administrativa' AND u.activo = 1
    ");
    $stmtPacientes = $pdo->prepare('SELECT COUNT(*) AS t FROM pacientes WHERE sede_id = ?');
    $stmtSesionesMes = $pdo->prepare("
        SELECT COUNT(*) AS t FROM sesiones s
        INNER JOIN pacientes p ON p.id = s.paciente_id
        WHERE p.sede_id = ? AND s.fecha_sesion BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
    ");
    $stmtCitasMes = $pdo->prepare("
        SELECT c.estado, COUNT(*) AS total FROM citas c
        INNER JOIN pacientes p ON p.id = c.paciente_id
        WHERE p.sede_id = ? AND c.fecha BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
        GROUP BY c.estado
    ");

    $resultado = [];
    foreach ($sedes as $sede) {
        $stmtProfesionales->execute([$sede['id']]);
        $stmtAdministrativas->execute([$sede['id']]);
        $stmtPacientes->execute([$sede['id']]);
        $stmtSesionesMes->execute([$sede['id']]);
        $stmtCitasMes->execute([$sede['id']]);

        $resultado[] = [
            'id' => $sede['id'],
            'nombre' => $sede['nombre'],
            'profesionales' => (int) $stmtProfesionales->fetch()['t'],
            'administrativas' => (int) $stmtAdministrativas->fetch()['t'],
            'pacientes' => (int) $stmtPacientes->fetch()['t'],
            'sesiones_mes' => (int) $stmtSesionesMes->fetch()['t'],
            'citas_por_estado_mes' => $stmtCitasMes->fetchAll(),
        ];
    }

    echo json_encode(['ok' => true, 'datos' => $resultado]);
    exit;
}

// ------------------------------------------------------------
// VERIFICAR VERSIÓN (GET ?accion=verificar_version) — solo
// desarrollador. Compara el contenido real de los archivos
// críticos del servidor contra los hashes de referencia que
// vinieron en la última entrega (version.json), para detectar
// de un vistazo si algún archivo quedó con una versión vieja
// después de una actualización a medio subir.
// ------------------------------------------------------------
if ($accion === 'verificar_version') {
    requiereDesarrollador();
    $rutaVersion = __DIR__ . '/../version.json';
    if (!file_exists($rutaVersion)) {
        echo json_encode(['ok' => true, 'sin_version_json' => true]);
        exit;
    }

    $referencia = json_decode(file_get_contents($rutaVersion), true);
    $resultado = [];
    $hayDesactualizados = false;

    foreach ($referencia['archivos_criticos'] as $rutaRelativa => $hashEsperado) {
        $rutaAbsoluta = __DIR__ . '/../' . $rutaRelativa;
        if (!file_exists($rutaAbsoluta)) {
            $resultado[] = ['archivo' => $rutaRelativa, 'estado' => 'falta', 'hash_esperado' => $hashEsperado, 'hash_real' => null];
            $hayDesactualizados = true;
            continue;
        }
        $hashReal = md5_file($rutaAbsoluta);
        $actualizado = ($hashReal === $hashEsperado);
        if (!$actualizado) $hayDesactualizados = true;
        $resultado[] = [
            'archivo' => $rutaRelativa,
            'estado' => $actualizado ? 'actualizado' : 'desactualizado',
            'hash_esperado' => $hashEsperado,
            'hash_real' => $hashReal,
        ];
    }

    echo json_encode([
        'ok' => true,
        'version' => $referencia['version'] ?? '?',
        'fecha' => $referencia['fecha'] ?? null,
        'descripcion' => $referencia['descripcion'] ?? null,
        'hay_desactualizados' => $hayDesactualizados,
        'archivos' => $resultado,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Solicitud no válida.']);

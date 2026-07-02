<?php
// ============================================================
// Del Austral — Limpieza de constancias vencidas
// ============================================================
// Pensado para correr una vez al día vía cron. Busca las
// constancias cuyo plazo de 90 días ya venció, guarda un
// rastro MÍNIMO en "constancias_historico" (solo para
// auditoría interna del Apoderado — nunca el contenido
// completo ni visible públicamente), y borra el registro
// original de "constancias".
//
// Cómo programarlo en cron (corre todos los días a las 4 AM):
// 0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_constancias.php >> /var/log/limpiar-constancias.log 2>&1
// ============================================================

require_once __DIR__ . '/config/config.php';

$pdo = obtenerConexion();

function registrarCorridaCron($pdo, $clave) {
    try {
        $pdo->prepare("
            INSERT INTO salud_sistema (clave, valor) VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE valor = NOW()
        ")->execute([$clave]);
    } catch (Throwable $e) {
        // No crítico: si la tabla no existe todavía (instalación
        // sin migrar), el cron sigue funcionando igual, solo no
        // queda registrado para el panel de salud del sistema.
    }
}

$stmtVencidas = $pdo->query("SELECT * FROM constancias WHERE vence_en < CURDATE()");
$vencidas = $stmtVencidas->fetchAll();

if (!$vencidas) {
    echo "[" . date('Y-m-d H:i:s') . "] Sin constancias vencidas para limpiar.\n";
    registrarCorridaCron($pdo, 'cron_constancias_ultima_corrida');
    exit;
}

$stmtInsertarHistorico = $pdo->prepare('
    INSERT INTO constancias_historico (token, tipo, profesional_id, nombre_completo, dni, emitida_en, vencio_en)
    VALUES (?, ?, ?, ?, ?, ?, ?)
');
$stmtBorrar = $pdo->prepare('DELETE FROM constancias WHERE id = ?');

$total = 0;
foreach ($vencidas as $c) {
    $stmtInsertarHistorico->execute([
        $c['token'],
        $c['tipo'],
        $c['profesional_id'],
        $c['nombre_completo'],
        $c['dni'],
        $c['creado_en'],
        $c['vence_en'],
    ]);
    $stmtBorrar->execute([$c['id']]);
    $total++;
}

echo "[" . date('Y-m-d H:i:s') . "] Se limpiaron $total constancias vencidas.\n";
registrarCorridaCron($pdo, 'cron_constancias_ultima_corrida');

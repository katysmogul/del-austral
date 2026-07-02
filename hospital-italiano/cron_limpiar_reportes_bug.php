<?php
// ============================================================
// Del Austral — Limpieza de reportes de bug ya cerrados
// ============================================================
// Pensado para correr una vez al día vía cron. Borra los
// reportes de bug que están en estado "resuelto" o
// "no_resuelto" desde hace más de 7 días. Los reportes nuevos,
// vistos o en curso nunca se tocan, sin importar la antigüedad
// — solo se limpian una vez que el ciclo quedó cerrado.
//
// Cómo programarlo en cron (corre todos los días a las 4 AM):
// 0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_reportes_bug.php >> /var/log/limpiar-reportes-bug.log 2>&1
// ============================================================

require_once __DIR__ . '/config/config.php';

$pdo = obtenerConexion();

$stmtBorrar = $pdo->prepare("
    DELETE FROM reportes_bug
    WHERE estado IN ('resuelto', 'no_resuelto')
    AND actualizado_en < (NOW() - INTERVAL 7 DAY)
");
$stmtBorrar->execute();
$total = $stmtBorrar->rowCount();

echo "[" . date('Y-m-d H:i:s') . "] Se limpiaron $total reportes de bug cerrados hace más de 7 días.\n";

try {
    $pdo->prepare("
        INSERT INTO salud_sistema (clave, valor) VALUES ('cron_reportes_bug_ultima_corrida', NOW())
        ON DUPLICATE KEY UPDATE valor = NOW()
    ")->execute();
} catch (Throwable $e) {
    // No crítico: si la tabla no existe todavía, el cron sigue
    // funcionando igual, solo no queda registrado para el panel
    // de salud del sistema.
}

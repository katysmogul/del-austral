<?php
/**
 * Muestra el listado de todas las facturas de esta institución
 * (una por cada cobro generado), navegables por mes, con un link
 * a cada factura individual (facturas/factura_AAAA-MM_ID.html,
 * ya sincronizada por el panel maestro). Es una página pública,
 * sin login — el Apoderado debe poder acceder a su historial de
 * pagos siempre, sin depender de tener la sesión abierta.
 */

$rutaIndice = __DIR__ . '/facturas/indice.json';
$indice = file_exists($rutaIndice) ? json_decode(file_get_contents($rutaIndice), true) : [];
if (!is_array($indice)) $indice = [];

function eFacturasListado($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

function etiquetaEstadoListado($estado) {
    $mapa = [
        'pendiente' => ['texto' => 'Pendiente de pago', 'color' => '#C97A1F'],
        'comprobante_subido' => ['texto' => 'En revisión', 'color' => '#C97A1F'],
        'aprobado' => ['texto' => 'Pagada', 'color' => '#1FA173'],
        'sin_acreditar' => ['texto' => 'Sin acreditar', 'color' => '#E63757'],
        'rechazado' => ['texto' => 'Rechazada', 'color' => '#E63757'],
        'anulado' => ['texto' => 'Anulada', 'color' => '#6B6459'],
    ];
    return $mapa[$estado] ?? ['texto' => $estado, 'color' => '#6B6459'];
}

function nombreMesLegible($claveMes) {
    $meses = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
    $partes = explode('-', $claveMes);
    if (count($partes) !== 2) return $claveMes;
    return ($meses[$partes[1]] ?? $partes[1]) . ' ' . $partes[0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Facturas</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1A1A1A;
    max-width: 680px;
    margin: 0 auto;
    padding: 40px 24px 60px;
    background: #FDFBF6;
  }
  h1 { font-size: 1.4rem; text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 8px; }
  p.sub { color: #6B6459; margin-bottom: 28px; }
  .fila-factura {
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
    border: 2px solid #1A1A1A; border-radius: 6px;
    padding: 14px 18px; margin-bottom: 10px;
    text-decoration: none; color: #1A1A1A;
    flex-wrap: wrap;
  }
  .fila-factura:hover { background: #FFE3CE; }
  .fila-factura .mes { font-weight: 800; }
  .fila-factura .monto { color: #6B6459; font-size: 0.9rem; }
  .pill-estado { padding: 4px 14px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; color: white; }
  .vacio { text-align: center; padding: 60px 20px; color: #6B6459; border: 2px dashed #EAE3D6; border-radius: 6px; }
</style>
</head>
<body>
  <h1>Facturas</h1>
  <p class="sub">Historial de cobros de esta institución, uno por mes.</p>

  <?php if (empty($indice)): ?>
    <div class="vacio">Todavía no hay ninguna factura generada.</div>
  <?php else: ?>
    <?php foreach ($indice as $f): $estadoInfo = etiquetaEstadoListado($f['estado']); ?>
      <a class="fila-factura" href="facturas/<?= eFacturasListado($f['archivo']) ?>" target="_blank" rel="noopener">
        <div>
          <div class="mes"><?= eFacturasListado(nombreMesLegible($f['mes'])) ?></div>
          <div class="monto">$<?= number_format($f['monto'], 2, ',', '.') ?> <?= eFacturasListado($f['moneda']) ?> · vence <?= eFacturasListado(date('d/m/Y', strtotime($f['vencimiento']))) ?></div>
        </div>
        <span class="pill-estado" style="background:<?= $estadoInfo['color'] ?>;"><?= eFacturasListado($estadoInfo['texto']) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>

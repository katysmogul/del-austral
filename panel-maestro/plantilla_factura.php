<?php
/**
 * ============================================================
 * MÓDULO COMPARTIDO: render de la factura de un cobro (HTML)
 * ============================================================
 * Muestra el detalle de un cobro puntual (monto de lista,
 * descuento, recargo por mora si corresponde, y saldo a favor
 * aplicado) con una marca de agua grande de fondo que dice
 * "PAGADA" o "NO PAGADA" según el estado.
 *
 * La usan:
 *   - factura.php del panel maestro (vista en vivo, con sesión
 *     de Super Admin)
 *   - la sincronización a la carpeta de cada institución, para
 *     que el Apoderado pueda verla sin login desde ver_facturas.php
 *
 * @param array $cobro  Fila de `cobros` + nombre_institucion (JOIN)
 * @return string HTML completo
 */

if (!defined('RECARGO_MORA_DIARIO_PCT')) {
    define('RECARGO_MORA_DIARIO_PCT', 2.5);
    define('IVA_PCT', 21);
}

if (!function_exists('calcularRecargoMora')) {
    /**
     * Recargo por mora: 2,5% del monto por cada día de atraso
     * desde el vencimiento (exclusive), más IVA (21%) sobre ese
     * recargo. Se calcula "en vivo" para cobros todavía sin
     * resolver. Una vez que el cobro queda aprobado, rechazado o
     * anulado, el recargo se "congela" al valor que tenía en ese
     * momento, para que la factura histórica no siga cambiando.
     */
    function calcularRecargoMora($montoBase, $vencimiento, $recargoCongelado = null) {
        if ($recargoCongelado !== null) return (float) $recargoCongelado;

        $hoy = new DateTime('today');
        $fechaVencimiento = new DateTime($vencimiento);
        if ($hoy <= $fechaVencimiento) return 0;

        $diasAtraso = $hoy->diff($fechaVencimiento)->days;
        $recargoSinIva = $montoBase * (RECARGO_MORA_DIARIO_PCT / 100) * $diasAtraso;
        $recargoConIva = $recargoSinIva * (1 + IVA_PCT / 100);
        return round($recargoConIva, 2);
    }
}

function renderizarFacturaHTML(array $cobro): string {

    function eFactura($texto) {
        return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
    }

    function fechaLegibleFactura($fechaIso) {
        if (!$fechaIso) return '—';
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $partes = explode('-', substr($fechaIso, 0, 10));
        if (count($partes) !== 3) return eFactura($fechaIso);
        return (int)$partes[2] . ' de ' . $meses[(int)$partes[1] - 1] . ' de ' . $partes[0];
    }

    function formatoMonedaFactura($monto, $moneda) {
        $simbolos = ['ARS' => '$', 'USD' => 'US$', 'EUR' => '€'];
        $simbolo = $simbolos[$moneda] ?? ($moneda . ' ');
        return $simbolo . number_format((float) $monto, 2, ',', '.');
    }

    $pagada = $cobro['estado'] === 'aprobado';
    $anulada = $cobro['estado'] === 'anulado';
    $marcaAgua = $anulada ? 'ANULADA' : ($pagada ? 'PAGADA' : 'NO PAGADA');
    $colorMarcaAgua = $anulada ? '#6B6459' : ($pagada ? '#1FA173' : '#E63757');

    $mesPeriodo = $cobro['periodo_desde']
        ? ucfirst(fechaLegibleFactura($cobro['periodo_desde']))
        : ucfirst(fechaLegibleFactura($cobro['creado_en']));

    $etiquetasEstado = [
        'pendiente' => ['texto' => 'Pendiente de pago', 'color' => '#C97A1F'],
        'comprobante_subido' => ['texto' => 'Comprobante en revisión', 'color' => '#C97A1F'],
        'aprobado' => ['texto' => 'Pago aprobado', 'color' => '#1FA173'],
        'sin_acreditar' => ['texto' => 'Sin acreditar', 'color' => '#E63757'],
        'rechazado' => ['texto' => 'Rechazado', 'color' => '#E63757'],
        'anulado' => ['texto' => 'Anulada', 'color' => '#6B6459'],
    ];
    $estadoInfo = $etiquetasEstado[$cobro['estado']] ?? ['texto' => $cobro['estado'], 'color' => '#6B6459'];

    $recargo = (float) ($cobro['recargo_mora_vigente'] ?? $cobro['recargo_congelado_monto'] ?? 0);
    $montoConDescuento = (float) $cobro['monto_lista'] * (1 - (float) $cobro['descuento_pct'] / 100);

    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura — <?= eFactura($cobro['institucion_nombre']) ?> — <?= eFactura($mesPeriodo) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1A1A1A;
    max-width: 680px;
    margin: 0 auto;
    padding: 40px 28px 60px;
    background: #FDFBF6;
    position: relative;
    font-size: 0.95rem;
    line-height: 1.5;
  }
  .marca-agua-factura {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
    z-index: -1;
  }
  .marca-agua-factura span {
    font-size: 5.5rem;
    font-weight: 900;
    color: <?= $colorMarcaAgua ?>;
    opacity: 0.14;
    transform: rotate(-22deg);
    letter-spacing: 0.06em;
    white-space: nowrap;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  @media print {
    @page { margin: 16mm; }
    .barra-exportar-factura { display: none !important; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  }
  .barra-exportar-factura {
    background: #1A1A1A; color: white;
    padding: 12px 18px; margin: -40px -28px 26px;
    display: flex; align-items: center; justify-content: space-between;
    font-family: Arial, sans-serif;
  }
  .barra-exportar-factura button {
    background: #FF7A2E; color: #1A1A1A; border: 2px solid #1A1A1A;
    padding: 8px 16px; border-radius: 999px; font-weight: 800;
    cursor: pointer; font-size: 0.85rem;
  }
  .encabezado-factura {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 3px solid #1A1A1A;
    padding-bottom: 16px;
    margin-bottom: 24px;
  }
  .encabezado-factura h1 { font-size: 1.3rem; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.02em; }
  .encabezado-factura .sub { color: #6B6459; font-size: 0.85rem; }
  .estado-pill-factura {
    padding: 5px 16px; border-radius: 999px;
    font-size: 0.75rem; font-weight: 800; color: white;
    background: <?= $estadoInfo['color'] ?>;
  }
  .tabla-detalle-factura { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .tabla-detalle-factura td { padding: 10px 4px; border-bottom: 1px solid #EAE3D6; }
  .tabla-detalle-factura td:last-child { text-align: right; font-weight: 600; }
  .tabla-detalle-factura tr.total td { border-top: 2px solid #1A1A1A; border-bottom: none; font-size: 1.1rem; font-weight: 800; padding-top: 14px; }
  .aviso-recargo-factura {
    background: #FCE4E9; border: 2px solid #E63757; border-radius: 6px;
    padding: 12px 16px; margin-bottom: 20px; font-size: 0.85rem;
  }
  .aviso-saldo-factura {
    background: #E1F5EC; border: 2px solid #1FA173; border-radius: 6px;
    padding: 12px 16px; margin-bottom: 20px; font-size: 0.85rem;
  }
  .pie-factura { margin-top: 30px; padding-top: 14px; border-top: 1px dashed #EAE3D6; font-size: 0.72rem; color: #6B6459; text-align: center; }
</style>
</head>
<body>
  <div class="marca-agua-factura"><span><?= eFactura($marcaAgua) ?></span></div>

  <div class="barra-exportar-factura">
    <span>Vista de factura — usá Ctrl/Cmd + P para guardar como PDF</span>
    <button onclick="window.print()">Guardar como PDF</button>
  </div>

  <div class="encabezado-factura">
    <div>
      <h1>Factura de servicio</h1>
      <div class="sub"><?= eFactura($cobro['institucion_nombre']) ?> · <?= eFactura($mesPeriodo) ?></div>
    </div>
    <span class="estado-pill-factura"><?= eFactura($estadoInfo['texto']) ?></span>
  </div>

  <table class="tabla-detalle-factura">
    <tr>
      <td>Monto de lista</td>
      <td><?= eFactura(formatoMonedaFactura($cobro['monto_lista'], $cobro['moneda'])) ?></td>
    </tr>
    <?php if ((float) $cobro['descuento_pct'] > 0): ?>
    <tr>
      <td>Descuento comercial (<?= eFactura($cobro['descuento_pct']) ?>%)</td>
      <td>- <?= eFactura(formatoMonedaFactura($cobro['monto_lista'] - $montoConDescuento, $cobro['moneda'])) ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($cobro['saldo_favor_aplicado']) && $cobro['saldo_favor_aplicado'] > 0): ?>
    <tr>
      <td>Saldo a favor aplicado</td>
      <td>- <?= eFactura(formatoMonedaFactura($cobro['saldo_favor_aplicado'], $cobro['moneda'])) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($recargo > 0): ?>
    <tr>
      <td>Recargo por mora</td>
      <td>+ <?= eFactura(formatoMonedaFactura($recargo, $cobro['moneda'])) ?></td>
    </tr>
    <?php endif; ?>
    <tr class="total">
      <td>Total<?= $cobro['estado'] === 'anulado' ? ' (anulado)' : '' ?></td>
      <td><?= eFactura(formatoMonedaFactura($cobro['monto'], $cobro['moneda'])) ?></td>
    </tr>
  </table>

  <?php if (!empty($cobro['saldo_favor_aplicado']) && $cobro['saldo_favor_aplicado'] > 0): ?>
  <div class="aviso-saldo-factura">
    Se aplicó un saldo a favor de <?= eFactura(formatoMonedaFactura($cobro['saldo_favor_aplicado'], $cobro['moneda'])) ?> a esta factura.
  </div>
  <?php endif; ?>

  <?php if ($recargo > 0): ?>
  <div class="aviso-recargo-factura">
    Esta factura venció el <?= eFactura(fechaLegibleFactura($cobro['vencimiento'])) ?> y acumuló un recargo por mora del <?= eFactura(RECARGO_MORA_DIARIO_PCT ?? 2.5) ?>% diario más IVA.
  </div>
  <?php endif; ?>

  <?php if ($cobro['estado'] === 'anulado' && !empty($cobro['motivo_anulacion'])): ?>
  <div class="aviso-recargo-factura" style="background:#EFE8E0; border-color:#6B6459;">
    Esta factura fue anulada. Motivo: <?= eFactura($cobro['motivo_anulacion']) ?>
  </div>
  <?php endif; ?>

  <p style="color:#6B6459; font-size:0.85rem;">Vencimiento: <?= eFactura(fechaLegibleFactura($cobro['vencimiento'])) ?></p>

  <div class="pie-factura">Del Austral · Comodoro Rivadavia, Chubut, Argentina</div>

  <script>
    var barra = document.querySelector('.barra-exportar-factura');
    window.addEventListener('beforeprint', function () { if (barra) barra.style.display = 'none'; });
    window.addEventListener('afterprint', function () { if (barra) barra.style.display = 'flex'; });
  </script>
</body>
</html>
<?php
    return ob_get_clean();
}

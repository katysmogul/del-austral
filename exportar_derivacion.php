<?php
require_once __DIR__ . '/config/config.php';

if (empty($_SESSION['autenticado']) || ($_SESSION['rol'] ?? '') !== 'profesional') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1C2421;">
    <h2>No tenés permiso para ver este documento</h2>
    <p style="color:#4A5650;">La exportación de resúmenes de derivación está disponible solo para el usuario profesional.</p>
    </body></html>';
    exit;
}

$pdo = obtenerConexion();
$id = $_GET['id'] ?? 0;
$profesionalActivoId = idProfesionalActivo();

$stmt = $pdo->prepare('
    SELECT d.*, s.nombre AS sede_nombre
    FROM resumenes_derivacion d
    INNER JOIN sedes s ON s.id = d.sede_id
    WHERE d.id = ? AND d.profesional_id = ?
');
$stmt->execute([$id, $profesionalActivoId]);
$derivacion = $stmt->fetch();

if (!$derivacion) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>No encontrado</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1C2421;">
    <h2>Resumen de derivación no encontrado</h2>
    </body></html>';
    exit;
}

$stmtProf = $pdo->prepare('
    SELECT u.nombre_completo, pl.titulo, pl.especialidad, pl.firma_digital
    FROM usuarios u
    LEFT JOIN profesionales_legajos pl ON pl.usuario_id = u.id
    WHERE u.id = ?
');
$stmtProf->execute([$profesionalActivoId]);
$prof = $stmtProf->fetch();

function e($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

function fechaLegible($fechaIso) {
    if (!$fechaIso) return '—';
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $partes = explode('-', substr($fechaIso, 0, 10));
    if (count($partes) !== 3) return e($fechaIso);
    return (int)$partes[2] . ' de ' . $meses[(int)$partes[1] - 1] . ' de ' . $partes[0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen de derivación — <?= e($derivacion['nombre_completo']) ?></title>
<style>
  @media print {
    @page { margin: 16mm 18mm; }
    .barra-exportar { display: none !important; }
    body { padding: 0 !important; }
    .encabezado-doc, .bloque-pdf, .bloque-firma {
      page-break-inside: avoid;
    }
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1C2421;
    max-width: 740px;
    margin: 0 auto;
    padding: 30px 24px 60px;
    line-height: 1.5;
  }
  .barra-exportar {
    position: sticky;
    top: 0;
    background: #3D6B63;
    color: white;
    padding: 14px 20px;
    margin: -30px -24px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: Arial, sans-serif;
  }
  .barra-exportar button {
    background: white;
    color: #2A4B45;
    border: none;
    padding: 10px 20px;
    border-radius: 999px;
    font-weight: 700;
    cursor: pointer;
    font-size: 0.9rem;
  }
  .encabezado-doc {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid #3D6B63;
    padding-bottom: 12px;
    margin-bottom: 26px;
  }
  .encabezado-doc h1 { font-size: 1.4rem; margin: 0 0 4px; color: #2A4B45; }
  .encabezado-doc .profesional { font-size: 0.85rem; color: #4A5650; }
  .encabezado-doc .fecha-emision { font-size: 0.8rem; color: #4A5650; text-align: right; }

  .titulo-doc {
    text-align: center;
    font-size: 1.15rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 22px;
    color: #2A4B45;
  }

  .destinatario-derivacion {
    font-size: 0.92rem;
    color: #4A5650;
    margin-bottom: 24px;
  }

  .grilla-datos-pdf {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 24px;
  }
  .dato-pdf {
    background: #F7F4ED;
    border: 1px solid #DDD6C7;
    border-radius: 8px;
    padding: 10px 12px;
  }
  .dato-pdf .etq { font-size: 0.68rem; text-transform: uppercase; color: #4A5650; font-weight: 700; letter-spacing: 0.04em; }
  .dato-pdf .val { font-size: 0.95rem; font-weight: 600; margin-top: 2px; }

  .bloque-pdf { margin-bottom: 18px; }
  .bloque-pdf h3 {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #2A4B45;
    border-bottom: 1px solid #DDD6C7;
    padding-bottom: 4px;
    margin-bottom: 8px;
  }
  .bloque-pdf p { margin: 0; white-space: pre-wrap; font-size: 0.92rem; }
  .bloque-pdf p.vacio { color: #4A5650; font-style: italic; }

  .bloque-firma {
    margin-top: 40px;
    width: 300px;
    text-align: center;
    margin-left: auto;
    margin-right: auto;
  }
  .imagen-firma {
    max-width: 280px;
    max-height: 110px;
    margin: 0 auto 4px;
    display: block;
  }
  .linea-firma { border-top: 1.5px solid #1C2421; margin-bottom: 8px; }
  .nombre-firma { font-weight: 700; font-size: 0.92rem; }
  .aclaracion-firma { font-size: 0.75rem; color: #4A5650; margin-top: 2px; }

  .pie-pdf {
    margin-top: 40px;
    padding-top: 14px;
    border-top: 1px solid #DDD6C7;
    font-size: 0.72rem;
    color: #4A5650;
    text-align: center;
  }
</style>
</head>
<body>

  <div class="barra-exportar">
    <span>Vista de exportación — usá Ctrl/Cmd + P y elegí "Guardar como PDF"</span>
    <button onclick="window.print()">Imprimir / Guardar como PDF</button>
  </div>

  <div class="encabezado-doc">
    <div>
      <h1>Del Austral</h1>
      <div class="profesional"><?= e($prof['nombre_completo'] ?? '') ?></div>
      <?php if (!empty($prof['especialidad'])): ?>
        <div class="profesional"><?= e($prof['especialidad']) ?></div>
      <?php endif; ?>
      <div class="profesional"><?= e($derivacion['sede_nombre']) ?></div>
    </div>
    <div class="fecha-emision">Documento generado el<br><?= fechaLegible(date('Y-m-d')) ?></div>
  </div>

  <div class="titulo-doc">Resumen de Derivación</div>

  <?php if ($derivacion['destinatario']): ?>
    <div class="destinatario-derivacion">Dirigido a: <strong><?= e($derivacion['destinatario']) ?></strong></div>
  <?php endif; ?>

  <div class="grilla-datos-pdf">
    <div class="dato-pdf"><div class="etq">Paciente</div><div class="val"><?= e($derivacion['nombre_completo']) ?></div></div>
    <div class="dato-pdf"><div class="etq">DNI</div><div class="val"><?= e($derivacion['dni']) ?></div></div>
    <div class="dato-pdf"><div class="etq">Fecha</div><div class="val"><?= fechaLegible(date('Y-m-d')) ?></div></div>
  </div>

  <div class="bloque-pdf">
    <h3>Motivo de consulta</h3>
    <?php if ($derivacion['motivo_consulta']): ?>
      <p><?= nl2br(e($derivacion['motivo_consulta'])) ?></p>
    <?php else: ?>
      <p class="vacio">No se registró información.</p>
    <?php endif; ?>
  </div>

  <div class="bloque-pdf">
    <h3>Diagnóstico</h3>
    <?php if ($derivacion['diagnostico']): ?>
      <p><?= nl2br(e($derivacion['diagnostico'])) ?></p>
    <?php else: ?>
      <p class="vacio">No se registró información.</p>
    <?php endif; ?>
  </div>

  <div class="bloque-pdf">
    <h3>Tratamiento actual</h3>
    <?php if ($derivacion['tratamiento_actual']): ?>
      <p><?= nl2br(e($derivacion['tratamiento_actual'])) ?></p>
    <?php else: ?>
      <p class="vacio">No se registró información.</p>
    <?php endif; ?>
  </div>

  <?php if ($derivacion['observaciones']): ?>
    <div class="bloque-pdf">
      <h3>Observaciones</h3>
      <p><?= nl2br(e($derivacion['observaciones'])) ?></p>
    </div>
  <?php endif; ?>

  <div class="bloque-firma">
    <?php if (!empty($prof['firma_digital'])): ?>
      <img src="<?= e($prof['firma_digital']) ?>" alt="Firma" class="imagen-firma">
    <?php endif; ?>
    <div class="linea-firma"></div>
    <div class="nombre-firma"><?= e($prof['nombre_completo'] ?? '') ?></div>
    <div class="aclaracion-firma">Profesional responsable del seguimiento clínico</div>
  </div>

  <div class="pie-pdf">
    Este documento contiene información personal protegida por la Ley N.º 25.326 de Protección de Datos Personales.
    Documento de uso entre profesionales/instituciones de salud, sin validación pública por token.
  </div>

  <script>
    var barraExportar = document.querySelector('.barra-exportar');
    window.addEventListener('beforeprint', function () { if (barraExportar) barraExportar.style.display = 'none'; });
    window.addEventListener('afterprint', function () { if (barraExportar) barraExportar.style.display = 'flex'; });
  </script>

</body>
</html>

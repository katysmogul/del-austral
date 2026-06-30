<?php
require_once __DIR__ . '/config/config.php';

if (empty($_SESSION['autenticado']) || ($_SESSION['rol'] ?? '') !== 'profesional') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1C2421;">
    <h2>No tenés permiso para ver este documento</h2>
    <p style="color:#4A5650;">La exportación de legajos clínicos está disponible solo para el usuario profesional.</p>
    </body></html>';
    exit;
}

$pdo = obtenerConexion();
$id = $_GET['id'] ?? 0;
$profesionalActivoId = idProfesionalActivo();

function calcularEdadExport($fechaNacimiento) {
    try {
        $nacimiento = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        return $hoy->diff($nacimiento)->y;
    } catch (Exception $e) {
        return null;
    }
}

$stmt = $pdo->prepare('
    SELECT p.*, o.nombre AS obra_social_nombre
    FROM pacientes p
    LEFT JOIN obras_sociales o ON o.id = p.obra_social_id
    WHERE p.id = ? AND p.profesional_id = ?
');
$stmt->execute([$id, $profesionalActivoId]);
$paciente = $stmt->fetch();

if (!$paciente) {
    http_response_code(404);
    echo 'Legajo no encontrado.';
    exit;
}

$stmtSesiones = $pdo->prepare('SELECT * FROM sesiones WHERE paciente_id = ? ORDER BY fecha_sesion ASC');
$stmtSesiones->execute([$id]);
$sesiones = $stmtSesiones->fetchAll();

$stmtNombreProf = $pdo->prepare('
    SELECT u.nombre_completo, pl.firma_digital
    FROM usuarios u
    LEFT JOIN profesionales_legajos pl ON pl.usuario_id = u.id
    WHERE u.id = ?
');
$stmtNombreProf->execute([$profesionalActivoId]);
$filaProf = $stmtNombreProf->fetch();
$nombreProfesional = $filaProf ? $filaProf['nombre_completo'] : '';
$firmaDigital = $filaProf ? ($filaProf['firma_digital'] ?? null) : null;

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

$edad = calcularEdadExport($paciente['fecha_nacimiento']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Legajo — <?= e($paciente['apellido'] . ', ' . $paciente['nombre']) ?></title>
<style>
  @media print {
    @page { margin: 16mm 16mm; }
    .barra-exportar { display: none !important; }
    body { padding: 0 !important; }
    .encabezado-doc, .bloque-pdf, .sesion-pdf, .bloque-firma {
      page-break-inside: avoid;
    }
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1C2421;
    max-width: 780px;
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
    align-items: flex-end;
    border-bottom: 3px solid #3D6B63;
    padding-bottom: 14px;
    margin-bottom: 24px;
  }
  .encabezado-doc h1 { font-size: 1.5rem; margin: 0 0 4px; color: #2A4B45; }
  .encabezado-doc .profesional { font-size: 0.85rem; color: #4A5650; }
  .encabezado-doc .fecha-emision { font-size: 0.8rem; color: #4A5650; text-align: right; }

  h2.titulo-paciente { font-size: 1.3rem; margin: 0 0 4px; }
  .meta-paciente { color: #4A5650; font-size: 0.88rem; margin-bottom: 20px; }

  .grilla-datos-pdf {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
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

  .titulo-sesiones { font-size: 1.05rem; margin: 30px 0 14px; border-top: 2px solid #DDD6C7; padding-top: 20px; }

  .sesion-pdf { margin-bottom: 16px; padding-left: 14px; border-left: 3px solid #3D6B63; }
  .sesion-pdf .fecha-pdf { font-weight: 700; font-size: 0.88rem; color: #2A4B45; margin-bottom: 3px; }
  .sesion-pdf .desc-pdf { font-size: 0.9rem; white-space: pre-wrap; }
  .sesion-pdf .evol-pdf { font-size: 0.85rem; color: #4A5650; margin-top: 4px; white-space: pre-wrap; }

  .bloque-firma {
    margin-top: 50px;
    width: 300px;
    text-align: center;
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

  <div class="barra-exportar" id="barra-exportar-pdf">
    <span>Vista de exportación — usá Ctrl/Cmd + P y elegí "Guardar como PDF"</span>
    <button onclick="window.print()">Imprimir / Guardar como PDF</button>
  </div>

  <div class="encabezado-doc">
    <div>
      <h1>Del Austral</h1>
      <div class="profesional"><?= $nombreProfesional ? e($nombreProfesional) : 'Historial clínico digital' ?></div>
    </div>
    <div class="fecha-emision">Documento generado el<br><?= fechaLegible(date('Y-m-d')) ?></div>
  </div>

  <h2 class="titulo-paciente"><?= e($paciente['apellido'] . ', ' . $paciente['nombre']) ?></h2>
  <div class="meta-paciente">Legajo clínico — paciente desde <?= fechaLegible($paciente['creado_en']) ?></div>

  <div class="grilla-datos-pdf">
    <div class="dato-pdf"><div class="etq">DNI</div><div class="val"><?= e($paciente['dni']) ?></div></div>
    <div class="dato-pdf"><div class="etq">Edad</div><div class="val"><?= $edad !== null ? $edad . ' años' : '—' ?></div></div>
    <div class="dato-pdf"><div class="etq">Sexo</div><div class="val"><?= e($paciente['sexo']) ?></div></div>
    <div class="dato-pdf"><div class="etq">Obra social</div><div class="val"><?= e($paciente['obra_social_nombre'] ?: 'Sin especificar') ?></div></div>
  </div>

  <div class="bloque-pdf">
    <h3>Motivo de consulta</h3>
    <p class="<?= $paciente['motivo_consulta'] ? '' : 'vacio' ?>"><?= e($paciente['motivo_consulta'] ?: 'No se registró información.') ?></p>
  </div>
  <div class="bloque-pdf">
    <h3>Patología</h3>
    <p class="<?= $paciente['patologia'] ? '' : 'vacio' ?>"><?= e($paciente['patologia'] ?: 'No se registró información.') ?></p>
  </div>
  <div class="bloque-pdf">
    <h3>Síntomas</h3>
    <p class="<?= $paciente['sintomas'] ? '' : 'vacio' ?>"><?= e($paciente['sintomas'] ?: 'No se registró información.') ?></p>
  </div>
  <div class="bloque-pdf">
    <h3>Observaciones generales</h3>
    <p class="<?= $paciente['observaciones_generales'] ? '' : 'vacio' ?>"><?= e($paciente['observaciones_generales'] ?: 'No se registró información.') ?></p>
  </div>

  <div class="titulo-sesiones">Historial de sesiones (<?= count($sesiones) ?>)</div>

  <?php if (empty($sesiones)): ?>
    <p class="vacio" style="color:#4A5650; font-style: italic;">Todavía no se registraron sesiones para este paciente.</p>
  <?php else: ?>
    <?php foreach ($sesiones as $s): ?>
      <div class="sesion-pdf">
        <div class="fecha-pdf"><?= fechaLegible($s['fecha_sesion']) ?></div>
        <div class="desc-pdf"><?= e($s['descripcion']) ?></div>
        <?php if ($s['evolucion']): ?>
          <div class="evol-pdf"><?= e($s['evolucion']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($nombreProfesional): ?>
    <div class="bloque-firma">
      <?php if ($firmaDigital): ?>
        <img src="<?= e($firmaDigital) ?>" alt="Firma" class="imagen-firma">
      <?php endif; ?>
      <div class="linea-firma"></div>
      <div class="nombre-firma"><?= e($nombreProfesional) ?></div>
      <div class="aclaracion-firma">Profesional responsable del seguimiento clínico</div>
    </div>
  <?php endif; ?>

  <div class="pie-pdf">
    Este documento contiene información clínica protegida por la Ley N.º 25.326 de Protección de Datos Personales.
    Su divulgación a terceros está prohibida salvo autorización expresa del paciente o requerimiento legal.
  </div>

  <script>
    // Respaldo independiente del CSS @media print: algunos
    // navegadores o motores de "guardar como PDF" no respetan
    // bien los media queries, así que ocultamos la barra
    // directamente con JS antes de imprimir, y la mostramos
    // de nuevo después (por si el usuario cancela y reintenta).
    var barraExportar = document.getElementById('barra-exportar-pdf');
    window.addEventListener('beforeprint', function () {
      if (barraExportar) barraExportar.style.display = 'none';
    });
    window.addEventListener('afterprint', function () {
      if (barraExportar) barraExportar.style.display = 'flex';
    });
  </script>

</body>
</html>

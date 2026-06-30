<?php
require_once __DIR__ . '/config/config.php';

if (empty($_SESSION['autenticado']) || ($_SESSION['rol'] ?? '') !== 'profesional') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1C2421;">
    <h2>No tenés permiso para ver este documento</h2>
    <p style="color:#4A5650;">La exportación de constancias está disponible solo para el usuario profesional.</p>
    </body></html>';
    exit;
}

$pdo = obtenerConexion();
$id = $_GET['id'] ?? 0;
$profesionalActivoId = idProfesionalActivo();

$stmt = $pdo->prepare('
    SELECT c.*, s.nombre AS sede_nombre
    FROM constancias c
    INNER JOIN sedes s ON s.id = c.sede_id
    WHERE c.id = ? AND c.profesional_id = ?
');
$stmt->execute([$id, $profesionalActivoId]);
$constancia = $stmt->fetch();

if (!$constancia) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>No encontrada</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center; color: #1C2421;">
    <h2>Constancia no encontrada</h2>
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

$generoSufijo = 'el/la'; // El sistema no guarda sexo en datos manuales; mantenemos lenguaje inclusivo.
$urlValidacion = 'https://' . $_SERVER['HTTP_HOST'] . '/validar_constancia.php?token=' . urlencode($constancia['token']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Constancia de asistencia — <?= e($constancia['nombre_completo']) ?></title>
<style>
  @media print {
    @page { margin: 16mm 18mm; }
    .barra-exportar { display: none !important; }
    body { padding: 0 !important; }
    .encabezado-doc, .titulo-constancia, .cuerpo-constancia, .bloque-firma, .bloque-validacion, .pie-pdf {
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
    line-height: 1.6;
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

  .titulo-constancia {
    text-align: center;
    font-size: 1.15rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 22px;
    color: #2A4B45;
  }

  .cuerpo-constancia {
    font-size: 1rem;
    text-align: justify;
  }
  .cuerpo-constancia p { margin: 0 0 16px; }

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

  .bloque-validacion {
    margin-top: 30px;
    padding: 14px 18px;
    background: #F7F4ED;
    border: 1px solid #DDD6C7;
    border-radius: 10px;
    font-size: 0.82rem;
    color: #2A4B45;
    text-align: center;
  }
  .bloque-validacion .token { font-family: 'Courier New', monospace; font-weight: 700; font-size: 1rem; letter-spacing: 0.05em; }
  .bloque-validacion .link { word-break: break-all; color: #3D6B63; }

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
      <div class="profesional"><?= e($constancia['sede_nombre']) ?></div>
    </div>
    <div class="fecha-emision">Documento generado el<br><?= fechaLegible(date('Y-m-d')) ?></div>
  </div>

  <div class="titulo-constancia">Constancia de Asistencia</div>

  <div class="cuerpo-constancia">
    <p>
      Por la presente se deja constancia de que <?= $generoSufijo ?> paciente <strong><?= e($constancia['nombre_completo']) ?></strong>,
      DNI N.º <strong><?= e($constancia['dni']) ?></strong><?= $constancia['lugar_nacimiento'] ? ', nacido/a en ' . e($constancia['lugar_nacimiento']) : '' ?>,
      es paciente de esta sede (<?= e($constancia['sede_nombre']) ?>).
    </p>
    <p>
      Asimismo, se certifica que en la fecha <?= fechaLegible($constancia['fecha_consulta']) ?> el/la mencionado/a asistió a una consulta
      en esta institución, la cual fue debidamente realizada.
    </p>
    <p>
      El presente certificado se extiende a solicitud del/de la paciente, <?= e($constancia['destino']) ?>.
    </p>
    <p>Sin otro particular, saludo atentamente.</p>
  </div>

  <div class="bloque-firma">
    <?php if (!empty($prof['firma_digital'])): ?>
      <img src="<?= e($prof['firma_digital']) ?>" alt="Firma" class="imagen-firma">
    <?php endif; ?>
    <div class="linea-firma"></div>
    <div class="nombre-firma"><?= e($prof['nombre_completo'] ?? '') ?></div>
    <div class="aclaracion-firma">Profesional responsable del seguimiento clínico</div>
  </div>

  <div class="bloque-validacion">
    Este certificado puede ser validado en:<br>
    <span class="link"><?= e($urlValidacion) ?></span><br>
    Token de validación: <span class="token"><?= e($constancia['token']) ?></span><br>
    Válido hasta el <?= fechaLegible($constancia['vence_en']) ?>.
  </div>

  <div class="pie-pdf">
    Este documento contiene información personal protegida por la Ley N.º 25.326 de Protección de Datos Personales.
    Lleva la firma electrónica del profesional, validable mediante el token incluido más arriba.
  </div>

  <script>
    var barraExportar = document.querySelector('.barra-exportar');
    window.addEventListener('beforeprint', function () { if (barraExportar) barraExportar.style.display = 'none'; });
    window.addEventListener('afterprint', function () { if (barraExportar) barraExportar.style.display = 'flex'; });
  </script>

</body>
</html>

<?php
require_once __DIR__ . '/config/config.php';

$pdo = obtenerConexion();
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

function e($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

function fechaLegiblePublicaConstancia($fechaIso) {
    if (!$fechaIso) return '—';
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $partes = explode('-', substr($fechaIso, 0, 10));
    if (count($partes) !== 3) return e($fechaIso);
    return (int)$partes[2] . ' de ' . $meses[(int)$partes[1] - 1] . ' de ' . $partes[0];
}

$constancia = null;
$estadoBusqueda = null; // 'no_buscado' | 'no_encontrado' | 'vencido' | 'valido'

if ($token !== '') {
    $stmt = $pdo->prepare('
        SELECT c.*, s.nombre AS sede_nombre, u.nombre_completo AS profesional_nombre, pl.titulo, pl.especialidad
        FROM constancias c
        INNER JOIN sedes s ON s.id = c.sede_id
        INNER JOIN usuarios u ON u.id = c.profesional_id
        LEFT JOIN profesionales_legajos pl ON pl.usuario_id = u.id
        WHERE c.token = ?
    ');
    $stmt->execute([$token]);
    $constancia = $stmt->fetch();

    if ($constancia) {
        $estadoBusqueda = (strtotime($constancia['vence_en']) < strtotime('today')) ? 'vencido' : 'valido';
    } else {
        // Puede ser un token que ya venció y fue borrado (queda
        // solo en el histórico interno, sin contenido), o que
        // directamente nunca existió. Para el público, en ambos
        // casos el mensaje es el mismo por privacidad.
        $stmtHist = $pdo->prepare('SELECT 1 FROM constancias_historico WHERE token = ?');
        $stmtHist->execute([$token]);
        $estadoBusqueda = $stmtHist->fetch() ? 'vencido_sin_datos' : 'no_encontrado';
    }
}

$tituloPorTipoPublico = [
    'asistencia' => 'Constancia de Asistencia',
    'tratamiento' => 'Constancia de Tratamiento Prolongado',
    'receta' => 'Receta',
];
$etiquetaFechaPorTipo = [
    'asistencia' => 'Fecha de la consulta',
    'tratamiento' => 'En tratamiento desde',
    'receta' => 'Fecha de emisión',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Validar constancia — Del Austral</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    background: #F7F4ED;
    color: #1C2421;
    margin: 0;
    padding: 24px 16px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .tarjeta {
    background: white;
    border-radius: 20px;
    padding: 36px 28px;
    max-width: 460px;
    width: 100%;
    box-shadow: 0 12px 40px rgba(28,36,33,0.12);
    text-align: center;
  }
  .icono-marca { width: 44px; height: 44px; color: #3D6B63; margin-bottom: 10px; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .subtitulo { color: #4A5650; font-size: 0.88rem; margin-bottom: 24px; display: block; }
  form.buscador { display: flex; flex-direction: column; gap: 10px; margin-bottom: 8px; }
  input[type="text"] {
    border: 1.5px solid #DDD6C7;
    border-radius: 10px;
    padding: 13px 16px;
    font-size: 0.95rem;
    font-family: inherit;
    text-align: center;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  button {
    border: none;
    border-radius: 999px;
    padding: 13px 20px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    font-family: inherit;
    background: #3D6B63;
    color: white;
  }
  .resultado { text-align: left; margin-top: 22px; }
  .estado-pill {
    display: inline-block;
    margin-bottom: 16px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
  }
  .estado-valido { background: #DCE8D8; color: #2A4B45; }
  .estado-vencido { background: #F5E3DC; color: #C4654A; }
  .dato-fila { margin-bottom: 10px; }
  .dato-fila .etq { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #4A5650; font-weight: 700; }
  .dato-fila .val { font-size: 0.95rem; font-weight: 600; }
  .error-pagina { color: #4A5650; margin-top: 18px; font-size: 0.92rem; }
</style>
</head>
<body>
  <div class="tarjeta">
    <svg class="icono-marca" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 24h4l3-12 4 24 4-30 4 24 4-18 3 12h6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <h1>Del Austral</h1>
    <span class="subtitulo">Validador de constancias</span>

    <form method="GET" class="buscador">
      <input type="text" name="token" placeholder="Ej: AB3D-4F7H-2KMN" value="<?= e($token) ?>" autocomplete="off">
      <button type="submit">Validar</button>
    </form>

    <?php if ($estadoBusqueda === 'valido'): ?>
      <div class="resultado">
        <span class="estado-pill estado-valido">✓ <?= e($tituloPorTipoPublico[$constancia['tipo']] ?? 'Constancia') ?> válida</span>
        <div class="dato-fila">
          <div class="etq">Paciente</div>
          <div class="val"><?= e($constancia['nombre_completo']) ?></div>
        </div>
        <div class="dato-fila">
          <div class="etq">DNI</div>
          <div class="val"><?= e($constancia['dni']) ?></div>
        </div>
        <div class="dato-fila">
          <div class="etq"><?= e($etiquetaFechaPorTipo[$constancia['tipo']] ?? 'Fecha') ?></div>
          <div class="val"><?= fechaLegiblePublicaConstancia($constancia['tipo'] === 'tratamiento' ? $constancia['tratamiento_desde'] : $constancia['fecha_consulta']) ?></div>
        </div>
        <div class="dato-fila">
          <div class="etq">Profesional</div>
          <div class="val"><?= e($constancia['profesional_nombre']) ?></div>
        </div>
        <div class="dato-fila">
          <div class="etq">Sede</div>
          <div class="val"><?= e($constancia['sede_nombre']) ?></div>
        </div>
        <div class="dato-fila">
          <div class="etq">Válida hasta</div>
          <div class="val"><?= fechaLegiblePublicaConstancia($constancia['vence_en']) ?></div>
        </div>
      </div>
    <?php elseif ($estadoBusqueda === 'vencido'): ?>
      <div class="resultado" style="text-align:center;">
        <span class="estado-pill estado-vencido">Constancia vencida</span>
        <p class="error-pagina">Esta constancia venció el <?= fechaLegiblePublicaConstancia($constancia['vence_en']) ?> y ya no es válida como justificante.</p>
      </div>
    <?php elseif ($estadoBusqueda === 'vencido_sin_datos'): ?>
      <div class="resultado" style="text-align:center;">
        <span class="estado-pill estado-vencido">Constancia vencida</span>
        <p class="error-pagina">Existió una constancia con este token, pero ya venció hace tiempo y fue eliminada del sistema.</p>
      </div>
    <?php elseif ($estadoBusqueda === 'no_encontrado'): ?>
      <p class="error-pagina">No encontramos ninguna constancia con ese token. Revisá que esté bien escrito.</p>
    <?php endif; ?>
  </div>
</body>
</html>

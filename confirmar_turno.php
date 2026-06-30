<?php
require_once __DIR__ . '/config/config.php';

$pdo = obtenerConexion();
$token = $_GET['token'] ?? '';
$accionPost = $_POST['accion'] ?? '';

function e($texto) {
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

function fechaLegiblePublica($fechaIso) {
    if (!$fechaIso) return '—';
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $partes = explode('-', substr($fechaIso, 0, 10));
    if (count($partes) !== 3) return e($fechaIso);
    return (int)$partes[2] . ' de ' . $meses[(int)$partes[1] - 1] . ' de ' . $partes[0];
}

$stmt = $pdo->prepare('
    SELECT c.*, p.nombre, p.apellido
    FROM citas c
    INNER JOIN pacientes p ON p.id = c.paciente_id
    WHERE c.token_confirmacion = ?
');
$stmt->execute([$token]);
$cita = $stmt->fetch();

$mensaje = null;
$tipoMensaje = null;

if ($cita && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accionPost, ['confirmar', 'cancelar'])) {
    if ($cita['estado'] !== 'pendiente') {
        $mensaje = 'Este turno ya no está pendiente, así que no se puede modificar desde acá.';
        $tipoMensaje = 'info';
    } else if ($accionPost === 'confirmar') {
        $pdo->prepare('UPDATE citas SET confirmada_por_paciente = 1, revisada_por_profesional = 0 WHERE id = ?')->execute([$cita['id']]);
        $mensaje = '¡Listo! Tu turno quedó confirmado.';
        $tipoMensaje = 'exito';
        $cita['confirmada_por_paciente'] = 1;
    } else if ($accionPost === 'cancelar') {
        $pdo->prepare('UPDATE citas SET estado = "cancelada", revisada_por_profesional = 0 WHERE id = ?')->execute([$cita['id']]);
        $mensaje = 'Tu turno quedó cancelado. Si necesitás reprogramarlo, comunicate con el consultorio.';
        $tipoMensaje = 'info';
        $cita['estado'] = 'cancelada';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirmar turno — Del Austral</title>
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
    max-width: 420px;
    width: 100%;
    box-shadow: 0 12px 40px rgba(28,36,33,0.12);
    text-align: center;
  }
  .icono-marca { width: 44px; height: 44px; color: #3D6B63; margin-bottom: 10px; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; }
  .subtitulo { color: #4A5650; font-size: 0.88rem; margin-bottom: 24px; display: block; }
  .dato-turno {
    background: #F7F4ED;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 20px;
    text-align: left;
  }
  .dato-turno .fecha { font-weight: 700; font-size: 1.05rem; color: #2A4B45; }
  .dato-turno .motivo { color: #4A5650; font-size: 0.9rem; margin-top: 4px; }
  .estado-pill {
    display: inline-block;
    margin-top: 10px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
  }
  .estado-pendiente { background: #E4EDE9; color: #2A4B45; }
  .estado-confirmada { background: #DCE8D8; color: #3D6B63; }
  .estado-cancelada { background: #F5E3DC; color: #C4654A; }
  .botones { display: flex; flex-direction: column; gap: 10px; }
  button {
    border: none;
    border-radius: 999px;
    padding: 13px 20px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    font-family: inherit;
  }
  .btn-confirmar { background: #3D6B63; color: white; }
  .btn-cancelar { background: white; color: #C4654A; border: 1.5px solid #C4654A; }
  .mensaje {
    padding: 14px 16px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 20px;
  }
  .mensaje.exito { background: #DCE8D8; color: #2A4B45; }
  .mensaje.info { background: #EFEAE0; color: #4A5650; }
  .error-pagina { color: #4A5650; }
</style>
</head>
<body>
  <div class="tarjeta">
    <svg class="icono-marca" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M4 24h4l3-12 4 24 4-30 4 24 4-18 3 12h6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <h1>Del Austral</h1>
    <span class="subtitulo">Confirmación de turno</span>

    <?php if (!$cita): ?>
      <p class="error-pagina">No encontramos este turno. El link puede haber expirado o ser incorrecto.</p>
    <?php else: ?>

      <?php if ($mensaje): ?>
        <div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div>
      <?php endif; ?>

      <div class="dato-turno">
        <div class="fecha"><?= fechaLegiblePublica($cita['fecha']) ?><?= $cita['hora'] ? ' · ' . substr($cita['hora'], 0, 5) : '' ?></div>
        <?php if ($cita['motivo']): ?>
          <div class="motivo"><?= e($cita['motivo']) ?></div>
        <?php endif; ?>
        <?php
          $etiquetas = [
            'pendiente' => $cita['confirmada_por_paciente'] ? ['Confirmado', 'confirmada'] : ['Pendiente de confirmar', 'pendiente'],
            'cancelada' => ['Cancelado', 'cancelada'],
            'atendida' => ['Ya atendido', 'confirmada'],
            'ausente' => ['Marcado como ausente', 'cancelada'],
          ];
          [$textoEstado, $claseEstado] = $etiquetas[$cita['estado']] ?? ['—', 'pendiente'];
        ?>
        <span class="estado-pill estado-<?= e($claseEstado) ?>"><?= e($textoEstado) ?></span>
      </div>

      <?php if ($cita['estado'] === 'pendiente' && !$cita['confirmada_por_paciente']): ?>
        <form method="POST" class="botones">
          <button type="submit" name="accion" value="confirmar" class="btn-confirmar">Confirmar turno</button>
          <button type="submit" name="accion" value="cancelar" class="btn-cancelar">No voy a poder asistir</button>
        </form>
      <?php elseif ($cita['estado'] === 'pendiente' && $cita['confirmada_por_paciente']): ?>
        <form method="POST" class="botones">
          <button type="submit" name="accion" value="cancelar" class="btn-cancelar">Necesito cancelar este turno</button>
        </form>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</body>
</html>

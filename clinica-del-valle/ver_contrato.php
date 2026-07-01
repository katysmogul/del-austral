<?php
/**
 * Muestra el contrato de servicio de esta institución. El
 * contenido NO se genera acá: es un HTML estático
 * (contrato.html) que el panel maestro copia a esta carpeta
 * cada vez que se crea o edita el contrato. Este archivo solo
 * lo sirve, sin requerir ninguna sesión — lo necesita ver el
 * apoderado antes incluso de poder loguearse por primera
 * vez (para firmarlo).
 */

$rutaContrato = __DIR__ . '/contrato.html';

if (!file_exists($rutaContrato)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Contrato no disponible</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; text-align: center;">
    <h2>El contrato todavía no está disponible</h2>
    <p style="color:#666;">Esto puede pasar si la institución se creó sin completar los datos del contrato. Consultá con quien administra el sistema.</p>
    </body></html>';
    exit;
}

readfile($rutaContrato);

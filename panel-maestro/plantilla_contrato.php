<?php
/**
 * ============================================================
 * MÓDULO COMPARTIDO: render del documento de contrato (HTML)
 * ============================================================
 * Esta función genera el HTML completo del Contrato Integral +
 * los 3 Anexos, con AMBOS ejemplares ("Ejemplar Uno" / "Ejemplar
 * Dos"), a partir de una fila de la tabla `contratos` (con JOIN
 * a `instituciones` para traer el nombre).
 *
 * La usan:
 *   - generar_contrato.php (vista en vivo para el Super Admin,
 *     con la barra de exportar a PDF)
 *   - sincronizar_contrato_cliente() en api_maestro.php, que
 *     copia un HTML estático (sin barra de exportar) a la
 *     carpeta de cada institución, para que el apoderado del
 *     cliente pueda leerlo antes de firmar.
 *
 * @param array $contrato       Fila de `contratos` + nombre_institucion (JOIN)
 * @param bool  $incluirBarraExportar  Si se muestra el botón "Imprimir / Guardar como PDF"
 * @return string HTML completo, listo para imprimir con echo o guardar en un archivo
 */
function renderizarDocumentoContratoHTML(array $contrato, bool $incluirBarraExportar = true): string {

    function e($texto) {
        return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
    }

    function fechaLegibleContrato($fechaIso) {
        if (!$fechaIso) return '—';
        $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $partes = explode('-', substr($fechaIso, 0, 10));
        if (count($partes) !== 3) return e($fechaIso);
        return (int)$partes[2] . ' de ' . $meses[(int)$partes[1] - 1] . ' de ' . $partes[0];
    }

    /**
     * Convierte un número a su forma "30 (treinta)" para los plazos
     * en días/meses/años, igual al estilo usado en el contrato base.
     * Cubre el rango razonable de valores que se usan en estas
     * cláusulas (no pretende ser un conversor numérico genérico).
     */
    function numeroEnPalabras($n) {
        $n = (int) $n;
        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $especiales = [
            10=>'diez',11=>'once',12=>'doce',13=>'trece',14=>'catorce',15=>'quince',
            16=>'dieciséis',17=>'diecisiete',18=>'dieciocho',19=>'diecinueve',20=>'veinte',
            30=>'treinta',40=>'cuarenta',50=>'cincuenta',60=>'sesenta',90=>'noventa',
        ];
        if ($n <= 0) return 'cero';
        if (isset($especiales[$n])) return $especiales[$n];
        if ($n < 10) return $unidades[$n];
        if ($n < 30 && $n > 20) return 'veinti' . $unidades[$n - 20];
        if ($n < 100) {
            $decena = intdiv($n, 10) * 10;
            $resto = $n % 10;
            if (isset($especiales[$decena]) && $resto > 0) return $especiales[$decena] . ' y ' . $unidades[$resto];
            if (isset($especiales[$decena])) return $especiales[$decena];
        }
        return (string) $n; // fallback para valores fuera del rango cubierto
    }

    function numeroConPalabras($n) {
        return $n . ' (' . numeroEnPalabras($n) . ')';
    }

    function formatoMoneda($monto, $moneda) {
        if ($monto === null || $monto === '') return null;
        $simbolos = ['ARS' => '$', 'USD' => 'US$', 'EUR' => '€'];
        $simbolo = $simbolos[$moneda] ?? ($moneda . ' ');
        return $simbolo . number_format((float) $monto, 2, ',', '.');
    }

    $textoPlazo = match($contrato['plazo_tipo']) {
        'dias' => numeroConPalabras($contrato['plazo_cantidad']) . ' días',
        'meses' => numeroConPalabras($contrato['plazo_cantidad']) . ' meses',
        'anios' => numeroConPalabras($contrato['plazo_cantidad']) . ' años',
        default => 'INDETERMINADA',
    };

    $esPlazoIndeterminado = $contrato['plazo_tipo'] === 'indeterminado';
    $precioTexto = formatoMoneda($contrato['precio_monto'], $contrato['precio_moneda']);
    $nombreClienteMayus = e(mb_strtoupper($contrato['nombre_institucion'], 'UTF-8'));
    $marcaPrestador = e($contrato['prestador_marca']);

    ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contrato de prestación de servicios — <?= e($contrato['nombre_institucion']) ?></title>
<style>
  @media print {
    @page { margin: 16mm 16mm; }
    .barra-exportar { display: none !important; }
    body { padding: 0 !important; }
    .clausula { page-break-inside: avoid; }
    .salto-pagina { page-break-before: always; }
    .salto-ejemplar { page-break-before: always; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #1A1A1A;
    max-width: 780px;
    margin: 0 auto;
    padding: 30px 24px 60px;
    line-height: 1.5;
    font-size: 0.92rem;
    background: #FDFBF6;
    position: relative;
  }
  .marca-agua {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
    z-index: -1;
  }
  .marca-agua span {
    font-size: 7.5rem;
    font-weight: 800;
    color: #FF7A2E;
    opacity: 0.07;
    transform: rotate(-35deg);
    letter-spacing: 0.1em;
    white-space: nowrap;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .barra-exportar {
    position: sticky; top: 0;
    background: #1A1A1A; color: white;
    padding: 14px 20px; margin: -30px -24px 26px;
    display: flex; align-items: center; justify-content: space-between;
    font-family: Arial, sans-serif;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  }
  .barra-exportar button {
    background: #FF7A2E; color: #1A1A1A; border: 2px solid #1A1A1A;
    padding: 9px 18px; border-radius: 999px; font-weight: 800;
    cursor: pointer; font-size: 0.88rem;
  }

  .etiqueta-ejemplar {
    text-align: center;
    color: #1A1A1A;
    background: #FF7A2E;
    border: 2px solid #1A1A1A;
    display: inline-block;
    padding: 4px 18px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin: 0 auto 14px;
  }
  .contenedor-etiqueta-ejemplar { text-align: center; }

  .encabezado-contrato {
    border-bottom: 3px solid #1A1A1A;
    padding-bottom: 14px;
    margin-bottom: 20px;
  }
  h1 {
    font-family: 'Arial Narrow', Arial, sans-serif;
    font-size: 1.35rem;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    font-weight: 800;
    margin: 0 0 6px;
    color: #1A1A1A;
  }
  h2.titulo-anexo {
    font-family: 'Arial Narrow', Arial, sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin: 0;
    color: #ffffff;
  }
  .barra-titulo-anexo {
    background: #1A1A1A;
    border: 2px solid #1A1A1A;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 4px;
  }
  .subtitulo-contrato { text-align: center; color: #6B6459; font-size: 0.82rem; margin-bottom: 0; }
  .clausula { margin-bottom: 12px; text-align: justify; }
  .clausula .num { font-weight: 800; color: #1A1A1A; }

  .firma-bloque {
    display: flex;
    justify-content: space-between;
    margin-top: 55px;
    gap: 50px;
  }
  .firma-item { flex: 1; text-align: center; }
  .linea-firma-contrato {
    border-top: 2px solid #1A1A1A;
    margin-bottom: 8px;
    padding-top: 45px;
  }
  .firma-item strong { display: block; font-size: 0.92rem; }
  .firma-item .rol-firma { font-size: 0.8rem; color: #6B6459; }
  .imagen-firma {
    display: block;
    max-width: 220px;
    max-height: 70px;
    margin: 0 auto -10px;
    object-fit: contain;
  }
  .nota-firma-digital {
    text-align: center;
    font-size: 0.7rem;
    color: #6B6459;
    margin-top: -8px;
  }
  .lugar-fecha-firma {
    text-align: center;
    margin-top: 24px;
    font-size: 0.85rem;
    color: #6B6459;
  }

  .pie-contrato {
    margin-top: 26px;
    padding-top: 12px;
    border-top: 2px dashed #EAE3D6;
    font-size: 0.7rem;
    color: #6B6459;
    text-align: center;
  }
  .mascota-pie { width: 22px; height: 22px; margin: 0 auto 6px; opacity: 0.8; }
  .mascota-pie svg { display: block; width: 100%; height: 100%; }

  .bloque-anexo { margin-top: 22px; }
  .subtitulo-anexo { text-align: center; color: #6B6459; font-size: 0.8rem; margin-bottom: 16px; }

  .recuadro-clausula-numerada {
    background: #FDF3E7;
    border-left: 4px solid #FF7A2E;
    padding: 14px 18px;
    border-radius: 0 6px 6px 0;
  }
</style>
</head>
<body>

  <div class="marca-agua"><span>CONTRATO</span></div>

  <?php if ($incluirBarraExportar): ?>
  <div class="barra-exportar">
    <span>Vista de exportación — usá Ctrl/Cmd + P y elegí "Guardar como PDF" (se generan los dos ejemplares)</span>
    <button onclick="window.print()">Imprimir / Guardar como PDF</button>
  </div>
  <?php endif; ?>

  <?php ob_start(); ?>

  <div class="encabezado-contrato">
    <div class="contenedor-etiqueta-ejemplar">
      <span class="etiqueta-ejemplar">__ETIQUETA_EJEMPLAR__</span>
    </div>
    <h1>Contrato Integral de Prestación Continua de Servicios Tecnológicos</h1>
    <p class="subtitulo-contrato">Licencia de uso de software, alojamiento, mantenimiento, soporte y tratamiento de datos digitales de salud.<br>Celebrado en Comodoro Rivadavia, Provincia del Chubut, República Argentina, el día <?= fechaLegibleContrato($contrato['fecha_contrato']) ?>.</p>
  </div>

  <div class="clausula">
    Entre <strong><?= $marcaPrestador ?></strong>, marca debidamente registrada ante el Instituto Nacional de la Propiedad Industrial (INPI) de la República Argentina, cuya titular es <strong><?= e($contrato['prestador_titular_nombre']) ?></strong>, CUIL <?= e($contrato['prestador_titular_cuil']) ?>, representada en este acto por su apoderado <strong><?= e($contrato['prestador_apoderado_nombre']) ?></strong>, CUIL <?= e($contrato['prestador_apoderado_cuil']) ?>, en adelante denominada indistintamente "<strong>EL PRESTADOR</strong>", "<?= $marcaPrestador ?>" o "<strong>LA PARTE PRESTADORA</strong>", por una parte;
  </div>

  <div class="clausula">
    y <strong><?= e($contrato['razon_social_cliente']) ?></strong>, CUIT/DNI <strong><?= e($contrato['cuit_dni_cliente']) ?></strong>, en adelante denominado "<strong>EL CLIENTE</strong>", "<strong>LA INSTITUCIÓN</strong>" o "<strong><?= $nombreClienteMayus ?></strong>", por la otra parte, convienen celebrar el presente Contrato Integral de Prestación Continua de Servicios Tecnológicos, sujeto a las declaraciones, términos, condiciones, obligaciones, responsabilidades, limitaciones y demás estipulaciones que se detallan a continuación.
  </div>

  <div class="clausula"><span class="num">PRIMERA — Objeto general del contrato.</span> EL PRESTADOR se obliga a brindar a EL CLIENTE, en forma continua, regular, profesional y conforme a las condiciones técnicas razonablemente exigibles para este tipo de servicios, el acceso y utilización del sistema denominado "<?= $marcaPrestador ?>", consistente en una plataforma informática destinada a la gestión, carga, consulta, administración, conservación y resguardo digital de legajos, historiales clínicos, constancias, registros, datos administrativos y demás información vinculada a la operatoria institucional del CLIENTE.
  <br><br>El servicio contratado comprende la puesta a disposición del Sistema bajo modalidad remota, la licencia de uso limitada del software, el alojamiento técnico de la información, el mantenimiento funcional, la aplicación de mejoras, la realización de tareas de respaldo, la conservación operativa de la plataforma, la asistencia técnica razonable y toda otra prestación accesoria necesaria para que EL CLIENTE pueda utilizar el Sistema de acuerdo con su finalidad principal.
  <br><br>Las partes dejan expresa constancia de que el presente contrato no importa venta, cesión, transferencia definitiva ni entrega del código fuente, estructura interna, lógica de programación, diseño, arquitectura, documentación técnica privada, repositorios, credenciales maestras, claves administrativas internas ni ningún otro elemento de propiedad intelectual o industrial perteneciente a EL PRESTADOR, sino únicamente el otorgamiento de un derecho de uso temporal, revocable, no exclusivo, no sublicenciable e intransferible, limitado al plazo de vigencia del presente contrato y a los fines propios de la actividad institucional de EL CLIENTE.</div>

  <div class="clausula"><span class="num">SEGUNDA — Naturaleza del servicio y alcance de la obligación asumida.</span> EL PRESTADOR asume frente a EL CLIENTE una obligación de prestación continua del servicio en todo aquello que dependa directa, técnica, operativa y administrativamente de su esfera de control. EL PRESTADOR deberá adoptar las medidas razonables y necesarias para procurar que el Sistema se encuentre disponible, funcional y accesible, evitando interrupciones imputables a negligencia, abandono, falta de mantenimiento, omisión técnica injustificada, desatención operativa o cualquier otra causa que le resulte directamente atribuible.
  <br><br>Sin perjuicio de ello, LAS PARTES reconocen que la prestación del servicio depende también de infraestructura tecnológica provista por terceros (proveedores de hosting, servidores privados virtuales, centros de datos, redes de conectividad, proveedores de internet, sistemas de nombres de dominio, enrutamiento, electricidad, telecomunicaciones, servicios de seguridad digital, certificados, bibliotecas externas y demás componentes ajenos al control directo de EL PRESTADOR). No se considerará incumplimiento imputable a EL PRESTADOR toda interrupción, caída, degradación, latencia, pérdida temporal de acceso, intermitencia, bloqueo, afectación de conectividad, mantenimiento externo o indisponibilidad que provenga de dichos terceros, siempre que EL PRESTADOR no haya actuado con dolo, culpa grave, negligencia manifiesta o desatención injustificada.
  <br><br>No obstante, EL PRESTADOR se obliga a actuar diligentemente ante la detección de fallas atribuibles al proveedor de hosting o a terceros técnicos, debiendo informar a EL CLIENTE, en cuanto resulte razonablemente posible, la existencia del inconveniente, su naturaleza estimada, el proveedor o componente aparentemente afectado, las medidas adoptadas para procurar la normalización del servicio y, de corresponder, la conveniencia de migrar o sustituir el proveedor de infraestructura por otro más apto, estable, escalable o seguro.</div>

  <div class="clausula"><span class="num">TERCERA — Infraestructura, alojamiento y posibilidad de modificación técnica.</span> El Sistema se encontrará alojado inicialmente en un Servidor Privado Virtual o infraestructura equivalente, con características técnicas actuales aproximadas de <?= (int) $contrato['ram_gb'] ?> GB de memoria RAM y <?= (int) $contrato['disco_gb'] ?> GB de almacenamiento en disco de estado sólido (SSD), ubicado físicamente en <?= e($contrato['ubicacion_servidor']) ?>, sin perjuicio de que tales especificaciones, ubicación, proveedor, configuración, arquitectura, capacidad, modalidad de contratación o tecnología utilizada puedan ser modificadas por EL PRESTADOR cuando razones técnicas, operativas, económicas, de seguridad, escalabilidad, continuidad o disponibilidad así lo aconsejen.
  <br><br>EL CLIENTE presta conformidad expresa para que EL PRESTADOR pueda migrar, ampliar, reducir, sustituir o reconfigurar la infraestructura utilizada, siempre que ello tenga por finalidad mantener, mejorar, corregir, resguardar, optimizar o adaptar la prestación del servicio. En caso de que la infraestructura utilizada resultare insuficiente, inestable o inadecuada por causas ajenas a EL PRESTADOR, este deberá comunicarlo a EL CLIENTE y podrá proponer alternativas técnicas, debiendo acordarse entre LAS PARTES los costos adicionales que dicha mejora, migración o contratación superior pudiera implicar.
  <br><br>La continuidad del servicio será una obligación prioritaria de EL PRESTADOR dentro de su ámbito de control, pero LAS PARTES reconocen que la contratación de infraestructura suficiente y proporcional al volumen de uso requiere la cooperación económica y decisoria de EL CLIENTE cuando sea necesario escalar recursos, contratar planes superiores, incrementar almacenamiento, mejorar redundancia, incorporar balanceo, contratar copias externas, sumar monitoreo avanzado o adoptar medidas técnicas de mayor complejidad.</div>

  <div class="clausula"><span class="num">CUARTA — Plazo de vigencia.</span> El presente contrato tendrá una duración <?= $esPlazoIndeterminado ? 'INDETERMINADA' : ('de ' . e($textoPlazo)) ?> a partir de su firma<?= $esPlazoIndeterminado ? ', y permanecerá vigente mientras LAS PARTES mantengan la relación contractual, el servicio se encuentre activo y EL CLIENTE continúe abonando los importes correspondientes' : '' ?>. Cualquiera de LAS PARTES podrá solicitar la finalización del vínculo mediante notificación fehaciente con una antelación mínima de <?= numeroConPalabras($contrato['preaviso_rescision_dias']) ?> días corridos, salvo que existan incumplimientos graves, mora, uso indebido, riesgo para la seguridad del Sistema, requerimiento legal, imposibilidad técnica sobreviniente o cualquier otra causal que habilite una suspensión o rescisión anticipada conforme al presente contrato.
  <br><br>La finalización del contrato, cualquiera sea su causa, no extinguirá las obligaciones pendientes de pago, confidencialidad, devolución o puesta a disposición de datos, indemnidad, responsabilidad por daños efectivamente ocasionados, protección de información sensible, jurisdicción pactada ni aquellas obligaciones que por su naturaleza deban subsistir aun después de extinguido el vínculo contractual.</div>

  <div class="clausula"><span class="num">QUINTA — Precio, forma de pago y actualización.</span> EL CLIENTE abonará a EL PRESTADOR el servicio en forma <?= $contrato['modalidad_pago'] === 'anual' ? 'ANUAL' : 'MENSUAL' ?><?= $precioTexto ? (', por un valor de ' . e($precioTexto) . ' (' . e($contrato['precio_moneda']) . ')') : ', cuyo valor será el que LAS PARTES acuerden' ?>, mediante transferencia bancaria por CBU, CVU o el medio de pago que EL PRESTADOR informe oportunamente, pudiendo utilizarse cuentas pertenecientes a <?= e($contrato['prestador_titular_nombre']) ?>, CUIL <?= e($contrato['prestador_titular_cuil']) ?>, y/o a <?= e($contrato['prestador_apoderado_nombre']) ?>, CUIL <?= e($contrato['prestador_apoderado_cuil']) ?>, o aquellas otras que en el futuro sean válidamente comunicadas.
  <br><br>El precio comprende el uso ordinario del Sistema dentro de los parámetros normales de funcionamiento, mantenimiento básico, soporte razonable y alojamiento conforme a la infraestructura contratada al momento de la prestación. No se entenderán incluidos, salvo pacto expreso por escrito, desarrollos especiales, módulos personalizados, integraciones con terceros, migraciones extraordinarias, aumentos significativos de almacenamiento, contratación de infraestructura premium, auditorías externas, certificaciones, capacitaciones presenciales, soporte fuera de horario, recuperación avanzada de datos por causas imputables al CLIENTE ni cualquier otra prestación que exceda el uso normal del servicio.
  <br><br>EL PRESTADOR podrá actualizar el valor del servicio cuando existan aumentos de costos, inflación, modificaciones tributarias, variaciones del tipo de cambio, incremento de tarifas de hosting, necesidad de infraestructura superior, incorporación de funcionalidades relevantes, aumento del volumen operativo o cualquier otra circunstancia objetiva que altere el equilibrio económico de la prestación, debiendo comunicarlo a EL CLIENTE con una antelación razonable.</div>

  <div class="clausula"><span class="num">SEXTA — Mora, tolerancia y suspensión por falta de pago.</span> La falta de pago del servicio en las fechas acordadas producirá la mora de pleno derecho, sin necesidad de interpelación judicial o extrajudicial alguna. Sin perjuicio de ello, EL PRESTADOR concede a EL CLIENTE una tolerancia máxima de <?= numeroConPalabras($contrato['tolerancia_mora_meses']) ?> meses de pagos impagos acumulados, transcurrida la cual podrá suspender total o parcialmente el acceso al Sistema, restringir usuarios, bloquear funcionalidades, impedir nuevas cargas o adoptar cualquier otra medida técnica razonable hasta tanto EL CLIENTE regularice íntegramente su situación.
  <br><br>La suspensión por falta de pago no implicará renuncia al cobro de las sumas adeudadas, no extinguirá el contrato salvo decisión expresa de EL PRESTADOR, no generará derecho a indemnización a favor de EL CLIENTE y no impedirá que EL PRESTADOR reclame judicial o extrajudicialmente los importes impagos, intereses, gastos, costas y demás daños que correspondieren.</div>

  <div class="clausula"><span class="num">SÉPTIMA — Backups, resguardo y conservación de información.</span> EL PRESTADOR realizará copias de seguridad de la información alojada en el Sistema de forma diaria, preferentemente en el horario de <?= e($contrato['backup_horario']) ?>, o en el horario técnico que resulte más adecuado según la carga del servidor, estabilidad de la infraestructura, mantenimiento programado o necesidades operativas del Sistema.
  <br><br>LAS PARTES reconocen que ningún sistema informático puede garantizar en términos absolutos la inexistencia total de pérdida, corrupción, alteración, inaccesibilidad o degradación de datos, especialmente cuando intervengan hechos de terceros, ataques informáticos, fallas del hosting, errores humanos de usuarios autorizados, eliminación voluntaria o accidental de información, credenciales comprometidas, uso indebido del Sistema o eventos de caso fortuito o fuerza mayor.
  <br><br>EL PRESTADOR deberá actuar diligentemente para mantener mecanismos razonables de respaldo, sin que ello implique asumir responsabilidad por pérdidas de datos derivadas de causas externas a su control, de actos u omisiones imputables a EL CLIENTE o sus usuarios, o de eventos imprevisibles o inevitables. EL CLIENTE podrá solicitar, y LAS PARTES podrán acordar por separado, esquemas de respaldo superiores, copias redundantes, backups externos, almacenamiento adicional, retención prolongada, cifrado avanzado o políticas específicas de recuperación ante desastres.</div>

  <div class="clausula"><span class="num">OCTAVA — Entrega de datos ante cese, suspensión o finalización.</span> En caso de suspensión definitiva, rescisión, finalización del contrato o cese del servicio por cualquier causa, EL PRESTADOR pondrá a disposición de EL CLIENTE el último backup disponible de la información alojada en el Sistema, en el formato técnico que razonablemente resulte posible, durante un plazo de <?= numeroConPalabras($contrato['plazo_entrega_datos_dias']) ?> días hábiles contados desde la comunicación de disponibilidad del archivo o repositorio correspondiente.
  <br><br>La entrega podrá realizarse mediante enlace privado, repositorio restringido, plataforma Git, Gitea, GitHub, almacenamiento temporal o cualquier otro medio digital razonablemente seguro determinado por EL PRESTADOR. EL CLIENTE será responsable de descargar, conservar, verificar y resguardar dicha información dentro del plazo indicado. Transcurrido dicho plazo sin que EL CLIENTE haya efectuado la descarga o solicitado una prórroga fundada y aceptada por EL PRESTADOR, este podrá eliminar, archivar, bloquear o conservar la información conforme a sus políticas internas, obligaciones legales, requerimientos técnicos o necesidades de seguridad, sin responsabilidad por la falta de descarga oportuna imputable a EL CLIENTE.</div>

  <div class="clausula"><span class="num">NOVENA — Datos personales, datos sensibles y responsabilidad institucional.</span> LAS PARTES reconocen que el Sistema puede contener datos personales y datos sensibles vinculados a la salud de pacientes, profesionales, personal administrativo u otras personas humanas. EL CLIENTE será considerado responsable primario del contenido, origen, licitud, exactitud, legitimidad, autorización, consentimiento, carga, modificación, uso y destino de dichos datos, en tanto es quien decide qué información se incorpora al Sistema, quiénes acceden a ella y con qué finalidad institucional se utiliza.
  <br><br>EL PRESTADOR actuará únicamente como proveedor tecnológico y encargado operativo del tratamiento en la medida necesaria para brindar el servicio. EL PRESTADOR no será responsable por la veracidad médica, administrativa o legal de la información ingresada por EL CLIENTE, ni por diagnósticos, tratamientos, decisiones clínicas, omisiones profesionales, errores de carga, historiales incompletos, uso indebido por usuarios autorizados, falta de consentimiento de pacientes o incumplimientos normativos propios de la actividad sanitaria de EL CLIENTE.
  <br><br>EL CLIENTE declara y garantiza que cuenta, o deberá contar bajo su exclusiva responsabilidad, con todas las autorizaciones, consentimientos, bases legales, protocolos internos, políticas de privacidad, deberes de información, medidas administrativas y habilitaciones necesarias para cargar, almacenar y tratar datos personales y datos sensibles en el Sistema.</div>

  <div class="clausula"><span class="num">DÉCIMA — Confidencialidad.</span> EL PRESTADOR se obliga a mantener estricta confidencialidad respecto de toda información técnica, administrativa, médica, clínica, institucional, comercial o personal a la que pudiera acceder con motivo de la prestación del servicio. Dicha obligación comprende la prohibición de divulgar, reproducir, comunicar, vender, ceder, transferir, publicar o utilizar información de EL CLIENTE o de sus pacientes para fines ajenos al cumplimiento del presente contrato, salvo autorización expresa de EL CLIENTE, requerimiento judicial, exigencia legal, necesidad técnica indispensable para la prestación del servicio o defensa de los derechos de EL PRESTADOR ante un reclamo concreto.
  <br><br>La obligación de confidencialidad subsistirá aun después de finalizado el contrato, cualquiera fuere la causa de terminación, y alcanzará a toda persona que intervenga por cuenta de EL PRESTADOR en tareas técnicas, administrativas, de soporte o mantenimiento.</div>

  <div class="clausula"><span class="num">DÉCIMA PRIMERA — Seguridad informática.</span> EL PRESTADOR deberá implementar medidas técnicas razonables de seguridad conforme al tamaño, naturaleza y modalidad del servicio, procurando proteger el Sistema contra accesos no autorizados, pérdida accidental, uso indebido, alteración, destrucción, indisponibilidad o divulgación no autorizada.
  <br><br>EL CLIENTE reconoce que ningún sistema informático conectado a internet puede considerarse absolutamente invulnerable, por lo que EL PRESTADOR no garantiza seguridad absoluta, inexistencia total de ataques, ausencia de vulnerabilidades, imposibilidad de intrusión, continuidad perfecta ni protección frente a todo evento externo. En caso de detectarse un incidente de seguridad relevante, EL PRESTADOR deberá adoptar medidas razonables para contenerlo, evaluarlo, corregirlo e informar a EL CLIENTE cuando el incidente pueda afectar la continuidad del servicio o la integridad de la información.</div>

  <div class="clausula"><span class="num">DÉCIMA SEGUNDA — Usuarios, credenciales y uso del Sistema.</span> EL CLIENTE será responsable exclusivo por la designación, habilitación, administración, baja y control de sus usuarios autorizados, así como por el uso que tales usuarios hagan del Sistema, por la confidencialidad de sus credenciales, por la asignación adecuada de permisos, por la capacitación interna de su personal y por la prevención de accesos indebidos dentro de su propia organización.
  <br><br>Todo acto realizado mediante usuarios, claves o accesos habilitados por EL CLIENTE se presumirá efectuado por personal autorizado de EL CLIENTE, salvo prueba fehaciente en contrario. EL PRESTADOR no responderá por daños derivados de claves compartidas, usuarios no dados de baja oportunamente, accesos otorgados indebidamente, errores de personal, carga incorrecta de información, uso negligente, falta de capacitación o incumplimiento de protocolos internos de EL CLIENTE.</div>

  <div class="clausula"><span class="num">DÉCIMA TERCERA — Continuidad del servicio, interrupciones y fallas de hosting.</span> EL PRESTADOR asume el compromiso de brindar el servicio de manera continua, procurando evitar interrupciones imputables a su propia gestión técnica, debiendo mantener el Sistema operativo dentro de criterios razonables, atender incidentes, ejecutar correcciones necesarias y monitorear la prestación en la medida de sus posibilidades.
  <br><br>Cuando la interrupción, caída o intermitencia provenga del proveedor de hosting, del datacenter, de redes externas, del proveedor de internet, de servicios de infraestructura, de contingencias técnicas de terceros o de hechos que excedan el control directo de EL PRESTADOR, este no será considerado responsable directo por la falla originaria, siempre que informe adecuadamente a EL CLIENTE y despliegue gestiones razonables para procurar la normalización del servicio.
  <br><br>Si las fallas del proveedor externo se reiteraran o evidenciaran que la infraestructura contratada no resulta apta para la necesidad real de EL CLIENTE, EL PRESTADOR deberá comunicar tal circunstancia y podrá recomendar la contratación de un proveedor más adecuado, una infraestructura superior o una arquitectura más robusta, quedando dicha decisión sujeta a acuerdo entre LAS PARTES respecto de los costos adicionales que pudiera implicar.</div>

  <div class="clausula"><span class="num">DÉCIMA CUARTA — Soporte, mantenimiento y actualizaciones.</span> EL PRESTADOR brindará soporte técnico remoto destinado a atender errores propios del Sistema, consultas razonables de funcionamiento, incidentes de acceso, fallas técnicas reportadas y mantenimiento general, en días y horarios razonables, salvo situaciones críticas que requieran atención prioritaria conforme a la disponibilidad técnica de EL PRESTADOR.
  <br><br>EL PRESTADOR podrá realizar actualizaciones, ajustes, mejoras, correcciones, modificaciones de interfaz, optimizaciones, cambios de arquitectura, refactorizaciones, nuevas funcionalidades o eliminación de componentes obsoletos, cuando lo considere conveniente para la seguridad, estabilidad, continuidad, rendimiento, escalabilidad o evolución del Sistema. EL CLIENTE acepta que el software puede cambiar con el tiempo y que tales modificaciones no constituirán incumplimiento contractual mientras no alteren sustancialmente la finalidad principal del servicio.</div>

  <div class="clausula"><span class="num">DÉCIMA QUINTA — Propiedad intelectual.</span> El Sistema <?= $marcaPrestador ?>, su código, estructura, diseño, lógica funcional, base conceptual, interfaces, documentación, marca, nombre comercial, desarrollos, módulos, configuraciones, mejoras, actualizaciones, componentes propios, know-how, repositorios y demás elementos técnicos o creativos pertenecen exclusivamente a EL PRESTADOR o a quien legalmente corresponda dentro de su estructura de titularidad.
  <br><br>EL CLIENTE no podrá copiar, reproducir, distribuir, modificar, adaptar, traducir, sublicenciar, vender, alquilar, transferir, publicar, descompilar, realizar ingeniería inversa, explotar comercialmente, clonar, replicar ni utilizar el Sistema fuera de los límites expresamente autorizados. Cualquier uso no autorizado constituirá incumplimiento grave y habilitará a EL PRESTADOR a suspender el servicio, rescindir el contrato e iniciar las acciones legales pertinentes.</div>

  <div class="clausula"><span class="num">DÉCIMA SEXTA — Responsabilidad por daños y perjuicios.</span> EL PRESTADOR responderá por los daños directos que pudieran derivarse de incumplimientos graves, dolo, culpa grave, negligencia manifiesta o inobservancia injustificada de obligaciones esenciales asumidas en el presente contrato, siempre que tales daños sean debidamente acreditados, directa y causalmente atribuibles a EL PRESTADOR, y no provengan de hechos de terceros, fallas de hosting, errores de EL CLIENTE, mal uso del Sistema, caso fortuito, fuerza mayor, ataques externos inevitables o circunstancias ajenas a su control razonable.
  <br><br>Cualquier demanda, acción, medida judicial, mediación, ejecución, intimación formal o controversia derivada directa o indirectamente del presente contrato deberá tramitar exclusivamente ante los tribunales competentes de la ciudad de Comodoro Rivadavia, Provincia del Chubut, lugar de celebración del presente contrato, con renuncia expresa de LAS PARTES a cualquier otro fuero, jurisdicción o competencia territorial que pudiera corresponder.
  <br><br>Las partes acuerdan que no procederán reclamos desproporcionados, indirectos, hipotéticos, eventuales, punitivos, especulativos o desvinculados causalmente del hecho imputado. En ningún caso EL PRESTADOR será responsable por decisiones médicas, actos profesionales, errores de diagnóstico, tratamientos, omisiones clínicas, reclamos de pacientes derivados de la prestación sanitaria, incumplimientos administrativos de EL CLIENTE, uso indebido por personal autorizado o falta de observancia de normas internas hospitalarias.</div>

  <div class="clausula"><span class="num">DÉCIMA SÉPTIMA — Indemnidad a favor del Prestador.</span> EL CLIENTE se obliga a mantener indemne a EL PRESTADOR frente a reclamos, denuncias, sanciones, demandas, acciones administrativas, daños, multas, costas, honorarios o cualquier otra consecuencia derivada del uso institucional del Sistema, de la información cargada por EL CLIENTE, del tratamiento de datos sin consentimiento suficiente, de errores médicos o administrativos, de accesos indebidos causados por usuarios del CLIENTE, de incumplimientos normativos sanitarios propios de EL CLIENTE o de cualquier hecho que no sea directamente imputable a EL PRESTADOR.
  <br><br>Esta indemnidad no alcanzará aquellos supuestos en los que se acredite de manera fehaciente que el daño fue provocado directa y exclusivamente por dolo, culpa grave o incumplimiento esencial imputable a EL PRESTADOR.</div>

  <div class="clausula"><span class="num">DÉCIMA OCTAVA — Fuerza mayor y caso fortuito.</span> Ninguna de LAS PARTES será responsable por incumplimientos o demoras ocasionadas por hechos imprevisibles, inevitables o ajenos a su control razonable, incluyendo desastres naturales, incendios, inundaciones, terremotos, cortes masivos de energía, interrupciones generales de internet, fallas de telecomunicaciones, ataques informáticos de gran escala, conflictos laborales, actos de autoridad pública, restricciones gubernamentales, guerras, conmoción social, pandemias, fallas generalizadas de infraestructura, indisponibilidad de proveedores críticos o cualquier otro evento que razonablemente impida o dificulte la prestación normal del servicio.
  <br><br>La parte afectada deberá comunicar la situación a la otra en cuanto resulte posible y adoptar medidas razonables para mitigar sus efectos.</div>

  <div class="clausula"><span class="num">DÉCIMA NOVENA — Rescisión por incumplimiento.</span> Cualquiera de LAS PARTES podrá rescindir el presente contrato en caso de incumplimiento grave de la otra parte, previa intimación fehaciente para que subsane el incumplimiento dentro de un plazo razonable, salvo que por la gravedad del hecho, riesgo para la seguridad, mora prolongada, uso ilícito, violación de confidencialidad, afectación de propiedad intelectual o imposibilidad de continuidad resulte procedente la rescisión inmediata.
  <br><br>La rescisión no afectará los derechos adquiridos, obligaciones pendientes, pagos devengados, cláusulas de confidencialidad, propiedad intelectual, responsabilidad, indemnidad, entrega de datos ni jurisdicción pactada.</div>

  <div class="clausula"><span class="num">VIGÉSIMA — Ley aplicable y jurisdicción exclusiva.</span> El presente contrato se regirá e interpretará exclusivamente conforme a las leyes de la República Argentina. LAS PARTES se someten en forma expresa, voluntaria, irrevocable y exclusiva a la jurisdicción de los tribunales ordinarios competentes de la ciudad de Comodoro Rivadavia, Provincia del Chubut, República Argentina, lugar de celebración del contrato, con renuncia expresa a cualquier otro fuero, jurisdicción, competencia territorial, federal, provincial o internacional que pudiera corresponder.
  <br><br>En consecuencia, cualquier demanda que EL CLIENTE pretenda iniciar contra EL PRESTADOR, su titular, responsable legal, representante, apoderado o persona jurídicamente legitimada, deberá ser promovida ante los tribunales competentes de la ciudad de Comodoro Rivadavia, no pudiendo reclamar válidamente la intervención de tribunales de otra ciudad, provincia o jurisdicción, salvo disposición legal imperativa en contrario.</div>

  <div class="clausula"><span class="num">VIGÉSIMA PRIMERA — Aceptación final.</span> Leído que fue el presente por LAS PARTES, y en prueba de conformidad con todas y cada una de sus cláusulas, obligaciones, declaraciones, limitaciones, responsabilidades, derechos y condiciones, se firman dos ejemplares de un mismo tenor y a un solo efecto, en la ciudad de Comodoro Rivadavia, Provincia del Chubut, República Argentina, en la fecha indicada al inicio.</div>

  <div class="lugar-fecha-firma">
    Comodoro Rivadavia, Provincia del Chubut, República Argentina — <?= fechaLegibleContrato($contrato['fecha_contrato']) ?>
  </div>

  <div class="firma-bloque">
    <div class="firma-item">
      <?php if (!empty($contrato['firma_apoderado_png'])): ?>
        <img src="<?= e($contrato['firma_apoderado_png']) ?>" class="imagen-firma" alt="Firma del apoderado">
      <?php endif; ?>
      <div class="linea-firma-contrato"></div>
      <strong><?= e($contrato['prestador_apoderado_nombre']) ?></strong>
      <span class="rol-firma">Apoderado de <?= $marcaPrestador ?></span>
    </div>
    <div class="firma-item">
      <?php if (!empty($contrato['firma_cliente_png'])): ?>
        <img src="<?= e($contrato['firma_cliente_png']) ?>" class="imagen-firma" alt="Firma del apoderado del cliente">
      <?php endif; ?>
      <div class="linea-firma-contrato"></div>
      <strong><?= e($contrato['razon_social_cliente']) ?></strong>
      <span class="rol-firma">CUIT/DNI <?= e($contrato['cuit_dni_cliente']) ?></span>
    </div>
  </div>

  <?php if (!empty($contrato['firma_cliente_png'])): ?>
  <p class="nota-firma-digital">Firmado digitalmente por el Apoderado del CLIENTE<?= !empty($contrato['firma_cliente_sincronizada_en']) ? (' el ' . fechaLegibleContrato($contrato['firma_cliente_sincronizada_en'])) : '' ?>.</p>
  <?php endif; ?>

  <!-- ============================================================ -->
  <!-- ANEXO I — ACUERDO DE NIVEL DE SERVICIO (SLA)                  -->
  <!-- ============================================================ -->
  <div class="bloque-anexo">
    <div class="barra-titulo-anexo"><h2 class="titulo-anexo">Anexo I — Acuerdo de Nivel de Servicio (SLA)</h2></div>
    <p class="subtitulo-anexo">Complementario al contrato principal entre <?= $marcaPrestador ?> y <?= e($contrato['razon_social_cliente']) ?>.</p>

    <div class="clausula">En complemento del contrato principal, las partes convienen que la prestación del servicio tecnológico brindado por EL PRESTADOR se desarrollará conforme a parámetros de disponibilidad, continuidad, mantenimiento y respuesta que, sin constituir garantía absoluta de funcionamiento ininterrumpido, establecen un marco de referencia técnico y operativo para evaluar el correcto desempeño del sistema.</div>

    <div class="clausula">EL PRESTADOR se compromete a procurar que el sistema se mantenga disponible de manera continua en todo aquello que dependa directa y razonablemente de su propia infraestructura lógica, desarrollo del software, administración del entorno y mantenimiento técnico bajo su control, comprometiéndose a actuar con la diligencia propia de un proveedor especializado en servicios tecnológicos.</div>

    <div class="clausula">No obstante ello, ambas partes reconocen que la disponibilidad efectiva del sistema puede verse afectada por factores externos tales como la infraestructura de hosting, proveedores de conectividad, fallas de red, interrupciones eléctricas, mantenimientos programados del proveedor de servidores, incidentes en centros de datos, ataques informáticos dirigidos a terceros o caídas de servicios globales de los cuales depende indirectamente la prestación. Dichas situaciones, en la medida en que no resulten imputables a una conducta negligente de EL PRESTADOR, no constituirán incumplimiento contractual.</div>

    <div class="clausula">EL PRESTADOR deberá, ante cualquier interrupción significativa, actuar de buena fe técnica, informando a EL CLIENTE dentro de un plazo razonable sobre la existencia del incidente, sus posibles causas, el grado de afectación estimado y las acciones implementadas para su mitigación o resolución. En aquellos casos en que las fallas provengan de manera reiterada de proveedores externos de infraestructura, EL PRESTADOR deberá comunicar esta situación y podrá recomendar la migración hacia soluciones más robustas, quedando dicha decisión sujeta a acuerdo entre las partes, especialmente en lo que respecta a costos adicionales.</div>

    <div class="clausula">El mantenimiento del sistema podrá implicar interrupciones programadas cuando ello resulte necesario para preservar la seguridad, integridad, actualización o estabilidad del servicio. En tales casos, EL PRESTADOR procurará realizar dichas tareas en horarios de bajo impacto operativo y, en la medida de lo posible, informarlas previamente.</div>

    <div class="clausula">Se entiende que la obligación asumida por EL PRESTADOR es una obligación de medios calificada, en la cual se compromete a desplegar todos los esfuerzos técnicos razonables para garantizar la continuidad del servicio, sin que ello implique asumir una obligación de resultado absoluto frente a eventos ajenos a su control.</div>
  </div>

  <!-- ============================================================ -->
  <!-- ANEXO II — POLÍTICA DE SOPORTE Y MANTENIMIENTO                -->
  <!-- ============================================================ -->
  <div class="bloque-anexo">
    <div class="barra-titulo-anexo"><h2 class="titulo-anexo">Anexo II — Política de Soporte y Mantenimiento</h2></div>

    <div class="clausula">El soporte técnico brindado por EL PRESTADOR tiene por finalidad asistir a EL CLIENTE en el uso del sistema, solucionar fallas propias del software, mantener la operatividad general y resolver incidentes técnicos que afecten la normal prestación del servicio.</div>

    <div class="clausula">Dicho soporte se desarrollará primordialmente de forma remota, mediante canales digitales, dentro de horarios razonables de trabajo, salvo situaciones excepcionales que requieran intervención fuera de dichos horarios por la criticidad del sistema o la urgencia del incidente reportado.</div>

    <div class="clausula">EL PRESTADOR no asume la obligación de prestar soporte ilimitado, ni de asistir en forma irrestricta ante cualquier tipo de requerimiento, sino únicamente en aquellos casos que se encuentren directamente relacionados con el funcionamiento del sistema provisto. Quedan expresamente excluidos del alcance del soporte los inconvenientes derivados de infraestructura propia de EL CLIENTE, tales como fallas en equipos, redes internas, conectividad, problemas eléctricos, mala configuración local, uso indebido del sistema o errores humanos de los operadores.</div>

    <div class="clausula">Asimismo, EL PRESTADOR podrá realizar tareas de mantenimiento preventivo, correctivo o evolutivo sobre el sistema, incluyendo actualización de componentes, corrección de errores, mejoras de seguridad, optimización de rendimiento y adaptación a cambios tecnológicos. Tales acciones podrán generar modificaciones parciales en el funcionamiento del sistema, sin que ello implique incumplimiento contractual.</div>

    <div class="clausula">Cuando las condiciones del servicio lo ameriten, especialmente ante crecimiento en el volumen de usuarios o exigencias técnicas superiores, EL PRESTADOR podrá sugerir mejoras en la infraestructura subyacente, requiriendo la colaboración de EL CLIENTE para adoptar dichas medidas, en tanto la calidad del servicio está directamente vinculada con los recursos disponibles.</div>
  </div>

  <!-- ============================================================ -->
  <!-- ANEXO III — PROTECCIÓN DE DATOS EN ENTORNOS DE SALUD          -->
  <!-- ============================================================ -->
  <div class="bloque-anexo">
    <div class="barra-titulo-anexo"><h2 class="titulo-anexo">Anexo III — Política de Protección de Datos y Confidencialidad en Entornos de Salud</h2></div>

    <div class="clausula">En el marco de la prestación del servicio, EL PRESTADOR podrá tomar contacto indirecto, alojar, procesar o gestionar información vinculada a la salud de personas humanas, considerada como dato sensible conforme a la legislación argentina vigente.</div>

    <div class="clausula">EL CLIENTE reconoce expresamente que la titularidad, responsabilidad legal y control sobre los datos corresponde exclusivamente a su institución, en su carácter de responsable del tratamiento, siendo EL PRESTADOR un proveedor tecnológico que actúa únicamente como soporte instrumental para la gestión digital de dicha información.</div>

    <div class="clausula">EL CLIENTE declara que dispone, o deberá disponer bajo su exclusiva responsabilidad, de todos los consentimientos necesarios de los pacientes, políticas internas de privacidad, medidas organizativas, protocolos de acceso y cumplimiento de normativa sanitaria aplicable, incluyendo pero no limitado a la Ley 25.326.</div>

    <div class="clausula">EL PRESTADOR, por su parte, se obliga a no acceder a la información almacenada salvo en los casos en que resulte estrictamente necesario para la prestación técnica del servicio, tales como tareas de mantenimiento, solución de incidentes o requerimientos técnicos específicos. En todo caso, dicho acceso deberá ser limitado, razonable y acorde al principio de minimización.</div>

    <div class="clausula">La información contenida en el sistema será tratada con carácter confidencial, y EL PRESTADOR adoptará medidas razonables de seguridad acordes al tipo de servicio, sin que ello implique garantizar un nivel absoluto de protección frente a todos los riesgos existentes en el ámbito digital.</div>

    <div class="clausula">Se deja expresa constancia de que EL PRESTADOR no realiza análisis clínicos, no valida datos médicos, no participa en decisiones sanitarias ni asume responsabilidad alguna derivada del contenido de las historias clínicas, diagnósticos o tratamientos registrados en el sistema.</div>

    <div class="clausula">En caso de requerimiento judicial o administrativo válido, EL PRESTADOR podrá verse obligado a colaborar con autoridades competentes, en la medida estrictamente necesaria y conforme a derecho.</div>

    <div class="clausula">Finalmente, las obligaciones de confidencialidad aquí asumidas continuarán vigentes incluso después de la finalización del contrato, cualquiera sea la causa de su extinción.</div>
  </div>

  <div class="pie-contrato">
    <div class="mascota-pie">
      <svg viewBox="0 0 100 100" fill="none">
        <path d="M50 8 L54 26 L64 12 L60 30 L76 20 L66 36 L84 32 L68 44 L86 46 L68 52 L82 62 L64 58 L72 74 L56 64 L58 82 L48 68 L40 84 L38 66 L24 76 L30 60 L14 64 L26 50 L10 46 L28 42 L16 28 L34 34 L32 16 L44 28 Z" fill="#FF7A2E"/>
        <circle cx="50" cy="58" r="22" fill="#1A1A1A"/>
        <circle cx="42" cy="55" r="3.5" fill="white"/>
        <circle cx="58" cy="55" r="3.5" fill="white"/>
        <path d="M42 66 Q50 71 58 66" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>
      </svg>
    </div>
    <?= e($contrato['nombre_institucion']) ?> · <?= $marcaPrestador ?> · Comodoro Rivadavia, Chubut, Argentina
  </div>

  <?php
    $cuerpoDocumento = ob_get_clean();
    // Se imprime el mismo contenido dos veces: el contrato establece
    // que se firman dos ejemplares de un mismo tenor y a un solo
    // efecto. Cada copia lleva su propia etiqueta y su propio bloque
    // de firma, separadas por un salto de página.
    echo str_replace('__ETIQUETA_EJEMPLAR__', 'Ejemplar Uno', $cuerpoDocumento);
    echo '<div class="salto-ejemplar"></div>';
    echo str_replace('__ETIQUETA_EJEMPLAR__', 'Ejemplar Dos', $cuerpoDocumento);
  ?>

  <?php if ($incluirBarraExportar): ?>
  <script>
    var barraExportar = document.querySelector('.barra-exportar');
    window.addEventListener('beforeprint', function () { if (barraExportar) barraExportar.style.display = 'none'; });
    window.addEventListener('afterprint', function () { if (barraExportar) barraExportar.style.display = 'flex'; });
  </script>
  <?php endif; ?>

</body>
</html>
<?php
    return ob_get_clean();
}

const API_MAESTRO = 'api_maestro.php';

function svgMascota(ancho) {
  return `<img class="mascota" src="assets/img/mascota.png" alt="Mascota" style="width:${ancho}px; height:${ancho}px; border-radius:50%; object-fit:cover; display:block; margin:0 auto;">`;
}

async function llamarApiMaestro(url, opciones = {}) {
  const respuesta = await fetch(url, {
    headers: { 'Content-Type': 'application/json' },
    ...opciones,
  });
  let datos;
  try {
    datos = await respuesta.json();
  } catch (e) {
    throw new Error('El servidor devolvió una respuesta inesperada.');
  }
  if (!respuesta.ok || !datos.ok) {
    throw new Error(datos.error || 'Ocurrió un error inesperado.');
  }
  return datos;
}

function mostrarErrorLogin(mensaje) {
  const el = document.getElementById('error-login-maestro');
  el.textContent = mensaje;
  el.classList.remove('oculto');
}

function ocultarErrorLogin() {
  document.getElementById('error-login-maestro').classList.add('oculto');
}

async function inicializarPanelMaestro() {
  let claveYaConfigurada = true;
  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=estado`, { method: 'GET' });
    claveYaConfigurada = res.clave_configurada;
  } catch (e) {
    mostrarErrorLogin('No se pudo conectar con el servidor.');
  }

  if (!claveYaConfigurada) {
    document.getElementById('titulo-login-maestro').textContent = 'Configuración inicial';
    document.getElementById('desc-login-maestro').textContent = 'Es la primera vez que entrás. Elegí tu clave de Super Admin (mínimo 6 caracteres).';
    document.getElementById('btn-submit-login-maestro').textContent = 'Crear clave y entrar';
  }

  document.getElementById('form-login-maestro').addEventListener('submit', async (e) => {
    e.preventDefault();
    ocultarErrorLogin();
    const clave = document.getElementById('input-clave-maestro').value;
    if (!clave) { mostrarErrorLogin('Ingresá una clave.'); return; }

    const btn = document.getElementById('btn-submit-login-maestro');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    try {
      const accion = claveYaConfigurada ? 'login' : 'configurar_clave_inicial';
      await llamarApiMaestro(`${API_MAESTRO}?accion=${accion}`, {
        method: 'POST',
        body: JSON.stringify({ accion, clave }),
      });
      mostrarDashboard();
    } catch (err) {
      mostrarErrorLogin(err.message);
      btn.disabled = false;
      btn.textContent = claveYaConfigurada ? 'Entrar' : 'Crear clave y entrar';
    }
  });

  document.getElementById('btn-cerrar-sesion-maestro').addEventListener('click', async () => {
    await llamarApiMaestro(`${API_MAESTRO}?accion=cerrar_sesion`, { method: 'POST', body: JSON.stringify({}) });
    location.reload();
  });
}

function mostrarDashboard() {
  document.getElementById('vista-login').classList.add('oculto');
  document.getElementById('vista-dashboard').classList.remove('oculto');
  document.getElementById('rail-navegacion').classList.remove('oculto');
  document.getElementById('btn-cerrar-sesion-maestro').classList.remove('oculto');
  cargarListaInstituciones();
  cargarContadorBugsPendientes();
  cargarEstadoMantenimiento();
}

async function cargarEstadoMantenimiento() {
  const btn = document.getElementById('btn-toggle-mantenimiento');
  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=obtener_mantenimiento`, { method: 'GET' });
    aplicarEstadoBotonMantenimiento(res.activo);
  } catch (e) {
    btn.textContent = 'Mantenimiento (error)';
  }
}

function aplicarEstadoBotonMantenimiento(activo) {
  const btn = document.getElementById('btn-toggle-mantenimiento');
  btn.classList.toggle('peligro', activo);
  btn.classList.toggle('secundario', !activo);
  btn.textContent = activo ? '🔴 Desactivar mantenimiento' : 'Activar mantenimiento';
  btn.dataset.activo = activo ? '1' : '0';
}

document.getElementById('btn-toggle-mantenimiento').addEventListener('click', async () => {
  const btn = document.getElementById('btn-toggle-mantenimiento');
  const activo = btn.dataset.activo === '1';

  if (!activo) {
    const confirmado = confirm(
      '¿Activar el modo mantenimiento?\n\nNadie va a poder ingresar a NINGUNA institución (salvo los Apoderados) hasta que lo desactives desde acá.'
    );
    if (!confirmado) return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-maestro"></span>';
  try {
    const accion = activo ? 'desactivar_mantenimiento' : 'activar_mantenimiento';
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=${accion}`, { method: 'POST' });
    aplicarEstadoBotonMantenimiento(res.activo);
  } catch (e) {
    alert('No se pudo cambiar el estado de mantenimiento: ' + e.message);
    aplicarEstadoBotonMantenimiento(activo);
  } finally {
    btn.disabled = false;
  }
});

let INTERVALO_SALUD_SISTEMA = null;

function mostrarVistaMaestro(nombreVista) {
  document.getElementById('vista-dashboard').classList.toggle('oculto', nombreVista !== 'instituciones');
  document.getElementById('vista-reportes-bug').classList.toggle('oculto', nombreVista !== 'reportes-bug');
  document.getElementById('vista-salud-sistema').classList.toggle('oculto', nombreVista !== 'salud-sistema');
  document.getElementById('vista-cobranza').classList.toggle('oculto', nombreVista !== 'cobranza');
  document.getElementById('nav-instituciones').classList.toggle('activo', nombreVista === 'instituciones');
  document.getElementById('nav-reportes-bug').classList.toggle('activo', nombreVista === 'reportes-bug');
  document.getElementById('nav-salud-sistema').classList.toggle('activo', nombreVista === 'salud-sistema');
  document.getElementById('nav-cobranza').classList.toggle('activo', nombreVista === 'cobranza');
  if (nombreVista === 'reportes-bug') cargarReportesBugMaestro();
  if (nombreVista === 'cobranza') cargarVistaCobranza();

  // El auto-refresco de salud del sistema solo corre mientras esa
  // vista está efectivamente abierta, para no gastar conexiones a
  // las bases de cada institución de fondo sin que nadie lo vea.
  if (INTERVALO_SALUD_SISTEMA) {
    clearInterval(INTERVALO_SALUD_SISTEMA);
    INTERVALO_SALUD_SISTEMA = null;
  }
  if (nombreVista === 'salud-sistema') {
    cargarSaludSistema();
    INTERVALO_SALUD_SISTEMA = setInterval(cargarSaludSistema, 60000);
  }
}

document.getElementById('nav-instituciones').addEventListener('click', () => mostrarVistaMaestro('instituciones'));
document.getElementById('nav-salud-sistema').addEventListener('click', () => mostrarVistaMaestro('salud-sistema'));
document.getElementById('nav-reportes-bug').addEventListener('click', () => mostrarVistaMaestro('reportes-bug'));
document.getElementById('nav-cobranza').addEventListener('click', () => mostrarVistaMaestro('cobranza'));

async function cargarContadorBugsPendientes() {
  const badge = document.getElementById('badge-contador-bugs');
  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=listar_reportes_bug`, { method: 'GET' });
    const pendientes = res.datos.filter(r => r.estado === 'nuevo').length;
    if (pendientes > 0) {
      badge.textContent = pendientes;
      badge.classList.remove('oculto');
    } else {
      badge.classList.add('oculto');
    }
  } catch (e) {
    badge.classList.add('oculto');
  }
}

document.getElementById('select-plazo-tipo-contrato').addEventListener('change', (e) => {
  document.getElementById('campo-plazo-cantidad').classList.toggle('oculto', e.target.value === 'indeterminado');
});

document.getElementById('select-editar-plazo-tipo-contrato').addEventListener('change', (e) => {
  document.getElementById('campo-editar-plazo-cantidad').classList.toggle('oculto', e.target.value === 'indeterminado');
});

document.getElementById('input-fecha-contrato').value = new Date().toLocaleDateString('es-AR');

// --- Canvas de firma del apoderado (obligatorio para crear) ---
let FIRMA_APODERADO_DATAURL = null;

function inicializarCanvasFirmaApoderado() {
  const canvas = document.getElementById('canvas-firma-apoderado');
  const ctx = canvas.getContext('2d');
  ctx.lineWidth = 2.4;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#14181B';
  let dibujando = false;
  let huboTrazo = false;

  function actualizarBotonCrear() {
    const btn = document.getElementById('btn-crear-institucion');
    btn.disabled = !huboTrazo;
    btn.textContent = huboTrazo ? 'Crear institución' : 'Firmá el contrato para continuar';
  }

  function posicionDesdeEvento(e) {
    const rect = canvas.getBoundingClientRect();
    const escalaX = canvas.width / rect.width;
    const escalaY = canvas.height / rect.height;
    const punto = e.touches ? e.touches[0] : e;
    return { x: (punto.clientX - rect.left) * escalaX, y: (punto.clientY - rect.top) * escalaY };
  }
  function empezarTrazo(e) {
    e.preventDefault();
    dibujando = true;
    huboTrazo = true;
    actualizarBotonCrear();
    const p = posicionDesdeEvento(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
  }
  function seguirTrazo(e) {
    if (!dibujando) return;
    e.preventDefault();
    const p = posicionDesdeEvento(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }
  function terminarTrazo() { dibujando = false; }

  canvas.addEventListener('mousedown', empezarTrazo);
  canvas.addEventListener('mousemove', seguirTrazo);
  canvas.addEventListener('mouseup', terminarTrazo);
  canvas.addEventListener('mouseleave', terminarTrazo);
  canvas.addEventListener('touchstart', empezarTrazo, { passive: false });
  canvas.addEventListener('touchmove', seguirTrazo, { passive: false });
  canvas.addEventListener('touchend', terminarTrazo);

  document.getElementById('btn-limpiar-firma-apoderado').addEventListener('click', () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    huboTrazo = false;
    actualizarBotonCrear();
  });

  actualizarBotonCrear();

  return {
    obtenerDataUrl: () => canvas.toDataURL('image/png'),
    hayTrazo: () => huboTrazo,
  };
}

const CANVAS_FIRMA_APODERADO = inicializarCanvasFirmaApoderado();

document.getElementById('btn-crear-institucion').addEventListener('click', async () => {
  const nombre = document.getElementById('input-nombre-institucion-nueva').value.trim();
  const carpeta = document.getElementById('input-carpeta-institucion-nueva').value.trim();
  if (!nombre) { alert('Ingresá el nombre de la institución.'); return; }

  const razonSocial = document.getElementById('input-razon-social-cliente').value.trim();
  const cuitDni = document.getElementById('input-cuit-dni-cliente').value.trim();
  if (!razonSocial || !cuitDni) {
    alert('Completá la razón social y el CUIT/DNI del cliente: los datos del contrato son obligatorios.');
    return;
  }
  if (!CANVAS_FIRMA_APODERADO.hayTrazo()) {
    alert('Falta firmar el contrato como apoderado antes de crear la institución.');
    return;
  }

  const datosContrato = {
    razon_social_cliente: razonSocial,
    cuit_dni_cliente: cuitDni,
    plazo_tipo: document.getElementById('select-plazo-tipo-contrato').value,
    plazo_cantidad: document.getElementById('input-plazo-cantidad-contrato').value.trim() || null,
    modalidad_pago: document.getElementById('select-modalidad-pago-contrato').value,
    precio_monto: document.getElementById('input-precio-monto-contrato').value.trim() || null,
    precio_moneda: document.getElementById('select-precio-moneda-contrato').value,
    ram_gb: document.getElementById('input-ram-contrato').value.trim() || '16',
    disco_gb: document.getElementById('input-disco-contrato').value.trim() || '25',
    backup_horario: document.getElementById('input-backup-horario-contrato').value.trim() || '3 a 5 A.M.',
    ubicacion_servidor: document.getElementById('input-ubicacion-servidor-contrato').value.trim() || 'Santiago de Chile, Chile',
    prestador_marca: document.getElementById('input-prestador-marca-contrato').value.trim() || 'DEL AUSTRAL',
    prestador_titular_nombre: document.getElementById('input-prestador-titular-nombre-contrato').value.trim() || 'MONTERO, FABIANA KARINA',
    prestador_titular_cuil: document.getElementById('input-prestador-titular-cuil-contrato').value.trim() || '27-20746451-7',
    prestador_apoderado_nombre: document.getElementById('input-prestador-apoderado-nombre-contrato').value.trim() || 'LORENZ MONTERO, ARIAN TAHIEL',
    prestador_apoderado_cuil: document.getElementById('input-prestador-apoderado-cuil-contrato').value.trim() || '20-46143095-4',
    tolerancia_mora_meses: document.getElementById('input-tolerancia-mora-contrato').value.trim() || '2',
    plazo_entrega_datos_dias: document.getElementById('input-plazo-entrega-datos-contrato').value.trim() || '7',
    preaviso_rescision_dias: document.getElementById('input-preaviso-rescision-contrato').value.trim() || '30',
  };

  const btn = document.getElementById('btn-crear-institucion');
  const resultadoDiv = document.getElementById('resultado-creacion-institucion');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-maestro"></span>';
  resultadoDiv.classList.add('oculto');

  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=crear_institucion`, {
      method: 'POST',
      body: JSON.stringify({ nombre, carpeta, contrato: datosContrato }),
    });

    // La institución ya quedó creada con su contrato. Ahora
    // registramos la firma del apoderado sobre ese contrato.
    // Si esto fallara, la institución y el contrato ya existen
    // (no se deshace lo anterior); se puede reintentar la firma
    // desde "Editar contrato" en el listado.
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=firmar_apoderado`, {
        method: 'POST',
        body: JSON.stringify({ institucion_id: res.institucion_id, firma_png: CANVAS_FIRMA_APODERADO.obtenerDataUrl() }),
      });
    } catch (eFirma) {
      resultadoDiv.classList.remove('oculto');
      resultadoDiv.className = 'resultado-creacion';
      resultadoDiv.innerHTML = `
        ⚠️ Institución creada, pero hubo un problema al guardar la firma: ${escaparHtmlMaestro(eFirma.message)}<br>
        <span style="color:var(--tinta-suave);">Podés firmar de nuevo desde "Editar contrato" en el listado de abajo.</span>
      `;
      document.getElementById('input-nombre-institucion-nueva').value = '';
      document.getElementById('input-carpeta-institucion-nueva').value = '';
      document.getElementById('input-razon-social-cliente').value = '';
      document.getElementById('input-cuit-dni-cliente').value = '';
      cargarListaInstituciones();
      return;
    }

    resultadoDiv.classList.remove('oculto');
    resultadoDiv.className = 'resultado-creacion';
    resultadoDiv.innerHTML = `
      Institución creada y contrato firmado correctamente.<br>
      URL: <a href="${res.url}" target="_blank">${res.url}</a><br>
      <span style="color:var(--tinta-suave);">Entrá a esa dirección: el apoderado va a tener que firmar el contrato de su lado antes de poder crear su clave de acceso.</span>
    `;
    document.getElementById('input-nombre-institucion-nueva').value = '';
    document.getElementById('input-carpeta-institucion-nueva').value = '';
    document.getElementById('input-razon-social-cliente').value = '';
    document.getElementById('input-cuit-dni-cliente').value = '';
    cargarListaInstituciones();
  } catch (e) {
    resultadoDiv.classList.remove('oculto');
    resultadoDiv.className = 'resultado-creacion';
    resultadoDiv.style.borderColor = 'var(--rosa)';
    resultadoDiv.style.background = '#FFE8EC';
    resultadoDiv.innerHTML = `<span style="color:var(--rosa);">${e.message}</span>`;
  } finally {
    btn.disabled = false;
    btn.textContent = CANVAS_FIRMA_APODERADO.hayTrazo() ? 'Crear institución' : 'Firmá el contrato para continuar';
  }
});

async function cargarListaInstituciones() {
  const cont = document.getElementById('lista-instituciones');
  const resumen = document.getElementById('resumen-rack-instituciones');
  cont.innerHTML = '<p class="resumen-vacio-maestro">Cargando…</p>';
  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=listar`, { method: 'GET' });
    renderizarResumenRack(res.datos, resumen);
    if (!res.datos.length) {
      cont.innerHTML = `<div class="resumen-vacio-maestro">${svgMascota(56)}Todavía no creaste ninguna institución.</div>`;
      return;
    }
    cont.innerHTML = '';
    res.datos.forEach(inst => cont.appendChild(crearFilaInstitucion(inst)));
  } catch (e) {
    cont.innerHTML = `<p class="resumen-vacio-maestro">No se pudo cargar la lista: ${e.message}</p>`;
  }
}

function renderizarResumenRack(instituciones, contenedor) {
  if (!contenedor) return;
  const activas = instituciones.filter(i => i.estado === 'activa').length;
  const suspendidas = instituciones.filter(i => i.estado === 'suspendida' || i.estado === 'suspendida_por_pago').length;
  const firmasPendientes = instituciones.filter(i => i.tiene_contrato && !i.firmado_cliente).length;

  contenedor.innerHTML = `
    <div class="chip-resumen chip-signal"><span class="emoji-chip">✅</span><div><div class="num">${activas}</div><div class="label">activas</div></div></div>
    <div class="chip-resumen chip-red"><span class="emoji-chip">⏸️</span><div><div class="num">${suspendidas}</div><div class="label">suspendidas</div></div></div>
    <div class="chip-resumen chip-amber"><span class="emoji-chip">✍️</span><div><div class="num">${firmasPendientes}</div><div class="label">firma pendiente</div></div></div>
  `;
}

function etiquetaCobroCorta(estado, vencimiento) {
  const vencido = vencimiento && new Date(vencimiento + 'T00:00:00') < new Date() && estado !== 'aprobado';
  const mapa = {
    pendiente: vencido ? '<span style="color:var(--rosa); font-weight:700;">vencido sin pagar</span>' : '<span style="color:var(--naranja); font-weight:700;">pendiente</span>',
    comprobante_subido: '<span style="color:var(--naranja); font-weight:700;">comprobante para revisar</span>',
    aprobado: '<span style="color:var(--lima); font-weight:700;">al día</span>',
    sin_acreditar: '<span style="color:var(--rosa); font-weight:700;">sin acreditar</span>',
    rechazado: '<span style="color:var(--rosa); font-weight:700;">rechazado</span>',
  };
  return mapa[estado] || estado;
}

function crearFilaInstitucion(inst) {
  const fila = document.createElement('div');
  fila.className = `fila-institucion rack-${inst.estado === 'activa' ? 'activa' : 'suspendida'}`;
  const fecha = new Date(inst.creado_en).toLocaleDateString('es-AR');
  const url = `${location.origin}/${inst.carpeta}/`;
  const etiquetasEstadoInst = {
    activa: '✅ Activa',
    suspendida: '⏸️ Suspendida',
    suspendida_por_pago: '💳 Suspendida por falta de pago',
  };
  fila.innerHTML = `
    <div class="bloque-nombre-inst">
      <span class="avatar-inst">${escaparHtmlMaestro(inst.nombre.charAt(0).toUpperCase())}</span>
      <div>
        <div class="nombre-inst">${escaparHtmlMaestro(inst.nombre)} <span class="estado-pill estado-${inst.estado === 'activa' ? 'activa' : 'suspendida'}">${etiquetasEstadoInst[inst.estado] || inst.estado}</span></div>
        <div class="meta-inst">/${escaparHtmlMaestro(inst.carpeta)}/ · creada el ${fecha} · <a href="${url}" target="_blank">${url}</a></div>
        ${inst.tiene_contrato ? `
          <div class="meta-inst" style="margin-top:4px;">
            Firma apoderado: ${inst.firmado_apoderado ? '<span style="color:var(--lima); font-weight:700;">✓ firmado</span>' : '<span style="color:var(--naranja); font-weight:700;">pendiente</span>'}
            &nbsp;·&nbsp;
            Firma cliente: ${inst.firmado_cliente ? '<span style="color:var(--lima); font-weight:700;">✓ firmado</span>' : '<span style="color:var(--naranja); font-weight:700;">pendiente</span>'}
          </div>
        ` : ''}
        ${inst.ultimo_cobro_estado ? `
          <div class="meta-inst" style="margin-top:4px;">
            Cobro: ${etiquetaCobroCorta(inst.ultimo_cobro_estado, inst.ultimo_cobro_vencimiento)}
          </div>
        ` : ''}
        ${inst.saldo_favor > 0 ? `
          <div class="meta-inst" style="margin-top:4px; color:var(--lima); font-weight:700;">
            Saldo a favor: $${Number(inst.saldo_favor).toLocaleString('es-AR')}
          </div>
        ` : ''}
      </div>
    </div>
    <div class="acciones-inst"></div>
  `;
  const acciones = fila.querySelector('.acciones-inst');
  const btnCambiar = document.createElement('button');
  btnCambiar.className = inst.estado === 'activa' ? 'peligro' : 'secundario';
  btnCambiar.textContent = inst.estado === 'activa' ? 'Suspender' : 'Reactivar';
  btnCambiar.addEventListener('click', async () => {
    const nuevoEstado = inst.estado === 'activa' ? 'suspendida' : 'activa';
    const confirmacion = nuevoEstado === 'suspendida'
      ? `¿Suspender "${inst.nombre}"? El sitio va a dejar de funcionar para ese cliente (INCLUSO para el Apoderado) hasta que la reactives. Los datos no se borran. Si es por falta de pago, mejor usá el botón "Suspender por falta de pago" dentro del cobro correspondiente en la pestaña Cobranza — así el Apoderado puede seguir entrando a pagar.`
      : `¿Reactivar "${inst.nombre}"? El sitio va a volver a funcionar normalmente.`;
    if (!confirm(confirmacion)) return;
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=cambiar_estado_institucion`, {
        method: 'POST',
        body: JSON.stringify({ id: inst.id, estado: nuevoEstado }),
      });
      cargarListaInstituciones();
    } catch (e) {
      alert(e.message);
    }
  });
  acciones.appendChild(btnCambiar);

  const btnEditarCuenta = document.createElement('button');
  btnEditarCuenta.className = 'secundario';
  btnEditarCuenta.textContent = 'Cuenta bancaria';
  btnEditarCuenta.addEventListener('click', () => abrirModalEditarCuenta(inst));
  acciones.appendChild(btnEditarCuenta);

  if (inst.tiene_contrato) {
    const btnContrato = document.createElement('button');
    btnContrato.className = 'secundario';
    btnContrato.textContent = 'Ver contrato';
    btnContrato.addEventListener('click', () => window.open(`generar_contrato.php?id=${inst.id}`, '_blank'));
    acciones.appendChild(btnContrato);
  }

  if (inst.tiene_contrato && !inst.firmado_cliente) {
    const btnVerificarFirma = document.createElement('button');
    btnVerificarFirma.className = 'secundario';
    btnVerificarFirma.textContent = 'Verificar firma del cliente';
    btnVerificarFirma.addEventListener('click', async () => {
      btnVerificarFirma.disabled = true;
      btnVerificarFirma.innerHTML = '<span class="spinner-maestro"></span>';
      try {
        const res = await llamarApiMaestro(`${API_MAESTRO}?accion=verificar_firma_cliente&id=${inst.id}`, { method: 'GET' });
        if (res.firmado) {
          cargarListaInstituciones();
        } else {
          alert('El apoderado todavía no firmó el contrato de su lado.');
        }
      } catch (e) {
        alert(e.message);
      } finally {
        btnVerificarFirma.disabled = false;
        btnVerificarFirma.textContent = 'Verificar firma del cliente';
      }
    });
    acciones.appendChild(btnVerificarFirma);
  }

  const btnEditarContrato = document.createElement('button');
  btnEditarContrato.className = 'secundario';
  btnEditarContrato.textContent = inst.tiene_contrato ? 'Editar contrato' : 'Completar contrato';
  btnEditarContrato.addEventListener('click', () => abrirModalEditarContrato(inst));
  acciones.appendChild(btnEditarContrato);

  const btnBorrar = document.createElement('button');
  btnBorrar.className = 'secundario';
  btnBorrar.style.color = 'var(--rosa)';
  btnBorrar.style.borderColor = 'var(--rosa)';
  btnBorrar.textContent = 'Borrar para siempre';
  btnBorrar.addEventListener('click', () => abrirModalBorrarInstitucion(inst));
  acciones.appendChild(btnBorrar);

  return fila;
}

function abrirModalBorrarInstitucion(inst) {
  const overlay = document.getElementById('overlay-borrar-institucion');
  const input = document.getElementById('input-confirmar-borrado-inst');
  input.value = '';
  document.getElementById('texto-confirmar-borrado-inst').textContent =
    `Esto borra para siempre "${inst.nombre}": su carpeta de archivos, su base de datos, su usuario MySQL, y todos sus pacientes y profesionales. No se puede deshacer.`;
  overlay.classList.remove('oculto');

  function cerrar() {
    overlay.classList.add('oculto');
    document.getElementById('btn-cancelar-borrado-inst').removeEventListener('click', cerrar);
    document.getElementById('btn-confirmar-borrado-inst').removeEventListener('click', confirmar);
  }

  async function confirmar() {
    const confirmacion = input.value.trim();
    if (confirmacion !== inst.carpeta) {
      alert(`Escribí exactamente "${inst.carpeta}" para confirmar.`);
      return;
    }
    const btn = document.getElementById('btn-confirmar-borrado-inst');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    try {
      const res = await llamarApiMaestro(`${API_MAESTRO}?accion=borrar_institucion`, {
        method: 'POST',
        body: JSON.stringify({ id: inst.id, confirmacion }),
      });
      if (res.advertencia) alert(res.advertencia);
      cerrar();
      cargarListaInstituciones();
    } catch (e) {
      alert(e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Borrar para siempre';
    }
  }

  document.getElementById('btn-cancelar-borrado-inst').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-borrado-inst').addEventListener('click', confirmar);
}

function abrirModalEditarContrato(inst) {
  const overlay = document.getElementById('overlay-editar-contrato');
  const errorP = document.getElementById('error-editar-contrato');
  errorP.classList.add('oculto');
  document.getElementById('texto-editando-contrato-de').textContent = `Institución: ${inst.nombre}`;
  document.getElementById('input-editar-institucion-id').value = inst.id;

  // Valores por defecto (igual que en el formulario de creación)
  const valoresPorDefecto = {
    razon_social_cliente: '', cuit_dni_cliente: '',
    plazo_tipo: 'indeterminado', plazo_cantidad: '', modalidad_pago: 'mensual',
    precio_monto: '', precio_moneda: 'ARS',
    ram_gb: '16', disco_gb: '25', backup_horario: '3 a 5 A.M.', ubicacion_servidor: 'Santiago de Chile, Chile',
    prestador_marca: 'DEL AUSTRAL',
    prestador_titular_nombre: 'MONTERO, FABIANA KARINA', prestador_titular_cuil: '27-20746451-7',
    prestador_apoderado_nombre: 'LORENZ MONTERO, ARIAN TAHIEL', prestador_apoderado_cuil: '20-46143095-4',
    tolerancia_mora_meses: '2', plazo_entrega_datos_dias: '7', preaviso_rescision_dias: '30',
  };

  function llenarFormulario(datos) {
    document.getElementById('input-editar-razon-social-cliente').value = datos.razon_social_cliente;
    document.getElementById('input-editar-cuit-dni-cliente').value = datos.cuit_dni_cliente;
    document.getElementById('select-editar-plazo-tipo-contrato').value = datos.plazo_tipo;
    document.getElementById('input-editar-plazo-cantidad-contrato').value = datos.plazo_cantidad ?? '';
    document.getElementById('campo-editar-plazo-cantidad').classList.toggle('oculto', datos.plazo_tipo === 'indeterminado');
    document.getElementById('select-editar-modalidad-pago-contrato').value = datos.modalidad_pago;
    document.getElementById('input-editar-precio-monto-contrato').value = datos.precio_monto ?? '';
    document.getElementById('select-editar-precio-moneda-contrato').value = datos.precio_moneda;
    document.getElementById('input-editar-ram-contrato').value = datos.ram_gb;
    document.getElementById('input-editar-disco-contrato').value = datos.disco_gb;
    document.getElementById('input-editar-backup-horario-contrato').value = datos.backup_horario;
    document.getElementById('input-editar-ubicacion-servidor-contrato').value = datos.ubicacion_servidor;
    document.getElementById('input-editar-prestador-marca-contrato').value = datos.prestador_marca;
    document.getElementById('input-editar-prestador-titular-nombre-contrato').value = datos.prestador_titular_nombre;
    document.getElementById('input-editar-prestador-titular-cuil-contrato').value = datos.prestador_titular_cuil;
    document.getElementById('input-editar-prestador-apoderado-nombre-contrato').value = datos.prestador_apoderado_nombre;
    document.getElementById('input-editar-prestador-apoderado-cuil-contrato').value = datos.prestador_apoderado_cuil;
    document.getElementById('input-editar-tolerancia-mora-contrato').value = datos.tolerancia_mora_meses;
    document.getElementById('input-editar-plazo-entrega-datos-contrato').value = datos.plazo_entrega_datos_dias;
    document.getElementById('input-editar-preaviso-rescision-contrato').value = datos.preaviso_rescision_dias;
  }

  llenarFormulario(valoresPorDefecto);
  overlay.classList.remove('oculto');

  // --- Canvas de firma del apoderado dentro del modal ---
  const canvasFirma = document.getElementById('canvas-editar-firma-apoderado');
  const ctxFirma = canvasFirma.getContext('2d');
  ctxFirma.lineWidth = 2.4;
  ctxFirma.lineCap = 'round';
  ctxFirma.strokeStyle = '#14181B';
  let huboTrazoNuevo = false;
  ctxFirma.clearRect(0, 0, canvasFirma.width, canvasFirma.height);
  document.getElementById('texto-estado-firma-apoderado').textContent = 'Firma del apoderado:';

  function posicionDesdeEventoFirma(e) {
    const rect = canvasFirma.getBoundingClientRect();
    const escalaX = canvasFirma.width / rect.width;
    const escalaY = canvasFirma.height / rect.height;
    const punto = e.touches ? e.touches[0] : e;
    return { x: (punto.clientX - rect.left) * escalaX, y: (punto.clientY - rect.top) * escalaY };
  }
  function empezarTrazoFirma(e) {
    e.preventDefault();
    huboTrazoNuevo = true;
    const p = posicionDesdeEventoFirma(e);
    ctxFirma.beginPath();
    ctxFirma.moveTo(p.x, p.y);
    canvasFirma._dibujando = true;
  }
  function seguirTrazoFirma(e) {
    if (!canvasFirma._dibujando) return;
    e.preventDefault();
    const p = posicionDesdeEventoFirma(e);
    ctxFirma.lineTo(p.x, p.y);
    ctxFirma.stroke();
  }
  function terminarTrazoFirma() { canvasFirma._dibujando = false; }

  canvasFirma.addEventListener('mousedown', empezarTrazoFirma);
  canvasFirma.addEventListener('mousemove', seguirTrazoFirma);
  canvasFirma.addEventListener('mouseup', terminarTrazoFirma);
  canvasFirma.addEventListener('mouseleave', terminarTrazoFirma);
  canvasFirma.addEventListener('touchstart', empezarTrazoFirma, { passive: false });
  canvasFirma.addEventListener('touchmove', seguirTrazoFirma, { passive: false });
  canvasFirma.addEventListener('touchend', terminarTrazoFirma);

  const btnLimpiarFirma = document.getElementById('btn-limpiar-editar-firma-apoderado');
  function limpiarFirma() {
    ctxFirma.clearRect(0, 0, canvasFirma.width, canvasFirma.height);
    huboTrazoNuevo = false;
  }
  btnLimpiarFirma.addEventListener('click', limpiarFirma);

  // Si ya existía un contrato, traemos sus valores reales para precargar.
  if (inst.tiene_contrato) {
    llamarApiMaestro(`${API_MAESTRO}?accion=obtener_contrato&id=${inst.id}`)
      .then((res) => {
        if (res.datos) llenarFormulario(res.datos);
        if (res.datos && res.datos.firma_apoderado_png) {
          document.getElementById('texto-estado-firma-apoderado').textContent = 'Firma del apoderado (ya firmado; podés firmar de nuevo si hace falta):';
        }
      })
      .catch((e) => { errorP.textContent = e.message; errorP.classList.remove('oculto'); });
  }

  function cerrar() {
    overlay.classList.add('oculto');
    canvasFirma.removeEventListener('mousedown', empezarTrazoFirma);
    canvasFirma.removeEventListener('mousemove', seguirTrazoFirma);
    canvasFirma.removeEventListener('mouseup', terminarTrazoFirma);
    canvasFirma.removeEventListener('mouseleave', terminarTrazoFirma);
    canvasFirma.removeEventListener('touchstart', empezarTrazoFirma);
    canvasFirma.removeEventListener('touchmove', seguirTrazoFirma);
    canvasFirma.removeEventListener('touchend', terminarTrazoFirma);
    btnLimpiarFirma.removeEventListener('click', limpiarFirma);
    document.getElementById('btn-cancelar-editar-contrato').removeEventListener('click', cerrar);
    document.getElementById('btn-guardar-editar-contrato').removeEventListener('click', guardar);
  }

  async function guardar() {
    const razonSocial = document.getElementById('input-editar-razon-social-cliente').value.trim();
    const cuitDni = document.getElementById('input-editar-cuit-dni-cliente').value.trim();
    if (!razonSocial || !cuitDni) {
      errorP.textContent = 'Completá al menos la razón social y el CUIT/DNI del cliente.';
      errorP.classList.remove('oculto');
      return;
    }

    const datosContrato = {
      razon_social_cliente: razonSocial,
      cuit_dni_cliente: cuitDni,
      plazo_tipo: document.getElementById('select-editar-plazo-tipo-contrato').value,
      plazo_cantidad: document.getElementById('input-editar-plazo-cantidad-contrato').value.trim() || null,
      modalidad_pago: document.getElementById('select-editar-modalidad-pago-contrato').value,
      precio_monto: document.getElementById('input-editar-precio-monto-contrato').value.trim() || null,
      precio_moneda: document.getElementById('select-editar-precio-moneda-contrato').value,
      ram_gb: document.getElementById('input-editar-ram-contrato').value.trim() || '16',
      disco_gb: document.getElementById('input-editar-disco-contrato').value.trim() || '25',
      backup_horario: document.getElementById('input-editar-backup-horario-contrato').value.trim() || '3 a 5 A.M.',
      ubicacion_servidor: document.getElementById('input-editar-ubicacion-servidor-contrato').value.trim() || 'Santiago de Chile, Chile',
      prestador_marca: document.getElementById('input-editar-prestador-marca-contrato').value.trim() || 'DEL AUSTRAL',
      prestador_titular_nombre: document.getElementById('input-editar-prestador-titular-nombre-contrato').value.trim() || 'MONTERO, FABIANA KARINA',
      prestador_titular_cuil: document.getElementById('input-editar-prestador-titular-cuil-contrato').value.trim() || '27-20746451-7',
      prestador_apoderado_nombre: document.getElementById('input-editar-prestador-apoderado-nombre-contrato').value.trim() || 'LORENZ MONTERO, ARIAN TAHIEL',
      prestador_apoderado_cuil: document.getElementById('input-editar-prestador-apoderado-cuil-contrato').value.trim() || '20-46143095-4',
      tolerancia_mora_meses: document.getElementById('input-editar-tolerancia-mora-contrato').value.trim() || '2',
      plazo_entrega_datos_dias: document.getElementById('input-editar-plazo-entrega-datos-contrato').value.trim() || '7',
      preaviso_rescision_dias: document.getElementById('input-editar-preaviso-rescision-contrato').value.trim() || '30',
    };

    const btn = document.getElementById('btn-guardar-editar-contrato');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');

    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=editar_contrato`, {
        method: 'POST',
        body: JSON.stringify({ institucion_id: inst.id, contrato: datosContrato }),
      });

      if (huboTrazoNuevo) {
        await llamarApiMaestro(`${API_MAESTRO}?accion=firmar_apoderado`, {
          method: 'POST',
          body: JSON.stringify({ institucion_id: inst.id, firma_png: canvasFirma.toDataURL('image/png') }),
        });
      }

      cerrar();
      cargarListaInstituciones();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Guardar contrato';
    }
  }

  document.getElementById('btn-cancelar-editar-contrato').addEventListener('click', cerrar);
  document.getElementById('btn-guardar-editar-contrato').addEventListener('click', guardar);
}

function escaparHtmlMaestro(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}

// ============================================================
// REPORTES DE BUG
// ============================================================

let REPORTES_BUG_CACHE = [];

const ETIQUETAS_ESTADO_BUG = {
  nuevo: { texto: '🆕 Nuevo', clase: 'chip-red' },
  visto: { texto: '👀 Visto', clase: 'chip-amber' },
  en_curso: { texto: '🔧 En curso', clase: 'chip-amber' },
  resuelto: { texto: '✅ Resuelto', clase: 'chip-signal' },
  no_resuelto: { texto: '🚫 No resuelto', clase: 'chip-red' },
};

const ETIQUETAS_SEVERIDAD_BUG = {
  visual: '🎨 Visual',
  funcional: '⚙️ Funcional',
  critico: '🚨 Crítico',
  sugerencia: '💡 Sugerencia',
};

async function cargarReportesBugMaestro() {
  const cont = document.getElementById('lista-reportes-bug-maestro');
  const resumen = document.getElementById('resumen-reportes-bug');
  cont.innerHTML = '<p class="resumen-vacio-maestro">Cargando…</p>';

  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=listar_reportes_bug`, { method: 'GET' });
    REPORTES_BUG_CACHE = res.datos;
    poblarFiltroInstitucionBug(REPORTES_BUG_CACHE);
    renderizarResumenBugs(REPORTES_BUG_CACHE, resumen);
    aplicarFiltrosBug();
    cargarContadorBugsPendientes();
  } catch (e) {
    cont.innerHTML = `<p class="resumen-vacio-maestro">No se pudo cargar: ${escaparHtmlMaestro(e.message)}</p>`;
  }
}

function poblarFiltroInstitucionBug(reportes) {
  const select = document.getElementById('select-filtro-institucion-bug');
  const valorActual = select.value;
  const instituciones = [...new Map(reportes.map(r => [r.institucion_id, r.institucion_nombre])).entries()];
  select.innerHTML = '<option value="">Todas las instituciones</option>' +
    instituciones.map(([id, nombre]) => `<option value="${id}">${escaparHtmlMaestro(nombre)}</option>`).join('');
  select.value = valorActual;
}

function renderizarResumenBugs(reportes, contenedor) {
  const nuevos = reportes.filter(r => r.estado === 'nuevo').length;
  const enCurso = reportes.filter(r => r.estado === 'en_curso' || r.estado === 'visto').length;
  const resueltos = reportes.filter(r => r.estado === 'resuelto').length;

  contenedor.innerHTML = `
    <div class="chip-resumen chip-red"><span class="emoji-chip">🆕</span><div><div class="num">${nuevos}</div><div class="label">nuevos</div></div></div>
    <div class="chip-resumen chip-amber"><span class="emoji-chip">🔧</span><div><div class="num">${enCurso}</div><div class="label">en curso</div></div></div>
    <div class="chip-resumen chip-signal"><span class="emoji-chip">✅</span><div><div class="num">${resueltos}</div><div class="label">resueltos</div></div></div>
  `;
}

function aplicarFiltrosBug() {
  const institucionId = document.getElementById('select-filtro-institucion-bug').value;
  const estado = document.getElementById('select-filtro-estado-bug').value;
  const cont = document.getElementById('lista-reportes-bug-maestro');

  const filtrados = REPORTES_BUG_CACHE.filter(r => {
    if (institucionId && String(r.institucion_id) !== institucionId) return false;
    if (estado && r.estado !== estado) return false;
    return true;
  });

  if (!filtrados.length) {
    cont.innerHTML = `<div class="resumen-vacio-maestro">${svgMascota(56)}No hay reportes que coincidan con este filtro.</div>`;
    return;
  }

  cont.innerHTML = '';
  filtrados.forEach(r => cont.appendChild(crearFilaReporteBug(r)));
}

document.getElementById('select-filtro-institucion-bug').addEventListener('change', aplicarFiltrosBug);
document.getElementById('select-filtro-estado-bug').addEventListener('change', aplicarFiltrosBug);

function crearFilaReporteBug(reporte) {
  const fila = document.createElement('div');
  const estadoInfo = ETIQUETAS_ESTADO_BUG[reporte.estado] || { texto: reporte.estado, clase: '' };
  const fecha = new Date(reporte.creado_en).toLocaleDateString('es-AR');
  fila.className = `fila-institucion rack-${reporte.estado === 'resuelto' ? 'activa' : (reporte.estado === 'nuevo' ? 'suspendida' : 'activa')}`;
  fila.innerHTML = `
    <div class="bloque-nombre-inst">
      <span class="avatar-inst">🐞</span>
      <div>
        <div class="nombre-inst">${escaparHtmlMaestro(reporte.titulo)} <span class="estado-pill" style="background:${estadoInfo.clase === 'chip-red' ? 'var(--rosa)' : (estadoInfo.clase === 'chip-amber' ? 'var(--naranja)' : 'var(--lima)')}; color:white;">${estadoInfo.texto}</span></div>
        <div class="meta-inst">${escaparHtmlMaestro(reporte.institucion_nombre)} · ${ETIQUETAS_SEVERIDAD_BUG[reporte.severidad] || reporte.severidad} ${reporte.sede_nombre ? '· ' + escaparHtmlMaestro(reporte.sede_nombre) : ''} · ${fecha}</div>
        <div class="meta-inst" style="margin-top:6px; max-width:600px;">${escaparHtmlMaestro(reporte.descripcion)}</div>
        ${reporte.respuesta_soporte ? `<div class="meta-inst" style="margin-top:6px; color:var(--violeta-oscuro); font-weight:600;">💬 ${escaparHtmlMaestro(reporte.respuesta_soporte)}</div>` : ''}
      </div>
    </div>
    <div class="acciones-inst"></div>
  `;
  const acciones = fila.querySelector('.acciones-inst');
  const btnResponder = document.createElement('button');
  btnResponder.textContent = 'Responder / cambiar estado';
  btnResponder.addEventListener('click', () => abrirModalResponderBug(reporte));
  acciones.appendChild(btnResponder);
  return fila;
}

function abrirModalResponderBug(reporte) {
  const overlay = document.getElementById('overlay-responder-bug');
  const errorP = document.getElementById('error-responder-bug');
  errorP.classList.add('oculto');

  document.getElementById('texto-titulo-reporte-bug').textContent = reporte.titulo;
  document.getElementById('texto-meta-reporte-bug').textContent =
    `${reporte.institucion_nombre} · ${ETIQUETAS_SEVERIDAD_BUG[reporte.severidad] || reporte.severidad}${reporte.sede_nombre ? ' · ' + reporte.sede_nombre : ''}`;
  document.getElementById('texto-descripcion-reporte-bug').textContent = reporte.descripcion;
  document.getElementById('input-responder-institucion-id').value = reporte.institucion_id;
  document.getElementById('input-responder-reporte-id').value = reporte.id;
  document.getElementById('select-responder-estado-bug').value = reporte.estado;
  document.getElementById('input-responder-texto-bug').value = reporte.respuesta_soporte || '';

  overlay.classList.remove('oculto');

  function cerrar() {
    overlay.classList.add('oculto');
    document.getElementById('btn-cancelar-responder-bug').removeEventListener('click', cerrar);
    document.getElementById('btn-guardar-responder-bug').removeEventListener('click', guardar);
    document.getElementById('btn-borrar-reporte-bug').removeEventListener('click', borrar);
  }

  async function guardar() {
    const btn = document.getElementById('btn-guardar-responder-bug');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=actualizar_reporte_bug`, {
        method: 'POST',
        body: JSON.stringify({
          institucion_id: reporte.institucion_id,
          reporte_id: reporte.id,
          estado: document.getElementById('select-responder-estado-bug').value,
          respuesta_soporte: document.getElementById('input-responder-texto-bug').value.trim(),
        }),
      });
      cerrar();
      cargarReportesBugMaestro();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Guardar';
    }
  }

  async function borrar() {
    if (!confirm(`¿Borrar para siempre el reporte "${reporte.titulo}"? Esta acción no se puede deshacer.`)) return;

    const btnBorrar = document.getElementById('btn-borrar-reporte-bug');
    btnBorrar.disabled = true;
    btnBorrar.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=borrar_reporte_bug`, {
        method: 'POST',
        body: JSON.stringify({ institucion_id: reporte.institucion_id, reporte_id: reporte.id }),
      });
      cerrar();
      cargarReportesBugMaestro();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
      btnBorrar.disabled = false;
      btnBorrar.textContent = 'Borrar este reporte';
    }
  }

  document.getElementById('btn-cancelar-responder-bug').addEventListener('click', cerrar);
  document.getElementById('btn-guardar-responder-bug').addEventListener('click', guardar);
  document.getElementById('btn-borrar-reporte-bug').addEventListener('click', borrar);
}

// ============================================================
// SALUD DEL SISTEMA
// ============================================================

function horasDesde(fechaIso) {
  if (!fechaIso) return null;
  const ms = Date.now() - new Date(fechaIso.replace(' ', 'T')).getTime();
  return ms / (1000 * 60 * 60);
}

function formatearFechaRelativa(fechaIso) {
  if (!fechaIso) return 'nunca';
  const horas = horasDesde(fechaIso);
  if (horas < 1) return 'hace instantes';
  if (horas < 24) return `hace ${Math.round(horas)} h`;
  const dias = Math.round(horas / 24);
  return `hace ${dias} día${dias === 1 ? '' : 's'}`;
}

async function cargarSaludSistema() {
  const cont = document.getElementById('lista-salud-instituciones');
  const resumen = document.getElementById('resumen-salud-sistema');
  const textoSSL = document.getElementById('texto-ssl-salud');
  const textoActualizado = document.getElementById('texto-ultima-actualizacion-salud');
  const badge = document.getElementById('badge-contador-alertas-salud');

  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=salud_sistema`, { method: 'GET' });

    // --- Certificado SSL ---
    if (res.dias_ssl === null) {
      textoSSL.textContent = 'No se pudo verificar el certificado en este momento.';
    } else if (res.dias_ssl < 0) {
      textoSSL.innerHTML = `<strong style="color:var(--rosa);">El certificado SSL ya venció.</strong> Renovalo lo antes posible.`;
    } else if (res.dias_ssl < 15) {
      textoSSL.innerHTML = `<strong style="color:var(--rosa);">Vence en ${res.dias_ssl} días.</strong> Conviene renovarlo pronto.`;
    } else if (res.dias_ssl < 30) {
      textoSSL.innerHTML = `<strong style="color:var(--naranja);">Vence en ${res.dias_ssl} días.</strong>`;
    } else {
      textoSSL.innerHTML = `Vence en <strong>${res.dias_ssl} días</strong>. Todo en orden.`;
    }

    // --- Evaluar alertas por institución ---
    let totalAlertas = 0;
    const evaluadas = res.instituciones.map(inst => {
      const alertas = [];
      if (!inst.conectado) {
        alertas.push({ nivel: 'rojo', texto: 'No se pudo conectar a su base de datos.' });
      } else {
        const horasCron1 = horasDesde(inst.cron_constancias_ultima_corrida);
        const horasCron2 = horasDesde(inst.cron_reportes_bug_ultima_corrida);
        if (horasCron1 === null || horasCron1 > 26) alertas.push({ nivel: 'amarillo', texto: 'El cron de constancias no corrió en las últimas 26 h (o nunca corrió).' });
        if (horasCron2 === null || horasCron2 > 26) alertas.push({ nivel: 'amarillo', texto: 'El cron de reportes de bug no corrió en las últimas 26 h (o nunca corrió).' });

        const horasActividad = horasDesde(inst.ultima_actividad);
        if (horasActividad === null || horasActividad > 24 * 14) alertas.push({ nivel: 'amarillo', texto: 'Sin actividad clínica registrada en los últimos 14 días.' });

        if (inst.errores_recientes_24h > 0) alertas.push({ nivel: 'rojo', texto: `${inst.errores_recientes_24h} error(es) de la app en las últimas 24 h.` });
        if (inst.version_desactualizada) alertas.push({ nivel: 'amarillo', texto: 'Tiene archivos con una versión vieja del sistema.' });
      }
      totalAlertas += alertas.filter(a => a.nivel === 'rojo').length > 0 ? 1 : (alertas.length > 0 ? 1 : 0);
      return { ...inst, alertas };
    });

    if (totalAlertas > 0) {
      badge.textContent = totalAlertas;
      badge.classList.remove('oculto');
    } else {
      badge.classList.add('oculto');
    }

    const sinAlertas = evaluadas.filter(i => i.alertas.length === 0).length;
    const conAdvertencias = evaluadas.filter(i => i.alertas.length > 0 && !i.alertas.some(a => a.nivel === 'rojo')).length;
    const conAlertasGraves = evaluadas.filter(i => i.alertas.some(a => a.nivel === 'rojo')).length;

    resumen.innerHTML = `
      <div class="chip-resumen chip-signal"><span class="emoji-chip">✅</span><div><div class="num">${sinAlertas}</div><div class="label">sin novedades</div></div></div>
      <div class="chip-resumen chip-amber"><span class="emoji-chip">⚠️</span><div><div class="num">${conAdvertencias}</div><div class="label">con advertencias</div></div></div>
      <div class="chip-resumen chip-red"><span class="emoji-chip">🚨</span><div><div class="num">${conAlertasGraves}</div><div class="label">con problemas</div></div></div>
    `;

    if (!evaluadas.length) {
      cont.innerHTML = `<div class="resumen-vacio-maestro">${svgMascota(56)}Todavía no hay instituciones para monitorear.</div>`;
    } else {
      cont.innerHTML = '';
      evaluadas.forEach(inst => cont.appendChild(crearFilaSaludInstitucion(inst)));
    }

    textoActualizado.textContent = new Date().toLocaleTimeString('es-AR');
  } catch (e) {
    cont.innerHTML = `<p class="resumen-vacio-maestro">No se pudo cargar: ${escaparHtmlMaestro(e.message)}</p>`;
  }
}

function crearFilaSaludInstitucion(inst) {
  const fila = document.createElement('div');
  const grave = inst.alertas.some(a => a.nivel === 'rojo');
  const tieneAlertas = inst.alertas.length > 0;
  fila.className = `fila-institucion rack-${grave ? 'suspendida' : (tieneAlertas ? 'suspendida' : 'activa')}`;

  const listaAlertas = inst.alertas.length
    ? inst.alertas.map(a => `<div style="color:${a.nivel === 'rojo' ? 'var(--rosa)' : 'var(--naranja)'}; font-weight:600; margin-top:3px;">${a.nivel === 'rojo' ? '🚨' : '⚠️'} ${escaparHtmlMaestro(a.texto)}</div>`).join('')
    : `<div style="color:var(--lima); font-weight:600; margin-top:3px;">✅ Todo en orden</div>`;

  const detalle = inst.conectado ? `
    <div class="meta-inst" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:14px;">
      <span>Cron constancias: ${formatearFechaRelativa(inst.cron_constancias_ultima_corrida)}</span>
      <span>Cron bugs: ${formatearFechaRelativa(inst.cron_reportes_bug_ultima_corrida)}</span>
      <span>Última actividad: ${formatearFechaRelativa(inst.ultima_actividad)}</span>
      <span>Versión: ${inst.version_actual || '—'}${inst.version_desactualizada ? ' (desactualizada)' : ''}</span>
    </div>
  ` : '';

  fila.innerHTML = `
    <div class="bloque-nombre-inst">
      <span class="avatar-inst">${escaparHtmlMaestro(inst.institucion_nombre.charAt(0).toUpperCase())}</span>
      <div>
        <div class="nombre-inst">${escaparHtmlMaestro(inst.institucion_nombre)}</div>
        ${listaAlertas}
        ${detalle}
      </div>
    </div>
  `;
  return fila;
}

document.getElementById('btn-refrescar-salud').addEventListener('click', cargarSaludSistema);

// ============================================================
// COBRANZA
// ============================================================

let INSTITUCIONES_CACHE_COBRANZA = [];

async function cargarVistaCobranza() {
  try {
    const resInst = await llamarApiMaestro(`${API_MAESTRO}?accion=listar`, { method: 'GET' });
    INSTITUCIONES_CACHE_COBRANZA = resInst.datos;
    poblarSelectoresInstitucionCobranza();
  } catch (e) {
    // Si falla, los selectores quedan vacíos; el resto de la vista sigue intentando cargar.
  }
  cargarListaCobros();
}

function poblarSelectoresInstitucionCobranza() {
  const opciones = INSTITUCIONES_CACHE_COBRANZA.map(i => `<option value="${i.id}">${escaparHtmlMaestro(i.nombre)}</option>`).join('');
  document.getElementById('select-institucion-cobro').innerHTML = opciones;
  document.getElementById('select-institucion-saldo').innerHTML = opciones;
}

function actualizarMontoFinalEstimadoCobro() {
  const monto = parseFloat(document.getElementById('input-monto-cobro').value.replace(',', '.')) || 0;
  const descuento = parseFloat(document.getElementById('select-descuento-cobro').value) || 0;
  const texto = document.getElementById('texto-monto-final-cobro');
  if (monto <= 0) {
    texto.value = '—';
    return;
  }
  const final = monto * (1 - descuento / 100);
  texto.value = `$${final.toLocaleString('es-AR', { minimumFractionDigits: 2 })}`;
}
document.getElementById('input-monto-cobro').addEventListener('input', actualizarMontoFinalEstimadoCobro);
document.getElementById('select-descuento-cobro').addEventListener('change', actualizarMontoFinalEstimadoCobro);

document.getElementById('btn-generar-cobro').addEventListener('click', async () => {
  const errorP = document.getElementById('error-generar-cobro');
  const resultadoDiv = document.getElementById('resultado-generar-cobro');
  errorP.classList.add('oculto');
  resultadoDiv.classList.add('oculto');

  const institucionId = document.getElementById('select-institucion-cobro').value;
  const montoLista = document.getElementById('input-monto-cobro').value.trim();
  const descuentoPct = document.getElementById('select-descuento-cobro').value;
  const moneda = document.getElementById('select-moneda-cobro').value;
  const periodoDesde = document.getElementById('input-periodo-desde-cobro').value;
  const periodoHasta = document.getElementById('input-periodo-hasta-cobro').value;
  const vencimiento = document.getElementById('input-vencimiento-cobro').value;

  if (!institucionId || !montoLista || !vencimiento) {
    errorP.textContent = 'Completá la institución, el monto y la fecha de vencimiento.';
    errorP.classList.remove('oculto');
    return;
  }

  const btn = document.getElementById('btn-generar-cobro');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-maestro"></span>';

  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=generar_cobro`, {
      method: 'POST',
      body: JSON.stringify({
        institucion_id: institucionId,
        monto_lista: montoLista,
        descuento_pct: descuentoPct,
        moneda,
        periodo_desde: periodoDesde || null,
        periodo_hasta: periodoHasta || null,
        vencimiento,
      }),
    });

    resultadoDiv.classList.remove('oculto');
    resultadoDiv.className = 'resultado-creacion';
    resultadoDiv.innerHTML = res.saldo_aplicado > 0
      ? `Cobro generado. Se aplicaron <strong>$${res.saldo_aplicado.toLocaleString('es-AR')}</strong> de saldo a favor — el monto final a cobrar quedó en <strong>$${res.monto_final.toLocaleString('es-AR')}</strong>.`
      : 'Cobro generado correctamente.';

    document.getElementById('input-monto-cobro').value = '';
    document.getElementById('select-descuento-cobro').value = '0';
    document.getElementById('input-periodo-desde-cobro').value = '';
    document.getElementById('input-periodo-hasta-cobro').value = '';
    document.getElementById('input-vencimiento-cobro').value = '';
    actualizarMontoFinalEstimadoCobro();

    cargarListaCobros();
  } catch (e) {
    errorP.textContent = e.message;
    errorP.classList.remove('oculto');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Generar cobro';
  }
});

document.getElementById('btn-cargar-saldo').addEventListener('click', async () => {
  const errorP = document.getElementById('error-cargar-saldo');
  errorP.classList.add('oculto');

  const institucionId = document.getElementById('select-institucion-saldo').value;
  const monto = document.getElementById('input-monto-saldo').value.trim();
  const nota = document.getElementById('input-nota-saldo').value.trim();

  if (!institucionId || !monto) {
    errorP.textContent = 'Completá la institución y el monto.';
    errorP.classList.remove('oculto');
    return;
  }

  const btn = document.getElementById('btn-cargar-saldo');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-maestro"></span>';

  try {
    await llamarApiMaestro(`${API_MAESTRO}?accion=cargar_saldo_favor`, {
      method: 'POST',
      body: JSON.stringify({ institucion_id: institucionId, monto, nota }),
    });
    document.getElementById('input-monto-saldo').value = '';
    document.getElementById('input-nota-saldo').value = '';
    alert('Saldo cargado correctamente.');
  } catch (e) {
    errorP.textContent = e.message;
    errorP.classList.remove('oculto');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Cargar saldo';
  }
});

const ETIQUETAS_ESTADO_COBRO = {
  pendiente: { texto: 'Pendiente', clase: 'chip-amber' },
  comprobante_subido: { texto: 'Comprobante para revisar', clase: 'chip-amber' },
  aprobado: { texto: 'Aprobado', clase: 'chip-signal' },
  sin_acreditar: { texto: 'Sin acreditar', clase: 'chip-red' },
  rechazado: { texto: 'Rechazado', clase: 'chip-red' },
  anulado: { texto: 'Anulado', clase: '' },
};

async function cargarListaCobros() {
  const cont = document.getElementById('lista-cobros-maestro');
  const resumen = document.getElementById('resumen-cobranza');
  cont.innerHTML = '<p class="resumen-vacio-maestro">Cargando…</p>';

  try {
    const res = await llamarApiMaestro(`${API_MAESTRO}?accion=listar_cobros`, { method: 'GET' });
    const cobros = res.datos;

    const pendientesRevision = cobros.filter(c => c.estado === 'comprobante_subido').length;
    const vencidosSinPagar = cobros.filter(c => c.estado === 'pendiente' && new Date(c.vencimiento + 'T00:00:00') < new Date()).length;
    const aprobados = cobros.filter(c => c.estado === 'aprobado').length;

    const badge = document.getElementById('badge-contador-pagos-pendientes');
    const totalAlertas = pendientesRevision + vencidosSinPagar;
    if (totalAlertas > 0) {
      badge.textContent = totalAlertas;
      badge.classList.remove('oculto');
    } else {
      badge.classList.add('oculto');
    }

    resumen.innerHTML = `
      <div class="chip-resumen chip-amber"><span class="emoji-chip">🧾</span><div><div class="num">${pendientesRevision}</div><div class="label">para revisar</div></div></div>
      <div class="chip-resumen chip-red"><span class="emoji-chip">⏰</span><div><div class="num">${vencidosSinPagar}</div><div class="label">vencidos sin pagar</div></div></div>
      <div class="chip-resumen chip-signal"><span class="emoji-chip">✅</span><div><div class="num">${aprobados}</div><div class="label">aprobados</div></div></div>
    `;

    if (!cobros.length) {
      cont.innerHTML = `<div class="resumen-vacio-maestro">${svgMascota(56)}Todavía no generaste ningún cobro.</div>`;
      return;
    }

    cont.innerHTML = '';
    cobros.forEach(c => cont.appendChild(crearFilaCobro(c)));
  } catch (e) {
    cont.innerHTML = `<p class="resumen-vacio-maestro">No se pudo cargar: ${escaparHtmlMaestro(e.message)}</p>`;
  }
}

document.getElementById('btn-refrescar-cobranza').addEventListener('click', cargarListaCobros);

function crearFilaCobro(cobro) {
  const fila = document.createElement('div');
  const estadoInfo = ETIQUETAS_ESTADO_COBRO[cobro.estado] || { texto: cobro.estado, clase: '' };
  const vencimiento = new Date(cobro.vencimiento + 'T00:00:00');
  const vencido = vencimiento < new Date() && cobro.estado === 'pendiente';
  const fechaFormateada = vencimiento.toLocaleDateString('es-AR');
  const montoMostrado = vencido ? cobro.monto_con_recargo : cobro.monto;

  const colorBadge = cobro.estado === 'anulado' ? 'var(--tinta-suave)' : (estadoInfo.clase === 'chip-red' ? 'var(--rosa)' : (estadoInfo.clase === 'chip-amber' ? 'var(--naranja)' : 'var(--lima)'));

  fila.className = `fila-institucion rack-${cobro.estado === 'aprobado' ? 'activa' : (cobro.estado === 'anulado' ? 'activa' : (vencido || cobro.estado === 'rechazado' || cobro.estado === 'sin_acreditar' ? 'suspendida' : 'activa'))}`;
  fila.innerHTML = `
    <div class="bloque-nombre-inst">
      <span class="avatar-inst">${escaparHtmlMaestro(cobro.institucion_nombre.charAt(0).toUpperCase())}</span>
      <div>
        <div class="nombre-inst">${escaparHtmlMaestro(cobro.institucion_nombre)} — $${Number(montoMostrado).toLocaleString('es-AR', { minimumFractionDigits: 2 })} ${escaparHtmlMaestro(cobro.moneda)}
          <span class="estado-pill" style="background:${colorBadge}; color:white;">${estadoInfo.texto}</span>
        </div>
        <div class="meta-inst">Vencimiento: ${fechaFormateada}${vencido ? ` <strong style="color:var(--rosa);">(vencido, incluye recargo por mora)</strong>` : ''}</div>
        ${cobro.descuento_pct > 0 ? `<div class="meta-inst" style="margin-top:2px;">Descuento comercial: ${cobro.descuento_pct}% sobre $${Number(cobro.monto_lista).toLocaleString('es-AR')}</div>` : ''}
        ${cobro.notas_super_admin ? `<div class="meta-inst" style="margin-top:4px;">💬 ${escaparHtmlMaestro(cobro.notas_super_admin)}</div>` : ''}
        ${cobro.estado === 'anulado' && cobro.motivo_anulacion ? `<div class="meta-inst" style="margin-top:4px;">Motivo de anulación: ${escaparHtmlMaestro(cobro.motivo_anulacion)}</div>` : ''}
      </div>
    </div>
    <div class="acciones-inst"></div>
  `;
  const acciones = fila.querySelector('.acciones-inst');
  const btnFactura = document.createElement('button');
  btnFactura.className = 'secundario';
  btnFactura.textContent = 'Factura';
  btnFactura.addEventListener('click', () => window.open(`factura.php?id=${cobro.id}`, '_blank'));
  acciones.appendChild(btnFactura);

  const btnResolver = document.createElement('button');
  btnResolver.textContent = 'Ver / resolver';
  btnResolver.addEventListener('click', () => abrirModalResolverCobro(cobro));
  acciones.appendChild(btnResolver);
  return fila;
}

function abrirModalResolverCobro(cobro) {
  const overlay = document.getElementById('overlay-resolver-cobro');
  const errorP = document.getElementById('error-resolver-cobro');
  errorP.classList.add('oculto');

  document.getElementById('texto-titulo-cobro').textContent = `${cobro.institucion_nombre} — $${Number(cobro.monto).toLocaleString('es-AR')} ${cobro.moneda}`;
  document.getElementById('texto-meta-cobro').textContent = `Vencimiento: ${new Date(cobro.vencimiento + 'T00:00:00').toLocaleDateString('es-AR')}`;
  document.getElementById('input-resolver-cobro-id').value = cobro.id;
  document.getElementById('select-resolver-estado-cobro').value = cobro.estado === 'comprobante_subido' ? 'pendiente' : cobro.estado;
  document.getElementById('input-notas-resolver-cobro').value = cobro.notas_super_admin || '';

  const bloqueComprobante = document.getElementById('bloque-comprobante-cobro');
  if (cobro.comprobante_nombre_archivo) {
    bloqueComprobante.classList.remove('oculto');
    document.getElementById('link-ver-comprobante').href = `${API_MAESTRO}?accion=ver_comprobante&id=${cobro.id}`;
  } else {
    bloqueComprobante.classList.add('oculto');
  }

  document.getElementById('link-ver-factura').href = `factura.php?id=${cobro.id}`;

  const btnAbrirAnular = document.getElementById('btn-abrir-anular-cobro');
  btnAbrirAnular.classList.toggle('oculto', ['aprobado', 'anulado'].includes(cobro.estado));

  // El botón de suspender por falta de pago solo aplica a cobros
  // todavía sin resolver (no tiene sentido sobre uno ya aprobado
  // o anulado). Si la institución ya está suspendida A CAUSA de
  // este mismo cobro, el botón ofrece reactivar en su lugar.
  const btnSuspenderPago = document.getElementById('btn-suspender-por-pago');
  const yaSuspendidaPorEsteCobro = cobro.institucion_estado === 'suspendida_por_pago' && Number(cobro.suspendida_por_cobro_id) === Number(cobro.id);
  btnSuspenderPago.classList.toggle('oculto', ['aprobado', 'anulado'].includes(cobro.estado) && !yaSuspendidaPorEsteCobro);
  btnSuspenderPago.textContent = yaSuspendidaPorEsteCobro ? 'Reactivar acceso' : 'Suspender por falta de pago';

  overlay.classList.remove('oculto');

  function cerrar() {
    overlay.classList.add('oculto');
    document.getElementById('btn-cancelar-resolver-cobro').removeEventListener('click', cerrar);
    document.getElementById('btn-guardar-resolver-cobro').removeEventListener('click', guardar);
    btnAbrirAnular.removeEventListener('click', abrirAnular);
    btnSuspenderPago.removeEventListener('click', toggleSuspensionPago);
  }

  function abrirAnular() {
    cerrar();
    abrirModalAnularCobro(cobro);
  }

  async function guardar() {
    const btn = document.getElementById('btn-guardar-resolver-cobro');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=resolver_cobro`, {
        method: 'POST',
        body: JSON.stringify({
          cobro_id: cobro.id,
          estado: document.getElementById('select-resolver-estado-cobro').value,
          notas_super_admin: document.getElementById('input-notas-resolver-cobro').value.trim(),
        }),
      });
      cerrar();
      cargarListaCobros();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Guardar';
    }
  }

  async function toggleSuspensionPago() {
    const activar = !yaSuspendidaPorEsteCobro;
    if (activar) {
      const confirmado = confirm(
        `¿Suspender el acceso de ${cobro.institucion_nombre} por falta de pago?\n\nNadie va a poder ingresar (salvo el Apoderado, que sigue entrando para pagar) hasta que este cobro se apruebe o lo reactivés a mano.`
      );
      if (!confirmado) return;
    }

    btnSuspenderPago.disabled = true;
    btnSuspenderPago.innerHTML = '<span class="spinner-maestro"></span>';
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=cambiar_estado_institucion`, {
        method: 'POST',
        body: JSON.stringify({
          id: cobro.institucion_id,
          estado: activar ? 'suspendida_por_pago' : 'activa',
          cobro_id: activar ? cobro.id : null,
        }),
      });
      cerrar();
      cargarListaCobros();
      if (typeof cargarListaInstituciones === 'function') cargarListaInstituciones();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btnSuspenderPago.disabled = false;
      btnSuspenderPago.textContent = activar ? 'Suspender por falta de pago' : 'Reactivar acceso';
    }
  }

  document.getElementById('btn-cancelar-resolver-cobro').addEventListener('click', cerrar);
  document.getElementById('btn-guardar-resolver-cobro').addEventListener('click', guardar);
  btnAbrirAnular.addEventListener('click', abrirAnular);
  btnSuspenderPago.addEventListener('click', toggleSuspensionPago);
}

function abrirModalAnularCobro(cobro) {
  const overlay = document.getElementById('overlay-anular-cobro');
  const errorP = document.getElementById('error-anular-cobro');
  errorP.classList.add('oculto');
  document.getElementById('input-anular-cobro-id').value = cobro.id;
  document.getElementById('input-motivo-anular-cobro').value = '';
  overlay.classList.remove('oculto');

  function cerrar() {
    overlay.classList.add('oculto');
    document.getElementById('btn-cancelar-anular-cobro').removeEventListener('click', cerrar);
    document.getElementById('btn-confirmar-anular-cobro').removeEventListener('click', confirmar);
  }

  async function confirmar() {
    const motivo = document.getElementById('input-motivo-anular-cobro').value.trim();
    if (!motivo) {
      errorP.textContent = 'Indicá el motivo de la anulación.';
      errorP.classList.remove('oculto');
      return;
    }
    const btn = document.getElementById('btn-confirmar-anular-cobro');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=anular_cobro`, {
        method: 'POST',
        body: JSON.stringify({ cobro_id: cobro.id, motivo }),
      });
      cerrar();
      cargarListaCobros();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Anular cobro';
    }
  }

  document.getElementById('btn-cancelar-anular-cobro').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-anular-cobro').addEventListener('click', confirmar);
}

function abrirModalEditarCuenta(inst) {
  const overlay = document.getElementById('overlay-editar-cuenta');
  const errorP = document.getElementById('error-editar-cuenta');
  errorP.classList.add('oculto');
  document.getElementById('texto-institucion-editar-cuenta').textContent = inst.nombre;
  document.getElementById('input-editar-cuenta-institucion-id').value = inst.id;

  ['titular', 'banco', 'cuil', 'numero', 'alias'].forEach(campo => {
    document.getElementById(`input-cuenta-${campo}`).value = '';
  });

  overlay.classList.remove('oculto');

  llamarApiMaestro(`${API_MAESTRO}?accion=obtener_cuenta_institucion&id=${inst.id}`, { method: 'GET' })
    .then(res => {
      const datos = res.datos;
      document.getElementById('input-cuenta-titular').value = datos.cuenta_titular || '';
      document.getElementById('input-cuenta-banco').value = datos.cuenta_banco || '';
      document.getElementById('input-cuenta-cuil').value = datos.cuenta_cuil || '';
      document.getElementById('input-cuenta-numero').value = datos.cuenta_numero || '';
      document.getElementById('input-cuenta-alias').value = datos.cuenta_alias || '';
    })
    .catch(e => { errorP.textContent = e.message; errorP.classList.remove('oculto'); });

  function cerrar() {
    overlay.classList.add('oculto');
    document.getElementById('btn-cancelar-editar-cuenta').removeEventListener('click', cerrar);
    document.getElementById('btn-guardar-editar-cuenta').removeEventListener('click', guardar);
  }

  async function guardar() {
    const btn = document.getElementById('btn-guardar-editar-cuenta');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-maestro"></span>';
    errorP.classList.add('oculto');
    try {
      await llamarApiMaestro(`${API_MAESTRO}?accion=editar_cuenta_institucion`, {
        method: 'POST',
        body: JSON.stringify({
          institucion_id: inst.id,
          cuenta_titular: document.getElementById('input-cuenta-titular').value,
          cuenta_banco: document.getElementById('input-cuenta-banco').value,
          cuenta_cuil: document.getElementById('input-cuenta-cuil').value,
          cuenta_numero: document.getElementById('input-cuenta-numero').value,
          cuenta_alias: document.getElementById('input-cuenta-alias').value,
        }),
      });
      cerrar();
    } catch (e) {
      errorP.textContent = e.message;
      errorP.classList.remove('oculto');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Guardar';
    }
  }

  document.getElementById('btn-cancelar-editar-cuenta').addEventListener('click', cerrar);
  document.getElementById('btn-guardar-editar-cuenta').addEventListener('click', guardar);
}

inicializarPanelMaestro();

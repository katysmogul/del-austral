const API_MAESTRO = 'api_maestro.php';

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
    document.getElementById('titulo-login-maestro').textContent = '¡Bienvenido! 🎉';
    document.getElementById('desc-login-maestro').textContent = 'Es la primera vez que entrás. Elegí tu clave de Super Admin (mínimo 6 caracteres).';
    document.getElementById('btn-submit-login-maestro').textContent = 'Crear clave y entrar 🚀';
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
      btn.textContent = claveYaConfigurada ? 'Entrar ✨' : 'Crear clave y entrar 🚀';
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
    btn.textContent = huboTrazo ? '🚀 Crear institución' : '✍️ Firmá el contrato para continuar';
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
      🎉 ¡Institución creada y contrato firmado correctamente!<br>
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
    btn.textContent = CANVAS_FIRMA_APODERADO.hayTrazo() ? '🚀 Crear institución' : '✍️ Firmá el contrato para continuar';
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
      cont.innerHTML = '<p class="resumen-vacio-maestro">Todavía no creaste ninguna institución.</p>';
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
  const suspendidas = instituciones.filter(i => i.estado === 'suspendida').length;
  const firmasPendientes = instituciones.filter(i => i.tiene_contrato && !i.firmado_cliente).length;

  contenedor.innerHTML = `
    <div class="chip-resumen chip-signal"><span class="emoji-chip">✅</span><div><div class="num">${activas}</div><div class="label">activas</div></div></div>
    <div class="chip-resumen chip-red"><span class="emoji-chip">⏸️</span><div><div class="num">${suspendidas}</div><div class="label">suspendidas</div></div></div>
    <div class="chip-resumen chip-amber"><span class="emoji-chip">✍️</span><div><div class="num">${firmasPendientes}</div><div class="label">firma pendiente</div></div></div>
  `;
}

function crearFilaInstitucion(inst) {
  const fila = document.createElement('div');
  fila.className = `fila-institucion rack-${inst.estado}`;
  const fecha = new Date(inst.creado_en).toLocaleDateString('es-AR');
  const url = `${location.origin}/${inst.carpeta}/`;
  fila.innerHTML = `
    <div class="bloque-nombre-inst">
      <span class="avatar-inst">🏥</span>
      <div>
        <div class="nombre-inst">${escaparHtmlMaestro(inst.nombre)} <span class="estado-pill estado-${inst.estado}">${inst.estado === 'activa' ? '✅ Activa' : '⏸️ Suspendida'}</span></div>
        <div class="meta-inst">/${escaparHtmlMaestro(inst.carpeta)}/ · creada el ${fecha} · <a href="${url}" target="_blank">${url}</a></div>
        ${inst.tiene_contrato ? `
          <div class="meta-inst" style="margin-top:4px;">
            Firma apoderado: ${inst.firmado_apoderado ? '<span style="color:var(--lima); font-weight:700;">✓ firmado</span>' : '<span style="color:var(--naranja); font-weight:700;">pendiente</span>'}
            &nbsp;·&nbsp;
            Firma cliente: ${inst.firmado_cliente ? '<span style="color:var(--lima); font-weight:700;">✓ firmado</span>' : '<span style="color:var(--naranja); font-weight:700;">pendiente</span>'}
          </div>
        ` : ''}
      </div>
    </div>
    <div class="acciones-inst"></div>
  `;
  const acciones = fila.querySelector('.acciones-inst');
  const btnCambiar = document.createElement('button');
  btnCambiar.className = inst.estado === 'activa' ? 'peligro' : 'secundario';
  btnCambiar.textContent = inst.estado === 'activa' ? '⏸️ Suspender' : '▶️ Reactivar';
  btnCambiar.addEventListener('click', async () => {
    const nuevoEstado = inst.estado === 'activa' ? 'suspendida' : 'activa';
    const confirmacion = nuevoEstado === 'suspendida'
      ? `¿Suspender "${inst.nombre}"? El sitio va a dejar de funcionar para ese cliente hasta que la reactives. Los datos no se borran.`
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

  if (inst.tiene_contrato) {
    const btnContrato = document.createElement('button');
    btnContrato.className = 'secundario';
    btnContrato.textContent = '📄 Ver contrato';
    btnContrato.addEventListener('click', () => window.open(`generar_contrato.php?id=${inst.id}`, '_blank'));
    acciones.appendChild(btnContrato);
  }

  if (inst.tiene_contrato && !inst.firmado_cliente) {
    const btnVerificarFirma = document.createElement('button');
    btnVerificarFirma.className = 'secundario';
    btnVerificarFirma.textContent = '🔍 Verificar firma del cliente';
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
        btnVerificarFirma.textContent = '🔍 Verificar firma del cliente';
      }
    });
    acciones.appendChild(btnVerificarFirma);
  }

  const btnEditarContrato = document.createElement('button');
  btnEditarContrato.className = 'secundario';
  btnEditarContrato.textContent = inst.tiene_contrato ? '✏️ Editar contrato' : '📝 Completar contrato';
  btnEditarContrato.addEventListener('click', () => abrirModalEditarContrato(inst));
  acciones.appendChild(btnEditarContrato);

  const btnBorrar = document.createElement('button');
  btnBorrar.className = 'secundario';
  btnBorrar.style.color = 'var(--rosa)';
  btnBorrar.style.borderColor = 'var(--rosa)';
  btnBorrar.textContent = '🗑️ Borrar para siempre';
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
      btn.textContent = 'Guardar contrato ✅';
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

inicializarPanelMaestro();

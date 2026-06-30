/* ============================================================
   DEL AUSTRAL · Lógica principal de la aplicación
   ============================================================ */

const API = {
  auth: 'api/auth.php',
  pacientes: 'api/pacientes.php',
  obrasSociales: 'api/obras_sociales.php',
  citas: 'api/citas.php',
  adjuntos: 'api/adjuntos.php',
  plantillas: 'api/plantillas.php',
  admin: 'api/admin.php',
};

let CACHE_OBRAS_SOCIALES = [];
let CACHE_PLANTILLAS = [];
let TIPO_BUSQUEDA_ACTUAL = 'dni';
let VISTA_BUSQUEDA_ORIGEN = 'acceder'; // 'acceder' | 'borrar', para volver bien desde el detalle
let MODO_FORM_LEGAJO = 'crear'; // 'crear' | 'editar'
let MES_AGENDA_ACTUAL = new Date(); // mes que se muestra en el calendario
let DIA_SELECCIONADO_AGENDA = null; // 'YYYY-MM-DD'
let ARCHIVO_PENDIENTE_SUBIR = null;
let PACIENTE_ID_ACTUAL_DETALLE = null;
let ROL_ACTUAL = null; // 'profesional' | 'administrativa'
let NOMBRE_USUARIO_ACTUAL = null;

document.addEventListener('DOMContentLoaded', () => {
  inicializarAcceso();
  vincularBotonesGlobales();
  registrarServiceWorker();
});

/**
 * Registra el service worker mínimo que permite "instalar" el
 * sitio como app. Si el navegador no lo soporta (algunos viejos
 * o ciertos modos privados), simplemente no pasa nada — el
 * sistema sigue funcionando igual, solo no se podría instalar.
 */
function registrarServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker.register('sw.js').catch(() => {
    // Si falla el registro (ej. el sitio no está en HTTPS),
    // no rompemos nada: la app sigue funcionando como sitio web normal.
  });
}

/* ============================================================
   TOASTS
   ============================================================ */
function mostrarToast(mensaje, tipo = 'info') {
  const cont = document.getElementById('toast-contenedor');
  const toast = document.createElement('div');
  toast.className = `toast ${tipo}`;
  toast.textContent = mensaje;
  cont.appendChild(toast);
  setTimeout(() => toast.remove(), 4200);
}

/**
 * Descarga un archivo desde una URL del propio backend (que
 * responde con Content-Disposition: attachment), sin abrir
 * pestañas nuevas. Usamos fetch + blob en vez de window.open
 * porque los navegadores suelen bloquear popups que disparan
 * una descarga inmediata, fallando en silencio sin avisar nada.
 * Esta forma también nos deja mostrar un error legible si la
 * descarga falla (por ejemplo, sesión vencida o error del server).
 */
async function descargarArchivoDesdeUrl(url, nombrePorDefecto = 'descarga.json') {
  try {
    const respuesta = await fetch(url, { method: 'GET' });
    if (!respuesta.ok) {
      let mensaje = 'No se pudo generar la descarga.';
      try {
        const datos = await respuesta.json();
        if (datos && datos.error) mensaje = datos.error;
      } catch (_) {
        // La respuesta de error no era JSON; nos quedamos con el mensaje genérico.
      }
      throw new Error(mensaje);
    }

    const disposicion = respuesta.headers.get('Content-Disposition') || '';
    const coincidencia = disposicion.match(/filename="?([^"]+)"?/);
    const nombreArchivo = coincidencia ? coincidencia[1] : nombrePorDefecto;

    const blob = await respuesta.blob();
    const urlObjeto = URL.createObjectURL(blob);
    const enlace = document.createElement('a');
    enlace.href = urlObjeto;
    enlace.download = nombreArchivo;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(urlObjeto);
  } catch (e) {
    mostrarToast(e.message || 'No se pudo descargar el archivo.', 'error');
  }
}

/* ============================================================
   PETICIONES A LA API
   ============================================================ */
async function llamarApi(url, opciones = {}) {
  try {
    const respuesta = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...opciones,
    });
    const datos = await respuesta.json();
    if (!respuesta.ok || !datos.ok) {
      throw new Error(datos.error || 'Ocurrió un error inesperado.');
    }
    return datos;
  } catch (err) {
    throw err;
  }
}

/* ============================================================
   ACCESO CON PIN — flujo multi-paso
   ============================================================ */
const LARGO_PIN = 4;
let ETAPA_SISTEMA = 'listo'; // 'sin_desarrollador' | 'sin_sedes_o_usuarios' | 'listo'
let SEDE_ELEGIDA_ID = null;
let SEDE_ELEGIDA_NOMBRE = null;
let USUARIO_ELEGIDO_ID = null;
let USUARIO_ELEGIDO_ROL = null;
let PROFESIONAL_ACTIVO_ELEGIDO_ID = null;

/**
 * Motor genérico de captura de PIN: conecta un <input> oculto con
 * sus puntitos indicadores, y llama el callback al completar los
 * 4 dígitos. Se reutiliza para los 3 inputs de PIN distintos que
 * hay en el flujo de acceso.
 */
function crearCapturadorPin(idInput, idIndicadores, alCompletar) {
  let valorActual = '';
  const input = document.getElementById(idInput);

  function actualizarIndicadores() {
    const puntos = document.querySelectorAll(`#${idIndicadores} .punto-pin`);
    puntos.forEach((p, i) => {
      p.classList.toggle('relleno', i < valorActual.length);
      p.classList.remove('error');
    });
  }

  function marcarError() {
    document.querySelectorAll(`#${idIndicadores} .punto-pin`).forEach(p => p.classList.add('error'));
  }

  function limpiar() {
    valorActual = '';
    input.value = '';
    input.focus();
    actualizarIndicadores();
  }

  input.addEventListener('input', () => {
    valorActual = input.value.replace(/\D/g, '').slice(0, LARGO_PIN);
    input.value = valorActual;
    actualizarIndicadores();
    if (valorActual.length === LARGO_PIN) {
      alCompletar(valorActual);
    }
  });

  return { limpiar, marcarError, focus: () => input.focus() };
}

let CAPTURADOR_PIN_LOGIN = null;
let CAPTURADOR_PIN_DEV_CREAR = null;
let CAPTURADOR_CLAVE_DEV = null;

async function inicializarAcceso() {
  try {
    const res = await llamarApi(`${API.auth}?accion=estado`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'estado' }),
    });
    ETAPA_SISTEMA = res.etapa;
  } catch (e) {
    const t = document.getElementById('texto-instruccion');
    t.classList.remove('oculto');
    t.textContent = 'No se pudo conectar con el servidor. Revisá config.php.';
    t.classList.add('error');
    return;
  }

  vincularPasosLogin();

  if (ETAPA_SISTEMA === 'sin_desarrollador') {
    mostrarPasoLogin('paso-crear-desarrollador');
  } else {
    // Tanto si faltan sedes/usuarios como si está todo listo, el
    // punto de partida visible es "elegir sede" — desde ahí hay un
    // link para entrar como Desarrollador si hace falta configurar.
    cargarSedesLogin();
    mostrarPasoLogin('paso-elegir-sede');
  }
}

function mostrarPasoLogin(idPaso) {
  document.querySelectorAll('.paso-login').forEach(p => p.classList.add('oculto'));
  document.getElementById(idPaso).classList.remove('oculto');
}

function vincularPasosLogin() {
  // --- Crear clave de desarrollador (primera vez) ---
  CAPTURADOR_PIN_DEV_CREAR = crearCapturadorPin('input-pin-dev-crear', 'indicadores-pin-dev-crear', async (clave) => {
    try {
      await llamarApi(API.auth, { method: 'POST', body: JSON.stringify({ accion: 'crear_desarrollador', clave }) });
      mostrarVistaSetupInicial();
    } catch (e) {
      mostrarToast(e.message, 'error');
      CAPTURADOR_PIN_DEV_CREAR.marcarError();
      setTimeout(() => CAPTURADOR_PIN_DEV_CREAR.limpiar(), 600);
    }
  });

  // --- Acceso discreto al rol Desarrollador ---
  document.getElementById('btn-acceso-mantenimiento').addEventListener('click', () => {
    mostrarPasoLogin('paso-clave-desarrollador');
    CAPTURADOR_CLAVE_DEV.focus();
  });

  // --- Elegir usuario dentro de la sede ---
  document.getElementById('btn-volver-sede').addEventListener('click', () => mostrarPasoLogin('paso-elegir-sede'));

  // --- Elegir profesional activo (administrativa) ---
  document.getElementById('btn-volver-usuario-desde-prof').addEventListener('click', () => mostrarPasoLogin('paso-elegir-usuario'));

  // --- Ingresar PIN normal ---
  document.getElementById('btn-volver-usuario').addEventListener('click', () => {
    mostrarPasoLogin(USUARIO_ELEGIDO_ROL === 'administrativa' ? 'paso-elegir-profesional-activo' : 'paso-elegir-usuario');
  });
  document.getElementById('btn-reintentar-patron').addEventListener('click', () => {
    document.getElementById('btn-reintentar-patron').classList.add('oculto');
    CAPTURADOR_PIN_LOGIN.limpiar();
  });
  CAPTURADOR_PIN_LOGIN = crearCapturadorPin('input-pin-oculto', 'indicadores-pin', manejarPinLoginCompletado);

  // --- Clave de desarrollador (login normal) ---
  document.getElementById('btn-volver-sede-desde-dev').addEventListener('click', () => mostrarPasoLogin('paso-elegir-sede'));
  CAPTURADOR_CLAVE_DEV = crearCapturadorPin('input-clave-dev', 'indicadores-pin-dev', async (clave) => {
    try {
      const res = await llamarApi(API.auth, { method: 'POST', body: JSON.stringify({ accion: 'verificar_desarrollador', clave }) });
      entrarAlApp(res.nombre_usuario, res.rol);
    } catch (e) {
      mostrarToast(e.message, 'error');
      CAPTURADOR_CLAVE_DEV.marcarError();
      setTimeout(() => CAPTURADOR_CLAVE_DEV.limpiar(), 600);
    }
  });

  // --- Setup inicial (sede + profesional, justo después de crear desarrollador) ---
  document.getElementById('btn-crear-setup-inicial').addEventListener('click', crearSetupInicial);
  document.getElementById('btn-ir-panel-dev').addEventListener('click', () => entrarAlApp('Desarrollador', 'desarrollador'));
}

function mostrarVistaSetupInicial() {
  document.getElementById('vista-acceso').classList.add('oculto');
  document.getElementById('vista-setup-inicial').classList.remove('oculto');
  document.getElementById('btn-acceso-mantenimiento').classList.add('oculto');
}

async function crearSetupInicial() {
  const nombreSede = document.getElementById('input-primera-sede').value.trim();
  const nombreProfesional = document.getElementById('input-primer-profesional').value.trim();
  const pin = document.getElementById('input-pin-primer-profesional').value.trim();
  const btn = document.getElementById('btn-crear-setup-inicial');

  if (!nombreSede || !nombreProfesional) {
    mostrarToast('Completá el nombre de la sede y del profesional.', 'error');
    return;
  }
  if (!/^\d{4}$/.test(pin)) {
    mostrarToast('El PIN del profesional debe tener 4 números.', 'error');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Creando...';
  try {
    await llamarApi(API.auth, {
      method: 'POST',
      body: JSON.stringify({ accion: 'crear_setup_inicial', nombre_sede: nombreSede, nombre_profesional: nombreProfesional, pin }),
    });
    mostrarToast('Sede y profesional creados. Ya podés entrar al panel.', 'exito');
    entrarAlApp('Desarrollador', 'desarrollador');
  } catch (e) {
    mostrarToast(e.message, 'error');
    btn.disabled = false;
    btn.textContent = 'Crear sede y profesional';
  }
}

async function cargarSedesLogin() {
  const cont = document.getElementById('lista-sedes-login');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_sedes_login`, { method: 'POST', body: JSON.stringify({ accion: 'listar_sedes_login' }) });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p class="texto-vacio-login">Todavía no hay sedes configuradas. Entrá como Desarrollador para crear la primera.</p>';
      return;
    }
    res.datos.forEach(s => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'opcion-login';
      btn.textContent = s.nombre;
      btn.addEventListener('click', () => {
        SEDE_ELEGIDA_ID = s.id;
        SEDE_ELEGIDA_NOMBRE = s.nombre;
        cargarUsuariosLogin(s.id);
        mostrarPasoLogin('paso-elegir-usuario');
      });
      cont.appendChild(btn);
    });
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

async function cargarUsuariosLogin(sedeId) {
  const cont = document.getElementById('lista-usuarios-login');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_usuarios_sede_login`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_usuarios_sede_login', sede_id: sedeId }),
    });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p class="texto-vacio-login">Esta sede todavía no tiene personas asignadas.</p>';
      return;
    }
    res.datos.forEach(u => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'opcion-login';
      const etiquetaRol = u.rol === 'profesional' ? 'Profesional' : 'Administrativa';
      btn.innerHTML = `${escaparHtml(u.nombre_completo)} <span class="etiqueta-rol-login">${etiquetaRol}</span>`;
      btn.addEventListener('click', () => {
        USUARIO_ELEGIDO_ID = u.id;
        USUARIO_ELEGIDO_ROL = u.rol;
        if (u.rol === 'administrativa') {
          cargarProfesionalesLogin(sedeId);
          mostrarPasoLogin('paso-elegir-profesional-activo');
        } else {
          PROFESIONAL_ACTIVO_ELEGIDO_ID = null;
          irAPasoPin(u.nombre_completo);
        }
      });
      cont.appendChild(btn);
    });
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

async function cargarProfesionalesLogin(sedeId) {
  const cont = document.getElementById('lista-profesionales-login');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_profesionales_sede_login`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_profesionales_sede_login', sede_id: sedeId }),
    });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p class="texto-vacio-login">No hay profesionales en esta sede todavía.</p>';
      return;
    }
    res.datos.forEach(p => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'opcion-login';
      btn.textContent = p.nombre_completo;
      btn.addEventListener('click', () => {
        PROFESIONAL_ACTIVO_ELEGIDO_ID = p.id;
        irAPasoPin(`Administrativa de ${p.nombre_completo}`);
      });
      cont.appendChild(btn);
    });
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function irAPasoPin(textoContexto) {
  document.getElementById('texto-instruccion-pin').textContent = `Ingresá tu PIN — ${textoContexto}`;
  mostrarPasoLogin('paso-ingresar-pin');
  setTimeout(() => CAPTURADOR_PIN_LOGIN.focus(), 50);
}

async function manejarPinLoginCompletado(pin) {
  try {
    const res = await llamarApi(API.auth, {
      method: 'POST',
      body: JSON.stringify({
        accion: 'verificar',
        sede_id: SEDE_ELEGIDA_ID,
        usuario_id: USUARIO_ELEGIDO_ID,
        pin,
        profesional_activo_id: PROFESIONAL_ACTIVO_ELEGIDO_ID,
      }),
    });
    entrarAlApp(res.nombre_usuario, res.rol);
  } catch (e) {
    mostrarToast(e.message, 'error');
    CAPTURADOR_PIN_LOGIN.marcarError();
    document.getElementById('btn-reintentar-patron').classList.remove('oculto');
    setTimeout(() => CAPTURADOR_PIN_LOGIN.limpiar(), 600);
  }
}

function entrarAlApp(nombreUsuario, rol) {
  ROL_ACTUAL = rol;
  NOMBRE_USUARIO_ACTUAL = nombreUsuario;
  document.getElementById('vista-acceso').classList.add('oculto');
  document.getElementById('vista-setup-inicial').classList.add('oculto');
  document.getElementById('vista-app').classList.remove('oculto');
  document.getElementById('btn-acceso-mantenimiento').classList.add('oculto');
  irAVista(rol === 'desarrollador' ? 'configuracion' : 'menu');
  mostrarCartelBienvenida(nombreUsuario);
}

function mostrarCartelBienvenida(nombreUsuario) {
  const modal = document.getElementById('modal-bienvenida');
  const texto = document.getElementById('texto-bienvenida');
  texto.textContent = nombreUsuario
    ? `Bienvenido/a, ${nombreUsuario}`
    : '¡Bienvenido/a!';
  modal.classList.remove('oculto');

  const cerrar = () => modal.classList.add('oculto');
  document.getElementById('btn-cerrar-bienvenida').onclick = cerrar;
}

/**
 * Oculta del DOM cualquier elemento marcado con data-rol="profesional"
 * cuando el usuario logueado es administrativa. Se llama después de
 * montar cada vista que pueda tener este tipo de elementos.
 */
function aplicarVisibilidadPorRol(contenedor) {
  if (ROL_ACTUAL === 'profesional') return;
  contenedor.querySelectorAll('[data-rol="profesional"]').forEach(el => el.remove());
}

function vincularBotonesGlobales() {
  document.getElementById('btn-inicio').addEventListener('click', () => irAVista(ROL_ACTUAL === 'desarrollador' ? 'configuracion' : 'menu'));
  document.getElementById('btn-cerrar-sesion').addEventListener('click', async () => {
    await llamarApi(`${API.auth}`, { method: 'POST', body: JSON.stringify({ accion: 'cerrar_sesion' }) });
    location.reload();
  });
}

/* ============================================================
   NAVEGACIÓN ENTRE VISTAS
   ============================================================ */
function irAVista(nombre, datos = {}) {
  const contenido = document.getElementById('contenido');
  contenido.innerHTML = '';

  if (nombre === 'menu') {
    contenido.appendChild(clonarPlantilla('tpl-menu'));
    aplicarVisibilidadPorRol(contenido);
    adaptarTextosMenuSegunRol(contenido);
    contenido.querySelectorAll('.tarjeta-menu').forEach(btn => {
      btn.addEventListener('click', () => irAVista(btn.dataset.vista));
    });
    cargarResumenMenu();
  } else if (nombre === 'crear') {
    MODO_FORM_LEGAJO = 'crear';
    montarVistaCrear(contenido);
  } else if (nombre === 'editar') {
    MODO_FORM_LEGAJO = 'editar';
    montarVistaCrear(contenido, datos.id);
  } else if (nombre === 'acceder') {
    VISTA_BUSQUEDA_ORIGEN = 'acceder';
    montarVistaBusqueda(contenido, 'acceder');
  } else if (nombre === 'borrar') {
    VISTA_BUSQUEDA_ORIGEN = 'borrar';
    montarVistaBusqueda(contenido, 'borrar');
  } else if (nombre === 'detalle') {
    montarVistaDetalle(contenido, datos.id);
  } else if (nombre === 'agenda') {
    montarVistaAgenda(contenido);
  } else if (nombre === 'dashboard') {
    montarVistaDashboard(contenido);
  } else if (nombre === 'mi-legajo') {
    montarVistaMiLegajo(contenido);
  } else if (nombre === 'configuracion') {
    montarVistaConfiguracion(contenido);
  }

  contenido.querySelectorAll('[data-volver]').forEach(b => b.addEventListener('click', () => irAVista(ROL_ACTUAL === 'desarrollador' ? 'configuracion' : 'menu')));
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * Ajusta textos del menú principal y de algunas tarjetas para que
 * tengan sentido cuando quien entra es la administrativa (no ve
 * contenido clínico, así que el copy no debe prometerlo).
 */
function adaptarTextosMenuSegunRol(contenido) {
  if (ROL_ACTUAL === 'profesional') return;

  const titulo = contenido.querySelector('#titulo-menu');
  const desc = contenido.querySelector('#desc-menu');
  if (titulo) titulo.textContent = `¿Qué necesitás hacer, ${NOMBRE_USUARIO_ACTUAL || ''}?`;
  if (desc) desc.textContent = 'Gestioná turnos y datos de contacto de los pacientes.';

  const descCrear = contenido.querySelector('#desc-crear-legajo');
  if (descCrear) descCrear.textContent = 'Registrá los datos de contacto de un paciente nuevo para poder agendarlo.';

  const descAcceder = contenido.querySelector('#desc-acceder-legajos');
  if (descAcceder) descAcceder.textContent = 'Buscá por DNI, nombre, fecha de atención u obra social para ver sus datos de contacto.';
}

function clonarPlantilla(idPlantilla) {
  const tpl = document.getElementById(idPlantilla);
  const clon = tpl.content.cloneNode(true);
  const envoltorio = document.createElement('div');
  envoltorio.appendChild(clon);
  return envoltorio;
}

/* ============================================================
   UTILIDADES DE FECHA
   ============================================================ */
function formatearFechaCorta(fechaIso) {
  if (!fechaIso) return '—';
  return new Date(fechaIso + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatearFechaLarga(fechaIso) {
  if (!fechaIso) return '—';
  return new Date(fechaIso + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: 'long', year: 'numeric' });
}

function aFechaIso(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/* ============================================================
   RESUMEN DEL MENÚ PRINCIPAL (agenda + inactivos)
   ============================================================ */
async function cargarResumenMenu() {
  cargarResumenCitas();
  cargarResumenCumpleanios();
  if (ROL_ACTUAL === 'profesional') {
    cargarResumenInactivos();
    cargarResumenHoy();
    cargarAvisosTurnos();
  }
}

async function cargarResumenHoy() {
  const franja = document.getElementById('franja-resumen-hoy');
  if (!franja) return;
  try {
    const res = await llamarApi(`${API.citas}?accion=resumen_hoy`, { method: 'GET' });
    if (res.total_hoy === 0) {
      franja.classList.add('oculto');
      return;
    }
    const horaTexto = res.proxima_hora ? ` — la próxima es a las ${res.proxima_hora.slice(0, 5)}` : '';
    if (res.restantes_hoy === 0) {
      franja.innerHTML = `✅ Ya atendiste todas tus consultas de hoy (${res.total_hoy} en total).`;
    } else {
      franja.innerHTML = `Hoy tenés <strong>${res.restantes_hoy}</strong> consulta${res.restantes_hoy === 1 ? '' : 's'} por delante de ${res.total_hoy}${horaTexto}.`;
    }
    franja.classList.remove('oculto');
  } catch (e) {
    franja.classList.add('oculto');
  }
}

async function cargarAvisosTurnos() {
  const boton = document.getElementById('franja-avisos-turnos');
  if (!boton) return;
  try {
    const res = await llamarApi(`${API.citas}?accion=avisos_pendientes`, { method: 'GET' });
    if (res.total === 0) {
      boton.classList.add('oculto');
      return;
    }
    boton.innerHTML = `🔔 Tenés <strong>${res.total}</strong> novedad${res.total === 1 ? '' : 'es'} de turnos (confirmaciones o cancelaciones) — tocá para ver`;
    boton.classList.remove('oculto');
    boton.onclick = abrirModalAvisosTurnos;
  } catch (e) {
    boton.classList.add('oculto');
  }
}

async function abrirModalAvisosTurnos() {
  const modalEnv = clonarPlantilla('tpl-modal-avisos-turnos');
  document.body.appendChild(modalEnv);
  const cont = document.getElementById('lista-avisos-turnos');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';

  try {
    const res = await llamarApi(`${API.citas}?accion=listar_avisos`, { method: 'GET' });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p class="resumen-vacio">No hay novedades.</p>';
    } else {
      res.datos.forEach(c => {
        const item = document.createElement('div');
        item.className = 'item-resumen';
        const fechaTexto = `${formatearFechaCorta(c.fecha)}${c.hora ? ' · ' + c.hora.slice(0, 5) : ''}`;
        let estadoTexto;
        if (c.estado === 'cancelada') {
          estadoTexto = '<span class="etiqueta-aviso cancelado">Canceló el turno</span>';
        } else if (c.confirmada_por_paciente === 1) {
          estadoTexto = '<span class="etiqueta-aviso confirmado">Confirmó el turno</span>';
        } else {
          estadoTexto = '<span class="etiqueta-aviso">Sin cambios de estado</span>';
        }
        item.innerHTML = `
          <span class="item-resumen-fecha">${fechaTexto}</span>
          <span class="item-resumen-nombre">${escaparHtml(c.nombre + ' ' + c.apellido)}</span>
          ${estadoTexto}
        `;
        cont.appendChild(item);
      });
    }
  } catch (e) {
    cont.innerHTML = '<p class="resumen-vacio">No se pudieron cargar las novedades.</p>';
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-marcar-avisos-vistos').addEventListener('click', async () => {
    try {
      await llamarApi(`${API.citas}?accion=marcar_avisos_vistos`, { method: 'POST', body: JSON.stringify({}) });
      cerrar();
      document.getElementById('franja-avisos-turnos').classList.add('oculto');
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
}

async function cargarResumenCitas() {
  const cont = document.getElementById('lista-resumen-citas');
  if (!cont) return;
  try {
    const res = await llamarApi(`${API.citas}?accion=proximas&dias=7`, { method: 'GET' });
    if (!res.datos.length) {
      cont.innerHTML = '<p class="resumen-vacio">No hay citas agendadas para los próximos 7 días.</p>';
      return;
    }
    cont.innerHTML = '';
    res.datos.slice(0, 6).forEach(c => {
      const item = document.createElement('button');
      item.className = 'item-resumen';
      item.type = 'button';
      const esHoy = c.fecha === aFechaIso(new Date());
      item.innerHTML = `
        <span class="item-resumen-fecha">${esHoy ? 'Hoy' : formatearFechaCorta(c.fecha)}${c.hora ? ' · ' + c.hora.slice(0,5) : ''}</span>
        <span class="item-resumen-nombre">${escaparHtml(c.nombre + ' ' + c.apellido)}</span>
      `;
      item.addEventListener('click', () => irAVista('detalle', { id: c.paciente_id }));
      cont.appendChild(item);
    });
  } catch (e) {
    cont.innerHTML = '<p class="resumen-vacio">No se pudo cargar la agenda.</p>';
  }
}

async function cargarResumenCumpleanios() {
  const cont = document.getElementById('lista-resumen-cumple');
  if (!cont) return;
  try {
    const res = await llamarApi(`${API.citas}?accion=cumpleanios&dias=14`, { method: 'GET' });
    if (!res.datos.length) {
      cont.innerHTML = '<p class="resumen-vacio">No hay cumpleaños en los próximos 14 días.</p>';
      return;
    }
    cont.innerHTML = '';
    res.datos.slice(0, 6).forEach(p => {
      const item = document.createElement('button');
      item.className = 'item-resumen';
      item.type = 'button';
      const fechaIso = `${new Date().getFullYear()}-${p.mes_dia}`;
      const esHoy = aFechaIso(new Date()).slice(5) === p.mes_dia;
      item.innerHTML = `
        <span class="item-resumen-fecha cumple">${esHoy ? 'Hoy' : formatearFechaCorta(fechaIso)} · ${p.edad_que_cumple} años</span>
        <span class="item-resumen-nombre">${escaparHtml(p.nombre + ' ' + p.apellido)}</span>
      `;
      item.addEventListener('click', () => irAVista('detalle', { id: p.id }));
      cont.appendChild(item);
    });
  } catch (e) {
    cont.innerHTML = '<p class="resumen-vacio">No se pudo cargar la información.</p>';
  }
}

async function cargarResumenInactivos() {
  const cont = document.getElementById('lista-resumen-inactivos');
  if (!cont) return;
  try {
    const res = await llamarApi(`${API.citas}?accion=inactivos&dias=30`, { method: 'GET' });
    if (!res.datos.length) {
      cont.innerHTML = '<p class="resumen-vacio">Todos tus pacientes tuvieron sesiones en los últimos 30 días.</p>';
      return;
    }
    cont.innerHTML = '';
    res.datos.slice(0, 6).forEach(p => {
      const item = document.createElement('button');
      item.className = 'item-resumen';
      item.type = 'button';
      const texto = p.ultima_sesion
        ? `Última sesión: ${formatearFechaCorta(p.ultima_sesion)}`
        : 'Sin sesiones registradas';
      item.innerHTML = `
        <span class="item-resumen-fecha alerta">${texto}</span>
        <span class="item-resumen-nombre">${escaparHtml(p.nombre + ' ' + p.apellido)}</span>
      `;
      item.addEventListener('click', () => irAVista('detalle', { id: p.id }));
      cont.appendChild(item);
    });
  } catch (e) {
    cont.innerHTML = '<p class="resumen-vacio">No se pudo cargar la información.</p>';
  }
}

/* ============================================================
   OBRAS SOCIALES (carga + selector + alta rápida)
   ============================================================ */
async function cargarObrasSociales() {
  const res = await llamarApi(API.obrasSociales, { method: 'GET' });
  CACHE_OBRAS_SOCIALES = res.datos;
  return CACHE_OBRAS_SOCIALES;
}

function poblarSelectObrasSociales(select, valorSeleccionado = null) {
  select.innerHTML = '';
  CACHE_OBRAS_SOCIALES.forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.id;
    opt.textContent = o.nombre;
    if (valorSeleccionado && Number(valorSeleccionado) === Number(o.id)) opt.selected = true;
    select.appendChild(opt);
  });
}

function abrirModalAgregarObra(callbackAlAgregar) {
  const modalEnv = clonarPlantilla('tpl-modal-obra-social');
  document.body.appendChild(modalEnv);
  const input = document.getElementById('input-nueva-obra');
  input.focus();

  function cerrar() { modalEnv.remove(); }

  document.getElementById('btn-cancelar-obra').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-obra').addEventListener('click', async () => {
    const nombre = input.value.trim();
    if (!nombre) { mostrarToast('Escribí un nombre para la obra social.', 'error'); return; }
    try {
      const res = await llamarApi(API.obrasSociales, {
        method: 'POST',
        body: JSON.stringify({ nombre }),
      });
      await cargarObrasSociales();
      mostrarToast(res.ya_existia ? 'Esa obra social ya existía, la seleccionamos.' : 'Obra social agregada.', 'exito');
      cerrar();
      if (callbackAlAgregar) callbackAlAgregar(res.id);
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') document.getElementById('btn-confirmar-obra').click();
  });
}

/* ============================================================
   VISTA: CREAR / EDITAR LEGAJO (comparten el mismo formulario)
   ============================================================ */
async function montarVistaCrear(contenido, idEditar = null) {
  contenido.appendChild(clonarPlantilla('tpl-crear'));

  const esEdicion = MODO_FORM_LEGAJO === 'editar';

  document.getElementById('titulo-form-crear').textContent = esEdicion ? 'Editar legajo' : 'Crear legajo nuevo';
  document.getElementById('desc-form-crear').textContent = esEdicion
    ? 'Actualizá los datos del paciente. Las sesiones se gestionan desde su ficha.'
    : 'Completá los datos del paciente. Podés agregar sesiones más adelante desde su ficha.';
  document.getElementById('btn-guardar-legajo').textContent = esEdicion ? 'Guardar cambios' : 'Guardar legajo';

  if (esEdicion) {
    document.getElementById('panel-sesiones-iniciales').classList.add('oculto');
  } else {
    document.getElementById('btn-abrir-aviso-legal').addEventListener('click', abrirModalAvisoLegal);
    if (!sessionStorage.getItem('aviso_legal_visto')) {
      abrirModalAvisoLegal();
      sessionStorage.setItem('aviso_legal_visto', '1');
    }
  }

  if (ROL_ACTUAL !== 'profesional') {
    document.getElementById('panel-cuadro-clinico').classList.add('oculto');
    document.getElementById('panel-sesiones-iniciales').classList.add('oculto');
  }

  await cargarObrasSociales();
  poblarSelectObrasSociales(document.getElementById('select-obra-social'));

  document.getElementById('btn-agregar-obra').addEventListener('click', () => {
    abrirModalAgregarObra((nuevoId) => {
      poblarSelectObrasSociales(document.getElementById('select-obra-social'), nuevoId);
    });
  });

  document.getElementById('input-fecha-nac').addEventListener('change', (e) => {
    const edad = calcularEdadDesde(e.target.value);
    document.getElementById('input-edad-calculada').value = edad !== null ? `${edad} años` : '';
  });

  const listaSesiones = document.getElementById('lista-sesiones-form');
  document.getElementById('btn-agregar-dia').addEventListener('click', () => {
    const item = clonarPlantilla('tpl-sesion-form-item').firstElementChild;
    listaSesiones.appendChild(item);
    item.querySelector('.quitar-sesion').addEventListener('click', () => item.remove());
  });

  // Si es edición, precargamos los datos del paciente
  if (esEdicion && idEditar) {
    const form = document.getElementById('form-crear');
    try {
      const res = await llamarApi(`${API.pacientes}?accion=detalle&id=${idEditar}`, { method: 'GET' });
      const p = res.datos;
      form.querySelector('[name="id"]').value = p.id;
      form.querySelector('[name="nombre"]').value = p.nombre || '';
      form.querySelector('[name="apellido"]').value = p.apellido || '';
      form.querySelector('[name="dni"]').value = p.dni || '';
      form.querySelector('[name="sexo"]').value = p.sexo || '';
      form.querySelector('[name="fecha_nacimiento"]').value = (p.fecha_nacimiento || '').slice(0, 10);
      document.getElementById('input-edad-calculada').value = p.edad !== null ? `${p.edad} años` : '';
      form.querySelector('[name="telefono"]').value = p.telefono || '';
      form.querySelector('[name="email"]').value = p.email || '';
      form.querySelector('[name="direccion"]').value = p.direccion || '';
      poblarSelectObrasSociales(document.getElementById('select-obra-social'), p.obra_social_id);
      form.querySelector('[name="numero_afiliado"]').value = p.numero_afiliado || '';
      form.querySelector('[name="motivo_consulta"]').value = p.motivo_consulta || '';
      form.querySelector('[name="patologia"]').value = p.patologia || '';
      form.querySelector('[name="sintomas"]').value = p.sintomas || '';
      form.querySelector('[name="observaciones_generales"]').value = p.observaciones_generales || '';
    } catch (e) {
      mostrarToast(e.message, 'error');
      irAVista('menu');
      return;
    }
  }

  document.getElementById('form-crear').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btnGuardar = document.getElementById('btn-guardar-legajo');
    const form = ev.target;
    const datos = Object.fromEntries(new FormData(form).entries());

    if (!esEdicion) {
      const sesiones = [];
      listaSesiones.querySelectorAll('.sesion-form-item').forEach(item => {
        sesiones.push({
          fecha_sesion: item.querySelector('.campo-fecha-sesion').value,
          proxima_cita: item.querySelector('.campo-proxima-cita').value || null,
          descripcion: item.querySelector('.campo-descripcion-sesion').value,
          evolucion: item.querySelector('.campo-evolucion-sesion').value || null,
        });
      });
      datos.sesiones = sesiones;
    }

    btnGuardar.disabled = true;
    btnGuardar.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      if (esEdicion) {
        await llamarApi(`${API.pacientes}?accion=actualizar`, {
          method: 'POST',
          body: JSON.stringify(datos),
        });
        mostrarToast('Legajo actualizado correctamente.', 'exito');
        irAVista('detalle', { id: datos.id });
      } else {
        await llamarApi(`${API.pacientes}?accion=crear`, {
          method: 'POST',
          body: JSON.stringify(datos),
        });
        mostrarToast('Legajo creado correctamente.', 'exito');
        irAVista('menu');
      }
    } catch (e) {
      mostrarToast(e.message, 'error');
      btnGuardar.disabled = false;
      btnGuardar.textContent = esEdicion ? 'Guardar cambios' : 'Guardar legajo';
    }
  });
}

function abrirModalAvisoLegal() {
  const modalEnv = clonarPlantilla('tpl-modal-aviso-legal');
  document.body.appendChild(modalEnv);
  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cerrar-aviso-legal').addEventListener('click', cerrar);
  document.getElementById('btn-cerrar-aviso-legal-x').addEventListener('click', cerrar);
  modalEnv.querySelector('.overlay-modal').addEventListener('click', (ev) => {
    if (ev.target.classList.contains('overlay-modal')) cerrar();
  });
}

function calcularEdadDesde(fechaIso) {
  if (!fechaIso) return null;
  const nacimiento = new Date(fechaIso);
  const hoy = new Date();
  let edad = hoy.getFullYear() - nacimiento.getFullYear();
  const aunNoCumple = (hoy.getMonth() < nacimiento.getMonth()) ||
    (hoy.getMonth() === nacimiento.getMonth() && hoy.getDate() < nacimiento.getDate());
  if (aunNoCumple) edad--;
  return edad;
}

/* ============================================================
   VISTA: BUSCAR (acceder / borrar comparten estructura)
   ============================================================ */
async function montarVistaBusqueda(contenido, modo) {
  const idPlantilla = modo === 'acceder' ? 'tpl-acceder' : 'tpl-borrar';
  contenido.appendChild(clonarPlantilla(idPlantilla));
  TIPO_BUSQUEDA_ACTUAL = 'dni';

  if (modo === 'borrar') {
    document.getElementById('btn-ver-papelera').addEventListener('click', abrirPapelera);
  }

  await cargarObrasSociales();

  const tabs = contenido.parentElement.querySelectorAll('.tab-busqueda');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('activo'));
      tab.classList.add('activo');
      TIPO_BUSQUEDA_ACTUAL = tab.dataset.tipo;
      renderizarFilaBuscador(modo);
      document.getElementById('resultados-busqueda').innerHTML = '';
    });
  });

  renderizarFilaBuscador(modo);
}

function renderizarFilaBuscador(modo) {
  const fila = document.getElementById('fila-buscador');
  fila.innerHTML = '';

  if (TIPO_BUSQUEDA_ACTUAL === 'dni') {
    fila.innerHTML = `<input type="text" id="input-busqueda-dni" placeholder="Ingresá el DNI..." inputmode="numeric">
      <button class="btn btn-primario" id="btn-buscar">Buscar</button>`;
  } else if (TIPO_BUSQUEDA_ACTUAL === 'nombre') {
    fila.innerHTML = `<input type="text" id="input-busqueda-nombre" placeholder="Nombre y/o apellido...">
      <button class="btn btn-primario" id="btn-buscar">Buscar</button>`;
  } else if (TIPO_BUSQUEDA_ACTUAL === 'fecha') {
    fila.innerHTML = `
      <input type="date" id="input-busqueda-desde" title="Desde">
      <input type="date" id="input-busqueda-hasta" title="Hasta">
      <button class="btn btn-primario" id="btn-buscar">Buscar</button>`;
  } else if (TIPO_BUSQUEDA_ACTUAL === 'obra_social') {
    fila.innerHTML = `<select id="input-busqueda-obra"></select>
      <button class="btn btn-primario" id="btn-buscar">Buscar</button>`;
    poblarSelectObrasSociales(document.getElementById('input-busqueda-obra'));
  } else if (TIPO_BUSQUEDA_ACTUAL === 'sede') {
    fila.innerHTML = `<select id="input-busqueda-sede"></select>
      <button class="btn btn-primario" id="btn-buscar">Buscar</button>`;
    poblarSelectSedesBusqueda(document.getElementById('input-busqueda-sede'));
  }

  document.getElementById('btn-buscar').addEventListener('click', () => ejecutarBusqueda(modo));

  fila.querySelectorAll('input').forEach(inp => {
    inp.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') ejecutarBusqueda(modo); });
  });
}

async function poblarSelectSedesBusqueda(select) {
  select.innerHTML = '<option>Cargando...</option>';
  try {
    const res = await llamarApi(`${API.pacientes}?accion=sedes_disponibles`, { method: 'GET' });
    select.innerHTML = '';
    res.datos.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = s.nombre;
      select.appendChild(opt);
    });
  } catch (e) {
    select.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

async function ejecutarBusqueda(modo) {
  const params = new URLSearchParams({ accion: 'buscar', tipo: TIPO_BUSQUEDA_ACTUAL });

  if (TIPO_BUSQUEDA_ACTUAL === 'dni') {
    params.set('valor', document.getElementById('input-busqueda-dni').value.trim());
  } else if (TIPO_BUSQUEDA_ACTUAL === 'nombre') {
    params.set('valor', document.getElementById('input-busqueda-nombre').value.trim());
  } else if (TIPO_BUSQUEDA_ACTUAL === 'fecha') {
    const desde = document.getElementById('input-busqueda-desde').value;
    const hasta = document.getElementById('input-busqueda-hasta').value;
    if (desde) params.set('desde', desde);
    if (hasta) params.set('hasta', hasta);
  } else if (TIPO_BUSQUEDA_ACTUAL === 'obra_social') {
    params.set('obra_social_id', document.getElementById('input-busqueda-obra').value);
  } else if (TIPO_BUSQUEDA_ACTUAL === 'sede') {
    params.set('sede_id', document.getElementById('input-busqueda-sede').value);
  }

  const contRes = document.getElementById('resultados-busqueda');
  contRes.innerHTML = '<div class="cargando-pagina"><span class="spinner"></span> Buscando...</div>';

  try {
    const res = await llamarApi(`${API.pacientes}?${params.toString()}`, { method: 'GET' });
    renderizarResultados(res.datos, modo);
  } catch (e) {
    contRes.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function renderizarResultados(lista, modo) {
  const contRes = document.getElementById('resultados-busqueda');
  contRes.innerHTML = '';

  if (!lista.length) {
    const vacio = document.createElement('div');
    vacio.className = 'estado-vacio';
    vacio.innerHTML = `
      <svg class="icono-vacio" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <h3>No encontramos resultados</h3>
      <p>Probá con otro criterio de búsqueda o revisá que el dato esté bien escrito.</p>`;
    contRes.appendChild(vacio);
    return;
  }

  const contenedorLista = document.createElement('div');
  contenedorLista.className = 'lista-resultados';

  lista.forEach(p => {
    const tarjeta = clonarPlantilla('tpl-tarjeta-paciente').firstElementChild;
    tarjeta.dataset.id = p.id;
    tarjeta.querySelector('.avatar-iniciales').textContent = (p.nombre[0] + p.apellido[0]).toUpperCase();
    tarjeta.querySelector('.nombre').textContent = `${p.apellido}, ${p.nombre}`;
    tarjeta.querySelector('.meta').innerHTML = `DNI ${p.dni} · ${p.edad} años${p.sede_nombre ? ' · ' + escaparHtml(p.sede_nombre) : ''} · `;

    const etiqueta = document.createElement('span');
    etiqueta.className = 'etiqueta' + (p.obra_social_nombre && p.obra_social_nombre.includes('Particular') ? ' particular' : '');
    etiqueta.textContent = p.obra_social_nombre || 'Sin obra social';
    tarjeta.querySelector('.meta').appendChild(etiqueta);

    const acciones = tarjeta.querySelector('.acciones-tarjeta');

    if (modo === 'acceder') {
      const btnVer = document.createElement('button');
      btnVer.className = 'btn-icono';
      btnVer.title = 'Ver legajo';
      btnVer.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
      btnVer.addEventListener('click', () => irAVista('detalle', { id: p.id }));
      acciones.appendChild(btnVer);
    } else if (modo === 'borrar') {
      const btnBorrar = document.createElement('button');
      btnBorrar.className = 'btn-icono peligro';
      btnBorrar.title = 'Eliminar legajo';
      btnBorrar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0l-1 14a2 2 0 01-2 2H7a2 2 0 01-2-2L4 6h16z"/></svg>`;
      btnBorrar.addEventListener('click', () => confirmarEliminarLegajo(p.id, `${p.nombre} ${p.apellido}`, modo));
      acciones.appendChild(btnBorrar);
    }

    contenedorLista.appendChild(tarjeta);
  });

  contRes.appendChild(contenedorLista);
}

function confirmarEliminarLegajo(id, nombreCompleto) {
  const modalEnv = clonarPlantilla('tpl-modal-confirmar-borrado');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-confirmar-borrado').textContent =
    `Vas a eliminar el legajo de ${nombreCompleto} del sistema activo. Quedará guardado en la base histórica, pero no aparecerá más en las búsquedas normales.`;

  const inputConfirmar = document.getElementById('input-confirmar-borrado');
  const btnConfirmar = document.getElementById('btn-confirmar-borrado');
  inputConfirmar.focus();

  inputConfirmar.addEventListener('input', () => {
    btnConfirmar.disabled = inputConfirmar.value.trim().toUpperCase() !== 'ELIMINAR';
  });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-borrado').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-borrado').addEventListener('click', async () => {
    if (inputConfirmar.value.trim().toUpperCase() !== 'ELIMINAR') return;
    btnConfirmar.disabled = true;
    btnConfirmar.innerHTML = '<span class="spinner"></span> Eliminando...';
    try {
      await llamarApi(`${API.pacientes}?accion=eliminar`, {
        method: 'POST',
        body: JSON.stringify({ id }),
      });
      mostrarToast('Legajo eliminado del sistema activo.', 'exito');
      cerrar();
      ejecutarBusqueda('borrar');
    } catch (e) {
      mostrarToast(e.message, 'error');
      btnConfirmar.disabled = false;
      btnConfirmar.textContent = 'Sí, eliminar definitivamente';
    }
  });
}

/* ============================================================
   BASE HISTÓRICA (papelera)
   ============================================================ */
async function abrirPapelera() {
  const modalEnv = clonarPlantilla('tpl-papelera');
  document.body.appendChild(modalEnv);
  document.getElementById('btn-cerrar-papelera').addEventListener('click', () => modalEnv.remove());

  const lista = document.getElementById('lista-papelera');
  lista.innerHTML = '<div class="cargando-pagina"><span class="spinner"></span> Cargando...</div>';

  try {
    const res = await llamarApi(`${API.pacientes}?accion=papelera`, { method: 'GET' });
    lista.innerHTML = '';
    if (!res.datos.length) {
      lista.innerHTML = '<p style="color:var(--tinta-suave); text-align:center; padding: 20px 0;">Todavía no eliminaste ningún legajo.</p>';
      return;
    }
    res.datos.forEach(item => {
      const fecha = new Date(item.eliminado_en).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
      const fila = document.createElement('div');
      fila.className = 'tarjeta-paciente';
      fila.innerHTML = `
        <div class="info-principal">
          <div class="avatar-iniciales">${item.nombre_completo.split(' ').map(p => p[0]).slice(0,2).join('').toUpperCase()}</div>
          <div>
            <div class="nombre">${item.nombre_completo}</div>
            <div class="meta">DNI ${item.dni} · Eliminado el ${fecha}</div>
          </div>
        </div>`;
      lista.appendChild(fila);
    });
  } catch (e) {
    lista.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

/* ============================================================
   VISTA: DETALLE DE PACIENTE
   ============================================================ */
async function montarVistaDetalle(contenido, id) {
  contenido.innerHTML = '<div class="cargando-pagina"><span class="spinner"></span> Cargando legajo...</div>';
  PACIENTE_ID_ACTUAL_DETALLE = id;

  let p;
  try {
    const res = await llamarApi(`${API.pacientes}?accion=detalle&id=${id}`, { method: 'GET' });
    p = res.datos;
  } catch (e) {
    contenido.innerHTML = '';
    mostrarToast(e.message, 'error');
    irAVista('menu');
    return;
  }

  contenido.innerHTML = '';
  contenido.appendChild(clonarPlantilla('tpl-detalle-paciente'));

  document.querySelector('[data-volver-busqueda]').addEventListener('click', () => irAVista(VISTA_BUSQUEDA_ORIGEN));

  document.getElementById('detalle-avatar').textContent = (p.nombre[0] + p.apellido[0]).toUpperCase();
  document.getElementById('detalle-nombre').textContent = `${p.nombre} ${p.apellido}`;
  document.getElementById('detalle-meta').textContent = `Paciente desde ${new Date(p.creado_en).toLocaleDateString('es-AR')}`;

  document.getElementById('dato-dni').textContent = p.dni;
  document.getElementById('dato-edad').textContent = `${p.edad} años`;
  document.getElementById('dato-sexo').textContent = p.sexo;
  document.getElementById('dato-obra').textContent = p.obra_social_nombre || 'Sin especificar';
  document.getElementById('dato-sede').textContent = p.sede_nombre || 'Sin asignar';

  const avisoRecuperado = document.getElementById('aviso-legajo-recuperado');
  if (p.recuperado_de_profesional) {
    avisoRecuperado.innerHTML = `📋 Este legajo perteneció antes a <strong>${escaparHtml(p.recuperado_de_profesional)}</strong> y fue recuperado de la papelera.`;
    avisoRecuperado.classList.remove('oculto');
  } else {
    avisoRecuperado.classList.add('oculto');
  }

  const esProfesionalActual = ROL_ACTUAL === 'profesional';

  if (esProfesionalActual) {
    rellenarTextoOVacio('dato-motivo', p.motivo_consulta);
    rellenarTextoOVacio('dato-patologia', p.patologia);
    rellenarTextoOVacio('dato-sintomas', p.sintomas);
    rellenarTextoOVacio('dato-observaciones', p.observaciones_generales);

    const linea = document.getElementById('linea-tiempo');
    linea.innerHTML = '';
    if (!p.sesiones || !p.sesiones.length) {
      linea.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">Todavía no se registraron sesiones para este paciente.</p>';
    } else {
      p.sesiones.forEach(s => {
        const item = document.createElement('div');
        item.className = 'sesion-item';
        const fecha = new Date(s.fecha_sesion + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: 'long', year: 'numeric' });
        item.innerHTML = `
          <div class="encabezado-sesion-item">
            <div class="fecha-sesion">${fecha}</div>
            <div class="acciones-sesion-item">
              <button class="btn-icono btn-icono-chico" title="Editar sesión" data-accion="editar-sesion">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
              </button>
              <button class="btn-icono btn-icono-chico peligro" title="Eliminar sesión" data-accion="eliminar-sesion">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0l-1 14a2 2 0 01-2 2H7a2 2 0 01-2-2L4 6h16z"/></svg>
              </button>
            </div>
          </div>
          <div class="desc-sesion">${escaparHtml(s.descripcion)}</div>
          ${s.evolucion ? `<div class="evolucion-sesion">${escaparHtml(s.evolucion)}</div>` : ''}
        `;
        item.querySelector('[data-accion="editar-sesion"]').addEventListener('click', () => abrirModalEditarSesion(s, p.id));
        item.querySelector('[data-accion="eliminar-sesion"]').addEventListener('click', () => confirmarEliminarSesion(s.id, fecha, p.id));
        linea.appendChild(item);
      });
    }

    document.getElementById('btn-nueva-sesion').addEventListener('click', () => abrirModalNuevaSesion(p.id));
    document.getElementById('btn-editar-legajo').addEventListener('click', () => irAVista('editar', { id: p.id }));
    document.getElementById('btn-exportar-pdf').addEventListener('click', () => {
      window.open(`exportar.php?id=${p.id}`, '_blank');
    });
    document.getElementById('btn-migrar-sede').addEventListener('click', () => abrirModalMigrarSede(p.id, `${p.nombre} ${p.apellido}`, p.sede_nombre));
    cargarAdjuntosPaciente(p.id);
    vincularSubidaAdjunto(p.id);
  } else {
    // La administrativa no ve contenido clínico: se ocultan los
    // bloques de motivo/patología/síntomas/observaciones, el historial
    // de sesiones y los adjuntos, junto con las acciones que los tocan.
    document.querySelectorAll('.bloque-texto').forEach(el => el.remove());
    document.getElementById('btn-editar-legajo').remove();
    document.getElementById('btn-exportar-pdf').remove();
    document.getElementById('btn-migrar-sede').remove();
    const panelSesiones = document.getElementById('linea-tiempo').closest('.panel');
    if (panelSesiones) panelSesiones.remove();
    const panelAdjuntos = document.getElementById('lista-adjuntos').closest('.panel');
    if (panelAdjuntos) panelAdjuntos.remove();
  }

  document.getElementById('btn-agendar-cita-paciente').addEventListener('click', () => abrirModalNuevaCita(p.id, `${p.nombre} ${p.apellido}`));

  cargarCitasPaciente(p.id, p.nombre, p.apellido, p.telefono);
}

async function abrirModalMigrarSede(pacienteId, nombrePaciente, sedeActualNombre) {
  const modalEnv = clonarPlantilla('tpl-modal-migrar-sede');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-paciente-migrar-sede').textContent =
    `${nombrePaciente} está actualmente en "${sedeActualNombre || 'sin sede asignada'}". Elegí la nueva sede.`;

  const select = document.getElementById('select-nueva-sede');
  select.innerHTML = '<option>Cargando...</option>';
  try {
    const res = await llamarApi(`${API.pacientes}?accion=sedes_disponibles`, { method: 'GET' });
    select.innerHTML = '';
    res.datos.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = s.nombre;
      select.appendChild(opt);
    });
  } catch (e) {
    select.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-migrar-sede').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-migrar-sede').addEventListener('click', async () => {
    const sedeId = select.value;
    if (!sedeId) { mostrarToast('Elegí una sede.', 'error'); return; }
    try {
      await llamarApi(`${API.pacientes}?accion=migrar_sede`, {
        method: 'POST',
        body: JSON.stringify({ id: pacienteId, sede_id: sedeId }),
      });
      mostrarToast('Paciente migrado correctamente.', 'exito');
      cerrar();
      irAVista('detalle', { id: pacienteId });
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
}

function rellenarTextoOVacio(idElemento, texto) {
  const el = document.getElementById(idElemento);
  if (texto && texto.trim() !== '') {
    el.textContent = texto;
    el.classList.remove('vacio');
  } else {
    el.textContent = 'No se registró información.';
    el.classList.add('vacio');
  }
}

function escaparHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto;
  return div.innerHTML;
}

/* ============================================================
   PLANTILLAS DE EVOLUCIÓN
   ============================================================ */
async function cargarPlantillas() {
  const res = await llamarApi(API.plantillas, { method: 'GET' });
  CACHE_PLANTILLAS = res.datos;
  return CACHE_PLANTILLAS;
}

function poblarSelectPlantillas(select) {
  select.innerHTML = '<option value="">Sin plantilla — escribir desde cero</option>';
  CACHE_PLANTILLAS.forEach(pl => {
    const opt = document.createElement('option');
    opt.value = pl.id;
    opt.textContent = pl.nombre;
    select.appendChild(opt);
  });
}

function abrirModalGestionarPlantillas(alCerrarCallback) {
  const modalEnv = clonarPlantilla('tpl-modal-gestionar-plantillas');
  document.body.appendChild(modalEnv);

  function cerrar() {
    modalEnv.remove();
    if (alCerrarCallback) alCerrarCallback();
  }

  function renderizarLista() {
    const lista = document.getElementById('lista-plantillas');
    lista.innerHTML = '';
    if (!CACHE_PLANTILLAS.length) {
      lista.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.88rem; margin-bottom:16px;">Todavía no creaste ninguna plantilla.</p>';
      return;
    }
    CACHE_PLANTILLAS.forEach(pl => {
      const item = document.createElement('div');
      item.className = 'item-plantilla';
      item.innerHTML = `
        <div class="item-plantilla-info">
          <div class="item-plantilla-nombre">${escaparHtml(pl.nombre)}</div>
          <div class="item-plantilla-contenido">${escaparHtml(pl.contenido)}</div>
        </div>
        <button class="btn-icono peligro btn-borrar-plantilla" title="Eliminar plantilla">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0l-1 14a2 2 0 01-2 2H7a2 2 0 01-2-2L4 6h16z"/></svg>
        </button>
      `;
      item.querySelector('.btn-borrar-plantilla').addEventListener('click', async () => {
        try {
          await llamarApi(`${API.plantillas}?accion=eliminar`, { method: 'POST', body: JSON.stringify({ id: pl.id }) });
          await cargarPlantillas();
          renderizarLista();
          mostrarToast('Plantilla eliminada.', 'exito');
        } catch (e) {
          mostrarToast(e.message, 'error');
        }
      });
      lista.appendChild(item);
    });
  }

  renderizarLista();

  document.getElementById('btn-guardar-plantilla').addEventListener('click', async () => {
    const nombre = document.getElementById('input-nombre-plantilla').value.trim();
    const texto = document.getElementById('input-contenido-plantilla').value.trim();
    if (!nombre || !texto) {
      mostrarToast('Completá el nombre y el contenido de la plantilla.', 'error');
      return;
    }
    try {
      await llamarApi(`${API.plantillas}?accion=crear`, {
        method: 'POST',
        body: JSON.stringify({ nombre, contenido: texto }),
      });
      await cargarPlantillas();
      renderizarLista();
      document.getElementById('input-nombre-plantilla').value = '';
      document.getElementById('input-contenido-plantilla').value = '';
      mostrarToast('Plantilla guardada.', 'exito');
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });

  document.getElementById('btn-cerrar-gestionar-plantillas').addEventListener('click', cerrar);
}

/* ============================================================
   SESIONES (agregar nueva, con plantillas)
   ============================================================ */
async function abrirModalNuevaSesion(pacienteId) {
  const modalEnv = clonarPlantilla('tpl-modal-nueva-sesion');
  document.body.appendChild(modalEnv);

  await cargarPlantillas();
  const selectPlantilla = document.getElementById('select-plantilla-sesion');
  poblarSelectPlantillas(selectPlantilla);

  const inputDescripcion = document.getElementById('input-descripcion-nueva-sesion');
  selectPlantilla.addEventListener('change', () => {
    const plantilla = CACHE_PLANTILLAS.find(pl => String(pl.id) === selectPlantilla.value);
    if (plantilla) {
      inputDescripcion.value = plantilla.contenido;
    }
  });

  document.getElementById('btn-gestionar-plantillas').addEventListener('click', () => {
    abrirModalGestionarPlantillas(() => {
      poblarSelectPlantillas(selectPlantilla);
    });
  });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-nueva-sesion').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-nueva-sesion').addEventListener('click', async () => {
    const fecha = document.getElementById('input-fecha-nueva-sesion').value;
    const descripcion = inputDescripcion.value.trim();
    const proxima = document.getElementById('input-proxima-nueva-sesion').value || null;
    const evolucion = document.getElementById('input-evolucion-nueva-sesion').value.trim() || null;

    if (!fecha || !descripcion) {
      mostrarToast('Completá la fecha y la descripción de la sesión.', 'error');
      return;
    }
    try {
      await llamarApi(`${API.pacientes}?accion=agregar_sesion`, {
        method: 'POST',
        body: JSON.stringify({ paciente_id: pacienteId, fecha_sesion: fecha, descripcion, proxima_cita: proxima, evolucion }),
      });
      mostrarToast('Sesión agregada correctamente.', 'exito');
      cerrar();
      irAVista('detalle', { id: pacienteId });
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
}

function abrirModalEditarSesion(sesion, pacienteId) {
  const modalEnv = clonarPlantilla('tpl-modal-editar-sesion');
  document.body.appendChild(modalEnv);

  document.getElementById('input-fecha-editar-sesion').value = sesion.fecha_sesion.slice(0, 10);
  document.getElementById('input-descripcion-editar-sesion').value = sesion.descripcion;
  document.getElementById('input-evolucion-editar-sesion').value = sesion.evolucion || '';

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-editar-sesion').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-editar-sesion').addEventListener('click', async () => {
    const fecha = document.getElementById('input-fecha-editar-sesion').value;
    const descripcion = document.getElementById('input-descripcion-editar-sesion').value.trim();
    const evolucion = document.getElementById('input-evolucion-editar-sesion').value.trim() || null;

    if (!fecha || !descripcion) {
      mostrarToast('Completá la fecha y la descripción de la sesión.', 'error');
      return;
    }

    const btn = document.getElementById('btn-confirmar-editar-sesion');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      await llamarApi(`${API.pacientes}?accion=editar_sesion`, {
        method: 'POST',
        body: JSON.stringify({ id: sesion.id, fecha_sesion: fecha, descripcion, evolucion }),
      });
      mostrarToast('Sesión actualizada.', 'exito');
      cerrar();
      irAVista('detalle', { id: pacienteId });
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Guardar cambios';
    }
  });
}

function confirmarEliminarSesion(sesionId, fechaTexto, pacienteId) {
  if (!confirm(`¿Eliminar la sesión del ${fechaTexto}? Esta acción no se puede deshacer.`)) return;
  llamarApi(`${API.pacientes}?accion=eliminar_sesion`, {
    method: 'POST',
    body: JSON.stringify({ id: sesionId }),
  }).then(() => {
    mostrarToast('Sesión eliminada.', 'exito');
    irAVista('detalle', { id: pacienteId });
  }).catch(e => {
    mostrarToast(e.message, 'error');
  });
}

/* ============================================================
   CITAS DENTRO DE LA FICHA DEL PACIENTE
   ============================================================ */
async function cargarCitasPaciente(pacienteId, nombrePaciente, apellidoPaciente, telefonoPaciente) {
  const cont = document.getElementById('lista-citas-paciente');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.citas}?accion=por_paciente&paciente_id=${pacienteId}`, { method: 'GET' });
    cont.innerHTML = '';
    const pendientes = res.datos.filter(c => c.estado === 'pendiente');
    if (!pendientes.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">No hay citas pendientes agendadas.</p>';
      return;
    }
    pendientes.forEach(c => cont.appendChild(crearFilaCita(c, pacienteId, nombrePaciente, apellidoPaciente, telefonoPaciente)));
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

/**
 * Genera un link de WhatsApp (wa.me) con un mensaje de recordatorio
 * pre-armado. No envía nada automáticamente: abre WhatsApp con el
 * texto listo para que el profesional/administrativa lo revise y
 * lo mande con un toque.
 */
function generarLinkWhatsapp(telefono, nombrePaciente, fechaIso, hora, tokenConfirmacion) {
  if (!telefono) return null;
  const telefonoLimpio = telefono.replace(/[^\d+]/g, '');
  const fechaTexto = formatearFechaLarga(fechaIso);
  const horaTexto = hora ? ` a las ${hora.slice(0, 5)}` : '';
  let mensaje = `Hola ${nombrePaciente}, te recuerdo tu turno del ${fechaTexto}${horaTexto}. ¡Te esperamos!`;
  if (tokenConfirmacion) {
    const linkConfirmacion = `${location.origin}${location.pathname.replace(/index\.html$/, '')}confirmar_turno.php?token=${tokenConfirmacion}`;
    mensaje += `\n\nPodés confirmar o cancelar tu turno acá: ${linkConfirmacion}`;
  }
  return `https://wa.me/${telefonoLimpio}?text=${encodeURIComponent(mensaje)}`;
}

function copiarLinkConfirmacion(token) {
  const link = `${location.origin}${location.pathname.replace(/index\.html$/, '')}confirmar_turno.php?token=${token}`;
  navigator.clipboard.writeText(link)
    .then(() => mostrarToast('Link copiado al portapapeles.', 'exito'))
    .catch(() => mostrarToast('No se pudo copiar el link. Copialo manualmente: ' + link, 'error'));
}

function crearFilaCita(c, pacienteId, nombrePaciente, apellidoPaciente, telefonoPaciente) {
  const fila = document.createElement('div');
  fila.className = 'tarjeta-paciente';
  fila.innerHTML = `
    <div class="info-principal">
      <div class="avatar-iniciales" style="background:var(--salvia-claro);">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      </div>
      <div>
        <div class="nombre">${formatearFechaLarga(c.fecha)}${c.hora ? ' · ' + c.hora.slice(0,5) : ''}</div>
        <div class="meta">${escaparHtml(c.motivo || 'Sin motivo especificado')}</div>
      </div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  const acciones = fila.querySelector('.acciones-tarjeta');

  const linkWhatsapp = generarLinkWhatsapp(telefonoPaciente, nombrePaciente, c.fecha, c.hora, c.token_confirmacion);
  if (linkWhatsapp) {
    const btnWhatsapp = document.createElement('a');
    btnWhatsapp.className = 'btn-icono whatsapp';
    btnWhatsapp.title = 'Enviar recordatorio por WhatsApp';
    btnWhatsapp.href = linkWhatsapp;
    btnWhatsapp.target = '_blank';
    btnWhatsapp.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.6 6.32A8.86 8.86 0 0012 4a8.9 8.9 0 00-7.69 13.4L3 21l3.79-1.27A8.9 8.9 0 0012 21a8.9 8.9 0 006.3-15.21l-.7.53zM12 19.4a7.4 7.4 0 01-3.77-1.03l-.27-.16-2.8.94.94-2.73-.18-.28A7.4 7.4 0 1119.4 12a7.4 7.4 0 01-7.4 7.4zm4.06-5.54c-.22-.11-1.3-.64-1.5-.71-.2-.08-.35-.11-.5.11-.14.22-.56.71-.69.85-.13.15-.25.16-.47.05-.22-.1-.92-.34-1.75-1.08-.65-.58-1.08-1.29-1.21-1.51-.13-.22-.01-.34.11-.46.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.16.04-.3-.03-.41-.08-.11-.46-1.11-.63-1.52-.17-.4-.34-.34-.47-.35h-.4c-.13 0-.35.05-.53.25-.18.2-.7.69-.7 1.66 0 .98.71 1.93.81 2.06.1.14 1.39 2.12 3.38 2.89 1.99.78 1.99.52 2.35.49.36-.04 1.3-.53 1.48-1.04.18-.51.18-.95.13-1.04-.05-.1-.2-.16-.42-.27z"/></svg>`;
    acciones.appendChild(btnWhatsapp);
  }

  if (c.token_confirmacion) {
    const btnCopiarLink = document.createElement('button');
    btnCopiarLink.className = 'btn-icono';
    btnCopiarLink.title = 'Copiar link de confirmación';
    btnCopiarLink.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>`;
    btnCopiarLink.addEventListener('click', () => copiarLinkConfirmacion(c.token_confirmacion));
    acciones.appendChild(btnCopiarLink);
  }

  const btnAtendida = document.createElement('button');
  btnAtendida.className = 'btn-icono';
  btnAtendida.title = 'Marcar como atendida';
  btnAtendida.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>`;
  btnAtendida.addEventListener('click', () => cambiarEstadoCita(c.id, 'atendida', pacienteId));

  const btnCancelar = document.createElement('button');
  btnCancelar.className = 'btn-icono peligro';
  btnCancelar.title = 'Cancelar cita';
  btnCancelar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>`;
  btnCancelar.addEventListener('click', () => cambiarEstadoCita(c.id, 'cancelada', pacienteId));

  acciones.appendChild(btnAtendida);
  acciones.appendChild(btnCancelar);
  return fila;
}

async function cambiarEstadoCita(citaId, estado, pacienteId) {
  try {
    await llamarApi(`${API.citas}?accion=cambiar_estado`, {
      method: 'POST',
      body: JSON.stringify({ id: citaId, estado }),
    });
    mostrarToast(estado === 'atendida' ? 'Cita marcada como atendida.' : 'Cita cancelada.', 'exito');
    if (pacienteId) cargarCitasPaciente(pacienteId);
  } catch (e) {
    mostrarToast(e.message, 'error');
  }
}

function abrirModalNuevaCita(pacienteIdFijo = null, nombreFijo = null) {
  const modalEnv = clonarPlantilla('tpl-modal-nueva-cita');
  document.body.appendChild(modalEnv);

  const inputBuscar = document.getElementById('input-buscar-paciente-cita');
  const inputPacienteId = document.getElementById('input-paciente-id-cita');
  const resultadosBox = document.getElementById('resultados-buscar-paciente-cita');

  if (pacienteIdFijo) {
    inputBuscar.value = nombreFijo;
    inputBuscar.disabled = true;
    inputPacienteId.value = pacienteIdFijo;
  } else {
    let timeoutBusqueda = null;
    inputBuscar.addEventListener('input', () => {
      clearTimeout(timeoutBusqueda);
      const valor = inputBuscar.value.trim();
      inputPacienteId.value = '';
      if (valor.length < 2) { resultadosBox.innerHTML = ''; return; }
      timeoutBusqueda = setTimeout(() => buscarPacientesParaCita(valor, resultadosBox, inputBuscar, inputPacienteId), 300);
    });
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-nueva-cita').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-nueva-cita').addEventListener('click', async () => {
    const pacienteId = inputPacienteId.value;
    const fecha = document.getElementById('input-fecha-cita').value;
    const hora = document.getElementById('input-hora-cita').value || null;
    const motivo = document.getElementById('input-motivo-cita').value.trim() || null;

    if (!pacienteId) { mostrarToast('Elegí un paciente de la lista.', 'error'); return; }
    if (!fecha) { mostrarToast('Elegí una fecha para la cita.', 'error'); return; }

    try {
      await llamarApi(`${API.citas}?accion=crear`, {
        method: 'POST',
        body: JSON.stringify({ paciente_id: pacienteId, fecha, hora, motivo }),
      });
      mostrarToast('Cita agendada correctamente.', 'exito');
      cerrar();
      if (PACIENTE_ID_ACTUAL_DETALLE && String(PACIENTE_ID_ACTUAL_DETALLE) === String(pacienteId)) {
        cargarCitasPaciente(pacienteId);
      }
      if (document.getElementById('calendario-grilla')) {
        renderizarCalendario();
      }
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
}

async function buscarPacientesParaCita(valor, resultadosBox, inputBuscar, inputPacienteId) {
  try {
    const esNumerico = /^\d+$/.test(valor);
    const tipo = esNumerico ? 'dni' : 'nombre';
    const res = await llamarApi(`${API.pacientes}?accion=buscar&tipo=${tipo}&valor=${encodeURIComponent(valor)}`, { method: 'GET' });
    resultadosBox.innerHTML = '';
    if (!res.datos.length) {
      resultadosBox.innerHTML = '<div class="item-autocompletar vacio">No se encontraron pacientes.</div>';
      return;
    }
    res.datos.slice(0, 6).forEach(p => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'item-autocompletar';
      item.textContent = `${p.apellido}, ${p.nombre} — DNI ${p.dni}`;
      item.addEventListener('click', () => {
        inputBuscar.value = `${p.apellido}, ${p.nombre}`;
        inputPacienteId.value = p.id;
        resultadosBox.innerHTML = '';
      });
      resultadosBox.appendChild(item);
    });
  } catch (e) {
    resultadosBox.innerHTML = '';
  }
}

/* ============================================================
   ARCHIVOS ADJUNTOS
   ============================================================ */
async function cargarAdjuntosPaciente(pacienteId) {
  const cont = document.getElementById('lista-adjuntos');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.adjuntos}?accion=listar&paciente_id=${pacienteId}`, { method: 'GET' });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">No hay archivos adjuntos todavía.</p>';
      return;
    }
    res.datos.forEach(a => cont.appendChild(crearItemAdjunto(a, pacienteId)));
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearItemAdjunto(a, pacienteId) {
  const esImagen = a.tipo_mime.startsWith('image/');
  const item = document.createElement('div');
  item.className = 'item-adjunto';
  const pesoKb = Math.round(a.tamanio_bytes / 1024);
  const pesoTexto = pesoKb > 1024 ? `${(pesoKb / 1024).toFixed(1)} MB` : `${pesoKb} KB`;

  item.innerHTML = `
    <div class="item-adjunto-icono ${esImagen ? 'imagen' : 'pdf'}">
      ${esImagen
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>'}
    </div>
    <div class="item-adjunto-info">
      <div class="item-adjunto-nombre">${escaparHtml(a.descripcion || a.nombre_original)}</div>
      <div class="item-adjunto-meta">${escaparHtml(a.nombre_original)} · ${pesoTexto} · ${formatearFechaCorta(a.subido_en.slice(0,10))}</div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  const acciones = item.querySelector('.acciones-tarjeta');

  const btnVer = document.createElement('a');
  btnVer.className = 'btn-icono';
  btnVer.title = 'Ver / descargar';
  btnVer.href = `${API.adjuntos}?accion=ver&id=${a.id}`;
  btnVer.target = '_blank';
  btnVer.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;

  const btnBorrar = document.createElement('button');
  btnBorrar.className = 'btn-icono peligro';
  btnBorrar.title = 'Eliminar archivo';
  btnBorrar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0l-1 14a2 2 0 01-2 2H7a2 2 0 01-2-2L4 6h16z"/></svg>`;
  btnBorrar.addEventListener('click', async () => {
    if (!confirm('¿Eliminar este archivo adjunto? Esta acción no se puede deshacer.')) return;
    try {
      await llamarApi(`${API.adjuntos}?accion=eliminar`, { method: 'POST', body: JSON.stringify({ id: a.id }) });
      mostrarToast('Archivo eliminado.', 'exito');
      cargarAdjuntosPaciente(pacienteId);
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });

  acciones.appendChild(btnVer);
  acciones.appendChild(btnBorrar);
  return item;
}

function vincularSubidaAdjunto(pacienteId) {
  const input = document.getElementById('input-subir-adjunto');
  input.addEventListener('change', () => {
    if (!input.files || !input.files[0]) return;
    const archivo = input.files[0];
    const TAMANIO_MAXIMO_MB = 15;
    if (archivo.size > TAMANIO_MAXIMO_MB * 1024 * 1024) {
      mostrarToast(`El archivo pesa ${(archivo.size / (1024 * 1024)).toFixed(1)} MB. El máximo permitido es ${TAMANIO_MAXIMO_MB} MB.`, 'error');
      input.value = '';
      return;
    }
    ARCHIVO_PENDIENTE_SUBIR = archivo;
    abrirModalDescripcionAdjunto(pacienteId);
    input.value = '';
  });
}

function abrirModalDescripcionAdjunto(pacienteId) {
  const modalEnv = clonarPlantilla('tpl-modal-descripcion-adjunto');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-nombre-archivo-subir').textContent = `Archivo: ${ARCHIVO_PENDIENTE_SUBIR.name}`;

  function cerrar() { modalEnv.remove(); ARCHIVO_PENDIENTE_SUBIR = null; }
  document.getElementById('btn-cancelar-subir-adjunto').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-subir-adjunto').addEventListener('click', async () => {
    const descripcion = document.getElementById('input-descripcion-adjunto').value.trim();
    const btn = document.getElementById('btn-confirmar-subir-adjunto');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Subiendo...';

    const formData = new FormData();
    formData.append('archivo', ARCHIVO_PENDIENTE_SUBIR);
    formData.append('paciente_id', pacienteId);
    formData.append('descripcion', descripcion);

    try {
      const respuesta = await fetch(`${API.adjuntos}?accion=subir`, { method: 'POST', body: formData });
      let datos;
      try {
        datos = await respuesta.json();
      } catch (errorParseo) {
        // El servidor devolvió algo que no es JSON (por ejemplo, una
        // página de error de PHP/Apache cuando el archivo supera el
        // límite de subida configurado en el hosting).
        throw new Error(
          respuesta.status === 413 || respuesta.status === 0
            ? 'El archivo es demasiado grande para este servidor. Probá con un archivo más chico.'
            : 'No se pudo subir el archivo (el servidor devolvió una respuesta inesperada). Probá con un archivo más chico, o avisá a soporte si sigue pasando.'
        );
      }
      if (!respuesta.ok || !datos.ok) throw new Error(datos.error || 'No se pudo subir el archivo.');
      mostrarToast('Archivo subido correctamente.', 'exito');
      modalEnv.remove();
      ARCHIVO_PENDIENTE_SUBIR = null;
      cargarAdjuntosPaciente(pacienteId);
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Subir archivo';
    }
  });
}

/* ============================================================
   VISTA: AGENDA (calendario completo)
   ============================================================ */
async function montarVistaAgenda(contenido) {
  contenido.appendChild(clonarPlantilla('tpl-agenda'));
  MES_AGENDA_ACTUAL = new Date();
  DIA_SELECCIONADO_AGENDA = null;

  document.getElementById('btn-mes-anterior').addEventListener('click', () => {
    MES_AGENDA_ACTUAL.setMonth(MES_AGENDA_ACTUAL.getMonth() - 1);
    renderizarCalendario();
  });
  document.getElementById('btn-mes-siguiente').addEventListener('click', () => {
    MES_AGENDA_ACTUAL.setMonth(MES_AGENDA_ACTUAL.getMonth() + 1);
    renderizarCalendario();
  });
  document.getElementById('btn-nueva-cita').addEventListener('click', () => abrirModalNuevaCita());

  await renderizarCalendario();
}

async function renderizarCalendario() {
  const titulo = document.getElementById('calendario-mes-titulo');
  const grilla = document.getElementById('calendario-grilla');
  if (!titulo || !grilla) return;

  const año = MES_AGENDA_ACTUAL.getFullYear();
  const mes = MES_AGENDA_ACTUAL.getMonth();

  titulo.textContent = MES_AGENDA_ACTUAL.toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });

  const primerDiaMes = new Date(año, mes, 1);
  const ultimoDiaMes = new Date(año, mes + 1, 0);
  const desde = aFechaIso(primerDiaMes);
  const hasta = aFechaIso(ultimoDiaMes);

  let citasDelMes = [];
  try {
    const res = await llamarApi(`${API.citas}?accion=rango&desde=${desde}&hasta=${hasta}`, { method: 'GET' });
    citasDelMes = res.datos;
  } catch (e) {
    mostrarToast('No se pudo cargar la agenda del mes.', 'error');
  }

  const citasPorDia = {};
  citasDelMes.forEach(c => {
    if (!citasPorDia[c.fecha]) citasPorDia[c.fecha] = [];
    citasPorDia[c.fecha].push(c);
  });

  grilla.innerHTML = '';
  const diaSemanaInicio = primerDiaMes.getDay(); // 0 = domingo
  for (let i = 0; i < diaSemanaInicio; i++) {
    const celdaVacia = document.createElement('div');
    celdaVacia.className = 'celda-calendario vacia';
    grilla.appendChild(celdaVacia);
  }

  const hoyIso = aFechaIso(new Date());

  for (let dia = 1; dia <= ultimoDiaMes.getDate(); dia++) {
    const fechaIso = aFechaIso(new Date(año, mes, dia));
    const celda = document.createElement('button');
    celda.type = 'button';
    celda.className = 'celda-calendario';
    if (fechaIso === hoyIso) celda.classList.add('hoy');
    if (fechaIso === DIA_SELECCIONADO_AGENDA) celda.classList.add('seleccionada');

    const citasDia = citasPorDia[fechaIso] || [];
    const pendientesDia = citasDia.filter(c => c.estado === 'pendiente').length;

    celda.innerHTML = `
      <span class="celda-numero">${dia}</span>
      ${pendientesDia ? `<span class="celda-puntito">${pendientesDia}</span>` : ''}
    `;
    celda.addEventListener('click', () => {
      DIA_SELECCIONADO_AGENDA = fechaIso;
      renderizarCalendario();
      mostrarCitasDelDia(fechaIso, citasDia);
    });
    grilla.appendChild(celda);
  }

  if (DIA_SELECCIONADO_AGENDA) {
    mostrarCitasDelDia(DIA_SELECCIONADO_AGENDA, citasPorDia[DIA_SELECCIONADO_AGENDA] || []);
  } else {
    document.getElementById('titulo-citas-dia').textContent = 'Citas — elegí un día en el calendario';
    document.getElementById('lista-citas-dia').innerHTML = '';
  }
}

function mostrarCitasDelDia(fechaIso, citas) {
  document.getElementById('titulo-citas-dia').textContent = `Citas — ${formatearFechaLarga(fechaIso)}`;
  const lista = document.getElementById('lista-citas-dia');
  lista.innerHTML = '';

  if (!citas.length) {
    lista.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">No hay citas agendadas este día.</p>';
    return;
  }

  citas.sort((a, b) => (a.hora || '').localeCompare(b.hora || ''));

  citas.forEach(c => {
    const fila = document.createElement('div');
    fila.className = 'tarjeta-paciente';
    const estadoEtiquetas = {
      pendiente: '<span class="etiqueta">Pendiente</span>',
      atendida: '<span class="etiqueta" style="background:var(--salvia-claro); color:var(--salvia-oscuro);">Atendida</span>',
      cancelada: '<span class="etiqueta particular">Cancelada</span>',
      ausente: '<span class="etiqueta" style="background:var(--coral-claro); color:var(--coral);">Ausente</span>',
    };
    fila.innerHTML = `
      <div class="info-principal">
        <div class="avatar-iniciales">${(c.nombre[0] + c.apellido[0]).toUpperCase()}</div>
        <div>
          <div class="nombre">${escaparHtml(c.nombre + ' ' + c.apellido)}</div>
          <div class="meta">${c.hora ? c.hora.slice(0,5) + ' · ' : ''}${escaparHtml(c.motivo || 'Sin motivo')} ${estadoEtiquetas[c.estado] || ''}</div>
        </div>
      </div>
      <div class="acciones-tarjeta"></div>
    `;
    const acciones = fila.querySelector('.acciones-tarjeta');

    const btnVerLegajo = document.createElement('button');
    btnVerLegajo.className = 'btn-icono';
    btnVerLegajo.title = 'Ver legajo';
    btnVerLegajo.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    btnVerLegajo.addEventListener('click', () => irAVista('detalle', { id: c.paciente_id }));
    acciones.appendChild(btnVerLegajo);

    const linkWhatsappDia = generarLinkWhatsapp(c.telefono, c.nombre, c.fecha, c.hora, c.token_confirmacion);
    if (linkWhatsappDia) {
      const btnWhatsappDia = document.createElement('a');
      btnWhatsappDia.className = 'btn-icono whatsapp';
      btnWhatsappDia.title = 'Enviar recordatorio por WhatsApp';
      btnWhatsappDia.href = linkWhatsappDia;
      btnWhatsappDia.target = '_blank';
      btnWhatsappDia.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.6 6.32A8.86 8.86 0 0012 4a8.9 8.9 0 00-7.69 13.4L3 21l3.79-1.27A8.9 8.9 0 0012 21a8.9 8.9 0 006.3-15.21l-.7.53zM12 19.4a7.4 7.4 0 01-3.77-1.03l-.27-.16-2.8.94.94-2.73-.18-.28A7.4 7.4 0 1119.4 12a7.4 7.4 0 01-7.4 7.4zm4.06-5.54c-.22-.11-1.3-.64-1.5-.71-.2-.08-.35-.11-.5.11-.14.22-.56.71-.69.85-.13.15-.25.16-.47.05-.22-.1-.92-.34-1.75-1.08-.65-.58-1.08-1.29-1.21-1.51-.13-.22-.01-.34.11-.46.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.16.04-.3-.03-.41-.08-.11-.46-1.11-.63-1.52-.17-.4-.34-.34-.47-.35h-.4c-.13 0-.35.05-.53.25-.18.2-.7.69-.7 1.66 0 .98.71 1.93.81 2.06.1.14 1.39 2.12 3.38 2.89 1.99.78 1.99.52 2.35.49.36-.04 1.3-.53 1.48-1.04.18-.51.18-.95.13-1.04-.05-.1-.2-.16-.42-.27z"/></svg>`;
      acciones.appendChild(btnWhatsappDia);
    }

    if (c.estado === 'pendiente') {
      const btnAtendida = document.createElement('button');
      btnAtendida.className = 'btn-icono';
      btnAtendida.title = 'Marcar como atendida';
      btnAtendida.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>`;
      btnAtendida.addEventListener('click', async () => {
        await cambiarEstadoCita(c.id, 'atendida');
        renderizarCalendario();
      });
      acciones.appendChild(btnAtendida);

      const btnAusente = document.createElement('button');
      btnAusente.className = 'btn-icono';
      btnAusente.title = 'Marcar como ausente';
      btnAusente.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>`;
      btnAusente.addEventListener('click', async () => {
        await cambiarEstadoCita(c.id, 'ausente');
        renderizarCalendario();
      });
      acciones.appendChild(btnAusente);

      const btnCancelar = document.createElement('button');
      btnCancelar.className = 'btn-icono peligro';
      btnCancelar.title = 'Cancelar cita';
      btnCancelar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>`;
      btnCancelar.addEventListener('click', async () => {
        await cambiarEstadoCita(c.id, 'cancelada');
        renderizarCalendario();
      });
      acciones.appendChild(btnCancelar);
    }

    lista.appendChild(fila);
  });
}

/* ============================================================
   VISTA: DASHBOARD DE ESTADÍSTICAS (solo profesional)
   ============================================================ */
async function montarVistaDashboard(contenido) {
  contenido.appendChild(clonarPlantilla('tpl-dashboard'));

  document.getElementById('btn-exportar-todo').addEventListener('click', () => {
    descargarArchivoDesdeUrl(`${API.pacientes}?accion=exportar_todo`);
  });

  const cont = document.getElementById('contenido-dashboard');
  try {
    const res = await llamarApi(`${API.admin}?accion=estadisticas`, { method: 'GET' });
    renderizarDashboard(cont, res.datos);
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function renderizarDashboard(cont, d) {
  cont.innerHTML = '';

  // Fila de tarjetas numéricas
  const filaStats = document.createElement('div');
  filaStats.className = 'grilla-stats';

  const diferenciaSesiones = d.sesiones_este_mes - d.sesiones_mes_anterior;
  const tendenciaTexto = diferenciaSesiones === 0
    ? 'Igual que el mes anterior'
    : diferenciaSesiones > 0
      ? `+${diferenciaSesiones} respecto al mes anterior`
      : `${diferenciaSesiones} respecto al mes anterior`;

  const stats = [
    { valor: d.total_pacientes, etiqueta: 'Pacientes totales', detalle: `+${d.pacientes_nuevos_mes} este mes` },
    { valor: d.sesiones_este_mes, etiqueta: 'Sesiones este mes', detalle: tendenciaTexto },
  ];

  const estadosCita = { pendiente: 'Pendientes', atendida: 'Atendidas', cancelada: 'Canceladas', ausente: 'Ausencias' };
  d.citas_por_estado_mes.forEach(c => {
    stats.push({ valor: c.total, etiqueta: estadosCita[c.estado] || c.estado, detalle: 'este mes' });
  });

  stats.forEach(s => {
    const tarjeta = clonarPlantilla('tpl-tarjeta-stat').firstElementChild;
    tarjeta.querySelector('.tarjeta-stat-valor').textContent = s.valor;
    tarjeta.querySelector('.tarjeta-stat-etiqueta').textContent = s.etiqueta;
    tarjeta.querySelector('.tarjeta-stat-detalle').textContent = s.detalle;
    filaStats.appendChild(tarjeta);
  });

  cont.appendChild(filaStats);

  // Panel de obras sociales
  const panelObras = document.createElement('div');
  panelObras.className = 'panel';
  panelObras.innerHTML = `
    <div class="panel-titulo">Pacientes por obra social</div>
    <p class="panel-subtitulo">Distribución de tu cartera de pacientes.</p>
    <div class="barras-obras-sociales" id="barras-obras-sociales"></div>
  `;
  cont.appendChild(panelObras);

  const contBarras = panelObras.querySelector('#barras-obras-sociales');
  const maximoObra = Math.max(...d.por_obra_social.map(o => o.total), 1);
  d.por_obra_social.forEach(o => {
    const fila = document.createElement('div');
    fila.className = 'fila-barra-stat';
    const porcentaje = Math.round((o.total / maximoObra) * 100);
    fila.innerHTML = `
      <span class="fila-barra-etiqueta">${escaparHtml(o.nombre)}</span>
      <div class="fila-barra-fondo"><div class="fila-barra-relleno" style="width:${porcentaje}%"></div></div>
      <span class="fila-barra-valor">${o.total}</span>
    `;
    contBarras.appendChild(fila);
  });

  // Panel de sesiones últimos 6 meses
  const panelMeses = document.createElement('div');
  panelMeses.className = 'panel';
  panelMeses.innerHTML = `
    <div class="panel-titulo">Sesiones de los últimos 6 meses</div>
    <p class="panel-subtitulo">Volumen de atención mes a mes.</p>
    <div class="barras-meses" id="barras-meses"></div>
  `;
  cont.appendChild(panelMeses);

  const contMeses = panelMeses.querySelector('#barras-meses');
  const maximoMes = Math.max(...d.sesiones_ultimos_6_meses.map(m => m.total), 1);
  const nombresMeses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
  d.sesiones_ultimos_6_meses.forEach(m => {
    const [anio, mes] = m.mes.split('-');
    const alturaPx = Math.max(8, Math.round((m.total / maximoMes) * 120));
    const columna = document.createElement('div');
    columna.className = 'columna-mes';
    columna.innerHTML = `
      <div class="columna-mes-valor">${m.total}</div>
      <div class="columna-mes-barra" style="height:${alturaPx}px"></div>
      <div class="columna-mes-etiqueta">${nombresMeses[parseInt(mes, 10) - 1]}</div>
    `;
    contMeses.appendChild(columna);
  });
}

/* ============================================================
   VISTA: CONFIGURACIÓN (usuarios + historial de cambios)
   ============================================================ */
async function cargarAvisoLicenciasPorVencer() {
  const cont = document.getElementById('aviso-licencias-por-vencer');
  if (!cont) return;
  try {
    const res = await llamarApi(`${API.admin}?accion=licencias_por_vencer`, { method: 'GET' });
    if (!res.datos.length) {
      cont.classList.add('oculto');
      return;
    }
    const nombres = res.datos.map(p => {
      const dias = p.dias_restantes;
      const texto = dias === 0 ? 'vence hoy' : dias === 1 ? 'vence mañana' : `vence en ${dias} días`;
      return `${escaparHtml(p.nombre_completo)} (${texto})`;
    }).join(' · ');
    cont.innerHTML = `
      <div class="aviso-licencias-vencer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86l-8.18 14.18A1 1 0 003 19.5h18a1 1 0 00.89-1.46L13.71 3.86a1 1 0 00-1.42 0z"/></svg>
        <div>
          <strong>${res.datos.length} licencia${res.datos.length === 1 ? '' : 's'} ${res.datos.length === 1 ? 'vence' : 'vencen'} pronto:</strong>
          ${nombres}
        </div>
      </div>`;
    cont.classList.remove('oculto');
  } catch (e) {
    cont.classList.add('oculto');
  }
}

async function montarVistaConfiguracion(contenido) {
  contenido.appendChild(clonarPlantilla('tpl-configuracion'));

  // El Desarrollador ya está en su única pantalla: no tiene
  // a dónde "volver al panel", así que ese botón no aplica.
  if (ROL_ACTUAL === 'desarrollador') {
    const btnVolver = contenido.querySelector('[data-volver]');
    if (btnVolver) btnVolver.remove();
    cargarAvisoLicenciasPorVencer();
  }

  const tabs = contenido.querySelectorAll('[data-tab-config]');
  const paneles = { sedes: 'panel-config-sedes', usuarios: 'panel-config-usuarios', historial: 'panel-config-historial', version: 'panel-config-version', reportes: 'panel-config-reportes', papelera: 'panel-config-papelera', huerfanos: 'panel-config-huerfanos' };
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('activo'));
      tab.classList.add('activo');
      const destino = tab.dataset.tabConfig;
      Object.entries(paneles).forEach(([clave, idPanel]) => {
        document.getElementById(idPanel).classList.toggle('oculto', clave !== destino);
      });
      if (destino === 'historial') cargarHistorialCambios(1);
      if (destino === 'usuarios') cargarListaUsuarios();
      if (destino === 'version') cargarVerificacionVersion();
      if (destino === 'reportes') cargarReportesSede();
      if (destino === 'papelera') inicializarPapeleraDev();
      if (destino === 'huerfanos') inicializarLegajosHuerfanos();
    });
  });

  document.getElementById('btn-revisar-version').addEventListener('click', cargarVerificacionVersion);
  document.getElementById('btn-agregar-sede').addEventListener('click', abrirModalNuevaSede);
  document.getElementById('btn-agregar-usuario').addEventListener('click', abrirModalNuevoUsuario);

  await cargarListaSedes();
}

let SEDE_PAPELERA_DEV_ID = null;

async function inicializarPapeleraDev() {
  const selectSede = document.getElementById('select-sede-papelera-dev');
  const selectProf = document.getElementById('select-profesional-papelera-dev');
  const lista = document.getElementById('lista-papelera-dev');

  // Evitamos volver a pedir las sedes si ya se cargaron antes en esta visita al panel.
  if (!selectSede.dataset.cargado) {
    try {
      const res = await llamarApi(`${API.auth}?accion=listar_sedes`, { method: 'POST', body: JSON.stringify({ accion: 'listar_sedes' }) });
      const sedesActivas = res.datos.filter(s => s.activa);
      sedesActivas.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.nombre;
        selectSede.appendChild(opt);
      });
      selectSede.dataset.cargado = '1';
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  }

  selectSede.onchange = async () => {
    SEDE_PAPELERA_DEV_ID = selectSede.value || null;
    selectProf.innerHTML = '<option value="">Cargando…</option>';
    selectProf.disabled = true;
    lista.innerHTML = '';

    if (!SEDE_PAPELERA_DEV_ID) {
      selectProf.innerHTML = '<option value="">Elegí primero una sede…</option>';
      return;
    }

    try {
      const res = await llamarApi(`${API.auth}?accion=listar_profesionales_sede_dev`, {
        method: 'POST',
        body: JSON.stringify({ accion: 'listar_profesionales_sede_dev', sede_id: SEDE_PAPELERA_DEV_ID }),
      });
      selectProf.innerHTML = '<option value="">Elegí un profesional…</option>';
      res.datos.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.nombre_completo;
        selectProf.appendChild(opt);
      });
      selectProf.disabled = false;
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  };

  selectProf.onchange = () => {
    if (selectProf.value) cargarPapeleraDevDeProfesional(selectProf.value);
    else lista.innerHTML = '';
  };
}

async function cargarPapeleraDevDeProfesional(profesionalId) {
  const lista = document.getElementById('lista-papelera-dev');
  lista.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_papelera_dev`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_papelera_dev', profesional_id: profesionalId, sede_id: SEDE_PAPELERA_DEV_ID }),
    });
    lista.innerHTML = '';
    if (!res.datos.length) {
      lista.innerHTML = '<p class="resumen-vacio">Este profesional no tiene legajos eliminados en esta sede.</p>';
      return;
    }
    res.datos.forEach(reg => lista.appendChild(crearFilaPapeleraDev(reg)));
  } catch (e) {
    lista.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearFilaPapeleraDev(reg) {
  const fila = document.createElement('div');
  fila.className = 'tarjeta-paciente';
  const fecha = new Date(reg.eliminado_en).toLocaleDateString('es-AR');
  fila.innerHTML = `
    <div class="info-principal">
      <div class="avatar-iniciales" style="background:#F5E3DC; color:#C4654A;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0l-1 14a2 2 0 01-2 2H7a2 2 0 01-2-2L4 6h16z"/></svg>
      </div>
      <div>
        <div class="nombre">${escaparHtml(reg.nombre_completo)}</div>
        <div class="meta">DNI ${escaparHtml(reg.dni)} · Eliminado el ${fecha}</div>
      </div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  const acciones = fila.querySelector('.acciones-tarjeta');
  const btnRecuperar = document.createElement('button');
  btnRecuperar.className = 'btn btn-secundario btn-chico';
  btnRecuperar.textContent = 'Recuperar';
  btnRecuperar.addEventListener('click', () => abrirModalRecuperarLegajo(reg));
  acciones.appendChild(btnRecuperar);
  return fila;
}

async function abrirModalRecuperarLegajo(reg) {
  const modalEnv = clonarPlantilla('tpl-modal-recuperar-legajo');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-paciente-recuperar').textContent =
    `${reg.nombre_completo} (DNI ${reg.dni}) va a volver a aparecer como legajo activo, a nombre del profesional que elijas.`;

  const select = document.getElementById('select-nuevo-profesional-recuperar');
  select.innerHTML = '<option value="">Cargando…</option>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_profesionales_sede_dev`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_profesionales_sede_dev', sede_id: reg.sede_id_original }),
    });
    select.innerHTML = '';
    if (!res.datos.length) {
      select.innerHTML = '<option value="">No hay profesionales en esa sede</option>';
    } else {
      res.datos.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.nombre_completo;
        select.appendChild(opt);
      });
    }
  } catch (e) {
    select.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-recuperar-legajo').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-recuperar-legajo').addEventListener('click', async () => {
    const nuevoProfesionalId = select.value;
    if (!nuevoProfesionalId) { mostrarToast('Elegí a qué profesional asignarlo.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-recuperar-legajo');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Recuperando...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({ accion: 'recuperar_legajo_dev', id: reg.id, profesional_id: nuevoProfesionalId }),
      });
      mostrarToast('Legajo recuperado correctamente.', 'exito');
      cerrar();
      const selectProf = document.getElementById('select-profesional-papelera-dev');
      if (selectProf.value) cargarPapeleraDevDeProfesional(selectProf.value);
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Recuperar legajo';
    }
  });
}

async function inicializarLegajosHuerfanos() {
  const select = document.getElementById('select-profesional-huerfano');
  const lista = document.getElementById('lista-huerfanos-dev');
  lista.innerHTML = '';

  select.innerHTML = '<option value="">Cargando…</option>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_profesionales_desactivados`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_profesionales_desactivados' }),
    });
    select.innerHTML = '<option value="">Elegí un profesional…</option>';
    if (!res.datos.length) {
      select.innerHTML = '<option value="">No hay profesionales desactivados</option>';
    } else {
      res.datos.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = `${p.nombre_completo} (${p.total_pacientes} paciente${p.total_pacientes === 1 ? '' : 's'})`;
        select.appendChild(opt);
      });
    }
  } catch (e) {
    select.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  select.onchange = () => {
    if (select.value) cargarLegajosHuerfanosDeProfesional(select.value);
    else lista.innerHTML = '';
  };
}

async function cargarLegajosHuerfanosDeProfesional(profesionalId) {
  const lista = document.getElementById('lista-huerfanos-dev');
  lista.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_legajos_huerfanos`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_legajos_huerfanos', profesional_id: profesionalId }),
    });
    lista.innerHTML = '';
    if (!res.datos.length) {
      lista.innerHTML = '<p class="resumen-vacio">Este profesional no tiene pacientes activos.</p>';
      return;
    }
    res.datos.forEach(p => lista.appendChild(crearFilaLegajoHuerfano(p)));
  } catch (e) {
    lista.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearFilaLegajoHuerfano(p) {
  const fila = document.createElement('div');
  fila.className = 'tarjeta-paciente';
  fila.innerHTML = `
    <div class="info-principal">
      <div class="avatar-iniciales">${(p.nombre[0] + p.apellido[0]).toUpperCase()}</div>
      <div>
        <div class="nombre">${escaparHtml(p.nombre)} ${escaparHtml(p.apellido)}</div>
        <div class="meta">DNI ${escaparHtml(p.dni)} · ${escaparHtml(p.sede_nombre || 'Sin sede')}</div>
      </div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  const acciones = fila.querySelector('.acciones-tarjeta');
  const btnTransferir = document.createElement('button');
  btnTransferir.className = 'btn btn-secundario btn-chico';
  btnTransferir.textContent = 'Transferir';
  btnTransferir.addEventListener('click', () => abrirModalTransferirHuerfano(p));
  acciones.appendChild(btnTransferir);
  return fila;
}

async function abrirModalTransferirHuerfano(p) {
  const modalEnv = clonarPlantilla('tpl-modal-transferir-huerfano');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-paciente-transferir').textContent =
    `${p.nombre} ${p.apellido} (DNI ${p.dni}) va a pasar a estar a cargo del profesional que elijas.`;

  const select = document.getElementById('select-nuevo-profesional-transferir');
  select.innerHTML = '<option value="">Cargando…</option>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_profesionales_sede_dev`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_profesionales_sede_dev', sede_id: p.sede_id }),
    });
    select.innerHTML = '';
    if (!res.datos.length) {
      select.innerHTML = '<option value="">No hay profesionales en esa sede</option>';
    } else {
      res.datos.forEach(prof => {
        const opt = document.createElement('option');
        opt.value = prof.id;
        opt.textContent = prof.nombre_completo;
        select.appendChild(opt);
      });
    }
  } catch (e) {
    select.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-transferir-huerfano').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-transferir-huerfano').addEventListener('click', async () => {
    const nuevoProfesionalId = select.value;
    if (!nuevoProfesionalId) { mostrarToast('Elegí a qué profesional asignarlo.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-transferir-huerfano');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Transfiriendo...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({ accion: 'reasignar_legajo_huerfano', paciente_id: p.id, profesional_id: nuevoProfesionalId }),
      });
      mostrarToast('Legajo transferido correctamente.', 'exito');
      cerrar();
      const selectProf = document.getElementById('select-profesional-huerfano');
      if (selectProf.value) cargarLegajosHuerfanosDeProfesional(selectProf.value);
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Transferir legajo';
    }
  });
}

async function cargarReportesSede() {
  const cont = document.getElementById('lista-reportes-sede');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.admin}?accion=reporte_sedes`, { method: 'GET' });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p class="resumen-vacio">Todavía no hay sedes activas para reportar.</p>';
      return;
    }
    res.datos.forEach(s => cont.appendChild(crearTarjetaReporteSede(s)));
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearTarjetaReporteSede(s) {
  const tarjeta = document.createElement('div');
  tarjeta.className = 'tarjeta-reporte-sede';

  const etiquetasEstado = { pendiente: 'Pendientes', atendida: 'Atendidas', cancelada: 'Canceladas', ausente: 'Ausentes' };
  const citasTexto = (s.citas_por_estado_mes || []).length
    ? s.citas_por_estado_mes.map(c => `${etiquetasEstado[c.estado] || c.estado}: ${c.total}`).join(' · ')
    : 'Sin citas registradas este mes';

  tarjeta.innerHTML = `
    <div class="encabezado-reporte-sede">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-5 4h1m4 0h1m-5 4h1m4 0h1"/></svg>
      <span class="nombre-sede-reporte">${escaparHtml(s.nombre)}</span>
    </div>
    <div class="grilla-metricas-sede">
      <div class="metrica-sede"><span class="valor-metrica">${s.profesionales}</span><span class="etiqueta-metrica">Profesional${s.profesionales === 1 ? '' : 'es'}</span></div>
      <div class="metrica-sede"><span class="valor-metrica">${s.administrativas}</span><span class="etiqueta-metrica">Administrativa${s.administrativas === 1 ? '' : 's'}</span></div>
      <div class="metrica-sede"><span class="valor-metrica">${s.pacientes}</span><span class="etiqueta-metrica">Paciente${s.pacientes === 1 ? '' : 's'} en total</span></div>
      <div class="metrica-sede"><span class="valor-metrica">${s.sesiones_mes}</span><span class="etiqueta-metrica">Sesiones este mes</span></div>
    </div>
    <div class="citas-mes-reporte-sede">${escaparHtml(citasTexto)}</div>
  `;
  return tarjeta;
}

async function cargarVerificacionVersion() {
  const resumen = document.getElementById('resumen-version');
  const lista = document.getElementById('lista-archivos-version');
  resumen.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  lista.innerHTML = '';

  try {
    const res = await llamarApi(`${API.admin}?accion=verificar_version`, { method: 'GET' });

    if (res.sin_version_json) {
      resumen.innerHTML = `<p class="resumen-vacio">No se encontró el archivo version.json en el servidor. Subilo junto con la próxima actualización para poder usar esta verificación.</p>`;
      return;
    }

    if (res.hay_desactualizados) {
      resumen.innerHTML = `
        <div class="aviso-version aviso-version-mal">
          ⚠ Hay archivos desactualizados. Versión esperada: <strong>${escaparHtml(res.version)}</strong>${res.fecha ? ' (' + escaparHtml(res.fecha) + ')' : ''}.
          ${res.descripcion ? `<div class="descripcion-version">${escaparHtml(res.descripcion)}</div>` : ''}
        </div>`;
    } else {
      resumen.innerHTML = `
        <div class="aviso-version aviso-version-ok">
          ✅ Todo está actualizado a la versión <strong>${escaparHtml(res.version)}</strong>${res.fecha ? ' (' + escaparHtml(res.fecha) + ')' : ''}.
          ${res.descripcion ? `<div class="descripcion-version">${escaparHtml(res.descripcion)}</div>` : ''}
        </div>`;
    }

    lista.innerHTML = '';
    res.archivos.forEach(a => {
      const fila = document.createElement('div');
      fila.className = 'fila-archivo-version';
      let etiqueta, clase;
      if (a.estado === 'actualizado') {
        etiqueta = '✅ Actualizado'; clase = 'ok';
      } else if (a.estado === 'falta') {
        etiqueta = '❌ Falta en el servidor'; clase = 'mal';
      } else {
        etiqueta = '⚠ Versión vieja'; clase = 'mal';
      }
      fila.innerHTML = `
        <span class="ruta-archivo-version">${escaparHtml(a.archivo)}</span>
        <span class="estado-archivo-version ${clase}">${etiqueta}</span>
      `;
      lista.appendChild(fila);
    });
  } catch (e) {
    resumen.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

async function cargarListaSedes() {
  const cont = document.getElementById('lista-sedes-config');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_sedes`, { method: 'POST', body: JSON.stringify({ accion: 'listar_sedes' }) });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">Todavía no creaste ninguna sede.</p>';
      return;
    }
    res.datos.forEach(s => cont.appendChild(crearFilaSede(s)));
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearFilaSede(s) {
  const fila = document.createElement('div');
  fila.className = 'tarjeta-paciente';
  const fecha = new Date(s.creado_en).toLocaleDateString('es-AR');
  fila.innerHTML = `
    <div class="info-principal">
      <div class="avatar-iniciales" style="background:#DCE8F5; color:#2C5F8A;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-5 4h1m4 0h1m-5 4h1m4 0h1"/></svg>
      </div>
      <div>
        <div class="nombre">${escaparHtml(s.nombre)} ${s.activa ? '' : '<span class="etiqueta" style="background:var(--coral-claro); color:var(--coral);">Desactivada</span>'}</div>
        <div class="meta">Desde ${fecha}</div>
      </div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  if (s.activa) {
    const acciones = fila.querySelector('.acciones-tarjeta');

    const btnRenombrar = document.createElement('button');
    btnRenombrar.className = 'btn-icono';
    btnRenombrar.title = 'Renombrar sede';
    btnRenombrar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
    btnRenombrar.addEventListener('click', async () => {
      const nombreNuevo = prompt(`Nuevo nombre para "${s.nombre}":`, s.nombre);
      if (nombreNuevo === null) return;
      const limpio = nombreNuevo.trim();
      if (!limpio || limpio === s.nombre) return;
      try {
        await llamarApi(API.auth, { method: 'POST', body: JSON.stringify({ accion: 'renombrar_sede', id: s.id, nombre: limpio }) });
        mostrarToast('Sede renombrada. Los legajos existentes se actualizaron automáticamente.', 'exito');
        cargarListaSedes();
      } catch (e) {
        mostrarToast(e.message, 'error');
      }
    });
    acciones.appendChild(btnRenombrar);

    const btnDesactivar = document.createElement('button');
    btnDesactivar.className = 'btn-icono peligro';
    btnDesactivar.title = 'Desactivar sede';
    btnDesactivar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M4.93 4.93l14.14 14.14"/></svg>`;
    btnDesactivar.addEventListener('click', async () => {
      if (!confirm(`¿Desactivar la sede "${s.nombre}"? Ya no va a aparecer en el login.`)) return;
      try {
        await llamarApi(API.auth, { method: 'POST', body: JSON.stringify({ accion: 'desactivar_sede', id: s.id }) });
        mostrarToast('Sede desactivada.', 'exito');
        cargarListaSedes();
      } catch (e) {
        mostrarToast(e.message, 'error');
      }
    });
    acciones.appendChild(btnDesactivar);
  }
  return fila;
}

function abrirModalNuevaSede() {
  const modalEnv = clonarPlantilla('tpl-modal-nueva-sede');
  document.body.appendChild(modalEnv);
  const input = document.getElementById('input-nombre-nueva-sede');
  input.focus();

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-nueva-sede').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-nueva-sede').addEventListener('click', async () => {
    const nombre = input.value.trim();
    if (!nombre) { mostrarToast('Escribí el nombre de la sede.', 'error'); return; }
    try {
      await llamarApi(API.auth, { method: 'POST', body: JSON.stringify({ accion: 'crear_sede', nombre }) });
      mostrarToast('Sede creada.', 'exito');
      cerrar();
      cargarListaSedes();
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  });
  input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') document.getElementById('btn-confirmar-nueva-sede').click(); });
}

async function cargarListaUsuarios() {
  const cont = document.getElementById('lista-usuarios');
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_usuarios`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'listar_usuarios' }),
    });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">Todavía no creaste ningún usuario.</p>';
      return;
    }
    res.datos.forEach(u => cont.appendChild(crearFilaUsuario(u)));
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  const input = document.getElementById('input-buscar-profesional');
  if (input && !input.dataset.conectado) {
    input.dataset.conectado = '1';
    let temporizador = null;
    input.addEventListener('input', () => {
      clearTimeout(temporizador);
      temporizador = setTimeout(() => buscarProfesionalesDev(input.value.trim()), 300);
    });
  }
}

async function buscarProfesionalesDev(q) {
  const cont = document.getElementById('lista-usuarios');
  if (!q) { cargarListaUsuarios(); return; }
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=buscar_profesionales`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'buscar_profesionales', q }),
    });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">No se encontró ningún profesional con esa búsqueda.</p>';
      return;
    }
    res.datos.forEach(u => {
      u.nombre_completo = u.nombre_completo || `${u.titulo} ${u.nombre_completo}`;
      u.rol = 'profesional';
      u.sedes = u.sedes || [];
      const fila = crearFilaUsuario(u);
      if (u.numero_legajo) {
        const meta = fila.querySelector('.meta');
        if (meta) meta.innerHTML += ` · <span style="font-family:var(--fuente-mono);">${escaparHtml(u.numero_legajo)}</span>`;
      }
      cont.appendChild(fila);
    });
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

function crearFilaUsuario(u) {
  const fila = document.createElement('div');
  fila.className = 'tarjeta-paciente';
  const rolEtiqueta = u.rol === 'profesional'
    ? '<span class="etiqueta">Profesional</span>'
    : '<span class="etiqueta particular">Administrativa</span>';
  const fecha = new Date(u.creado_en).toLocaleDateString('es-AR');
  const nombresSedes = (u.sedes || []).map(s => s.nombre).join(', ') || 'Sin sede asignada';

  let licenciaTexto = '';
  if (u.rol === 'profesional') {
    const colorEstado = { activo: 'var(--salvia-oscuro)', suspendido: 'var(--coral)', pausado: 'var(--arena)', prohibido: 'var(--coral)' };
    const estado = u.estado_licencia || 'activo';
    const color = colorEstado[estado] || 'var(--salvia-oscuro)';
    let diasInfo = '';
    if (estado === 'activo' && u.licencia_vencimiento) {
      diasInfo = ` · Vence ${formatearFechaCorta(u.licencia_vencimiento)}`;
    } else if (estado === 'activo' && !u.licencia_dias) {
      diasInfo = ' · Indeterminada';
    }
    licenciaTexto = ` · <span style="color:${color}; font-weight:700; text-transform:capitalize;">${estado}</span>${diasInfo}`;
  }

  fila.innerHTML = `
    <div class="info-principal">
      <div class="avatar-iniciales">${u.nombre_completo.split(' ').map(p => p[0]).slice(0,2).join('').toUpperCase()}</div>
      <div>
        <div class="nombre">${escaparHtml(u.nombre_completo)}${u.especialidad ? ' <span class="etiqueta-rol-login" style="font-size:0.68rem;">' + escaparHtml(u.especialidad) + '</span>' : ''}</div>
        <div class="meta">${rolEtiqueta} · ${escaparHtml(nombresSedes)}${licenciaTexto} · Desde ${fecha}</div>
      </div>
    </div>
    <div class="acciones-tarjeta"></div>
  `;
  if (u.activo && u.id !== undefined) {
    const acciones = fila.querySelector('.acciones-tarjeta');

    if (u.rol === 'profesional') {
      const btnEditarLegajo = document.createElement('button');
      btnEditarLegajo.className = 'btn-icono';
      btnEditarLegajo.title = 'Editar legajo';
      btnEditarLegajo.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
      btnEditarLegajo.addEventListener('click', () => abrirModalEditarLegajoProfesional(u));
      acciones.appendChild(btnEditarLegajo);

      const btnLicencia = document.createElement('button');
      btnLicencia.className = 'btn-icono';
      btnLicencia.title = 'Gestionar licencia';
      btnLicencia.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`;
      btnLicencia.addEventListener('click', () => abrirModalGestionarLicencia(u));
      acciones.appendChild(btnLicencia);
    }

    const btnCambiarPin = document.createElement('button');
    btnCambiarPin.className = 'btn-icono';
    btnCambiarPin.title = 'Cambiar PIN';
    btnCambiarPin.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h.01M10 12h.01M14 12h.01"/></svg>`;
    btnCambiarPin.addEventListener('click', () => abrirModalCambiarPin(u));
    acciones.appendChild(btnCambiarPin);

    const btnGestionarSedes = document.createElement('button');
    btnGestionarSedes.className = 'btn-icono';
    btnGestionarSedes.title = 'Gestionar sedes';
    btnGestionarSedes.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1m4 0h1m-5 4h1m4 0h1m-5 4h1m4 0h1"/></svg>`;
    btnGestionarSedes.addEventListener('click', () => abrirModalGestionarSedesUsuario(u));
    acciones.appendChild(btnGestionarSedes);

    const btnDesactivar = document.createElement('button');
    btnDesactivar.className = 'btn-icono peligro';
    btnDesactivar.title = 'Quitar acceso';
    btnDesactivar.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M4.93 4.93l14.14 14.14"/></svg>`;
    btnDesactivar.addEventListener('click', async () => {
      if (!confirm(`¿Quitar el acceso de ${u.nombre_completo}? Va a dejar de poder entrar al sistema.`)) return;
      try {
        await llamarApi(`${API.auth}`, {
          method: 'POST',
          body: JSON.stringify({ accion: 'desactivar_usuario', id: u.id }),
        });
        mostrarToast('Acceso desactivado.', 'exito');
        cargarListaUsuarios();
      } catch (e) {
        mostrarToast(e.message, 'error');
      }
    });
    acciones.appendChild(btnDesactivar);
  } else if (!u.activo && u.id !== undefined) {
    const acciones = fila.querySelector('.acciones-tarjeta');
    const etiquetaDesactivado = document.createElement('span');
    etiquetaDesactivado.className = 'etiqueta';
    etiquetaDesactivado.style.background = 'var(--coral-claro)';
    etiquetaDesactivado.style.color = 'var(--coral)';
    etiquetaDesactivado.textContent = 'Sin acceso';
    acciones.appendChild(etiquetaDesactivado);

    const btnRestaurar = document.createElement('button');
    btnRestaurar.className = 'btn btn-secundario btn-chico';
    btnRestaurar.textContent = 'Restaurar acceso';
    btnRestaurar.addEventListener('click', async () => {
      if (!confirm(`¿Restaurar el acceso de ${u.nombre_completo}? Va a poder volver a entrar con su PIN.`)) return;
      try {
        await llamarApi(`${API.auth}`, {
          method: 'POST',
          body: JSON.stringify({ accion: 'reactivar_usuario', id: u.id }),
        });
        mostrarToast('Acceso restaurado.', 'exito');
        cargarListaUsuarios();
      } catch (e) {
        mostrarToast(e.message, 'error');
      }
    });
    acciones.appendChild(btnRestaurar);
  }
  return fila;
}

async function abrirModalNuevoUsuario() {
  // Preguntamos qué tipo de persona se quiere agregar,
  // para abrir el formulario correcto.
  const tipo = await new Promise(resolve => {
    const div = document.createElement('div');
    div.className = 'overlay-modal';
    div.innerHTML = `
      <div class="modal" style="max-width: 400px;">
        <h3>¿Qué tipo de persona querés agregar?</h3>
        <div style="display:flex; flex-direction:column; gap:12px; margin-top:20px;">
          <button class="btn btn-primario" id="elegir-profesional">Profesional (médico, licenciado, etc.)</button>
          <button class="btn btn-secundario" id="elegir-administrativa">Administrativa (agenda y contacto)</button>
        </div>
        <div class="acciones-modal" style="margin-top:18px;">
          <button class="btn btn-secundario" id="elegir-cancelar">Cancelar</button>
        </div>
      </div>`;
    document.body.appendChild(div);
    div.querySelector('#elegir-profesional').onclick = () => { div.remove(); resolve('profesional'); };
    div.querySelector('#elegir-administrativa').onclick = () => { div.remove(); resolve('administrativa'); };
    div.querySelector('#elegir-cancelar').onclick = () => { div.remove(); resolve(null); };
  });

  if (!tipo) return;
  if (tipo === 'profesional') abrirModalNuevoProfesional();
  else abrirModalNuevaAdministrativa();
}

async function cargarSedesEnCheckboxes(contenedorId, claseCheckbox) {
  const cont = document.getElementById(contenedorId);
  if (!cont) return;
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_sedes`, { method: 'POST', body: JSON.stringify({ accion: 'listar_sedes' }) });
    const sedesActivas = res.datos.filter(s => s.activa);
    cont.innerHTML = '';
    if (!sedesActivas.length) {
      cont.innerHTML = '<p class="texto-vacio-login">Primero creá al menos una sede.</p>';
    } else {
      sedesActivas.forEach(s => {
        const label = document.createElement('label');
        label.className = 'checkbox-sede-item';
        label.innerHTML = `<input type="checkbox" value="${s.id}" class="${claseCheckbox}"> ${escaparHtml(s.nombre)}`;
        cont.appendChild(label);
      });
    }
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

async function abrirModalNuevoProfesional() {
  const modalEnv = clonarPlantilla('tpl-modal-nuevo-profesional');
  document.body.appendChild(modalEnv);
  await cargarSedesEnCheckboxes('lista-checkboxes-sedes-prof', 'checkbox-sede-prof');

  const inputPin = document.getElementById('input-pin-prof');
  inputPin.addEventListener('input', () => { inputPin.value = inputPin.value.replace(/\D/g, '').slice(0, 4); });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-nuevo-profesional').addEventListener('click', cerrar);

  document.getElementById('btn-confirmar-nuevo-profesional').addEventListener('click', async () => {
    const titulo = document.getElementById('input-titulo-prof').value;
    const nombre = document.getElementById('input-nombre-prof').value.trim();
    const apellido = document.getElementById('input-apellido-prof').value.trim();
    const dni = document.getElementById('input-dni-prof').value.trim();
    const fechaNac = document.getElementById('input-fechanac-prof').value;
    const lugarNac = document.getElementById('input-lugarnac-prof').value.trim();
    const especialidad = document.getElementById('input-especialidad-prof').value.trim();
    const email = document.getElementById('input-email-prof').value.trim();
    const tel = document.getElementById('input-tel-prof').value.trim();
    const pin = inputPin.value.trim();
    const licenciaDias = document.getElementById('input-licencia-dias-prof').value;
    const sedeIds = Array.from(document.querySelectorAll('.checkbox-sede-prof:checked')).map(c => parseInt(c.value, 10));

    if (!nombre || !apellido) { mostrarToast('Ingresá el nombre y apellido del profesional.', 'error'); return; }
    if (!/^\d{4}$/.test(pin)) { mostrarToast('El PIN tiene que tener exactamente 4 números.', 'error'); return; }
    if (!sedeIds.length) { mostrarToast('Elegí al menos una sede.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-nuevo-profesional');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creando...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({
          accion: 'crear_usuario', rol: 'profesional', titulo, nombre, apellido,
          dni, fecha_nacimiento: fechaNac, lugar_nacimiento: lugarNac, especialidad,
          email, telefono: tel, pin, sede_ids: sedeIds, licencia_dias: licenciaDias,
        }),
      });
      mostrarToast('Legajo creado correctamente.', 'exito');
      cerrar();
      cargarListaUsuarios();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Crear legajo';
    }
  });
}

async function abrirModalNuevaAdministrativa() {
  const modalEnv = clonarPlantilla('tpl-modal-nueva-administrativa');
  document.body.appendChild(modalEnv);
  await cargarSedesEnCheckboxes('lista-checkboxes-sedes-admin', 'checkbox-sede-admin');

  const inputPin = document.getElementById('input-pin-nueva-admin');
  inputPin.addEventListener('input', () => { inputPin.value = inputPin.value.replace(/\D/g, '').slice(0, 4); });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-nueva-admin').addEventListener('click', cerrar);

  document.getElementById('btn-confirmar-nueva-admin').addEventListener('click', async () => {
    const nombre = document.getElementById('input-nombre-nueva-admin').value.trim();
    const pin = inputPin.value.trim();
    const sedeIds = Array.from(document.querySelectorAll('.checkbox-sede-admin:checked')).map(c => parseInt(c.value, 10));

    if (!nombre) { mostrarToast('Ingresá el nombre de la administrativa.', 'error'); return; }
    if (!/^\d{4}$/.test(pin)) { mostrarToast('El PIN tiene que tener exactamente 4 números.', 'error'); return; }
    if (!sedeIds.length) { mostrarToast('Elegí al menos una sede.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-nueva-admin');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creando...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({ accion: 'crear_usuario', rol: 'administrativa', nombre_completo: nombre, pin, sede_ids: sedeIds }),
      });
      mostrarToast('Acceso creado correctamente.', 'exito');
      cerrar();
      cargarListaUsuarios();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Crear acceso';
    }
  });
}

async function abrirModalEditarLegajoProfesional(usuario) {
  const modalEnv = clonarPlantilla('tpl-modal-editar-legajo-profesional');
  document.body.appendChild(modalEnv);

  const campos = ['titulo', 'nombre', 'apellido', 'dni', 'fechanac', 'lugarnac', 'especialidad', 'email', 'tel'];
  campos.forEach(c => { document.getElementById(`input-${c}-editar-prof`).disabled = true; });

  try {
    const res = await llamarApi(`${API.auth}?accion=obtener_legajo_profesional`, {
      method: 'POST',
      body: JSON.stringify({ accion: 'obtener_legajo_profesional', usuario_id: usuario.id }),
    });
    const l = res.datos;
    document.getElementById('input-titulo-editar-prof').value = l.titulo || 'Dr.';
    document.getElementById('input-nombre-editar-prof').value = l.nombre || '';
    document.getElementById('input-apellido-editar-prof').value = l.apellido || '';
    document.getElementById('input-dni-editar-prof').value = l.dni || '';
    document.getElementById('input-fechanac-editar-prof').value = l.fecha_nacimiento || '';
    document.getElementById('input-lugarnac-editar-prof').value = l.lugar_nacimiento || '';
    document.getElementById('input-especialidad-editar-prof').value = l.especialidad || '';
    document.getElementById('input-email-editar-prof').value = l.email || '';
    document.getElementById('input-tel-editar-prof').value = l.telefono || '';
    campos.forEach(c => { document.getElementById(`input-${c}-editar-prof`).disabled = false; });
  } catch (e) {
    mostrarToast(e.message, 'error');
    modalEnv.remove();
    return;
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-editar-profesional').addEventListener('click', cerrar);

  document.getElementById('btn-confirmar-editar-profesional').addEventListener('click', async () => {
    const datos = {
      accion: 'editar_legajo_profesional',
      usuario_id: usuario.id,
      titulo: document.getElementById('input-titulo-editar-prof').value,
      nombre: document.getElementById('input-nombre-editar-prof').value.trim(),
      apellido: document.getElementById('input-apellido-editar-prof').value.trim(),
      dni: document.getElementById('input-dni-editar-prof').value.trim(),
      fecha_nacimiento: document.getElementById('input-fechanac-editar-prof').value,
      lugar_nacimiento: document.getElementById('input-lugarnac-editar-prof').value.trim(),
      especialidad: document.getElementById('input-especialidad-editar-prof').value.trim(),
      email: document.getElementById('input-email-editar-prof').value.trim(),
      telefono: document.getElementById('input-tel-editar-prof').value.trim(),
    };
    if (!datos.nombre || !datos.apellido) { mostrarToast('Ingresá el nombre y apellido del profesional.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-editar-profesional');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      await llamarApi(API.auth, { method: 'POST', body: JSON.stringify(datos) });
      mostrarToast('Legajo actualizado correctamente.', 'exito');
      cerrar();
      cargarListaUsuarios();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Guardar cambios';
    }
  });
}

function abrirModalCambiarPin(usuario) {
  const modalEnv = clonarPlantilla('tpl-modal-cambiar-pin');
  document.body.appendChild(modalEnv);
  document.getElementById('texto-usuario-cambiar-pin').textContent =
    `Vas a definir un PIN nuevo para ${usuario.nombre_completo}. El anterior deja de funcionar.`;

  const inputPin = document.getElementById('input-nuevo-pin');
  inputPin.addEventListener('input', () => { inputPin.value = inputPin.value.replace(/\D/g, '').slice(0, 4); });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-cambiar-pin').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-cambiar-pin').addEventListener('click', async () => {
    const pin = inputPin.value.trim();
    if (!/^\d{4}$/.test(pin)) { mostrarToast('El PIN tiene que tener exactamente 4 números.', 'error'); return; }

    const btn = document.getElementById('btn-confirmar-cambiar-pin');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({ accion: 'cambiar_pin_usuario', usuario_id: usuario.id, pin }),
      });
      mostrarToast('PIN actualizado correctamente.', 'exito');
      cerrar();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Guardar PIN';
    }
  });
}

function abrirModalGestionarLicencia(usuario) {
  const modalEnv = clonarPlantilla('tpl-modal-licencia');
  document.body.appendChild(modalEnv);

  document.getElementById('texto-prof-licencia').textContent =
    `${usuario.nombre_completo} — estado actual: ${usuario.estado_licencia || 'activo'}.`;

  const selectEstado = document.getElementById('select-estado-licencia');
  const selectDias = document.getElementById('select-dias-licencia');
  const campoDias = document.getElementById('campo-dias-licencia');

  selectEstado.value = usuario.estado_licencia || 'activo';
  if (usuario.licencia_dias) selectDias.value = String(usuario.licencia_dias);

  selectEstado.addEventListener('change', () => {
    campoDias.style.opacity = selectEstado.value === 'activo' ? '1' : '0.4';
    campoDias.style.pointerEvents = selectEstado.value === 'activo' ? 'auto' : 'none';
  });
  if (selectEstado.value !== 'activo') {
    campoDias.style.opacity = '0.4';
    campoDias.style.pointerEvents = 'none';
  }

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-licencia').addEventListener('click', cerrar);
  document.getElementById('btn-confirmar-licencia').addEventListener('click', async () => {
    const estado = selectEstado.value;
    const dias = selectDias.value;
    const btn = document.getElementById('btn-confirmar-licencia');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      await llamarApi(API.auth, {
        method: 'POST',
        body: JSON.stringify({ accion: 'actualizar_licencia', usuario_id: usuario.id, estado, dias }),
      });
      mostrarToast('Licencia actualizada.', 'exito');
      cerrar();
      cargarListaUsuarios();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btn.disabled = false;
      btn.textContent = 'Guardar';
    }
  });
}

async function montarVistaMiLegajo(contenido) {
  contenido.appendChild(clonarPlantilla('tpl-mi-legajo'));
  const cont = document.getElementById('contenido-mi-legajo');
  try {
    const res = await llamarApi(`${API.auth}?accion=obtener_legajo_profesional`, {
      method: 'POST', body: JSON.stringify({ accion: 'obtener_legajo_profesional' }),
    });
    const l = res.datos;
    const venc = l.licencia_vencimiento
      ? `Vence el ${formatearFechaCorta(l.licencia_vencimiento)}`
      : 'Sin vencimiento (indeterminada)';
    const estadoColor = { activo: 'var(--salvia-oscuro)', suspendido: 'var(--coral)', pausado: 'var(--arena)', prohibido: 'var(--coral)' };
    cont.innerHTML = `
      <div class="panel">
        <div class="encabezado-panel-accion" style="margin-bottom:24px;">
          <div>
            <div class="panel-titulo">${escaparHtml(l.titulo + ' ' + l.nombre + ' ' + l.apellido)}</div>
            ${l.numero_legajo ? `<p class="panel-subtitulo" style="font-family:var(--fuente-mono); font-size:0.85rem; color:var(--tinta-suave); margin-top:2px;">Legajo ${escaparHtml(l.numero_legajo)}</p>` : ''}
            ${l.especialidad ? `<p class="panel-subtitulo">${escaparHtml(l.especialidad)}</p>` : ''}
          </div>
          <span style="background:${l.estado_licencia === 'activo' ? 'var(--salvia-claro)' : l.estado_licencia === 'pausado' ? '#F5EBD2' : 'var(--coral-claro)'}; color:${estadoColor[l.estado_licencia] || 'var(--salvia-oscuro)'}; text-transform:capitalize; padding:6px 16px; border-radius:999px; font-size:0.8rem; font-weight:700; white-space:nowrap;">${l.estado_licencia || 'activo'}</span>
        </div>
        <div class="grilla-datos">
          <div class="dato-box"><div class="etiqueta-dato">DNI</div><div class="valor-dato">${escaparHtml(l.dni || '—')}</div></div>
          <div class="dato-box"><div class="etiqueta-dato">Fecha de nacimiento</div><div class="valor-dato">${l.fecha_nacimiento ? formatearFechaCorta(l.fecha_nacimiento) : '—'}</div></div>
          <div class="dato-box"><div class="etiqueta-dato">Lugar de nacimiento</div><div class="valor-dato">${escaparHtml(l.lugar_nacimiento || '—')}</div></div>
          <div class="dato-box" style="grid-column: span 2;"><div class="etiqueta-dato">Correo</div><div class="valor-dato" style="word-break:break-all;">${escaparHtml(l.email || '—')}</div></div>
          <div class="dato-box"><div class="etiqueta-dato">Celular</div><div class="valor-dato">${escaparHtml(l.telefono || '—')}</div></div>
          <div class="dato-box" style="grid-column: span 2;"><div class="etiqueta-dato">Licencia</div><div class="valor-dato">${venc}</div></div>
        </div>
        <p style="font-size:0.82rem; color:var(--tinta-suave); margin-top:8px;">Si necesitás corregir algún dato, comunicate con el administrador del sistema.</p>
      </div>`;
  } catch (e) {
    cont.innerHTML = '<p class="resumen-vacio">No se encontró tu legajo. Comunicate con el administrador del sistema.</p>';
  }
}

async function abrirModalGestionarSedesUsuario(usuario) {
  const modalEnv = clonarPlantilla('tpl-modal-gestionar-sedes-usuario');
  document.body.appendChild(modalEnv);

  document.getElementById('texto-nombre-usuario-gestionar-sedes').textContent =
    `Marcá o destildá en qué sedes atiende ${usuario.nombre_completo}.`;

  const sedeIdsActuales = (usuario.sedes || []).map(s => s.id);
  const contCheckboxes = document.getElementById('lista-checkboxes-sedes-editar');
  const avisoBorrado = document.getElementById('aviso-borrado-sedes');
  const bloqueConfirmar = document.getElementById('bloque-confirmar-borrado-sedes');
  const inputConfirmarTexto = document.getElementById('input-confirmar-borrado-sedes');
  const btnConfirmar = document.getElementById('btn-confirmar-gestionar-sedes');

  contCheckboxes.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const res = await llamarApi(`${API.auth}?accion=listar_sedes`, { method: 'POST', body: JSON.stringify({ accion: 'listar_sedes' }) });
    const sedesActivas = res.datos.filter(s => s.activa);
    contCheckboxes.innerHTML = '';
    if (!sedesActivas.length) {
      contCheckboxes.innerHTML = '<p class="texto-vacio-login">No hay sedes activas.</p>';
    } else {
      sedesActivas.forEach(s => {
        const label = document.createElement('label');
        label.className = 'checkbox-sede-item';
        const marcado = sedeIdsActuales.includes(s.id) ? 'checked' : '';
        label.innerHTML = `<input type="checkbox" value="${s.id}" class="checkbox-sede-editar" ${marcado}> ${escaparHtml(s.nombre)}`;
        contCheckboxes.appendChild(label);
      });
    }
  } catch (e) {
    contCheckboxes.innerHTML = '';
    mostrarToast(e.message, 'error');
  }

  let totalPacientesABorrar = 0;

  async function revisarImpacto() {
    const sedeIdsElegidas = Array.from(document.querySelectorAll('.checkbox-sede-editar:checked')).map(c => parseInt(c.value, 10));
    try {
      const res = await llamarApi(`${API.auth}?accion=previsualizar_cambio_sedes`, {
        method: 'POST',
        body: JSON.stringify({ accion: 'previsualizar_cambio_sedes', usuario_id: usuario.id, sede_ids: sedeIdsElegidas }),
      });
      totalPacientesABorrar = res.total_pacientes_a_borrar;
      if (totalPacientesABorrar > 0) {
        const detalle = res.sedes_que_se_quitan.map(s => `${s.pacientes} legajo(s) en "${s.nombre}"`).join(', ');
        avisoBorrado.textContent = `⚠ Si guardás este cambio, se van a eliminar definitivamente ${totalPacientesABorrar} legajo(s): ${detalle}. Esta acción no se puede deshacer.`;
        avisoBorrado.classList.remove('oculto');
        bloqueConfirmar.classList.remove('oculto');
        btnConfirmar.disabled = true;
        btnConfirmar.textContent = 'Guardar cambios';
      } else {
        avisoBorrado.classList.add('oculto');
        bloqueConfirmar.classList.add('oculto');
        inputConfirmarTexto.value = '';
        btnConfirmar.disabled = false;
        btnConfirmar.textContent = 'Guardar cambios';
      }
    } catch (e) {
      mostrarToast(e.message, 'error');
    }
  }

  contCheckboxes.addEventListener('change', revisarImpacto);
  inputConfirmarTexto.addEventListener('input', () => {
    if (totalPacientesABorrar > 0) {
      btnConfirmar.disabled = inputConfirmarTexto.value.trim().toUpperCase() !== 'ELIMINAR';
    }
  });

  function cerrar() { modalEnv.remove(); }
  document.getElementById('btn-cancelar-gestionar-sedes').addEventListener('click', cerrar);

  btnConfirmar.addEventListener('click', async () => {
    const sedeIdsElegidas = Array.from(document.querySelectorAll('.checkbox-sede-editar:checked')).map(c => parseInt(c.value, 10));

    if (!sedeIdsElegidas.length) { mostrarToast('Elegí al menos una sede.', 'error'); return; }
    if (totalPacientesABorrar > 0 && inputConfirmarTexto.value.trim().toUpperCase() !== 'ELIMINAR') {
      mostrarToast('Escribí ELIMINAR para confirmar el borrado de legajos.', 'error');
      return;
    }

    btnConfirmar.disabled = true;
    btnConfirmar.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
      const res = await llamarApi(`${API.auth}`, {
        method: 'POST',
        body: JSON.stringify({ accion: 'actualizar_sedes_usuario', usuario_id: usuario.id, sede_ids: sedeIdsElegidas }),
      });
      mostrarToast(
        res.pacientes_borrados > 0
          ? `Sedes actualizadas. Se eliminaron ${res.pacientes_borrados} legajo(s).`
          : 'Sedes actualizadas correctamente.',
        'exito'
      );
      cerrar();
      cargarListaUsuarios();
    } catch (e) {
      mostrarToast(e.message, 'error');
      btnConfirmar.disabled = false;
      btnConfirmar.textContent = 'Guardar cambios';
    }
  });
}

async function cargarHistorialCambios(pagina) {
  const cont = document.getElementById('lista-historial');
  const selectFiltro = document.getElementById('select-filtro-historial');

  // El filtro por tipo de entidad solo tiene sentido para el
  // Desarrollador (es el único que ve TODO el historial mezclado).
  if (selectFiltro) {
    const filaFiltro = selectFiltro.closest('.fila-buscador-usuarios');
    if (ROL_ACTUAL !== 'desarrollador') {
      if (filaFiltro) filaFiltro.style.display = 'none';
    } else if (filaFiltro) {
      filaFiltro.style.display = 'flex';
      if (!selectFiltro.dataset.conectado) {
        selectFiltro.dataset.conectado = '1';
        selectFiltro.addEventListener('change', () => cargarHistorialCambios(1));
      }
    }
  }

  const entidad = selectFiltro ? selectFiltro.value : '';
  cont.innerHTML = '<div class="cargando-pagina chico"><span class="spinner"></span></div>';
  try {
    const url = `${API.admin}?accion=historial&pagina=${pagina}${entidad ? '&entidad=' + entidad : ''}`;
    const res = await llamarApi(url, { method: 'GET' });
    cont.innerHTML = '';
    if (!res.datos.length) {
      cont.innerHTML = '<p style="color:var(--tinta-suave); font-size:0.9rem;">Todavía no hay cambios registrados.</p>';
      document.getElementById('paginado-historial').innerHTML = '';
      return;
    }
    const accionesTexto = { crear: 'Creó', editar: 'Editó', eliminar: 'Eliminó', desactivar: 'Desactivó' };
    res.datos.forEach(h => {
      const fila = document.createElement('div');
      fila.className = 'item-historial';
      const fecha = new Date(h.creado_en).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
      fila.innerHTML = `
        <div class="item-historial-fecha">${fecha}</div>
        <div class="item-historial-texto">
          <strong>${escaparHtml(h.usuario_nombre || 'Usuario eliminado')}</strong>
          ${(accionesTexto[h.accion] || h.accion).toLowerCase()}
          ${escaparHtml(h.descripcion || '')}
        </div>
      `;
      cont.appendChild(fila);
    });

    const totalPaginas = Math.max(1, Math.ceil(res.total / 40));
    const paginadoEl = document.getElementById('paginado-historial');
    paginadoEl.innerHTML = '';
    if (totalPaginas > 1) {
      for (let i = 1; i <= totalPaginas; i++) {
        const btnPagina = document.createElement('button');
        btnPagina.className = 'btn-pagina' + (i === pagina ? ' activo' : '');
        btnPagina.textContent = i;
        btnPagina.addEventListener('click', () => cargarHistorialCambios(i));
        paginadoEl.appendChild(btnPagina);
      }
    }
  } catch (e) {
    cont.innerHTML = '';
    mostrarToast(e.message, 'error');
  }
}

# Del Austral

[![License: PolyForm Noncommercial 1.0.0](https://img.shields.io/badge/license-PolyForm%20Noncommercial%201.0.0-blue.svg)](./LICENSE)

**Historial clínico digital multi-sede para consultorios de salud.** Sistema web autoalojado (PHP + MySQL) pensado para profesionales de la salud —médicos, fonoaudiólogos, nutricionistas, psicólogos y afines— que necesitan reemplazar las fichas de papel sin depender de un SaaS de terceros ni pagar licencias mensuales. Soporta varias sedes y varios profesionales con aislamiento total de datos entre ellos.

Corre tanto en hosting compartido tipo cPanel (sin SSH, Composer ni extensiones especiales) como en un VPS propio con Nginx + PHP-FPM + MariaDB.

---

## Índice

- [Características](#características)
- [Stack técnico](#stack-técnico)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Instalación](#instalación)
- [Sedes, profesionales y roles](#sedes-profesionales-y-roles)
- [Modelo de datos](#modelo-de-datos)
- [Seguridad](#seguridad)
- [Hoja de ruta](#hoja-de-ruta)
- [Licencia](#licencia)

---

## Características

- 🔐 **Acceso por PIN numérico** (4 dígitos) en vez de usuario/contraseña tradicional.
- 🏥 **Multi-sede**: un consultorio puede tener varias sucursales, renombrables en cualquier momento sin perder ni mover ningún legajo existente.
- 👥 **Multi-profesional con aislamiento total**: cada profesional ve únicamente sus propios pacientes, incluso compartiendo sede con otros. El filtrado se aplica en el servidor, no solo en la interfaz.
- 🛠️ **Rol Desarrollador**: nivel de acceso separado y discreto (sin mención en la pantalla de login pública), dedicado a crear/renombrar sedes y gestionar profesionales y administrativas.
- 🪪 **Legajos completos de profesionales**: título, nombre, DNI, fecha y lugar de nacimiento, especialidad, contacto, y un número de legajo automático con formato `LG-2026-001`.
- ⏳ **Sistema de licencias por profesional**: activa por 7/15/30/60/90/120 días o indeterminada, pausada o prohibida. Si vence, el acceso se bloquea solo, con un mensaje claro al intentar entrar.
- 📅 **Calendario de vencimientos de licencia** y aviso anticipado de los que vencen en la próxima semana, para el Desarrollador.
- 🧑‍💼 **Rol Administrativa**: gestiona agenda y contacto de los pacientes de un profesional elegido al iniciar sesión, sin acceso a contenido clínico.
- 📋 **Legajos de pacientes** completos: datos personales, obra social, motivo de consulta, patología, síntomas, observaciones.
- 🗓️ **Sesiones clínicas** editables y eliminables, con historial cronológico, y plantillas de evolución propias de cada profesional.
- 📅 **Agenda con calendario mensual** y detección de choque de horario.
- ✅ **Confirmación de turno por el paciente**: link público único (sin login) para confirmar o cancelar.
- 💬 **Recordatorio de turnos por WhatsApp**, con el link de confirmación incluido.
- 📎 **Archivos adjuntos** por paciente (PDF e imágenes, hasta 15 MB), con validación real de tipo MIME.
- ✍️ **Firma digital del profesional**: dibujada con mouse/dedo o subida como imagen, se inserta automáticamente al pie de cada legajo exportado a PDF.
- 📄 **Exportación de legajo a PDF** vía vista de impresión del navegador.
- ⚠️ **Detección de pacientes en riesgo de abandono**, comparando cuánto pasó desde la última sesión contra el ritmo histórico propio de cada paciente (no una regla fija igual para todos).
- 🎂 Resumen de próximos cumpleaños y pacientes sin sesiones recientes.
- 📊 **Dashboard de estadísticas** por profesional: pacientes activos, sesiones por mes, distribución por obra social, citas por estado.
- 🧾 **Historial de cambios (auditoría)**: acotado a los propios pacientes de cada profesional; el Desarrollador ve el historial completo de todos, con filtro por tipo de entidad.
- 🗑️ **Papelera** y **legajos huérfanos**: recuperar legajos eliminados, o transferir pacientes activos de un profesional desactivado, asignándolos a otro de la misma sede.
- ⚖️ Aviso de protección de datos personales conforme a la Ley 25.326 (Argentina).
- 📱 **App instalable (PWA)**, sin pasar por ninguna tienda de aplicaciones.
- 🔍 **Verificación de versión**: compara los archivos del servidor contra la última actualización entregada.
- 📊 **Reportes por sede**, y **exportación masiva** de todos los legajos propios a un único archivo de respaldo.

## Stack técnico

| Capa | Tecnología |
|---|---|
| Backend | PHP 7.4+ puro (sin frameworks, sin Composer) |
| Base de datos | MySQL / MariaDB (InnoDB, utf8mb4) |
| Frontend | HTML, CSS y JavaScript vanilla (sin build step, sin npm) |
| Autenticación | PIN hasheado con `password_hash` (bcrypt) + sesiones nativas de PHP |
| Exportación PDF | Vista HTML de impresión (`window.print()`), sin librerías externas |
| Firma digital | `canvas` nativo del navegador, guardada como PNG en base64 |

No requiere `composer install`, `npm install` ni proceso de build. Se sube por FTP, el Administrador de archivos de cPanel, o se clona por git en un VPS.

## Estructura del proyecto

```
del-austral/
├── index.html                 # SPA: toda la interfaz vive acá (templates + vistas)
├── exportar.php                # Vista de impresión/exportación de legajo a PDF
├── confirmar_turno.php          # Página pública de confirmación de turno (sin login)
├── manifest.json                 # Metadata de la PWA (nombre, iconos, colores)
├── sw.js                          # Service worker mínimo, para habilitar la instalación
├── version.json                   # Hashes de referencia para la verificación de versión
├── database.sql                # Esquema completo (instalación nueva, desde cero)
├── migracion_v2.sql             # Citas, archivos adjuntos, plantillas
├── migracion_v3.sql             # Usuarios con roles, historial de cambios
├── migracion_v4.sql             # Acceso por PIN numérico (en vez de patrón dibujado)
├── migracion_v5.sql             # Sedes, aislamiento por profesional, rol Desarrollador
├── migracion_v6.sql             # Aviso de confirmaciones/cancelaciones de turno
├── migracion_v7.sql             # Profesional/sede original en la papelera
├── migracion_v8.sql             # Aviso de "legajo recuperado de otro profesional"
├── migracion_v9.sql             # DNI único por profesional, no global
├── migracion_v10.sql            # Legajos completos de profesionales + sistema de licencias
├── migracion_v11.sql            # Número de legajo automático (LG-AAAA-NNN)
├── migracion_v12.sql            # Firma digital del profesional
├── config/
│   └── config.php               # Credenciales de BD + helpers de sesión/rol/auditoría
├── api/
│   ├── auth.php                  # Login multi-paso, Desarrollador, sedes, usuarios, legajos
│   ├── pacientes.php              # CRUD de legajos, sesiones, búsqueda, migración de sede
│   ├── citas.php                   # Agenda, choque de horario, cumpleaños, inactivos
│   ├── adjuntos.php                 # Subida/descarga de archivos por paciente
│   ├── plantillas.php                # CRUD de plantillas de evolución (por profesional)
│   ├── obras_sociales.php             # Catálogo de obras sociales
│   └── admin.php                       # Estadísticas, historial, versión, riesgo de abandono, calendario de licencias
├── assets/
│   ├── css/estilos.css           # Toda la hoja de estilos
│   ├── js/app.js                  # Toda la lógica de frontend
│   └── icons/                      # Íconos de la PWA en distintos tamaños
└── adjuntos/                   # Carpeta de archivos subidos (protegida con .htaccess)
```

## Instalación

Guía completa paso a paso (cPanel y VPS, configuración de `config.php`, migración desde versiones anteriores) en **[`INSTRUCCIONES_INSTALACION.md`](./INSTRUCCIONES_INSTALACION.md)**.

Resumen rápido para una instalación nueva:

1. Creá una base de datos MySQL/MariaDB e importá `database.sql`.
2. Completá `config/config.php` con tus credenciales y un `APP_SECRET` propio.
3. Subí todo el proyecto a tu hosting o VPS, incluyendo la carpeta `adjuntos/`.
4. Abrí el sitio: la primera vez te va a pedir crear la clave de Desarrollador, y luego tu primera sede y profesional.

Si venís de una versión anterior con pacientes ya cargados, corré las migraciones en orden (`migracion_v2.sql` hasta `migracion_v12.sql`), sin saltarte ninguna. Ningún script borra pacientes, sesiones, citas ni adjuntos.

## Sedes, profesionales y roles

| | Desarrollador | Profesional | Administrativa |
|---|---|---|---|
| Crear/renombrar/desactivar sedes y usuarios | ✅ | ❌ | ❌ |
| Gestionar licencias de acceso | ✅ | ❌ | ❌ |
| Ver pacientes y agenda | ❌ | ✅ (propios) | ✅ (de un profesional elegido) |
| Crear paciente (datos de contacto) | ❌ | ✅ | ✅ |
| Motivo, patología, síntomas, sesiones | ❌ | ✅ | ❌ |
| Editar / eliminar legajo o sesión | ❌ | ✅ | ❌ |
| Exportar PDF, estadísticas, historial | ❌ | ✅ | ❌ |

- El **Desarrollador** entra por un acceso discreto que no se menciona en la pantalla de login pública (un botón pequeño que dice "Mantenimiento"), con su propia clave separada. No ve pacientes; gestiona sedes, profesionales, administrativas y licencias.
- Cada **profesional** ve exclusivamente los pacientes que le pertenecen (`pacientes.profesional_id`), incluso si comparte sede con otros profesionales. Su acceso puede estar activo (por tiempo determinado o indeterminado), pausado, prohibido o suspendido automáticamente al vencer la licencia.
- La **administrativa**, al iniciar sesión, además de elegir sede y PIN debe indicar a qué profesional representa — eso determina qué pacientes ve, sin contenido clínico.

El filtrado por rol y por dueño de cada paciente se aplica en cada endpoint de `api/`, no solo ocultando elementos del HTML.

## Modelo de datos

Tablas principales (ver `database.sql` para el esquema completo con índices y claves foráneas):

- `sedes` — sucursales del consultorio
- `desarrollador` — clave única del rol Desarrollador
- `usuarios` — accesos al sistema (PIN hasheado, rol, estado activo/inactivo, estado y duración de licencia)
- `usuarios_sedes` — relación N a N entre usuarios y las sedes donde atienden
- `profesionales_legajos` — datos personales completos de cada profesional, número de legajo y firma digital
- `pacientes` — legajo principal, con `profesional_id` (dueño), `sede_id` y rastro de procedencia si fue recuperado de otro profesional
- `sesiones` — historial clínico cronológico por paciente, editable
- `citas` — agenda, con `profesional_id`, token de confirmación pública y detección de choque de horario
- `archivos_adjuntos` — metadata de archivos subidos (PDF/imágenes)
- `plantillas_evolucion` — textos reutilizables por profesional (no compartidos)
- `obras_sociales` — catálogo editable de coberturas de salud, compartido
- `legajos_eliminados` — papelera, con el profesional y la sede original para poder recuperarlos
- `historial_cambios` — auditoría de acciones (quién, qué, cuándo)

## Seguridad

- Los PIN y la clave de Desarrollador se almacenan con `password_hash()` (bcrypt) combinados con un secreto de aplicación (`APP_SECRET`); nunca en texto plano.
- Protección básica contra fuerza bruta: bloqueo temporal tras 5 intentos fallidos.
- Todos los endpoints en `api/` requieren sesión autenticada; los que exponen datos clínicos exigen además rol *profesional*, y siempre filtran por el profesional dueño de cada registro.
- El link público de confirmación de turno usa un token aleatorio — no expone contenido clínico, solo fecha/hora/motivo de la cita puntual.
- La carpeta `adjuntos/` incluye un `.htaccess` que impide la ejecución de scripts.
- Validación de tipo MIME real (no solo por extensión) al subir archivos y al guardar la firma digital.
- El acceso de Desarrollador no se anuncia en la pantalla de login pública.
- Se recomienda servir el sitio bajo HTTPS dado que se transmiten datos de salud.

> Este proyecto no ha pasado por una auditoría de seguridad profesional ni un pentest formal. Es razonablemente seguro para el caso de uso de un consultorio pequeño o mediano, pero quien lo despliegue es responsable de evaluar si cumple los requisitos normativos de su jurisdicción (en Argentina, Ley 25.326 de Protección de Datos Personales) antes de usarlo con datos reales de pacientes.

## Hoja de ruta

Ideas pendientes, sin compromiso de implementación:

- [ ] Recuperación de PIN olvidado sin pasar por phpMyAdmin (hoy existe el cambio de PIN por el Desarrollador, pero no un flujo de autorrecuperación)
- [ ] Buscador único en el panel principal del profesional (DNI/nombre → resultado directo, sin entrar primero a "Acceder a legajos")
- [ ] Recordatorio de turno disparado automáticamente por cron (hoy el mensaje de WhatsApp se manda a mano)
- [ ] Notificaciones push reales (que avisen aunque la app no esté abierta), más allá del aviso dentro del sistema
- [ ] Notas privadas del profesional, separadas de la historia clínica formal
- [ ] Importación de pacientes desde Excel/CSV
- [ ] Modo offline básico para la app instalada (consulta de solo lectura sin conexión)
- [ ] Exportación de estadísticas a Excel/CSV
- [ ] Internacionalización (hoy todos los textos están en español rioplatense)

## Licencia

[PolyForm Noncommercial 1.0.0](./LICENSE) — código fuente disponible para cualquier uso **no comercial**: estudio personal, proyectos propios sin fines de lucro, instituciones educativas, organizaciones de salud pública, ONGs y organismos de gobierno. Para uso comercial (ofrecerlo como servicio, instalarlo a terceros a cambio de un pago, integrarlo en un producto comercial) se necesita una licencia aparte — abrí un issue o contactá a quien mantiene este repositorio.

---

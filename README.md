# Del Austral

[![License: PolyForm Noncommercial 1.0.0](https://img.shields.io/badge/license-PolyForm%20Noncommercial%201.0.0-blue.svg)](./LICENSE)

**Historial clínico digital multi-sede para consultorios de salud.** Sistema web autoalojado (PHP + MySQL) pensado para profesionales de la salud —médicos, fonoaudiólogos, nutricionistas, psicólogos y afines— que necesitan reemplazar las fichas de papel sin depender de un SaaS de terceros ni pagar licencias mensuales. Soporta varias sedes y varios profesionales con aislamiento total de datos entre ellos.

Pensado para correr en hosting compartido tipo cPanel, sin necesidad de acceso SSH, Composer ni extensiones especiales del servidor.

---

## Índice

- [Características](#características)
- [Stack técnico](#stack-técnico)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Instalación](#instalación)
- [Sedes y roles de usuario](#sedes-y-roles-de-usuario)
- [Modelo de datos](#modelo-de-datos)
- [Seguridad](#seguridad)
- [Hoja de ruta](#hoja-de-ruta)
- [Licencia](#licencia)

---

## Características

- 🔐 **Acceso por PIN numérico** (4 dígitos) en vez de usuario/contraseña tradicional.
- 🏥 **Multi-sede**: un consultorio puede tener varias sucursales; cada paciente pertenece a una sede y se puede migrar a otra.
- 👥 **Multi-profesional con aislamiento total**: cada profesional ve únicamente sus propios pacientes, incluso compartiendo sede con otros médicos. El filtrado se aplica en el servidor, no solo en la interfaz.
- 🛠️ **Rol Desarrollador**: nivel de acceso separado, sin visibilidad de pacientes, dedicado exclusivamente a crear sedes y dar de alta/baja profesionales y administrativas.
- 🧑‍💼 **Rol Administrativa**: gestiona agenda y contacto de los pacientes de un profesional elegido al iniciar sesión, sin acceso a contenido clínico.
- 📋 **Legajos de pacientes** completos: datos personales, obra social (catálogo editable), motivo de consulta, patología, síntomas, observaciones.
- 🗓️ **Sesiones clínicas** editables y eliminables, con historial cronológico, y **plantillas de evolución** propias de cada profesional.
- 📅 **Agenda con calendario mensual** y **detección de choque de horario** (no se puede agendar dos turnos a la misma fecha/hora para el mismo profesional, sin importar la sede).
- ✅ **Confirmación de turno por el paciente**: link público único (sin login) donde el paciente confirma o cancela su cita.
- 💬 **Recordatorio de turnos por WhatsApp**, con el link de confirmación incluido en el mensaje.
- 📎 **Archivos adjuntos** por paciente (PDF e imágenes, hasta 15 MB), servidos desde una carpeta protegida.
- 📄 **Exportación de legajo a PDF** vía vista de impresión del navegador.
- 🎂 Resumen de **próximos cumpleaños** de pacientes.
- 📊 **Dashboard de estadísticas** por profesional: pacientes activos, sesiones por mes, distribución por obra social, citas por estado.
- 🧾 **Historial de cambios (auditoría)**: quién creó, editó o eliminó cada legajo, y cuándo — acotado a los propios pacientes de cada profesional.
- 🗑️ **Papelera / base histórica**: los legajos eliminados quedan archivados como JSON y son consultables.
- ⚖️ Aviso de protección de datos personales conforme a la **Ley 25.326** (Argentina).
- 📱 **App instalable (PWA)**: se puede instalar en celular o computadora con su propio ícono, sin pasar por una tienda de aplicaciones.
- 🔍 **Verificación de versión**: el Desarrollador puede comparar los archivos del servidor contra la última actualización entregada, para detectar archivos desactualizados tras una subida incompleta.
- 📊 **Reportes por sede**: el Desarrollador ve, por cada sede activa, cuántos profesionales/administrativas atienden ahí, pacientes totales y actividad del mes.
- 💾 **Exportación masiva**: cada profesional puede descargar un backup propio en JSON con todos sus pacientes, sesiones, citas y metadata de adjuntos.
- ♻️ **Papelera global** (Desarrollador): ver y recuperar legajos eliminados por cualquier profesional, asignándolos a otro profesional de la misma sede — útil cuando un profesional deja la sede y otro continúa con sus pacientes.

## Stack técnico

| Capa | Tecnología |
|---|---|
| Backend | PHP 7.4+ puro (sin frameworks, sin Composer) |
| Base de datos | MySQL / MariaDB (InnoDB, utf8mb4) |
| Frontend | HTML, CSS y JavaScript vanilla (sin build step, sin npm) |
| Autenticación | PIN hasheado con `password_hash` (bcrypt) + sesiones nativas de PHP |
| Exportación PDF | Vista HTML de impresión (`window.print()`), sin librerías externas |

No requiere `composer install`, `npm install` ni proceso de build. Se sube por FTP o el Administrador de archivos de cPanel y funciona.

## Estructura del proyecto

```
del-austral/
├── index.html               # SPA: toda la interfaz vive acá (templates + vistas)
├── exportar.php              # Vista de impresión/exportación de legajo a PDF
├── confirmar_turno.php        # Página pública de confirmación de turno (sin login)
├── manifest.json              # Metadata de la PWA (nombre, iconos, colores)
├── sw.js                       # Service worker mínimo, solo para habilitar la instalación
├── version.json                 # Hashes de referencia para la verificación de versión
├── database.sql              # Esquema completo (instalación nueva, desde cero)
├── migracion_v2.sql           # Agrega: citas, archivos adjuntos, plantillas
├── migracion_v3.sql           # Agrega: usuarios con roles, historial de cambios
├── migracion_v4.sql           # Cambia el acceso de patrón dibujado a PIN numérico
├── migracion_v5.sql           # Agrega: sedes, aislamiento por profesional, rol Desarrollador
├── migracion_v6.sql           # Agrega: aviso de confirmaciones/cancelaciones de turno
├── migracion_v7.sql           # Agrega: profesional/sede original a la papelera (recuperación cruzada)
├── config/
│   └── config.php             # Credenciales de BD + helpers de sesión/rol/auditoría
├── api/
│   ├── auth.php                # Login multi-paso, Desarrollador, sedes, usuarios
│   ├── pacientes.php            # CRUD de legajos, sesiones, búsqueda, migración de sede
│   ├── citas.php                 # Agenda, choque de horario, cumpleaños, inactivos
│   ├── adjuntos.php               # Subida/descarga de archivos por paciente
│   ├── plantillas.php              # CRUD de plantillas de evolución (por profesional)
│   ├── obras_sociales.php           # Catálogo de obras sociales
│   └── admin.php                     # Estadísticas, historial de cambios, verificación de versión
├── assets/
│   ├── css/estilos.css         # Toda la hoja de estilos
│   ├── js/app.js                # Toda la lógica de frontend
│   └── icons/                    # Íconos de la PWA en distintos tamaños
└── adjuntos/                 # Carpeta de archivos subidos (protegida con .htaccess)
```

## Instalación

Guía completa paso a paso (creación de base de datos en cPanel, configuración de `config.php`, subida de archivos, migración desde versiones anteriores) en **[`INSTRUCCIONES_INSTALACION.md`](./INSTRUCCIONES_INSTALACION.md)**.

Resumen rápido para una instalación nueva:

1. Creá una base de datos MySQL en tu hosting e importá `database.sql` desde phpMyAdmin.
2. Completá `config/config.php` con tus credenciales de base de datos y un `APP_SECRET` propio.
3. Subí todo el proyecto a tu hosting (FTP o Administrador de archivos de cPanel), incluyendo la carpeta `adjuntos/`.
4. Abrí el sitio: la primera vez te va a pedir crear la clave de Desarrollador, y luego tu primera sede y profesional.

Si venís de una versión anterior con pacientes ya cargados, corré las migraciones en orden (`migracion_v2.sql` → `migracion_v3.sql` → `migracion_v4.sql` → `migracion_v5.sql`), sin saltarte ninguna. Ningún script borra pacientes, sesiones, citas ni adjuntos.

## Sedes y roles de usuario

| | Desarrollador | Profesional | Administrativa |
|---|---|---|---|
| Crear/desactivar sedes y usuarios | ✅ | ❌ | ❌ |
| Ver pacientes y agenda | ❌ | ✅ (propios) | ✅ (de un profesional elegido) |
| Crear paciente (datos de contacto) | ❌ | ✅ | ✅ |
| Motivo, patología, síntomas, sesiones | ❌ | ✅ | ❌ |
| Editar / eliminar legajo o sesión | ❌ | ✅ | ❌ |
| Exportar PDF, estadísticas, historial | ❌ | ✅ | ❌ |

- El **Desarrollador** entra con una clave separada (no elige sede ni aparece en el login normal) y solo gestiona sedes y altas/bajas de usuarios.
- Cada **profesional** ve exclusivamente los pacientes que le pertenecen (`pacientes.profesional_id`), incluso si comparte sede con otros profesionales.
- La **administrativa**, al iniciar sesión, además de elegir sede y PIN debe indicar a qué profesional representa — eso determina qué pacientes ve (filtrados, sin contenido clínico).

El filtrado por rol y por dueño de cada paciente se aplica en cada endpoint de `api/`, no solo ocultando elementos del HTML — un profesional no puede ver ni manipular los pacientes de otro aunque conozca o adivine su ID.

## Modelo de datos

Tablas principales (ver `database.sql` para el esquema completo con índices y claves foráneas):

- `sedes` — sucursales del consultorio
- `desarrollador` — clave única del rol Desarrollador
- `usuarios` — accesos al sistema (PIN hasheado, rol, estado activo/inactivo)
- `usuarios_sedes` — relación N a N entre usuarios y las sedes donde atienden
- `pacientes` — legajo principal, con `profesional_id` (dueño) y `sede_id`
- `sesiones` — historial clínico cronológico por paciente, editable
- `citas` — agenda, con `profesional_id`, token de confirmación pública y detección de choque de horario
- `archivos_adjuntos` — metadata de archivos subidos (PDF/imágenes)
- `plantillas_evolucion` — textos reutilizables por profesional (no compartidos)
- `obras_sociales` — catálogo editable de coberturas de salud, compartido
- `legajos_eliminados` — papelera / base histórica (JSON del legajo completo al eliminarlo)
- `historial_cambios` — auditoría de acciones (quién, qué, cuándo)

## Seguridad

- Los PIN y la clave de Desarrollador se almacenan con `password_hash()` (bcrypt) combinados con un secreto de aplicación (`APP_SECRET`); nunca en texto plano.
- Protección básica contra fuerza bruta: bloqueo temporal tras 5 intentos fallidos, tanto para el login normal como para el de Desarrollador.
- Todos los endpoints en `api/` requieren sesión autenticada; los que exponen datos clínicos exigen además rol *profesional*, y siempre filtran por el profesional dueño de cada registro.
- El link público de confirmación de turno usa un token aleatorio de 20 bytes — no expone contenido clínico, solo fecha/hora/motivo de la cita puntual.
- La carpeta `adjuntos/` incluye un `.htaccess` que impide la ejecución de scripts, aunque se suba un archivo con extensión engañosa.
- Validación de tipo MIME real (no solo por extensión) al subir archivos.
- Se recomienda servir el sitio bajo HTTPS (AutoSSL de cPanel es gratuito) dado que se transmiten datos de salud.

> Este proyecto no ha pasado por una auditoría de seguridad profesional ni un pentest formal. Es razonablemente seguro para el caso de uso de un consultorio pequeño o mediano, pero quien lo despliegue es responsable de evaluar si cumple los requisitos normativos de su jurisdicción (en Argentina, Ley 25.326 de Protección de Datos Personales) antes de usarlo con datos reales de pacientes.

## Hoja de ruta

Ideas pendientes, sin compromiso de implementación:

- [ ] Firma con imagen escaneada en el PDF exportado (hoy es solo nombre y título en texto)
- [ ] Recuperación de PIN olvidado sin pasar por phpMyAdmin
- [ ] Buscador único en el panel principal (DNI/nombre → resultado directo)
- [ ] Notificaciones push o por email para recordatorios de turno
- [ ] Exportación de estadísticas a Excel/CSV
- [ ] Internacionalización (hoy todos los textos están en español rioplatense)

## Licencia

[PolyForm Noncommercial 1.0.0](./LICENSE) — código fuente disponible para cualquier uso **no comercial**: estudio personal, proyectos propios sin fines de lucro, instituciones educativas, organizaciones de salud pública, ONGs y organismos de gobierno. Para uso comercial (ofrecerlo como servicio, instalarlo a terceros a cambio de un pago, integrarlo en un producto comercial) se necesita una licencia aparte — abrí un issue o contactá a quien mantiene este repositorio.

---

Construido de forma iterativa junto a [Claude](https://claude.ai) (Anthropic).

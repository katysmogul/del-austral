# Del Austral — Historial Clínico Digital
### Guía de instalación en tu hosting cPanel

---

## ¿Ya tenés el sistema instalado? Empezá acá

Si ya estabas usando una versión anterior con pacientes cargados, **no necesitás reinstalar todo desde cero**, pero esta actualización es la más grande hasta ahora: agrega sedes, varios profesionales con datos separados entre sí, y un nuevo nivel de acceso ("Desarrollador"). Seguí estos pasos en orden, sin saltar ninguno:

1. **Migrá la base de datos** (una sola vez, en este orden exacto): en phpMyAdmin, entrá a tu base de datos → pestaña **"SQL"** (no "Importar"):
   - Si nunca corriste `migracion_v2.sql`, pegalo y ejecutalo (agrega citas, archivos adjuntos y plantillas).
   - Si nunca corriste `migracion_v3.sql`, pegalo y ejecutalo (agrega usuarios con roles e historial de cambios).
   - Si nunca corriste `migracion_v4.sql`, pegalo y ejecutalo (cambia el acceso de patrón dibujado a PIN numérico).
   - Por último, pegá y ejecutá **`migracion_v5.sql`** (agrega sedes, separa los pacientes por profesional, agrega el rol Desarrollador y la confirmación de turnos por el paciente). Este script crea una "Sede principal" automática y te asigna ahí todo lo que ya tenías cargado, para no perder nada.
   - Si nunca corriste `migracion_v6.sql`, pegalo y ejecutalo también (agrega el aviso de confirmaciones/cancelaciones de turno).
   - Por último, pegá y ejecutá **`migracion_v7.sql`** (agrega a la papelera el profesional y la sede original de cada legajo eliminado, necesario para que el Desarrollador pueda recuperarlos).
   - Si nunca corriste `migracion_v8.sql`, pegalo y ejecutalo también (agrega el aviso de "legajo recuperado de otro profesional" en la ficha del paciente).
   - Por último, pegá y ejecutá **`migracion_v9.sql`** (el DNI pasa a ser único por profesional, no global — así dos profesionales distintos pueden tener cada uno un paciente con el mismo número de DNI, sin chocar entre sí).
   - Ninguno de los cinco scripts borra pacientes, sesiones, citas ni adjuntos.
2. **Subí la carpeta `adjuntos/`** completa (si todavía no la tenías) al mismo nivel que `index.html`.
3. **Reemplazá** estos archivos por sus versiones nuevas: `index.html`, toda la carpeta `assets/`, toda la carpeta `api/`, `exportar.php`, y agregá el archivo nuevo **`confirmar_turno.php`**. No toques `config/config.php` — ya tiene tus credenciales y no cambió.

**Importante — cómo queda tu acceso después de esta migración.** Tu usuario profesional y tu PIN siguen funcionando igual que antes, pero el login cambió de forma: ahora antes de poner el PIN vas a tener que elegir una sede (te va a aparecer "Sede principal", que es la que creó la migración) y después tu nombre en la lista. Es un paso más, pero el PIN es el mismo de siempre.

**Sobre el nuevo rol Desarrollador.** Es un nivel de acceso por encima de todo, pensado para que solo vos (o quien administre el sistema técnicamente) pueda crear sedes nuevas y dar de alta o baja a profesionales y administrativas — así un médico no puede, por error o sin permiso, crear accesos para otros médicos. La migración **no te crea automáticamente** una clave de Desarrollador: la primera vez que entres después de migrar, vas a ver el botón "Soy el Desarrollador" en la pantalla de selección de sede. Tocalo y vas a poder crear esa clave (también de 4 números) en ese momento. Una vez creada, desde el panel de Desarrollador podés organizar mejor tus sedes y agregar a los demás profesionales si tenés más de uno.

Si en tu consultorio **solo hay un profesional** (vos) y no necesitás nada de sedes múltiples ni separar pacientes entre médicos, no es obligatorio que uses el rol Desarrollador para el día a día — solo lo vas a necesitar la primera vez para crear la sede y, si querés, agregar una administrativa.

---

## Instalación desde cero (primera vez)

## 1. Crear la base de datos en cPanel

1. Entrá a **cPanel** → buscá **"Bases de datos MySQL"**.
2. En **"Crear nueva base de datos"**, escribí por ejemplo `legajos` y hacé clic en **Crear base de datos**.
   - cPanel le va a agregar tu usuario de hosting como prefijo, por ejemplo: `tuusuario_legajos`. Anotá ese nombre completo.
3. Bajá hasta **"Usuarios MySQL"** → **"Añadir nuevo usuario"**. Elegí un usuario (ej: `admin`) y una contraseña segura. Anotala. El nombre final va a ser algo como `tuusuario_admin`.
4. Bajá hasta **"Añadir usuario a la base de datos"**. Seleccioná el usuario y la base que creaste, hacé clic en **Añadir**, y en la pantalla de privilegios marcá **"ALL PRIVILEGES"**. Guardá.

Con esto ya tenés: nombre de base de datos, usuario y contraseña. Los vas a necesitar en el paso 3.

---

## 2. Importar la estructura de tablas

1. En cPanel, abrí **phpMyAdmin**.
2. En la columna izquierda, hacé clic en la base de datos que creaste (`tuusuario_legajos`).
3. Arriba, hacé clic en la pestaña **"Importar"**.
4. Elegí el archivo **`database.sql`** (incluido en este proyecto) y hacé clic en **Continuar / Importar** abajo de todo.
5. Deberías ver un mensaje de éxito y, a la izquierda, las tablas: `sedes`, `desarrollador`, `usuarios`, `usuarios_sedes`, `obras_sociales`, `pacientes`, `sesiones`, `legajos_eliminados`, `citas`, `archivos_adjuntos`, `plantillas_evolucion`, `historial_cambios`.

---

## 3. Configurar la conexión

1. Abrí el archivo **`config/config.php`** con el editor de texto que prefieras.
2. Completá estas 4 líneas con tus datos reales del paso 1:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tuusuario_legajos');
define('DB_USER', 'tuusuario_admin');
define('DB_PASS', 'tu_contraseña_real');
```

3. Cambiá también esta línea por cualquier texto largo e inventado (solo una vez, antes de subir el sitio):

```php
define('APP_SECRET', 'escribí-aquí-cualquier-texto-largo-y-random-unico');
```

Guardá el archivo.

---

## 4. Subir los archivos

1. En cPanel, abrí **"Administrador de archivos"** (File Manager) o usá un cliente FTP (FileZilla, etc.).
2. Entrá a la carpeta donde se publica tu sitio (normalmente `public_html`, o una subcarpeta si querés que viva en `tudominio.com/legajos`).
3. Subí **todo el contenido** de esta carpeta del proyecto (manteniendo la estructura: `index.html`, `exportar.php`, `confirmar_turno.php`, `manifest.json`, `sw.js`, `version.json`, carpetas `api/`, `assets/` —incluida `assets/icons/`—, `config/`, `adjuntos/`).

**Importante:** la carpeta `adjuntos/` necesita permisos de escritura para que el sitio pueda guardar los archivos que subas. Si al subir un archivo te da error de permisos, entrá al Administrador de archivos, clic derecho sobre la carpeta `adjuntos` → Permisos → poné **755** (o 775 si 755 no alcanza).

**Sobre `config/`:** no necesita estar accesible desde el navegador directamente, pero no es grave si lo está porque PHP no expone el código fuente, solo lo ejecuta.

---

## 5. Probar — primer ingreso

1. Entrá a `https://tudominio.com/` (o la ruta donde lo subiste).
2. La primera vez te va a pedir crear la **clave de Desarrollador** (4 números). Es la llave maestra para configurar el sistema — guardala en un lugar seguro, separado de los PIN de los profesionales.
3. Después de crear la clave, te va a aparecer una pantalla para crear de una vez tu **primera sede** y tu **primer profesional** (con su propio PIN de 4 números). Completá los tres datos y tocá "Crear sede y profesional".
4. Listo. Cerrá sesión y volvé a entrar: ahora vas a ver la pantalla normal de acceso — elegís la sede, elegís tu nombre, y poné tu PIN.

Si en algún momento necesitás agregar otra sede, otro profesional, o una administrativa, entrá con la clave de Desarrollador (botón "Soy el Desarrollador" en la pantalla de selección de sede) y gestionalo desde ahí.

---

## ¿Qué hace cada parte del sistema?

- **Crear legajo**: registra un paciente nuevo con sus datos, obra social y, opcionalmente, las primeras sesiones. El paciente queda asociado automáticamente a tu usuario (solo vos lo vas a poder ver) y a la sede donde iniciaste sesión.
- **Acceder a legajos**: buscá por DNI, nombre y apellido, fecha de atención, obra social, o **sede**. Desde la ficha del paciente podés:
  - **Editar sus datos** en cualquier momento con el botón "Editar datos".
  - **Cambiarlo de sede** con el botón "Cambiar de sede", si el paciente empezó a atenderse en otro lugar.
  - **Agregar, editar o eliminar sesiones**: cada sesión de la línea de tiempo tiene sus propios botones de lápiz (editar) y tacho (eliminar), por si te equivocaste al escribir algo.
  - Usar una **plantilla de evolución** al agregar una sesión (texto reutilizable, propio de cada profesional — no se comparten entre médicos distintos).
  - **Agendar y gestionar citas** del paciente (marcarlas como atendidas, ausentes o cancelarlas). El sistema no te deja agendar dos turnos a la misma fecha y hora para el mismo profesional, sin importar en qué sede sea.
  - **Enviar un recordatorio por WhatsApp** con un mensaje pre-armado que incluye un link para que el paciente confirme o cancele el turno con un toque, sin necesidad de loguearse a nada.
  - **Copiar el link de confirmación** del turno (ícono de "copiar"), por si preferís mandarlo por otro medio que no sea WhatsApp.
  - **Subir y descargar archivos adjuntos** (PDF o imágenes), hasta 15 MB cada uno.
  - **Exportar el legajo completo a PDF**, con tu nombre y título como firma al final.
- **Agenda**: calendario mensual completo con tus citas. Desde el panel principal también ves un resumen de "próximas citas" de los próximos 7 días.
- **"Hoy tenés X consultas"**: en el panel principal, una franja te muestra cuántas consultas de hoy todavía no llegó su hora y a qué hora es la próxima. El número baja automáticamente a medida que pasan las horas agendadas, sin que tengas que tocar nada — no depende de que marques "Atendida".
- **Aviso de confirmaciones y cancelaciones**: cuando un paciente confirma o cancela su turno desde el link que le mandaste, en el panel principal te aparece un cartel con la cantidad de novedades. Tocalo para ver el detalle (quién confirmó, quién canceló) y marcarlas como vistas.
- **Pacientes sin sesiones recientes** y **próximos cumpleaños**: resúmenes en el panel principal.
- **Estadísticas**: pacientes totales, sesiones del mes, citas por estado, distribución por obra social — siempre de **tus propios pacientes**, no de otros profesionales. Desde ahí también podés tocar "Descargar backup de todos mis legajos" para bajar un archivo con todos tus pacientes, sus sesiones, citas y la lista de adjuntos — útil como respaldo propio, fuera de la base de datos.
- **Eliminar legajos**: el legajo desaparece de las búsquedas normales, pero queda guardado completo en la base histórica.
- **Obras sociales**: catálogo compartido entre todos los profesionales (no es información de un paciente puntual, así que no hace falta separarlo).
- **Instalar el sistema como app**: tanto en celular como en computadora, podés "instalar" Del Austral para que tenga su propio ícono y se abra como una app, sin pasar por el navegador cada vez. Ver la sección [Instalar como app](#instalar-como-app) más abajo.
- **Verificación de versión** (solo Desarrollador): una pestaña en el panel del Desarrollador que revisa si los archivos del servidor coinciden con la última actualización que te entregamos, para detectar de un vistazo si algo quedó con una versión vieja después de subir archivos nuevos.
- **Reportes por sede** (solo Desarrollador): otra pestaña del panel que muestra, sede por sede, cuántos profesionales y administrativas atienden ahí, cuántos pacientes hay en total, y la actividad (sesiones y citas) de este mes. Útil para tener una foto general si el consultorio crece a varias sucursales.

---

## Sedes, profesionales y roles

Este sistema soporta un consultorio con **varias sedes** y **varios profesionales**, donde cada profesional ve únicamente sus propios pacientes — ni siquiera otro profesional de la misma sede puede verlos.

### Los tres niveles de acceso

| | **Desarrollador** | **Profesional** | **Administrativa** |
|---|---|---|---|
| Crear/desactivar sedes | Sí | No | No |
| Crear/desactivar usuarios | Sí | No | No |
| Ver pacientes y agenda | No | Sí (los propios) | Sí (de un profesional elegido) |
| Crear pacientes (contacto) | No | Sí | Sí |
| Ver motivo, patología, síntomas, sesiones | No | Sí | **No** |
| Editar / eliminar legajos, sesiones | No | Sí | No |
| Exportar PDF, ver estadísticas e historial | No | Sí | No |

- **El Desarrollador** entra por una puerta separada (botón "Soy el Desarrollador" en la pantalla de acceso) con su propia clave de 4 números. No ve ningún paciente — su única función es organizar sedes y dar de alta o baja a las personas que sí van a usar el sistema día a día.
- **El profesional** entra eligiendo su sede y su nombre, y después su PIN. Ve y gestiona solo los pacientes que él mismo creó (o que el Desarrollador le haya dejado asociados).
- **La administrativa** entra de la misma forma, pero además tiene que indicar **a nombre de qué profesional** está trabajando en ese momento (si en la sede hay varios médicos, esto evita que el sistema confunda a quién pertenecen los pacientes que ella gestiona). Ve la agenda y los datos de contacto de los pacientes de ese profesional, pero nunca el contenido clínico. Una vez adentro, en la topbar y en el cartel de bienvenida aparece el nombre del profesional (no el de la administrativa) — así no se "mezclan" visualmente las cuentas. Internamente el sistema sigue sabiendo que fue ella quien hizo cada acción, para que el historial de cambios siga siendo preciso.

### Gestionar sedes y usuarios (como Desarrollador)

Desde el panel de Desarrollador (al que entrás con tu clave) tenés varias pestañas:

- **Sedes**: crear sedes nuevas o desactivar las que ya no usás.
- **Usuarios**: agregar profesionales o administrativas, elegir en qué sede(s) atienden, y asignarles su PIN. También podés quitarles el acceso cuando haga falta (no se borra su historial, solo deja de poder entrar), o gestionar a qué sedes pertenece cada uno.
- **Historial de cambios**: acá el Desarrollador ve **todo** el historial de todos los profesionales (es el único rol con esa vista global; cada profesional, desde su propio panel, solo ve el historial de sus propios pacientes).
- **Versión del sistema**: compara los archivos del servidor contra la última actualización entregada.
- **Reportes por sede**: resumen de cada sede (profesionales, pacientes, actividad del mes).
- **Papelera**: ver los legajos que eliminó cualquier profesional y, si hace falta, recuperarlos asignándolos a otro profesional de la misma sede — por ejemplo, si un profesional deja de trabajar en una sede y otro va a continuar atendiendo a sus pacientes. Para usarla: elegí primero la sede, después el profesional que eliminó el legajo, y vas a ver su papelera. Al tocar "Recuperar" en un paciente, elegís a cuál de los profesionales de esa misma sede se lo querés asignar — el legajo vuelve a aparecer activo, con todas sus sesiones, a nombre del profesional que elegiste. Si lo asignaste a alguien distinto del profesional que lo tenía antes, le va a quedar un aviso permanente en la ficha del paciente, indicando de qué profesional venía ese legajo.
- **Legajos huérfanos**: distinto de la papelera — acá ves los pacientes que **siguen activos** (nunca se eliminaron) de un profesional al que le **quitaste el acceso** ("Quitar acceso" en la pestaña Usuarios). Como ese profesional ya no puede entrar al sistema, sus pacientes quedarían sin nadie que los gestione, a menos que los transfieras a otro profesional de la misma sede desde esta pestaña. Funciona igual que la papelera: elegís el profesional desactivado, ves sus pacientes, y tocás "Transferir" para asignárselos a otro.

---

## Instalar como app

Del Austral se puede "instalar" tanto en celular como en computadora, para que tenga su propio ícono y se abra como una aplicación, sin las barras del navegador. No es una app de tienda (no pasa por Google Play ni App Store) — se instala directo desde el navegador, y funciona exactamente igual que la versión web (necesita conexión a internet, no funciona sin ella).

**En Android (Chrome):**
1. Entrá al sitio normalmente.
2. Va a aparecer un cartelito abajo ofreciendo "Instalar app", o podés tocar los tres puntos (⋮) arriba a la derecha → **"Instalar app"** o **"Agregar a pantalla principal"**.
3. Confirmá. Va a aparecer el ícono de Del Austral entre tus apps, como cualquier otra.

**En iPhone/iPad (Safari):**
1. Entrá al sitio normalmente.
2. Tocá el botón de compartir (el cuadrado con la flecha hacia arriba).
3. Buscá **"Agregar a pantalla de inicio"** y confirmá.

**En computadora (Chrome o Edge):**
1. Entrá al sitio.
2. En la barra de direcciones, a la derecha, va a aparecer un ícono de instalación (una pantalla con una flecha). Hacé clic ahí, o desde el menú (⋮) buscá **"Instalar Del Austral"**.
3. Se va a abrir en su propia ventana, con su propio ícono en el escritorio o en la barra de tareas.

Si no te aparece la opción de instalar, puede ser que el sitio no esté funcionando bajo HTTPS (revisá que tengas el candadito en la barra de direcciones; si no, activá el AutoSSL de cPanel mencionado en la sección de seguridad).

---

## Verificación de versión (para el Desarrollador)

Cada vez que te entreguemos una actualización, vas a recibir junto con los archivos un **`version.json`** nuevo. Subilo siempre junto con el resto — es el que le dice al sistema "esta es la versión correcta esperada".

Para revisar si todo quedó bien actualizado:

1. Entrá como Desarrollador.
2. En el panel, pestaña **"Versión del sistema"**.
3. Vas a ver un cartel verde si todo coincide con la última actualización, o uno rojo si algún archivo quedó con una versión vieja — con el detalle de cuál archivo específico tiene el problema.

Esto compara el contenido real de los archivos del servidor contra lo que te entregamos, así que es la forma más confiable de confirmar una actualización sin tener que ir abriendo archivo por archivo en el editor de cPanel (como tuvimos que hacer alguna vez antes de tener esta pantalla).

---

## Si falla la subida de archivos adjuntos

El sistema permite adjuntar PDF o imágenes de hasta 15 MB por archivo, pero ese límite también depende de la configuración propia de PHP en tu hosting (`upload_max_filesize` y `post_max_size`). Si tu hosting tiene un límite más bajo (algo común en planes básicos, donde el default suele ser 2 MB u 8 MB), vas a ver un mensaje claro indicando el límite real del servidor.

Para subirlo, en cPanel buscá **"Seleccionar versión de PHP"** o **"MultiPHP INI Editor"** → ahí podés aumentar `upload_max_filesize` y `post_max_size` a, por ejemplo, 20M cada uno (siempre poné `post_max_size` igual o más grande que `upload_max_filesize`). Si no encontrás esa opción, contactá al soporte de tu hosting y pedíselo directamente.

---

## Sobre la seguridad de los datos

Esto maneja datos clínicos de pacientes, así que algunas recomendaciones:

- Activá el **certificado SSL gratuito** de tu cPanel (sección "SSL/TLS Status" → "Run AutoSSL") para que el sitio funcione con `https://` y no `http://`.
- Hacé backups periódicos de la base de datos desde phpMyAdmin, y también de la carpeta `adjuntos/` (los archivos subidos no están en la base de datos).
- Los PIN y la clave de Desarrollador se guardan encriptados (hash), nunca en texto plano.
- El aislamiento entre profesionales no depende solo de la pantalla: está aplicado en el servidor, así que aunque alguien intente forzar una URL con el ID de un paciente de otro profesional, el sistema no lo va a mostrar.
- La carpeta `adjuntos/` tiene un archivo `.htaccess` que impide que cualquier archivo subido se ejecute como código.
- El link de confirmación de turno (`confirmar_turno.php`) es público a propósito —es lo que permite que el paciente confirme sin loguearse— pero solo expone los datos de esa cita puntual (fecha, hora, motivo), nunca información clínica. Cada link es único e impredecible (un código largo generado al azar), así que no se puede adivinar el de otro paciente.

Cualquier ajuste que necesites lo podemos ir sumando.

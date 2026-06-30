# Del Austral — Historial Clínico Digital
### Guía de instalación y uso (versión final)

---

## ¿Ya tenés el sistema instalado? Empezá acá

Si ya estabas usando una versión anterior con pacientes cargados, **no necesitás reinstalar nada desde cero**. Solo tenés que correr las migraciones de base de datos que todavía no hayas corrido, y reemplazar los archivos por sus versiones nuevas.

### 1. Migrá la base de datos

En phpMyAdmin, entrá a tu base de datos → pestaña **"SQL"** (no "Importar"), y corré en orden, una por una, las migraciones que todavía no hayas aplicado:

| Migración | Qué agrega |
|---|---|
| `migracion_v2.sql` | Citas, archivos adjuntos y plantillas de evolución |
| `migracion_v3.sql` | Usuarios con roles e historial de cambios |
| `migracion_v4.sql` | Cambia el acceso de patrón dibujado a PIN numérico |
| `migracion_v5.sql` | Sedes, separación de pacientes por profesional, rol Desarrollador, confirmación de turnos por el paciente |
| `migracion_v6.sql` | Aviso de confirmaciones/cancelaciones de turno |
| `migracion_v7.sql` | Profesional y sede original en la papelera (para poder recuperar legajos) |
| `migracion_v8.sql` | Aviso de "legajo recuperado de otro profesional" en la ficha del paciente |
| `migracion_v9.sql` | El DNI pasa a ser único por profesional, no global |
| `migracion_v10.sql` | Legajos completos de profesionales (datos personales, especialidad) y sistema de licencias |
| `migracion_v11.sql` | Número de legajo automático (formato `LG-AAAA-NNN`) |
| `migracion_v12.sql` | Firma digital del profesional |
| `migracion_v13.sql` | Matrícula nacional/provincial y sello automático generado al crear el legajo |
| `migracion_v14.sql` | Constancias médicas (Servicios Plus) con validación por token |

Ninguna de estas migraciones borra pacientes, sesiones, citas ni adjuntos existentes. Si no estás seguro de cuáles ya corriste, no pasa nada grave en correr una de nuevo por error — la mayoría usa `ALTER TABLE ... ADD COLUMN`, que falla de forma segura (sin romper nada) si la columna ya existe.

### 2. Reemplazá los archivos

Subí las versiones nuevas de: `index.html`, toda la carpeta `assets/`, toda la carpeta `api/`, `exportar.php`, `confirmar_turno.php`, `version.json`, `manifest.json`, `sw.js`. **No toques** `config/config.php` — ya tiene tus credenciales reales y no cambia entre actualizaciones.

### 3. Confirmá la actualización

Entrá como Desarrollador (ver más abajo cómo) → pestaña **"Versión del sistema"** → tocá "Revisar ahora". Si todo quedó en verde, la actualización se aplicó correctamente.

---

## Instalación desde cero (primera vez)

### Si vas a usar hosting compartido (cPanel)

**1. Crear la base de datos**

1. Entrá a cPanel → **"Bases de datos MySQL"**.
2. En "Crear nueva base de datos", escribí un nombre (ej: `legajos`) → Crear. cPanel le agrega tu usuario de hosting como prefijo (ej: `tuusuario_legajos`); anotá el nombre completo.
3. Bajá a "Usuarios MySQL" → "Añadir nuevo usuario". Elegí usuario y contraseña, anotalos.
4. Bajá a "Añadir usuario a la base de datos", asociá el usuario a la base, y marcá **"ALL PRIVILEGES"**.

**2. Importar la estructura**

1. Abrí phpMyAdmin → tu base de datos → pestaña **"Importar"**.
2. Elegí el archivo `database.sql` (incluido en este proyecto) → Importar.
3. Deberías ver, a la izquierda, todas las tablas: `sedes`, `desarrollador`, `usuarios`, `usuarios_sedes`, `profesionales_legajos`, `obras_sociales`, `pacientes`, `sesiones`, `legajos_eliminados`, `citas`, `archivos_adjuntos`, `plantillas_evolucion`, `historial_cambios`.

**3. Configurar la conexión**

Abrí `config/config.php` y completá con tus datos reales:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tuusuario_legajos');
define('DB_USER', 'tuusuario_admin');
define('DB_PASS', 'tu_contraseña_real');
define('APP_SECRET', 'escribí-aquí-cualquier-texto-largo-y-random-único');
```

**4. Subir los archivos**

Subí todo el contenido del proyecto (manteniendo la estructura) a `public_html` o la subcarpeta donde quieras publicar el sitio. La carpeta `adjuntos/` necesita permisos de escritura (755, o 775 si no alcanza).

### Si vas a usar un VPS propio (Nginx + PHP-FPM + MariaDB)

La instalación es la misma en esencia (base de datos, `config.php`, archivos), pero con algunas diferencias de configuración del servidor que vale la pena tener en cuenta:

- **`DB_HOST`**: en algunos VPS, `'localhost'` falla con el error `SQLSTATE[HY000] [2002] No such file or directory` porque PHP intenta usar un socket Unix que no está donde se espera. Si te pasa esto, cambiá `DB_HOST` a `'127.0.0.1'` — eso fuerza la conexión por TCP y evita el problema.
- **PHP-FPM debe estar corriendo**: verificá con `systemctl status php8.2-fpm` (ajustá la versión). Si el socket en `/run/php/` no existe, el sitio va a dar 404 o 502 en cualquier archivo `.php`.
- **El virtual host de Nginx** necesita el bloque `location ~ \.php$` apuntando al socket correcto de PHP-FPM, sin comentar. Un ejemplo mínimo:

```
server {
    server_name tudominio.com;
    root /var/www/tu-proyecto;
    index index.php index.html;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

- **Importar la base de datos** se hace igual que en cPanel, pero desde la terminal: `mysql -u tu_usuario -p tu_base < database.sql`.
- Si el sitio carga pero da un error de PHP sobre una tabla que "no existe" (`Table 'tubase.desarrollador' doesn't exist`), es señal de que `database.sql` nunca se importó — la base existe pero está vacía.

### Primer ingreso (cPanel o VPS, es igual)

1. Entrá a tu dominio. La primera vez te va a pedir crear la **clave de Desarrollador** (4 números) — guardala en un lugar seguro, separado de los PIN de los profesionales.
2. Después, una pantalla te deja crear de una vez tu **primera sede** y tu **primer profesional** (con su legajo completo y PIN).
3. Listo. Cerrá sesión y volvé a entrar: ahora vas a ver la pantalla normal — elegís sede, elegís tu nombre, ponés tu PIN.

Si en algún momento necesitás agregar otra sede, otro profesional o una administrativa, entrá con la clave de Desarrollador (ver la sección siguiente sobre cómo acceder) y gestionalo desde el panel.

---

## Sedes, profesionales y roles

El sistema soporta varias sedes y varios profesionales, donde cada profesional ve únicamente sus propios pacientes — ni siquiera otro profesional de la misma sede puede verlos.

### Los tres niveles de acceso

| | **Desarrollador** | **Profesional** | **Administrativa** |
|---|---|---|---|
| Crear/renombrar/desactivar sedes | Sí | No | No |
| Crear/editar/desactivar usuarios | Sí | No | No |
| Gestionar licencias de profesionales | Sí | No | No |
| Ver pacientes y agenda | No | Sí (los propios) | Sí (de un profesional elegido) |
| Crear pacientes (contacto) | No | Sí | Sí |
| Ver motivo, patología, síntomas, sesiones | No | Sí | No |
| Editar / eliminar legajos, sesiones | No | Sí | No |
| Exportar PDF, ver estadísticas e historial | No | Sí | No |

- **El Desarrollador** entra por un acceso discreto, separado del login normal (ver más abajo), con su propia clave de 4 números. No ve ningún paciente — su función es organizar sedes, dar de alta o baja a las personas que usan el sistema, y gestionar sus licencias de acceso.
- **El profesional** entra eligiendo su sede y su nombre, y después su PIN. Ve y gestiona solo los pacientes que él mismo creó (o que el Desarrollador le haya asignado).
- **La administrativa** entra igual, pero además indica a nombre de qué profesional está trabajando ese momento. Ve agenda y contacto, nunca contenido clínico.

### Cómo acceder como Desarrollador

La pantalla de acceso normal no menciona que existe un rol de Desarrollador, a propósito. En la esquina superior derecha de la pantalla hay un botón pequeño y discreto que dice **"Mantenimiento"** — tocalo para ingresar tu clave de Desarrollador.

### Gestionar sedes y usuarios (como Desarrollador)

Desde el panel de Desarrollador tenés estas pestañas:

- **Sedes**: crear sedes nuevas, renombrarlas (el cambio se refleja automáticamente en todos los legajos existentes, sin perder ni mover nada — pacientes y profesionales se vinculan por un identificador interno, no por el texto del nombre), o desactivarlas.
- **Usuarios**: agregar profesionales (con su legajo completo: título, nombre, DNI, fecha y lugar de nacimiento, especialidad, matrícula nacional y/o provincial —ambas opcionales—, contacto, sede y licencia) o administrativas (alta simple). Cada profesional recibe un número de legajo automático con formato `LG-2026-001`, y un sello/firma de partida generado automáticamente con sus datos (nombre, título y matrícula si la cargaste). Desde acá también podés editar el legajo de un profesional ya creado (si todavía tiene el sello automático sin reemplazar, se regenera solo con los datos nuevos al guardar), gestionar su licencia (activarla por 7 a 120 días o indeterminada, pausarla o prohibirla), cambiar su PIN sin recrearlo, restaurar el acceso de alguien desactivado, y buscar por nombre, DNI o número de legajo en un único buscador.
- **Historial de cambios**: el Desarrollador ve todo el historial de todos los profesionales, con un filtro opcional por tipo de entidad.
- **Versión del sistema**: compara los archivos del servidor contra la última actualización entregada.
- **Reportes por sede**: resumen de cada sede (profesionales, pacientes, actividad del mes).
- **Papelera**: recuperar legajos eliminados, asignándolos a otro profesional de la misma sede donde estaban.
- **Legajos huérfanos**: transferir pacientes activos de un profesional desactivado a otro de la misma sede.
- **Calendario de licencias**: vista de calendario mensual con cada profesional marcado en el día exacto en que vence su licencia, para planificar renovaciones con anticipación. En la pestaña Sedes también aparece un aviso si alguna licencia vence dentro de los próximos 7 días.

---

## ¿Qué hace cada parte del sistema (vista del profesional)?

- **Crear legajo**: registra un paciente con sus datos, obra social y, opcionalmente, las primeras sesiones.
- **Acceder a legajos**: buscá por DNI, nombre, fecha, obra social o sede. Desde la ficha podés editar datos, cambiar de sede, agregar/editar/eliminar sesiones, usar plantillas de evolución, agendar citas, mandar recordatorio de turno por WhatsApp, subir/descargar adjuntos, y exportar a PDF.
- **Firma digital**: cuando el Desarrollador crea el legajo, el sistema genera automáticamente un sello de partida (título junto al nombre, especialidad y matrícula si la cargaste, en el estilo de un sello real). Desde "Mi legajo" → "Mi firma", el profesional ve ese sello ya precargado en el recuadro de dibujo y puede firmar directamente encima con el mouse o el dedo, reemplazarlo subiendo una imagen de su propio sello escaneado (firmando igual encima), o limpiar todo y dibujar una firma libre. Sea cual sea el resultado, se inserta automáticamente al pie de cada legajo que exporte a PDF.
- **Agenda**: calendario mensual con tus citas, más un resumen de próximas citas en el panel principal.
- **"Hoy tenés X consultas"**: franja en el panel principal con la cantidad de consultas de hoy que todavía no llegó su hora.
- **Aviso de confirmaciones y cancelaciones**: cartel con la cantidad de novedades cuando un paciente confirma o cancela desde el link de WhatsApp.
- **Pacientes que podrían estar abandonando el seguimiento**: en "Estadísticas", una sección compara cuánto pasó desde la última sesión de cada paciente contra su propio ritmo habitual de visitas — si un paciente que solía venir cada 2 semanas ya lleva más de un mes sin sesión, aparece marcado ahí. Para pacientes con poca historia, usa una regla de respaldo de 60 días sin actividad.
- **Pacientes sin sesiones recientes** y **próximos cumpleaños**: resúmenes en el panel principal.
- **Estadísticas**: pacientes totales, sesiones del mes, citas por estado, distribución por obra social, y un botón para descargar un backup completo de todos tus legajos.
- **Eliminar legajos**: queda guardado en la papelera, recuperable más adelante.
- **Mi legajo**: tus propios datos profesionales en modo de solo lectura, con tu número de legajo y el estado de tu licencia.

---

## Instalar como app (PWA)

Del Austral se puede "instalar" en celular o computadora para que tenga su propio ícono y se abra sin las barras del navegador. No pasa por ninguna tienda de apps.

- **Android (Chrome)**: menú → "Instalar app" o "Agregar a pantalla principal".
- **iPhone/iPad (Safari)**: botón de compartir → "Agregar a pantalla de inicio".
- **Computadora (Chrome/Edge)**: ícono de instalación en la barra de direcciones, o menú → "Instalar Del Austral".

Si no aparece la opción de instalar, confirmá que el sitio funcione con HTTPS.

---

## Servicios Plus: Constancia médica

Desde el panel principal del profesional, "Servicios Plus" → "Constancia médica" genera un justificante de asistencia exportable a PDF, con el membrete de la sede, el sello/firma del profesional, y un token de validación pública.

**Cómo funciona:**
1. El profesional elige si busca un legajo existente (autocompleta nombre y DNI) o carga los datos a mano (sin necesidad de legajo).
2. Completa, opcionalmente, ciudad de nacimiento y el lugar de trabajo/institución a la que va dirigida (si no lo completa, dice "ante las autoridades que lo requieran"; si lo completa, dice "ante las autoridades de [eso]").
3. La fecha de consulta y la sede se completan solas (hoy, y la sede donde el profesional inició sesión).
4. Al generar, se abre la vista de impresión del PDF, con el sello/firma del profesional al pie.

**Validación pública:** cada constancia tiene un token único (formato `XXXX-XXXX-XXXX`) impreso al pie del PDF, junto con un link a `validar_constancia.php`. Cualquiera que reciba el documento puede entrar a esa página, escribir el token, y ver los datos reales guardados en el sistema (paciente, fecha, profesional, sede) para confirmar que no fueron alterados.

**Vencimiento automático a los 90 días:** pasado ese plazo, la constancia deja de ser válida. Para que se borre automáticamente del sistema (dejando solo un rastro interno mínimo para auditoría, nunca visible públicamente), hace falta programar un cron que corra `cron_limpiar_constancias.php` una vez al día:

```bash
0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_constancias.php >> /var/log/limpiar-constancias.log 2>&1
```

Si no programás este cron, las constancias vencidas simplemente van a mostrar "vencida" al validarlas (porque el sistema compara la fecha igual), pero no se van a borrar de la base de datos hasta que el cron corra al menos una vez.

---

## Verificación de versión (para el Desarrollador)

Cada actualización viene con un `version.json` nuevo — subilo siempre junto con el resto. Para revisar si todo quedó bien actualizado: entrá como Desarrollador → pestaña "Versión del sistema" → "Revisar ahora". Un cartel verde confirma que todo coincide; uno rojo señala exactamente qué archivo quedó con una versión vieja.

Un problema común: si pegás el contenido de un archivo a mano dentro del editor de texto del hosting (en vez de subir el archivo real), a veces se cambian detalles invisibles que alteran el archivo sin que se note, y la verificación marca "versión vieja" aunque se vea idéntico. Si pasa esto, borrá el archivo del servidor y subilo de nuevo con el botón de "Cargar/Subir", en vez de copiar y pegar texto.

---

## Si falla la subida de archivos adjuntos

El sistema permite adjuntar PDF o imágenes de hasta 15 MB, pero ese límite también depende de la configuración de PHP en tu hosting (`upload_max_filesize` y `post_max_size`). Si tu hosting tiene un límite más bajo, vas a ver un mensaje claro con el límite real del servidor. Para subirlo: en cPanel, "MultiPHP INI Editor"; en VPS, editá el `php.ini` y reiniciá PHP-FPM.

---

## Sincronizar el servidor con un repositorio Git (opcional, para VPS)

Si tenés tu propio Gitea, GitHub u otro servidor Git, podés mantener un respaldo automático con historial de cada cambio en el servidor:

1. Convertí la carpeta del proyecto en un repositorio git y conectalo con tu servidor remoto.
2. Asegurate de que `config/config.php` esté en el `.gitignore` (ya viene así) para no subir tus credenciales reales nunca.
3. Usá un token de acceso personal en vez de tu contraseña real.
4. Un script simple en cron, corriendo periódicamente, puede hacer commit y push solo si detecta cambios reales.

Esto es opcional y pensado para quien ya tiene experiencia con git.

---

## Sobre la seguridad de los datos

- Activá el certificado SSL (AutoSSL en cPanel, o Certbot en VPS).
- Hacé backups periódicos de la base de datos y de la carpeta `adjuntos/`.
- Los PIN y la clave de Desarrollador se guardan encriptados, nunca en texto plano.
- El aislamiento entre profesionales está aplicado en el servidor, no solo en la pantalla.
- La carpeta `adjuntos/` tiene un `.htaccess` que impide ejecutar archivos subidos como código.
- El link de confirmación de turno es público a propósito, pero solo expone datos de esa cita puntual, y cada link es único e impredecible.
- El acceso de Desarrollador no se anuncia en la pantalla de login.

Cualquier ajuste que necesites lo podemos ir sumando.

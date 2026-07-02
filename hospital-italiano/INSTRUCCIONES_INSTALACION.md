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
| `migracion_v15.sql` | Tratamiento prolongado, Receta y Resumen de derivación |
| `migracion_v16.sql` | Papelera de resúmenes de derivación (eliminar / eliminar para siempre) |
| `migracion_v17.sql` | Nombre de institución personalizable (reemplaza "Del Austral" en toda la interfaz) |
| `migracion_firma_contrato_cliente.sql` | Firma del Apoderado sobre el contrato, requerida antes de crear la clave por primera vez |
| `migracion_reportes_bug_cliente.sql` | Reportes de bug del Apoderado, con estado y respuesta del equipo de soporte |
| `migracion_salud_sistema.sql` | Log de errores de la app y registro de actividad/corridas de cron, para el panel de Salud del Sistema |

Ninguna de estas migraciones borra pacientes, sesiones, citas ni adjuntos existentes. Si no estás seguro de cuáles ya corriste, no pasa nada grave en correr una de nuevo por error — la mayoría usa `ALTER TABLE ... ADD COLUMN`, que falla de forma segura (sin romper nada) si la columna ya existe.

### 2. Reemplazá los archivos

Subí las versiones nuevas de: `index.html`, toda la carpeta `assets/`, toda la carpeta `api/`, `exportar.php`, `confirmar_turno.php`, `ver_contrato.php`, `cron_limpiar_reportes_bug.php`, `version.json`, `manifest.php`, `sw.js`. **No toques** `config/config.php` — ya tiene tus credenciales reales y no cambia entre actualizaciones.

### 3. Confirmá la actualización

Entrá como Apoderado (ver más abajo cómo) → pestaña **"Versión del sistema"** → tocá "Revisar ahora". Si todo quedó en verde, la actualización se aplicó correctamente.

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

1. Entrá a tu dominio. La primera vez te va a pedir leer y firmar el contrato de servicio (con un canvas), y recién después crear la **clave de Apoderado** (4 números) — guardala en un lugar seguro, separado de los PIN de los profesionales.
2. Después, una pantalla te deja crear de una vez tu **primera sede** y tu **primer profesional** (con su legajo completo y PIN).
3. Listo. Cerrá sesión y volvé a entrar: ahora vas a ver la pantalla normal — elegís sede, elegís tu nombre, ponés tu PIN.

Si en algún momento necesitás agregar otra sede, otro profesional o una administrativa, entrá con la clave de Apoderado (ver la sección siguiente sobre cómo acceder) y gestionalo desde el panel.

---

## Sedes, profesionales y roles

El sistema soporta varias sedes y varios profesionales, donde cada profesional ve únicamente sus propios pacientes — ni siquiera otro profesional de la misma sede puede verlos.

### Los tres niveles de acceso

| | **Apoderado** | **Profesional** | **Administrativa** |
|---|---|---|---|
| Crear/renombrar/desactivar sedes | Sí | No | No |
| Crear/editar/desactivar usuarios | Sí | No | No |
| Gestionar licencias de profesionales | Sí | No | No |
| Ver pacientes y agenda | No | Sí (los propios) | Sí (de un profesional elegido) |
| Crear pacientes (contacto) | No | Sí | Sí |
| Ver motivo, patología, síntomas, sesiones | No | Sí | No |
| Editar / eliminar legajos, sesiones | No | Sí | No |
| Exportar PDF, ver estadísticas e historial | No | Sí | No |

- **El Apoderado** entra por un botón visible en la esquina superior derecha, separado del login normal (ver más abajo), con su propia clave de 4 números. No ve ningún paciente — su función es organizar sedes, dar de alta o baja a las personas que usan el sistema, y gestionar sus licencias de acceso.
- **El profesional** entra eligiendo su sede y su nombre, y después su PIN. Ve y gestiona solo los pacientes que él mismo creó (o que el Apoderado le haya asignado).
- **La administrativa** entra igual, pero además indica a nombre de qué profesional está trabajando ese momento. Ve agenda y contacto, nunca contenido clínico.

### Cómo acceder como Apoderado

La pantalla de acceso normal muestra, en la esquina superior derecha, un botón que dice **"Apoderado"** — tocalo para ingresar tu clave. Si todavía no firmaste el contrato de servicio, primero te va a pedir hacerlo (con un canvas) antes de dejarte crear esa clave.

### Gestionar sedes y usuarios (como Apoderado)

Desde el panel de Apoderado tenés estas pestañas, incluyendo "Contrato" (para volver a leer el contrato firmado en cualquier momento) y "Reportar un problema" (para avisar bugs o sugerencias al equipo de soporte):

- **Sedes**: crear sedes nuevas, renombrarlas (el cambio se refleja automáticamente en todos los legajos existentes, sin perder ni mover nada — pacientes y profesionales se vinculan por un identificador interno, no por el texto del nombre), o desactivarlas.
- **Usuarios**: agregar profesionales (con su legajo completo: título, nombre, DNI, fecha y lugar de nacimiento, especialidad, matrícula nacional y/o provincial —ambas opcionales—, contacto, sede y licencia) o administrativas (alta simple). Cada profesional recibe un número de legajo automático con formato `LG-2026-001`, y un sello/firma de partida generado automáticamente con sus datos (nombre, título y matrícula si la cargaste). Desde acá también podés editar el legajo de un profesional ya creado (si todavía tiene el sello automático sin reemplazar, se regenera solo con los datos nuevos al guardar), gestionar su licencia (activarla por 7 a 120 días o indeterminada, pausarla o prohibirla), cambiar su PIN sin recrearlo, restaurar el acceso de alguien desactivado, y buscar por nombre, DNI o número de legajo en un único buscador.
- **Historial de cambios**: el Apoderado ve todo el historial de todos los profesionales, con un filtro opcional por tipo de entidad.
- **Versión del sistema**: compara los archivos del servidor contra la última actualización entregada.
- **Reportes por sede**: resumen de cada sede (profesionales, pacientes, actividad del mes).
- **Papelera**: recuperar legajos eliminados, asignándolos a otro profesional de la misma sede donde estaban.
- **Legajos huérfanos**: transferir pacientes activos de un profesional desactivado a otro de la misma sede.
- **Calendario de licencias**: vista de calendario mensual con cada profesional marcado en el día exacto en que vence su licencia, para planificar renovaciones con anticipación. En la pestaña Sedes también aparece un aviso si alguna licencia vence dentro de los próximos 7 días.

---

## ¿Qué hace cada parte del sistema (vista del profesional)?

- **Crear legajo**: registra un paciente con sus datos, obra social y, opcionalmente, las primeras sesiones.
- **Acceder a legajos**: buscá por DNI, nombre, fecha, obra social o sede. Desde la ficha podés editar datos, cambiar de sede, agregar/editar/eliminar sesiones, usar plantillas de evolución, agendar citas, mandar recordatorio de turno por WhatsApp, subir/descargar adjuntos, y exportar a PDF.
- **Firma digital**: cuando el Apoderado crea el legajo, el sistema genera automáticamente un sello de partida (título junto al nombre, especialidad y matrícula si la cargaste, en el estilo de un sello real). Desde "Mi legajo" → "Mi firma", el profesional ve ese sello ya precargado en el recuadro de dibujo y puede firmar directamente encima con el mouse o el dedo, reemplazarlo subiendo una imagen de su propio sello escaneado (firmando igual encima), o limpiar todo y dibujar una firma libre. Sea cual sea el resultado, se inserta automáticamente al pie de cada legajo que exporte a PDF.
- **Agenda**: calendario mensual con tus citas, más un resumen de próximas citas en el panel principal.
- **"Hoy tenés X consultas"**: franja en el panel principal con la cantidad de consultas de hoy que todavía no llegó su hora.
- **Aviso de confirmaciones y cancelaciones**: cartel con la cantidad de novedades cuando un paciente confirma o cancela desde el link de WhatsApp.
- **Pacientes que podrían estar abandonando el seguimiento**: en "Estadísticas", una sección compara cuánto pasó desde la última sesión de cada paciente contra su propio ritmo habitual de visitas — si un paciente que solía venir cada 2 semanas ya lleva más de un mes sin sesión, aparece marcado ahí. Para pacientes con poca historia, usa una regla de respaldo de 60 días sin actividad.
- **Pacientes sin sesiones recientes** y **próximos cumpleaños**: resúmenes en el panel principal.
- **Estadísticas**: pacientes totales, sesiones del mes, citas por estado, distribución por obra social, y un botón para descargar un backup completo de todos tus legajos.
- **Eliminar legajos**: queda guardado en la papelera, recuperable más adelante.
- **Mi legajo**: tus propios datos profesionales en modo de solo lectura, con tu número de legajo y el estado de tu licencia.

---

## Personalizar el nombre de la institución

Desde la versión que incluye `migracion_v17.sql`, el sistema dejó de tener "Del Austral" escrito a mano en el código — el nombre se lee de la base de datos y se puede cambiar libremente.

Para cambiarlo: entrá como Apoderado → pestaña "Sedes" (la primera) → arriba de todo vas a ver "Nombre de la institución" → escribí el nombre que quieras y tocá "Guardar". El cambio se aplica al instante en: el título de la pestaña del navegador, la pantalla de acceso, la barra superior una vez logueado, el nombre con el que se instala como app (PWA), y el encabezado de todos los PDF que se exporten de ahí en adelante (legajos, constancias, recetas, resúmenes de derivación).

**Importante para quien actualice desde una versión anterior:** el archivo `manifest.json` (de la app instalable) fue reemplazado por `manifest.php`, porque un archivo `.json` estático no puede leer el nombre desde la base de datos. Asegurate de:
1. Subir el nuevo `manifest.php`.
2. **Borrar** el `manifest.json` viejo del servidor (si queda, no se usa para nada, pero conviene limpiarlo).
3. Confirmar que `index.html` (ya actualizado) apunte a `manifest.php` y no a `manifest.json`.

---

## Instalar como app (PWA)

Del Austral se puede "instalar" en celular o computadora para que tenga su propio ícono y se abra sin las barras del navegador. No pasa por ninguna tienda de apps.

- **Android (Chrome)**: menú → "Instalar app" o "Agregar a pantalla principal".
- **iPhone/iPad (Safari)**: botón de compartir → "Agregar a pantalla de inicio".
- **Computadora (Chrome/Edge)**: ícono de instalación en la barra de direcciones, o menú → "Instalar Del Austral".

Si no aparece la opción de instalar, confirmá que el sitio funcione con HTTPS.

---

## Servicios Plus

Desde el panel principal del profesional, "Servicios Plus" agrupa cuatro documentos generables a partir de un legajo existente o cargando los datos a mano:

| Documento | Token público | Vence a los 90 días |
|---|---|---|
| Constancia de asistencia | Sí | Sí |
| Constancia de tratamiento prolongado | Sí | Sí |
| Receta | Sí | Sí |
| Resumen de derivación | No | No vence (queda guardado siempre) |

**Constancia de asistencia**: justificante de que el paciente concurrió a una consulta en el día de la emisión.

**Constancia de tratamiento prolongado**: certifica que el paciente está bajo tratamiento continuo desde una fecha de inicio, con diagnóstico opcional. Útil para trámites de obra social o certificados de discapacidad.

**Receta**: indicaciones (medicación, dosis, frecuencia, ejercicios) con diagnóstico opcional.

**Resumen de derivación**: pensado para compartir directamente con otro profesional o institución (motivo de consulta, diagnóstico, tratamiento actual, observaciones, y a quién va dirigido). A diferencia de los otros tres, no tiene token de validación pública ni vencimiento — queda guardado de forma permanente, ya que no está pensado para que terceros lo validen. Como nunca se borra solo, el profesional puede **eliminarlo** (va a una papelera, recuperable después por el Apoderado desde "Papelera de derivaciones") o **eliminarlo para siempre** (irreversible, ni el Apoderado lo puede recuperar).

**Cómo funciona la creación (los tres con token):**
1. El profesional elige si busca un legajo existente (autocompleta nombre y DNI) o carga los datos a mano (sin necesidad de legajo).
2. Completa los campos propios de cada tipo (fecha de inicio y diagnóstico para tratamiento; indicaciones para receta; lugar de nacimiento y destino para asistencia).
3. La fecha y la sede se completan solas.
4. Al generar, se abre la vista de impresión del PDF, con el sello/firma del profesional al pie.

Desde el panel principal del profesional, "Servicios Plus" → "Constancia médica" genera un justificante de asistencia exportable a PDF, con el membrete de la sede, el sello/firma del profesional, y un token de validación pública.

**Cómo funciona:**
1. El profesional elige si busca un legajo existente (autocompleta nombre y DNI) o carga los datos a mano (sin necesidad de legajo).
2. Completa, opcionalmente, ciudad de nacimiento y el lugar de trabajo/institución a la que va dirigida (si no lo completa, dice "ante las autoridades que lo requieran"; si lo completa, dice "ante las autoridades de [eso]").
3. La fecha de consulta y la sede se completan solas (hoy, y la sede donde el profesional inició sesión).
4. Al generar, se abre la vista de impresión del PDF, con el sello/firma del profesional al pie.

**Validación pública:** cada constancia, tratamiento prolongado o receta tiene un token único (formato `XXXX-XXXX-XXXX`) impreso al pie del PDF, junto con un link a `validar_constancia.php`. Cualquiera que reciba el documento puede entrar a esa página, escribir el token, y ver los datos reales guardados en el sistema (paciente, fecha, profesional, sede) para confirmar que no fueron alterados. El resumen de derivación no tiene token, ya que está pensado para ir directo a otro profesional o institución, no para que terceros lo validen públicamente.

**Vencimiento automático a los 90 días:** pasado ese plazo, la constancia deja de ser válida. Para que se borre automáticamente del sistema (dejando solo un rastro interno mínimo para auditoría, nunca visible públicamente), hace falta programar un cron que corra `cron_limpiar_constancias.php` una vez al día:

```bash
0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_constancias.php >> /var/log/limpiar-constancias.log 2>&1
```

Si no programás este cron, las constancias vencidas simplemente van a mostrar "vencida" al validarlas (porque el sistema compara la fecha igual), pero no se van a borrar de la base de datos hasta que el cron corra al menos una vez.

---

## Reportes de bug del Apoderado

Desde Configuración → "Reportar un problema", el Apoderado puede avisar sobre errores o sugerencias, indicando severidad y, opcionalmente, la sede donde ocurrió. Cada reporte queda visible ahí mismo con su estado (nuevo, visto, en curso, resuelto, no resuelto) y la respuesta que el equipo de soporte haya escrito desde el panel maestro.

**Borrado automático a los 7 días de cerrado:** los reportes marcados como "resuelto" o "no resuelto" se borran solos una semana después de haber quedado en ese estado (los que siguen nuevos, vistos o en curso nunca se tocan, sin importar la antigüedad). Para que esto funcione hace falta programar un segundo cron, igual que el de constancias:

```bash
0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_reportes_bug.php >> /var/log/limpiar-reportes-bug.log 2>&1
```

Sin este cron, los reportes cerrados se acumulan en la base para siempre (no rompe nada, pero conviene programarlo). El Super Admin también puede borrar cualquier reporte a mano en cualquier momento desde el panel maestro, sin depender del cron.

---

## Salud del sistema (para el Super Admin)

En el panel maestro, la pestaña "Salud del sistema" (sidebar) muestra, para cada institución, cinco señales sin necesidad de entrar manualmente a ninguna:

| Señal | Qué significa | Cuándo alerta |
|---|---|---|
| Cron de constancias | Última vez que corrió `cron_limpiar_constancias.php` | Más de 26 horas sin correr, o nunca corrió |
| Cron de reportes de bug | Última vez que corrió `cron_limpiar_reportes_bug.php` | Más de 26 horas sin correr, o nunca corrió |
| Actividad reciente | Último login o última sesión clínica registrada | Más de 14 días sin actividad (posible institución inactiva) |
| Versión del sistema | Si los archivos del servidor coinciden con `version.json` | Cualquier archivo desactualizado |
| Errores de la app | Errores fatales o excepciones capturados automáticamente | Cualquier error en las últimas 24 horas |

Además, se muestra el certificado SSL del dominio (es el mismo para todas las instituciones, porque comparten el dominio raíz con subcarpetas).

**Cómo funciona técnicamente:** el panel maestro se conecta puntualmente a la base de datos de cada institución (mismo mecanismo que usa para verificar firmas y traer reportes de bug) y lee dos tablas nuevas: `salud_sistema` (última corrida de cada cron, última actividad) y `errores_app` (log de errores propio de la aplicación, capturado desde `config.php` — no depende del log de PHP del hosting, que varía de un servidor a otro).

La vista se **actualiza sola cada 60 segundos** mientras esté abierta, y hay un botón "Actualizar ahora" para forzar un refresco inmediato. El auto-refresco se detiene solo si salís de esa pestaña, para no generar conexiones de fondo innecesarias a las bases de todas las instituciones.

**Requisito:** correr `migracion_salud_sistema.sql` en cada institución ya instalada (las nuevas ya la traen en `database.sql`). Sin esa migración, la institución simplemente aparece sin esas señales (no rompe nada, el resto del panel sigue funcionando).

---

## Modo mantenimiento global (para el Super Admin)

En el panel maestro, abajo del sidebar, hay un botón "Activar mantenimiento". Al tocarlo (con confirmación previa), **todas las instituciones a la vez** quedan bloqueadas: cualquiera que intente entrar (profesional, administrativa, o alguien que ya tenía una sesión abierta) ve la pantalla "¡Ups! Estamos en mantenimiento" en vez de poder ingresar.

**El Apoderado de cada institución sigue pudiendo entrar sin restricciones** mientras el mantenimiento está activo — así podés seguir gestionando o revisando cosas mientras el resto está bloqueado.

Para desactivarlo, volvés al mismo botón (ahora dice "Desactivar mantenimiento", en rojo) y lo tocás de nuevo — no hace falta confirmación para desactivar.

**Cómo funciona técnicamente:** el botón crea (o borra) un único archivo `mantenimiento.json` en la carpeta que contiene tanto el panel maestro como todas las instituciones (un nivel arriba de `panel-maestro/`). Cada instalación cliente revisa la existencia de ese archivo directamente del disco, sin conectarse a ninguna base de datos ajena — por eso el bloqueo es instantáneo y no depende de que cada institución esté "sana" para poder aplicarlo.

**No requiere ninguna migración de base de datos** — funciona apenas subís los archivos actualizados de esta versión, tanto en el panel maestro como en cada institución.

---

## Sistema de cobranza (para el Super Admin)

En el panel maestro, pestaña "Cobranza" del sidebar.

### Antes de usarlo por primera vez

Completá la cuenta bancaria por defecto de Del Austral en `config/config_maestro.php`, buscando la constante `CUENTA_DEFAULT`:

```php
define('CUENTA_DEFAULT', [
    'titular' => 'MONTERO, FABIANA KARINA',
    'banco' => 'Banco Santander',
    'cuil' => '27-20746451-7',
    'numero' => '0000003100000012345678',
    'alias' => 'delaustral.pagos',
]);
```

**El número de cuenta que viene por defecto es un placeholder de ejemplo — reemplazalo por el CBU real antes de generar cualquier cobro real.** Si alguna institución en particular necesita pagar a una cuenta distinta (por ejemplo, otra sociedad o representante), podés sobreescribirla desde el botón "Cuenta bancaria" en su fila del listado de Instituciones.

### Cómo generar un cobro

Es 100% manual — no hay ningún cron corriendo esto automáticamente. Desde la pestaña Cobranza:

1. Elegí la institución, el **monto de lista**, la moneda, y la fecha de vencimiento (el período desde/hasta es opcional, solo informativo).
2. Si querés aplicar un descuento comercial, elegí uno de los valores fijos: 5%, 10%, 15%, 20% o 30% — se aplica sobre el monto de lista, antes del saldo a favor y antes de cualquier recargo por mora futuro. El campo "Monto final estimado" te muestra el cálculo en vivo (sin contar el saldo a favor, que se descuenta recién al generar).
3. Si la institución tiene saldo a favor cargado, se descuenta automáticamente del monto (ya con el descuento aplicado) antes de generar el cobro — el sistema te avisa cuánto se aplicó.
4. Al generar, se sincroniza `estado_cobro.json` y la factura correspondiente directo a la carpeta de esa institución.

### Recargo por mora automático

Si un cobro queda **vencido sin pagar** (estado "Pendiente" con la fecha de vencimiento ya pasada), el sistema calcula automáticamente un recargo del **2,5% diario más IVA (21%)** sobre el monto del cobro, sin que tengas que hacer nada — se ve tanto en el panel maestro como en el panel del Apoderado, y crece día a día mientras el cobro siga sin resolverse.

Cuando marcás el cobro como Aprobado, Rechazado o Sin acreditar, el recargo se **congela** en el valor que tenía en ese momento — deja de crecer, y ese es el monto que queda registrado para siempre en la factura histórica.

### Cómo el Apoderado paga y sube el comprobante

Desde su panel, Configuración → Facturación, el Apoderado ve el monto adeudado (con el recargo por mora ya sumado si corresponde), la fecha de vencimiento, el descuento aplicado si lo hay, si se usó saldo a favor, y los datos completos para la transferencia (titular, banco, CUIL, número de cuenta, alias). Después de transferir, sube el comprobante (PDF, JPG o PNG) desde ahí mismo — queda guardado en la carpeta de su propia institución, en `comprobantes_pago/`.

### Cómo revisar, aprobar o anular un pago

Volvé a la pestaña Cobranza (o tocá "Actualizar") — el panel maestro detecta automáticamente los comprobantes subidos por cualquier institución. Tocá "Ver / resolver" en el cobro correspondiente para:

- **Ver el comprobante** (se abre en una pestaña nueva, servido directo desde la carpeta de esa institución).
- **Ver la factura** de ese cobro puntual (ver sección siguiente).
- **Cambiar el estado**: Aprobado, Sin acreditar, o Rechazado — con una nota opcional que el Apoderado va a ver en su panel.
- **Anular el cobro**, si se generó con un dato erróneo (monto o vencimiento mal puestos, por ejemplo). Al anular:
  - Queda marcado como "Anulado" en el historial — **no se borra**, sigue siendo visible para trazabilidad.
  - Deja de contar como deuda pendiente para el Apoderado.
  - Si tenía saldo a favor aplicado, se le devuelve automáticamente a la institución.
  - Un cobro **ya aprobado no se puede anular** desde el panel — si hay un pago mal registrado después de aprobado, hay que resolverlo manualmente (contactando soporte técnico).

### Factura visible por mes

Cada cobro genera automáticamente una factura en HTML, con una marca de agua de fondo que dice **PAGADA**, **NO PAGADA** o **ANULADA** según el estado. Se puede ver:

- **Desde el panel maestro**: botón "Factura" en cada fila del listado de Cobranza, o el link "Ver factura" dentro del modal de resolver.
- **Desde el panel del Apoderado**: botón "Ver historial de facturas" en Configuración → Facturación, que muestra todas las facturas generadas, navegables por mes, sin necesidad de tener la sesión abierta (es una página pública).

La factura muestra el detalle completo: monto de lista, descuento comercial (si hubo), saldo a favor aplicado (si hubo), recargo por mora (si corresponde), y el total final.

### Saldo a favor

Desde la pestaña Cobranza, "Cargar saldo a favor" — elegís la institución y el monto, y se acredita como crédito. La próxima vez que generes un cobro para esa institución, el sistema descuenta automáticamente ese saldo del monto nuevo, sin importar si cubre el período completo o no.

**El saldo a favor de cada institución ahora se ve siempre** en el listado principal de Instituciones (no solo cuando hay un cobro generado) — aparece como una línea "Saldo a favor: $X" debajo del nombre, en verde, cada vez que sea mayor a cero. El Apoderado también ve su saldo vigente en su propio panel.

### Suspender por falta de pago

Activarla sigue siendo una decisión manual, pero ahora hay un botón específico para esto: dentro del modal "Ver / resolver" de cualquier cobro sin resolver (pendiente, comprobante subido, sin acreditar o rechazado), tocá **"Suspender por falta de pago"**.

A diferencia del botón genérico "Suspender" del listado de Instituciones (que bloquea absolutamente todo, ni el Apoderado puede entrar), esta suspensión específica:

- Bloquea el login normal (profesionales y administrativas).
- **El Apoderado sigue pudiendo entrar sin restricciones**, para ver la deuda, los datos bancarios, y subir el comprobante.
- **Se reactiva sola** en cuanto ese cobro puntual se marca como Aprobado (o si se anula, ya que en ese caso el cobro deja de ser una deuda real). No hace falta que te acuerdes de reactivarla a mano.
- Si preferís reactivarla vos mismo antes de eso, el mismo botón cambia a "Reactivar acceso" mientras la suspensión sigue activa.

En el listado de Instituciones, una institución en este estado se ve con la etiqueta "💳 Suspendida por falta de pago", distinta de la suspensión total.

### Cómo funciona técnicamente

Todos los datos de cobranza (montos, estados, saldo a favor) viven en la base del **panel maestro**, no en la de cada institución — es información comercial, no clínica. La comunicación siempre va panel maestro → cliente (nunca al revés): el panel maestro escribe `estado_cobro.json` y las facturas HTML en la carpeta de la institución cada vez que genera un cobro o cambia su estado, y el cliente lo lee del disco. Cuando el Apoderado sube un comprobante, el cliente escribe `estado_cobro_subida.json` en su propia carpeta, y el panel maestro lo detecta la próxima vez que actualiza el listado de cobros.

**Requisitos:** correr `migracion_panel_maestro_v3_cobranza.sql`, `migracion_panel_maestro_v3b_cobranza.sql` y `migracion_panel_maestro_v4_suspension_pago.sql` (en ese orden) en la base del panel maestro. Si es una instalación nueva, ya está todo incluido en `database_panel_maestro.sql`, no hace falta ninguna migración. Del lado cliente no hace falta ninguna migración de base de datos, pero sí subir `config/config.php` y `api/auth.php` actualizados (agregan el chequeo de la suspensión).

---

## Sesiones separadas entre el panel maestro y cada institución (para el Super Admin)

Si notabas que loguearte como Apoderado en una institución te cerraba la sesión del panel maestro (o viceversa), era porque ambos sistemas usaban el nombre de cookie por defecto de PHP (`PHPSESSID`) — y al vivir bajo el mismo dominio, compartían la misma cookie sin querer.

A partir de esta versión, cada sistema tiene su propio nombre de cookie de sesión (el panel maestro, y cada institución por separado), así que podés tener el panel maestro abierto en una pestaña y estar logueado como Apoderado en otra institución en otra pestaña, sin que se pisen entre sí.

**Importante:** la primera vez que subas esta actualización, cualquier sesión que estuviera abierta en ese momento se va a cerrar sola (porque el nombre de la cookie cambió) — es normal, solo hace falta volver a loguearse una vez. De ahí en adelante, cada sistema mantiene su sesión de forma independiente, y recargar la página (F5) no debería cerrarla.

---

## Verificación de versión (para el Apoderado)

Cada actualización viene con un `version.json` nuevo — subilo siempre junto con el resto. Para revisar si todo quedó bien actualizado: entrá como Apoderado → pestaña "Versión del sistema" → "Revisar ahora". Un cartel verde confirma que todo coincide; uno rojo señala exactamente qué archivo quedó con una versión vieja.

Un problema común: si pegás el contenido de un archivo a mano dentro del editor de texto del hosting (en vez de subir el archivo real), a veces se cambian detalles invisibles que alteran el archivo sin que se note, y la verificación marca "versión vieja" aunque se vea idéntico. Si pasa esto, borrá el archivo del servidor y subilo de nuevo con el botón de "Cargar/Subir", en vez de copiar y pegar texto.

---

## Tareas programadas (cron) y certificado SSL — resumen final

Esta sección junta en un solo lugar las dos tareas de mantenimiento que hay que dejar programadas después de instalar, más el certificado SSL. Es lo primero que hay que revisar si algo "no se borra solo" o si el navegador marca el sitio como "no seguro".

### Los dos cron que hay que programar

Son dos scripts independientes, ambos pensados para correr una vez al día. Podés programarlos juntos o por separado, el orden no importa:

| Script | Qué hace | Si no lo programás |
|---|---|---|
| `cron_limpiar_constancias.php` | Borra las constancias médicas vencidas (90 días desde la emisión) | Las constancias vencidas se marcan como "vencida" al validarlas, pero no se borran de la base |
| `cron_limpiar_reportes_bug.php` | Borra los reportes de bug resueltos o no resueltos con más de 7 días desde ese cambio de estado | Los reportes cerrados se acumulan en la base para siempre (no rompe nada, solo ensucia el historial) |

**Cómo programarlos (VPS o hosting con acceso a `crontab`):**

```bash
crontab -e
```

Y agregá estas dos líneas (ajustá la ruta real de tu proyecto):

```bash
0 4 * * * php /ruta/a/tu/proyecto/cron_limpiar_constancias.php >> /var/log/limpiar-constancias.log 2>&1
0 5 * * * php /ruta/a/tu/proyecto/cron_limpiar_reportes_bug.php >> /var/log/limpiar-reportes-bug.log 2>&1
```

Los corrí a las 4 y a las 5 de la mañana a propósito, para que no compitan por recursos al mismo tiempo exacto — en la práctica da igual, ambos tardan menos de un segundo.

**Si tenés varias instituciones instaladas** (una carpeta por institución, cada una con su propia base de datos), hay que agregar este mismo par de líneas **una vez por cada institución**, cambiando la ruta. Por ejemplo:

```bash
0 4 * * * php /var/www/neptuno/hospital-regional/cron_limpiar_constancias.php >> /var/log/limpiar-constancias-hospital-regional.log 2>&1
0 5 * * * php /var/www/neptuno/hospital-regional/cron_limpiar_reportes_bug.php >> /var/log/limpiar-reportes-bug-hospital-regional.log 2>&1

0 4 * * * php /var/www/neptuno/clinica-norte/cron_limpiar_constancias.php >> /var/log/limpiar-constancias-clinica-norte.log 2>&1
0 5 * * * php /var/www/neptuno/clinica-norte/cron_limpiar_reportes_bug.php >> /var/log/limpiar-reportes-bug-clinica-norte.log 2>&1
```

**En hosting compartido con cPanel** (sin acceso directo a `crontab`): buscá "Cron Jobs" en el panel de cPanel. Ahí se configura igual (minuto, hora, comando), pero desde una interfaz web en vez de la terminal. El comando a pegar es el mismo que va después de la hora en las líneas de arriba (ej: `php /home/tu_usuario/public_html/hospital-regional/cron_limpiar_constancias.php`).

**Cómo confirmar que están corriendo:** después de que pase la hora programada, revisá el archivo de log correspondiente:

```bash
cat /var/log/limpiar-constancias.log
cat /var/log/limpiar-reportes-bug.log
```

Cada corrida deja una línea con la fecha y cuántos registros borró (aunque sea "0 constancias" o "0 reportes", eso confirma que el cron corrió).

### Certificado SSL (HTTPS)

El sistema maneja datos de salud, así que **es obligatorio** servir el sitio bajo HTTPS, no HTTP. Sin esto, además, no vas a poder instalar la app como PWA en el celular (el navegador lo bloquea en sitios sin HTTPS).

- **En cPanel:** buscá "SSL/TLS Status" o "AutoSSL" y activalo para el dominio — la mayoría de los hostings lo emiten gratis y automático (Let's Encrypt por debajo). Si después de unos minutos no se activó solo, mirá si el dominio ya está apuntando bien al hosting (DNS propagado).
- **En VPS propio:** usá [Certbot](https://certbot.eff.org/) con Let's Encrypt. Comando típico en Ubuntu con Nginx: `sudo certbot --nginx -d tu-dominio.com`. Certbot también configura la renovación automática, así que no hay que repetirlo cada 90 días a mano.
- **Cómo confirmar que quedó bien:** entrá al sitio con `https://` y fijate que el navegador muestre el candado sin advertencias. Si entrás por `http://` debería redirigirte solo a `https://` — si no lo hace, revisá la configuración de tu servidor web (Nginx/Apache) para forzar esa redirección.

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

- Activá el certificado SSL (ver sección de arriba) — es obligatorio, no opcional, porque se transmiten datos de salud.
- Hacé backups periódicos de la base de datos y de la carpeta `adjuntos/`.
- Los PIN y la clave de Apoderado se guardan encriptados, nunca en texto plano.
- El aislamiento entre profesionales está aplicado en el servidor, no solo en la pantalla.
- La carpeta `adjuntos/` tiene un `.htaccess` que impide ejecutar archivos subidos como código.
- El link de confirmación de turno es público a propósito, pero solo expone datos de esa cita puntual, y cada link es único e impredecible.
- El acceso de Apoderado es visible en la pantalla de login (botón "Apoderado"), protegido por su propia clave.

Cualquier ajuste que necesites lo podemos ir sumando.

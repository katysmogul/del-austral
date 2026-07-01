# Panel Maestro — Guía de instalación

El Panel Maestro es una herramienta separada del sistema "Del Austral" (o como se llame cada institución cliente): sirve para crear instituciones nuevas con un formulario, sin tener que hacerlo a mano por SSH/phpMyAdmin cada vez.

**Importante:** este panel asume que ya tenés el sistema principal funcionando en `/var/www/neptuno/` (o la ruta equivalente en tu servidor) — el panel maestro vive como una subcarpeta dentro de esa misma instalación.

---

## Paso 1 — Crear el usuario MySQL de administración

Este es el paso de seguridad más importante. En vez de usar la contraseña de `root` de MySQL (que tiene acceso a absolutamente todo el servidor), creamos un usuario dedicado con permisos acotados a solo lo necesario: crear bases de datos y usuarios nuevos.

Conectate a MySQL como root:

```bash
mysql -u root -p
```

Y corré (reemplazando `TU_CONTRASEÑA_NUEVA_AQUI` por una contraseña fuerte y nueva, distinta a cualquier otra que ya uses):

```sql
CREATE USER 'panel_maestro'@'localhost' IDENTIFIED BY 'TU_CONTRASEÑA_NUEVA_AQUI';
GRANT CREATE, DROP ON *.* TO 'panel_maestro'@'localhost';
GRANT CREATE USER ON *.* TO 'panel_maestro'@'localhost';
GRANT GRANT OPTION ON *.* TO 'panel_maestro'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Guardá esa contraseña en un lugar seguro — la vas a necesitar en el Paso 3.

## Paso 2 — Crear la base de datos propia del panel maestro

Esta es una base de datos chica y separada, solo para guardar tu clave de Super Admin y el listado de instituciones que vayas creando. No tiene nada que ver con las bases de datos de cada cliente.

```bash
mysql -u root -p -e "CREATE DATABASE panel_maestro_db CHARACTER SET utf8mb4;"
mysql -u root -p -e "CREATE USER 'panel_maestro_db_user'@'localhost' IDENTIFIED BY 'OTRA_CONTRASEÑA_NUEVA';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON panel_maestro_db.* TO 'panel_maestro_db_user'@'localhost';"
mysql -u root -p -e "FLUSH PRIVILEGES;"
mysql -u root -p panel_maestro_db < database_panel_maestro.sql
```

(Si preferís, podés usar el mismo usuario `panel_maestro` del Paso 1 para esto también — la separación en dos usuarios es opcional, pero más prolija.)

## Paso 3 — Subir los archivos y configurar

Subí toda la carpeta `panel-maestro/` dentro de tu instalación principal (junto a `index.html`, `api/`, etc., no dentro de ninguna subcarpeta).

Abrí `panel-maestro/config/config_maestro.php` y completá las 4 secciones:

```php
// Base de datos propia del panel maestro (Paso 2)
define('DB_HOST', 'localhost');
define('DB_NAME', 'panel_maestro_db');
define('DB_USER', 'panel_maestro_db_user');
define('DB_PASS', 'la contraseña que elegiste en el Paso 2');

// Usuario administrador de MySQL (Paso 1)
define('DB_ADMIN_HOST', 'localhost');
define('DB_ADMIN_USER', 'panel_maestro');
define('DB_ADMIN_PASS', 'la contraseña que elegiste en el Paso 1');

// Un secreto propio, DISTINTO al de cualquier institución
define('APP_SECRET_MAESTRO', 'escribí aquí un texto largo y random único');

// La URL pública de tu servidor (sin barra al final)
define('URL_BASE', 'https://neptuno.delaustral.com');
```

## Paso 4 — Primer ingreso

Entrá a `https://tudominio.com/panel-maestro/`. La primera vez te va a pedir crear tu clave de Super Admin (mínimo 6 caracteres) — después de eso, vas a ver el dashboard con el formulario para crear instituciones.

## Cómo funciona crear una institución

1. Escribís el nombre (ej: "Hospital Regional") y, opcionalmente, el nombre de carpeta que querés (si lo dejás vacío, se genera automáticamente del nombre).
2. Al tocar "Crear", el sistema hace, en este orden:
   - Crea una base de datos MySQL nueva, con un nombre único generado al azar.
   - Crea un usuario MySQL nuevo, con acceso solo a esa base de datos (no puede ver ni tocar la base de ninguna otra institución, ni la del panel maestro).
   - Importa el esquema completo (database.sql) en esa base nueva.
   - Copia todos los archivos del sistema a una carpeta nueva (/nombre-elegido/).
   - Genera el config/config.php de esa institución con las credenciales recién creadas.
   - Guarda el nombre de la institución, para que aparezca en toda la interfaz en vez de "Del Austral".
3. Te muestra la URL final. Entrando ahí, el cliente ve la pantalla de "primera vez" — crea su propia clave de Desarrollador, y arranca a usar el sistema normalmente, como cualquier instalación nueva.

Si algo falla a mitad de camino (por ejemplo, se cae la conexión justo después de crear la base de datos pero antes de terminar), el sistema intenta deshacer automáticamente lo que se llegó a crear, para no dejar bases de datos o usuarios MySQL sueltos sin usar.

## Suspender o reactivar una institución

Desde la lista de instituciones, el botón "Suspender" no borra nada — simplemente renombra el config.php de esa institución a config.php.suspendido, lo cual hace que el sitio deje de poder conectarse a su base de datos (y por lo tanto, deje de funcionar) hasta que la reactives. Útil para clientes que dejan de pagar, sin tener que borrar sus datos.

## Seguridad

- El usuario panel_maestro de MySQL solo puede crear/borrar bases y usuarios — no puede leer el contenido de ninguna base de datos existente (ni la de Del Austral, ni la de ninguna institución, ni la de Gitea si tenés uno en el mismo servidor).
- Cada institución tiene su propio usuario MySQL, con acceso exclusivo a su propia base — ninguna institución puede ver los datos de otra, ni aunque haya una vulnerabilidad en el código del sistema cliente.
- La URL del panel maestro no se anuncia en ningún lado del sistema cliente — solo la conocés vos.
- El .gitignore del repositorio principal está armado con un esquema de "lista blanca": si sincronizás con git/Gitea, ninguna institución nueva se sube por error, ni tampoco las credenciales del propio panel maestro.

## Antes de usarlo con clientes reales

Este panel hace operaciones de administración de MySQL en producción (CREATE DATABASE, CREATE USER) directamente desde código PHP expuesto a internet. Aunque está protegido por tu clave de Super Admin, vale la pena:

- Usar una clave larga y única, que no uses en ningún otro lado.
- Considerar restringir el acceso a /panel-maestro/ por IP en la configuración de Nginx, si siempre vas a entrar desde la misma conexión.
- Hacer un backup de tu servidor antes de la primera vez que crees una institución real, para tener margen de error mientras probás el flujo completo.

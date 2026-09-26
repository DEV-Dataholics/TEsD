# Despliegue en Site5 / cPanel

Procedimiento verificado para instalar o actualizar **Tu evento, en orden** en Site5. Este documento refleja los pasos realmente ejecutados en el despliegue actual, no solo un plan teórico.

## Resumen del entorno actual (producción)

- Dominio: `tueventosindramas.dataholics.com.mx`, servido detrás de Cloudflare (TLS en el borde).
- Base de datos: MySQL en el mismo hosting, host interno `shared63.accountservergroup.com` (no `localhost`, porque las conexiones administrativas se hacen desde fuera del servidor).
- El origen (el propio servidor Site5) **no tiene certificado TLS propio verificado**; el navegador ve `https://` gracias a Cloudflare, no al servidor directamente.
- Acceso de archivos: cuenta FTP dedicada, solo **FTP sin cifrar** (ver limitación abajo).

## 1. Preparar la base de datos

1. Crea la base y el usuario MySQL en cPanel; asigna el usuario a la base con todos los privilegios.
2. Si es una instalación nueva, importa `database/schema.sql` directamente.
3. Si ya existe la base histórica de listas familiares (`events`, `families`, `family_members`):
   1. Haz un respaldo. En este proyecto se generó un respaldo mínimo con un script PHP ad-hoc que exporta esas tres tablas a `INSERT` statements (no se dejó en el repo por ser específico de un respaldo puntual; para respaldos recurrentes usa `mysqldump` si está disponible, o el exportador de phpMyAdmin en cPanel).
   2. Ejecuta `php scripts/migrate-multiuser.php`. Es **idempotente**: crea las tablas nuevas, deja `owner_user_id` nullable temporalmente y no falla si ya se ejecutó antes.
   3. Crea la cuenta administradora (paso 2 de este documento).
   4. Vuelve a ejecutar `php scripts/migrate-multiuser.php`. En esta segunda pasada: asigna los eventos existentes al administrador, copia los integrantes al roster y crea invitaciones `pending`, y finalmente vuelve `owner_user_id` obligatorio (`NOT NULL`).

> **Nota técnica:** si tu MySQL rechaza el paso que hace `owner_user_id NOT NULL` con el error `Cannot change column ... used in a foreign key constraint`, asegúrate de usar la versión del script que elimina la restricción `fk_events_owner` antes del cambio de columna y la vuelve a crear después. Este bug ya fue corregido en el repositorio.

## 2. Crear la cuenta administradora

1. Entra a **cPanel Terminal** (no un navegador) sobre un checkout privado del proyecto, fuera de `public_html`.
2. Ejecuta `php scripts/bootstrap-admin.php`.
3. Escribe la contraseña cuando se solicite; el prompt no la muestra en pantalla y el script no la acepta como argumento.
4. El script falla intencionalmente si la cuenta `development@dataholics.com.mx` ya existe — no sirve para restablecer contraseñas.
5. Después de confirmar que la cuenta funciona (inicia sesión una vez), **elimina `scripts/bootstrap-admin.php` del servidor**.

## 3. Configurar variables de entorno

Crea (o actualiza) estos archivos **fuera** de `public_html`, en `credentials/`:

**`credentials/database.env`**
```
DB_HOST=<host interno de MySQL, no siempre "localhost">
DB_NAME=<nombre de la base>
DB_USER=<usuario de la base>
DB_PASSWORD=<contraseña>
```

**`credentials/application.env`**
```
APP_BASE_URL=https://tueventosindramas.dataholics.com.mx
APP_ALLOWED_ORIGINS=https://tueventosindramas.dataholics.com.mx
APP_COOKIE_SECURE=true
APP_MAIL_FROM=tueventosindramas@dataholics.com.mx
```

> **Importante:** `APP_ALLOWED_ORIGINS` y `APP_BASE_URL` deben usar el protocolo que el **navegador** realmente ve, no el que ve el servidor de origen. En este proyecto, aunque el servidor de origen no tiene TLS propio, Cloudflare presenta el sitio como `https://` al público — por eso estas variables deben ir en `https://`, o el login falla con `403 Origen no permitido` aunque las credenciales sean correctas.

Añade `OPENAI_API_KEY` solo si vas a activar el OCR.

## 4. Compilar y subir el frontend y la API

1. Desde `frontend/`: `npm ci` y luego `npm run build`. Esto genera `frontend/dist/`.
2. Sube el **contenido** de `frontend/dist/` (no la carpeta en sí) a la raíz del documento del subdominio.
3. Sube la carpeta `api/` completa a `<raíz>/api/`, **excepto**:
   - `api/router.php` (solo se usa para desarrollo local con `php -S`).
   - `api/.env.example` (es solo plantilla).
4. No subas `node_modules/`, `credentials/` con datos reales al alcance de `public_html`, ni exportaciones SQL.
5. Sube `credentials/database.env` y `credentials/application.env` a una carpeta `credentials/` **dentro del document root** solo si tu configuración de hosting no permite colocarla un nivel arriba; en ese caso protégela con un `.htaccess` con `Require all denied` (ver [seguridad de credentials/](#5-proteger-credentials-si-queda-dentro-del-document-root) abajo). Preferible: colocarla en `/home/CPANEL_USER/credentials/`, fuera de `public_html`, y ajustar la ruta en `api/config.php` si es necesario.

## 5. Proteger `credentials/` si queda dentro del document root

Si por restricciones del hosting `credentials/` termina dentro del document root, agrega un archivo `.htaccess` en esa carpeta con:

```
Require all denied
```

Y verifica que `https://tu-dominio/credentials/database.env` devuelva `403 Forbidden`, no el contenido del archivo.

## 6. Verificación posterior al despliegue

Ejecuta estas comprobaciones en orden:

1. `GET https://tu-dominio/api/health` → debe responder `{"status":"ok","database":"connected"}`.
2. `GET https://tu-dominio/credentials/database.env` → debe responder `403 Forbidden`.
3. `GET https://tu-dominio/api/config.php` → debe responder `403 Forbidden`.
4. Cargar la página principal → debe mostrar la pantalla de acceso, sin errores de consola distintos a un `401` inicial esperado (antes de iniciar sesión).
5. Iniciar sesión con la cuenta administradora → debe mostrar el panel de eventos, no un error de `Origen no permitido`.
6. Disparar `/api/auth/forgot-password` o registrar una cuenta de prueba → confirmar que el correo llega a una bandeja real (no solo que la API responde `202`).

## Limitación conocida de FTP

La cuenta FTP configurada (`dev_TESD@tueventosindramas.dataholics.com.mx` en `ftp.dataholics.com.mx:21`) **rechaza tanto `AUTH TLS` como `AUTH SSL`** con el error `504 Command not implemented for that parameter`. Esto significa que, con esta cuenta, **no es posible FTPS** (FTP cifrado); solo FTP en texto plano.

Recomendaciones, de más a menos preferible:

1. **Usar cPanel File Manager** sobre HTTPS para subir archivos — evita el problema por completo.
2. **Pedir a Site5/soporte del hosting** que habilite FTPS en esa cuenta, o que indique el endpoint correcto si existe uno distinto.
3. Si ninguna de las anteriores es posible y se decide subir por FTP sin cifrar, hacerlo solo con autorización explícita del responsable del proyecto, sabiendo que la contraseña viaja sin cifrar por la red durante esa transferencia. Cambiar la contraseña de esa cuenta FTP después de un uso así reduce el riesgo si la red no era confiable.

## Actualizaciones posteriores (cambios de código)

Para desplegar un cambio de código después de la instalación inicial:

1. Repite el paso 4 (compilar y subir) solo para los archivos que cambiaron.
2. Si el cambio incluye una migración de base de datos nueva, escribe un script idempotente similar a `scripts/migrate-multiuser.php` (verifica antes de alterar, nunca asume que la tabla/columna no existe).
3. Vuelve a correr la verificación de la sección 6.

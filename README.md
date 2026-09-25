# Tu evento, en orden

MVP multiusuario para administrar eventos propios, un roster privado de personas e invitaciones con RSVP individual. El frontend React se compila a archivos estáticos; la API usa PHP 8+, PDO y MySQL para hosting compartido con cPanel.

## Estructura

- `database/schema.sql`: esquema de usuarios, sesiones, eventos, roster, invitaciones, auditoría y tablas familiares históricas.
- `scripts/migrate-multiuser.php`: migración repetible de la base familiar anterior. No ejecutarla en producción sin respaldo probado.
- `scripts/bootstrap-admin.php`: alta CLI de un solo uso para `development@dataholics.com.mx`; contraseña interactiva oculta, almacenada solo como hash.
- `api/`: API REST en PHP con sesiones HttpOnly/CSRF, aislamiento por usuario, PDO, correo de verificación/RSVP y OCR opcional con OpenAI Responses API.
- `frontend/`: aplicación React/Vite adaptable a móvil.
- `credentials/`: secretos locales ignorados por Git. No subir esta carpeta a `public_html`.

## Requisitos

- MySQL 5.7+ o MariaDB compatible con InnoDB y `utf8mb4`.
- PHP 8.1+ con `pdo_mysql`, `fileinfo`, `mbstring`, `curl` y OpenSSL. PHP `mail()` debe estar configurado para verificación y recuperación de cuenta.
- Apache/LiteSpeed con `mod_rewrite` o reglas compatibles de `.htaccess`.
- Node.js 18+ y npm para compilar el frontend.
- HTTPS habilitado en producción; las sesiones usan cookie `HttpOnly`, `SameSite=Lax` y CSRF.

## Configuración local

1. Para una base nueva, importa `database/schema.sql`. No crea usuarios ni eventos de ejemplo; el admin crea el primer evento desde la aplicación.
2. Para la base existente con familias, sigue primero el procedimiento de migración de abajo; no uses `schema.sql` como sustituto de esa migración.
3. Configura `credentials/database.env` con `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`. `localhost` solo apunta al equipo donde corre PHP.
4. Crea `credentials/application.env` a partir de `api/.env.example`. Define `APP_BASE_URL`, `APP_ALLOWED_ORIGINS`, `APP_COOKIE_SECURE` y `APP_MAIL_FROM`; añade `OPENAI_API_KEY` solo si activarás OCR. No guardes secretos en el repo.
5. Desde una terminal Linux interactiva en Site5, ejecuta `php scripts/bootstrap-admin.php`. Escribe y confirma la contraseña en el prompt oculto; no la pases como argumento. El script crea el admin activo y no restablece cuentas existentes.
6. Para desarrollo local, desde `proyectos/` inicia la API:

   ```powershell
   cd tueventosindramas
   php -S 127.0.0.1:8000 api/router.php
   ```

7. En otra terminal inicia Vite:

   ```powershell
   cd tueventosindramas/frontend
   npm ci
   npm run dev
   ```

8. Abre la URL local de Vite e inicia sesión con el correo del admin y la contraseña introducida en el bootstrap.

Para migrar la base con familias existente, exporta un respaldo y verifica que pueda restaurarse en una copia antes de tocar Site5. Ejecuta `php scripts/migrate-multiuser.php`; crea las tablas y deja `owner_user_id` temporalmente nullable. Crea el admin con el bootstrap y vuelve a ejecutar el migrador: asigna los eventos existentes al admin, copia integrantes al roster e inserta invitaciones `pending`, conservando sin cambios el `status` y `actual_count` familiar histórico. Reejecutar es seguro. Después de confirmar el admin, elimina `scripts/bootstrap-admin.php` del servidor.

## API

Las rutas privadas requieren la cookie de sesión; las operaciones mutables también requieren CSRF. Registro, login, verificación, recuperación y RSVP público tienen rutas dedicadas.

| Método | Ruta | Uso |
| --- | --- | --- |
| `GET` | `/api/health` | Comprueba conexión a MySQL; no requiere sesión. |
| `POST` | `/api/auth/register` | Crea una cuenta normal pendiente de verificación. |
| `POST` | `/api/auth/verify` | Verifica el correo con un token de un solo uso. |
| `POST` | `/api/auth/login` | Inicia sesión y devuelve CSRF; crea cookie HttpOnly. |
| `GET` / `POST` | `/api/auth/me`, `/api/auth/logout` | Consulta usuario/CSRF o cierra la sesión. |
| `POST` | `/api/auth/resend-verification` | Reenvía la verificación de una cuenta pendiente. |
| `POST` | `/api/auth/forgot-password`, `/api/auth/reset-password` | Recuperación mediante token de un solo uso. |
| `GET` / `POST` | `/api/events` | Lista eventos propios o crea uno bajo el usuario autenticado. |
| `PATCH` / `DELETE` | `/api/events/{id}` | Edita/elimina un evento propio. |
| `GET` / `POST` | `/api/contacts` | Lista o agrega contactos del roster propio. |
| `PATCH` / `DELETE` | `/api/contacts/{id}` | Edita o elimina un contacto; las invitaciones conservan snapshots. |
| `GET` / `POST` | `/api/events/{id}/invitations` | Lista invitaciones o invita personas del roster. |
| `POST` | `/api/invitations/{id}/link` | Rota y devuelve un enlace RSVP. |
| `DELETE` | `/api/invitations/{id}` | Revoca la invitación y su enlace. |
| `POST` | `/api/rsvp/lookup`, `/api/rsvp/respond` | Consulta o responde usando token en el cuerpo JSON. |
| `GET` / `PATCH` | `/api/admin/users`, `/api/admin/users/{id}` | Lista y gestiona cuentas; solo admin y auditado. |
| `GET` | `/api/admin/audit` | Consulta acciones administrativas globales. |
| `GET` / `POST` | `/api/families`, `/api/members` | Accede a las listas familiares históricas bajo el propietario del evento. |
| `POST` | `/api/upload-ocr` | Recibe una imagen y crea familias históricas como borrador. |

Las rutas privadas requieren cookie de sesión; las mutaciones requieren CSRF. El admin tiene acceso global y cada acceso cross-tenant se escribe en `audit_logs`. Las invitaciones son individuales; sus tokens aleatorios se almacenan como hash, se pueden revocar/rotar y viajan en el fragmento `#rsvp=...`, nunca en la petición inicial ni en logs de URL. Vencen siete días después del evento o en 90 días si no hay fecha.

El OCR acepta JPG, PNG o WebP de hasta 10 MB. Las imágenes se envían al proveedor OpenAI configurado en el backend; no se procesan en el navegador. Revisa el resultado antes de confirmar invitados. El uso requiere `OPENAI_API_KEY`, extensión PHP `curl`, salida HTTPS disponible desde el hosting y puede generar cargos en la cuenta del proveedor.

## Despliegue en Site5 / cPanel

1. Crea una base y un usuario MySQL en cPanel; asigna el usuario a la base. Para la base actual, sigue el procedimiento de migración local con respaldo indicado arriba.
2. Respalda la base y prueba la migración en una copia restaurada. No ejecutes scripts de migración contra producción sin aprobar previamente el respaldo y el resultado de la prueba.
3. Crea `/home/CPANEL_USER/credentials/`, fuera de `public_html`, y coloca ahí `database.env` y `application.env`. Desde `public_html/api`, el loader busca `../../credentials/`; también acepta variables de entorno del servidor.
4. Coloca temporalmente un checkout del proyecto en un directorio privado bajo `/home/CPANEL_USER/` (incluye `api/`, `database/` y `scripts/`, nunca dentro de `public_html`). Desde ahí ejecuta la migración y el bootstrap admin en cPanel Terminal, no desde el navegador. Introduce la contraseña solo en el prompt oculto. Elimina `scripts/bootstrap-admin.php` del checkout después de confirmar la cuenta.
5. Desde `frontend/`, ejecuta `npm ci` y `npm run build`. Sube **el contenido** de `frontend/dist/` a la raíz del subdominio y `api/` a `public_html/api/`. No subas `node_modules/`, credenciales ni exportaciones SQL.
6. Define `APP_BASE_URL=https://tu-subdominio`, `APP_ALLOWED_ORIGINS` con ese mismo origen, `APP_COOKIE_SECURE=true` y un `APP_MAIL_FROM` válido. Confirma que el PHP de Site5 puede enviar correo y que `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, OpenSSL y `mod_rewrite` están disponibles.
7. Activa SSL. `GET https://tu-subdominio/api/health` debe indicar que MySQL está conectado. Verifica registro, correo, login, creación de evento, invitación y RSVP.

El correo transaccional usa `mail()` de PHP y depende de que Site5 tenga el transporte saliente configurado y permita el remitente indicado; no se ha integrado un SMTP autenticado externo. El OCR sigue siendo opcional y requiere `OPENAI_API_KEY` y `curl`.

No subas `credentials/`, archivos `.env` reales ni exportaciones de base de datos al document root. El FTP configurado actualmente rechaza `AUTH TLS`; hasta que el proveedor lo habilite o indique el endpoint correcto, usa cPanel File Manager/Terminal sobre HTTPS para cargar los archivos, no FTP sin cifrar.

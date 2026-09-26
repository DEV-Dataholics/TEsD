# Guía técnica — Tu evento, en orden

Documentación para desarrolladores que mantienen o extienden el proyecto. Ver también [README.md](../README.md) en la raíz para instrucciones rápidas de instalación local.

## 1. Arquitectura

```
frontend/   React + Vite → se compila a HTML/CSS/JS estáticos
api/        PHP 8+ (sin framework), PDO, sesiones propias, sin dependencias externas
database/   Esquema MySQL versionado (schema.sql)
scripts/    Herramientas CLI de una sola vez: migración y bootstrap del admin
credentials/  Secretos locales, ignorado por Git; nunca se sube a public_html
```

El frontend llama a rutas relativas `/api/*`, por lo que frontend y API deben servirse desde el **mismo origen** (mismo dominio y protocolo). No hay build-time config de URL de API.

## 2. Esquema de base de datos

Ver [database/schema.sql](../database/schema.sql) para el DDL completo. Resumen de tablas:

| Tabla | Propósito |
| --- | --- |
| `users` | Cuentas: rol (`user`/`admin`), estado (`pending`/`active`/`disabled`), hash de contraseña. |
| `sessions` | Sesiones activas; solo se guarda el hash del token de cookie, nunca el token en claro. |
| `account_tokens` | Tokens de un solo uso para verificación de correo y recuperación de contraseña (hasheados, con vencimiento). |
| `auth_rate_limits` | Límite de intentos por identificador (correo/IP) en rutas públicas sensibles. |
| `events` | Eventos, con `owner_user_id` obligatorio — todo evento pertenece a un usuario. |
| `contacts` | Roster reutilizable de personas por usuario, independiente de cualquier evento. |
| `event_invitations` | Invitaciones por evento y contacto, con token de respuesta hasheado y vencimiento. |
| `families` / `family_members` | Tablas históricas del sistema anterior (listas familiares sin cuentas); se conservan para no perder datos de producción. |
| `audit_logs` | Registro de acciones administrativas cross-tenant. |
| `schema_migrations` | Bitácora de migraciones aplicadas (ver `scripts/migrate-multiuser.php`). |

**Regla de aislamiento:** ninguna consulta a `families`, `family_members`, `contacts` o `event_invitations` debe resolverse solo con el ID recibido del cliente. Siempre se valida que el `event_id` (o el recurso derivado de él) pertenezca a `owner_user_id = sesión_actual`, salvo que el usuario autenticado tenga `role = admin` (y en ese caso se escribe en `audit_logs`).

## 3. Autenticación y sesiones

- Login exitoso crea una fila en `sessions` y una cookie `HttpOnly`, `SameSite=Lax`, marcada `Secure` cuando `APP_COOKIE_SECURE=true`.
- Toda mutación (`POST`/`PATCH`/`DELETE`) requiere el token CSRF devuelto en `/api/auth/login` o `/api/auth/me`.
- El middleware de origen (`api/security.php::requireSameOrigin`) exige que el header `Origin` coincida con `APP_ALLOWED_ORIGINS` o `APP_BASE_URL` para toda escritura pública y para `/auth/*` no-`GET`. Los `GET` de solo lectura no lo exigen, porque no todos los navegadores envían `Origin` en `GET`.
- Los tokens de verificación de correo, recuperación de contraseña y RSVP viajan en el **fragmento de URL** (`#verify=...`, `#reset=...`, `#rsvp=...`), no en la ruta ni en query string, para que no queden en logs de servidor ni en el header `Referer`. El frontend los lee del fragmento y los envía en el cuerpo JSON de la petición.

## 4. Referencia de la API

Todas las rutas cuelgan de `/api`. Ver la tabla completa en el [README.md](../README.md#api) de la raíz — se mantiene ahí como fuente única para evitar que este documento se desalinee del código.

Puntos clave no obvios:

- `/api/events/{id}/invitations` (POST) solo acepta contactos que ya existen en el roster del usuario autenticado; no crea invitados nuevos "al vuelo".
- `/api/invitations/{id}/link` (POST) **rota** el token de respuesta: genera uno nuevo y el anterior deja de funcionar. Es la única forma de recuperar un enlace si se perdió.
- `/api/rsvp/lookup` y `/api/rsvp/respond` son públicas (no requieren sesión) y reciben el token en el cuerpo JSON, no en la URL.
- `/api/admin/users` y `/api/admin/audit` verifican `role = admin` en el servidor; el frontend oculta esas vistas para usuarios normales, pero la autorización real vive en la API.

## 5. OCR (opcional)

`POST /api/upload-ocr` envía la imagen al proveedor configurado en `OPENAI_API_KEY` (Responses API). Requisitos:

- Extensión PHP `curl` habilitada.
- Salida HTTPS saliente permitida por el hosting.
- Acepta JPG/PNG/WebP hasta 10 MB.

Si `OPENAI_API_KEY` no está definida, la ruta responde con un error explícito en vez de fallar silenciosamente.

## 6. Correo transaccional

`api/security.php::sendAccountEmail()` usa la función nativa `mail()` de PHP, sin librería SMTP externa. Depende de que el hosting tenga configurado el transporte saliente (Site5 ya lo tiene operativo para este dominio, verificado con entregas reales a Gmail y al mismo dominio). El remitente se toma de `APP_MAIL_FROM`.

Si en el futuro se requiere mayor confiabilidad (reintentos, colas, DKIM propio por subdominio), habría que introducir un SMTP autenticado (por ejemplo, PHPMailer + credenciales SMTP) en lugar de `mail()`.

## 7. Seguridad

- `credentials/` nunca se commitea (ver `.gitignore`) ni se sube dentro de `public_html`; en producción vive un nivel arriba del document root.
- `api/config.php::loadPrivateEnvironment()` solo carga una allowlist fija de variables (`DB_*`, `APP_*`, `OPENAI_*`) — no ejecuta ni interpreta el archivo `.env` como código.
- `api/.htaccess` bloquea el acceso HTTP directo a `config.php`.
- Las contraseñas se hashean con `password_hash()` (bcrypt/Argon2 según PHP); nunca se guardan ni se registran en logs en texto plano.
- Los scripts de migración/bootstrap (`scripts/`) están pensados para ejecutarse desde un checkout privado fuera de `public_html`, nunca vía navegador.

## 8. Pruebas y validación

No hay suite automatizada de tests todavía. La validación actual es:

- `php -l` sobre cada archivo PHP modificado.
- `npm run build` (Vite) para detectar errores de JSX/importaciones.
- Pruebas manuales end-to-end contra producción: login, health check, envío de correo (verificado a dos destinatarios reales), aislamiento de datos por usuario.

Si agregas una suite de pruebas, documenta aquí cómo ejecutarla.

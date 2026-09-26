# Manual Técnico — Tu Evento sin Dramas

> **Objetivo:** Guía operativa de referencia técnica para ingenieros de software, DevOps y soporte nivel 2/3 (K-2SO / TiRA).  
> **Compañía:** `tueventosindramas` (Alias: `tesd`)  
> **Repositorio:** `DEV-Dataholics/TEsD`  

---

## 1. Guía de Ejecución y Entorno de Desarrollo Local

### 1.1 Prerrequisitos
- PHP 8.1 o superior con extensiones: `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`.
- MySQL 5.7+ o MariaDB compatible.
- Node.js 18+ y npm.

### 1.2 Arranque del Servidor Backend
En la raíz del proyecto local:
```powershell
# Levantar el servidor embebido de PHP usando el enrutador local
php -S 127.0.0.1:8000 api/router.php
```

### 1.3 Arranque de la Interfaz React (Vite)
En un terminal paralelo:
```powershell
cd frontend
npm ci
npm run dev
```

---

## 2. Configuración y Variables de Entorno

Las variables de entorno se leen desde dos archivos alojados en `credentials/` (fuera de `public_html` en producción):

### 2.1 `credentials/database.env`
```ini
DB_HOST=shared63.accountservergroup.com
DB_NAME=noodluis_TESD
DB_USER=noodluis_TESD
DB_PASSWORD="<PROD_OR_LOCAL_PASSWORD>"
```

### 2.2 `credentials/application.env`
```ini
APP_BASE_URL=https://tueventosindramas.dataholics.com.mx
APP_ALLOWED_ORIGINS=https://tueventosindramas.dataholics.com.mx
APP_COOKIE_SECURE=true
APP_MAIL_FROM=tueventosindramas@dataholics.com.mx
OPENAI_API_KEY=sk-... # Opcional: solo necesario si se activa la digitalización de listas vía OCR
```

---

## 3. Seguridad de Sesiones, Tokens y Criptografía

1. **Sesiones de Usuario:**
   - La autenticación crea una fila en la tabla `sessions`.
   - Se emite una cookie HTTP con flag `HttpOnly`, `SameSite=Lax` y `Secure` (cuando `APP_COOKIE_SECURE=true`).
   - El token de la cookie viaja al cliente, pero en la base de datos se almacena **únicamente el hash SHA-256** (`token_hash`).
2. **Protección CSRF:**
   - Toda solicitud HTTP con mutación de estado (`POST`, `PATCH`, `DELETE`) exige el header `X-CSRF-Token`.
   - El token CSRF se compara calculando su SHA-256 contra el `csrf_token_hash` registrado en la sesión activa.
3. **Validación de Origen (`requireSameOrigin`):**
   - El backend valida el encabezado `Origin`. Si difiere de `APP_ALLOWED_ORIGINS` o `APP_BASE_URL`, rechaza con `403 Origen no permitido`.
4. **Protección contra Fuerza Bruta (`auth_rate_limits`):**
   - Las rutas públicas sensibles (`/api/auth/login`, `/api/auth/register`, `/api/auth/forgot-password`) aplican límites de ventana temporal por hash de IP/correo para evitar ataques de diccionario.
5. **Ciclo de Vida de Invitaciones y Tokens RSVP:**
   - Cada enlace de invitación tiene un token criptográfico pseudoaleatorio de 32 bytes en formato hex.
   - En base de datos se guarda como `response_token_hash` (`CHAR(64)`).
   - Vencimiento por defecto: 7 días posteriores a la fecha del evento, o 90 días naturales si el evento no tiene fecha fijada.
   - Rotación (`POST /api/invitations/{id}/link`): regenera el token e invalida de forma inmediata el hash anterior.

---

## 4. Procedimiento de Despliegue en Producción (Site5 / cPanel)

1. **Compilación Frontend:**
   ```bash
   cd frontend && npm ci && npm run build
   ```
2. **Transferencia Web:**
   - El contenido de `frontend/dist/*` se sincroniza a la raíz del subdominio `/public_html`.
   - El directorio `api/` se sube a `/public_html/api/` (excluyendo `router.php` y `.env.example`).
3. **Credenciales en Servidor:**
   - Deben ubicarse en `/home/CPANEL_USER/credentials/` (dos niveles arriba de `public_html/api`).
4. **Prueba de Verificación Inmediata:**
   ```bash
   curl -s -i https://tueventosindramas.dataholics.com.mx/api/health
   # Respuesta esperada: HTTP 200 {"status":"ok","database":"connected"}
   ```

---

## 5. Manejo de Migraciones y Scripts de Mantenimiento

- `database/schema.sql`: Esquema DDL canónico de todas las tablas e índices.
- `scripts/migrate-multiuser.php`: Script CLI idempotente que transforma esquemas heredados de listas familiares a la arquitectura multi-inquilino de cuentas y rosters.
- `scripts/bootstrap-admin.php`: Utilidad CLI interactiva para dar de alta la cuenta de superadministrador `development@dataholics.com.mx` sin dejar contraseñas en logs ni en argumentos. Debe eliminarse del servidor tras su uso.

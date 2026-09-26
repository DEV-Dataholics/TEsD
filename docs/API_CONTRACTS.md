# Contratos de API REST — Tu Evento sin Dramas

> **Base URL:** `https://tueventosindramas.dataholics.com.mx/api`  
> **Formato:** JSON (UTF-8)  
> **Seguridad:** Cookies `HttpOnly` para sesión (`te_session`), Header `X-CSRF-Token` para mutaciones (`POST`, `PATCH`, `DELETE`), y verificación de header `Origin`.

---

## 1. Endpoints Públicos y de Diagnóstico

### 1.1 `GET /api/health`
Comprobación de operatividad y conexión a base de datos MySQL.
- **Autenticación:** Ninguna.
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "ok",
  "database": "connected"
}
```

---

## 2. Endpoints de Autenticación y Cuentas

### 2.1 `POST /api/auth/register`
Registro de nuevo organizador de eventos.
- **Headers:** `Origin` requerido.
- **Payload:**
```json
{
  "display_name": "Ana Gómez",
  "email": "ana@ejemplo.com",
  "password": "MiPasswordSeguro123!"
}
```
- **Respuesta (201 Created):**
```json
{
  "status": "success",
  "message": "Cuenta creada. Revisa tu correo para confirmarla."
}
```

### 2.2 `POST /api/auth/verify`
Activación de cuenta con token de un solo uso extraído del hash `#verify=...`.
- **Payload:** `{"token": "string_32_bytes_hex"}`
- **Respuesta (200 OK):** `{"status": "success", "message": "Correo verificado exitosamente."}`

### 2.3 `POST /api/auth/login`
Inicio de sesión con credenciales.
- **Payload:** `{"email": "ana@ejemplo.com", "password": "..."}`
- **Cookies:** Establece `te_session` (`HttpOnly`, `SameSite=Lax`).
- **Respuesta (200 OK):**
```json
{
  "user": {
    "id": 12,
    "display_name": "Ana Gómez",
    "email": "ana@ejemplo.com",
    "role": "user"
  },
  "csrf_token": "a1b2c3d4..."
}
```

### 2.4 `GET /api/auth/me`
Verifica la sesión actual y devuelve los datos del usuario y nuevo token CSRF.
- **Respuesta (200 OK):** Mismo formato que login.
- **Respuesta no autenticado (401 Unauthorized):** `{"error": "No autenticado"}`

### 2.5 `POST /api/auth/logout`
Cierra la sesión activa invalidando la cookie y el registro en la tabla `sessions`.
- **Respuesta (200 OK):** `{"status": "success"}`

---

## 3. Endpoints de Eventos

### 3.1 `GET /api/events`
Obtiene los eventos creados por el usuario autenticado.
- **Respuesta (200 OK):**
```json
{
  "events": [
    {
      "id": 1,
      "event_name": "Boda Mariana & Carlos",
      "event_date": "2026-11-15",
      "invitations_count": 85,
      "confirmed_count": 62,
      "created_at": "2026-09-01 10:00:00"
    }
  ]
}
```

### 3.2 `POST /api/events`
Crea un nuevo evento asignado al `owner_user_id` de la sesión.
- **Headers:** `X-CSRF-Token`
- **Payload:**
```json
{
  "event_name": "XV Años Valeria",
  "event_date": "2026-12-05"
}
```
- **Respuesta (201 Created):** `{"event": {"id": 2, "event_name": "XV Años Valeria", ...}}`

### 3.3 `PATCH /api/events/{id}` / `DELETE /api/events/{id}`
Modifica o elimina un evento perteneciente al usuario.

---

## 4. Endpoints del Roster (Libreta de Contactos)

### 4.1 `GET /api/contacts`
Lista los contactos permanentes del usuario.
- **Respuesta (200 OK):**
```json
{
  "contacts": [
    {
      "id": 101,
      "name": "Roberto Morales",
      "email": "roberto@ejemplo.com",
      "age": 35,
      "type": "adult",
      "dietary_restrictions": "Sin mariscos",
      "notes": "Mesa cercana a pista"
    }
  ]
}
```

### 4.2 `POST /api/contacts`
Agrega una persona a la libreta de contactos.
- **Headers:** `X-CSRF-Token`
- **Payload:** Objeto con `name`, `type` ('adult' o 'child'), opcionales `email`, `age`, `dietary_restrictions`, `notes`.

---

## 5. Endpoints de Invitaciones y Enlaces RSVP

### 5.1 `GET /api/events/{id}/invitations`
Lista las invitaciones de un evento con su estado de confirmación.
- **Respuesta (200 OK):**
```json
{
  "invitations": [
    {
      "id": 501,
      "contact_id": 101,
      "guest_name": "Roberto Morales",
      "status": "accepted",
      "has_link": true,
      "responded_at": "2026-09-10 14:22:00"
    }
  ]
}
```

### 5.2 `POST /api/events/{id}/invitations`
Genera invitaciones asociando contactos del roster al evento.
- **Payload:** `{"contact_ids": [101, 102]}`

### 5.3 `POST /api/invitations/{id}/link`
Genera o rota el enlace RSVP para un invitado.
- **Respuesta (200 OK):**
```json
{
  "link": "https://tueventosindramas.dataholics.com.mx/#rsvp=9f8e7d6c5b4a..."
}
```

### 5.4 `DELETE /api/invitations/{id}`
Revoca la invitación e invalida cualquier enlace activo.

---

## 6. Endpoints Públicos de Confirmación RSVP (Invitado)

### 6.1 `POST /api/rsvp/lookup`
Consulta los datos del evento y del invitado usando el token del fragmento.
- **Payload:** `{"token": "9f8e7d6c5b4a..."}`
- **Respuesta (200 OK):**
```json
{
  "event_name": "Boda Mariana & Carlos",
  "event_date": "2026-11-15",
  "guest_name": "Roberto Morales",
  "status": "pending",
  "dietary_restrictions": "Sin mariscos"
}
```

### 6.2 `POST /api/rsvp/respond`
El invitado confirma o declina su asistencia.
- **Payload:**
```json
{
  "token": "9f8e7d6c5b4a...",
  "status": "accepted",
  "dietary_restrictions": "Sin mariscos, preferente ensalada"
}
```
- **Respuesta (200 OK):** `{"status": "success", "message": "Respuesta guardada con éxito."}`

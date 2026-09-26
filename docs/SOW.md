# Statement of Work & Project Charter — Tu Evento sin Dramas

> **Identificador:** `SOW-TESD-2026-01`  
> **Compañía / Producto:** Tu Evento sin Dramas (`tueventosindramas`)  
> **Cliente:** Plataforma Interna Dataholics  
> **Estado:** En Producción (MVP Multi-usuario Operativo)  

---

## 1. Alcance del Proyecto

1. **Gestión de Eventos y Privacidad Multi-usuario:**
   - Soporte para múltiples organizadores independientes.
   - Aislamiento estricto de eventos, listas y contactos mediante identificador de propietario (`owner_user_id`).
2. **Directorio Reutilizable de Contactos (Roster):**
   - Agenda centralizada para evitar capturas repetidas entre diferentes eventos.
   - Clasificación por tipo (Adulto/Niño), campos de restricciones alimentarias y notas.
3. **Flujo de Invitaciones y Confirmación (RSVP):**
   - Generación de enlaces únicos e individuales.
   - Seguridad mediante fragmentos de URL (`#rsvp=...`) que no dejan rastro en logs de servidores web.
   - Capacidad de revocación y rotación de enlaces.
4. **Herramientas de Captura Acelerada:**
   - Digitalización asistida de listas de invitados por fotografía mediante OpenAI Responses API.
5. **Panel de Administración y Auditoría:**
   - Vistas para superadministrador con trazabilidad en `audit_logs` de cualquier interacción cross-tenant.

---

## 2. Entorno y Criterios de Aceptación Cumplidos

- **Infraestructura:** Subdominio `https://tueventosindramas.dataholics.com.mx/` desplegado en hosting Site5 con terminación SSL en Cloudflare.
- **Base de Datos:** MySQL `noodluis_TESD` con esquema íntegro de migraciones.
- **Integración con TiRA / DevResolve:**
  - Registro en `managed_projects` (ID 7) y `companies` (ID 4).
  - Repositorio `DEV-Dataholics/TEsD` clonado en `/opt/tira-repos/TEsD` del nodo soberano K-2SO.
  - Base de conocimiento provisionada con especificación de arquitectura, manual de usuario, manual técnico y esquema de datos.

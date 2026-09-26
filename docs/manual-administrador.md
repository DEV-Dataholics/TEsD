# Manual del administrador

Este documento es para quien opera la cuenta administradora del sistema (por defecto, `development@dataholics.com.mx`). El rol de administrador tiene **acceso global** a todos los eventos, roster e invitaciones de todos los usuarios, y cada acceso fuera de tus propios datos queda registrado en la auditoría.

## 1. Qué puede hacer el administrador que un usuario normal no puede

| Capacidad | Dónde |
| --- | --- |
| Ver y administrar eventos, roster e invitaciones de **cualquier usuario** | Mismas pantallas que un usuario normal, pero sin restricción de propiedad. |
| Listar todas las cuentas del sistema | Panel de administración → **Usuarios**. |
| Cambiar el rol de una cuenta (`user` ↔ `admin`) | Panel de administración → **Usuarios** → editar cuenta. |
| Activar / desactivar una cuenta | Panel de administración → **Usuarios**. Una cuenta desactivada no puede iniciar sesión. |
| Consultar el registro de auditoría | Panel de administración → **Auditoría**. |

Cada vez que el administrador accede o modifica datos que pertenecen a otro usuario (no a sí mismo), la acción se guarda en la tabla `audit_logs` con el usuario administrador, la acción realizada y la fecha. Esto existe para que el acceso global sea trazable, no silencioso.

## 2. Gestión de cuentas

1. Entra al panel de administración.
2. En **Usuarios**, puedes ver: correo, nombre, rol, estado (activo/pendiente/desactivado) y fecha de registro.
3. Para **desactivar** una cuenta (por ejemplo, un usuario que abusa del sistema o que ya no debe tener acceso), cambia su estado a inactivo. Sus eventos e invitaciones no se borran, solo pierde la capacidad de iniciar sesión.
4. Para **promover** una cuenta a administrador, cambia su rol. Hazlo con cuidado: un segundo administrador también tendrá acceso global.

## 3. Cuenta administradora por defecto

- Correo: `development@dataholics.com.mx`
- Se creó una sola vez mediante `scripts/bootstrap-admin.php`, que pide la contraseña de forma oculta y la guarda solo como hash (nunca en texto plano, ni en el repositorio, ni en logs).
- El script **rechaza** volver a ejecutarse si esa cuenta ya existe; no sirve para restablecer la contraseña. Para eso, usa **Olvidé mi contraseña** en la pantalla de acceso, como cualquier otra cuenta.
- Por seguridad, el archivo `scripts/bootstrap-admin.php` debe eliminarse del servidor de producción después de confirmar que la cuenta funciona (ver [despliegue-site5.md](./despliegue-site5.md)).

## 4. Migración de datos históricos

El sistema anterior (una sola clave compartida, sin cuentas) tenía un evento y listas familiares. Al migrar:

- Los eventos existentes se asignaron automáticamente a la cuenta administradora.
- Los integrantes de familias se copiaron al roster del administrador.
- Se crearon invitaciones en estado `pending` para cada integrante, sin inventar respuestas de asistencia que nunca se dieron.
- El estado (`draft`/`confirmed`) y el conteo real (`actual_count`) de cada familia histórica se conservaron sin cambios.

Si necesitas reasignar ese evento histórico a otro usuario, puedes hacerlo manualmente desde el panel (edición de evento) una vez que esa persona tenga su propia cuenta.

## 5. Configuración de correo

- El remitente configurado es `tueventosindramas@dataholics.com.mx`.
- Verificado: entrega a bandeja de entrada tanto en el mismo dominio como en Gmail (no cae en spam en las pruebas realizadas).
- Si cambias el remitente en el futuro, prueba de nuevo la entrega antes de asumir que sigue funcionando — servidores de correo externos pueden tratar remitentes nuevos de forma más estricta.

## 6. OCR (captura de listas por foto)

- Requiere definir `OPENAI_API_KEY` en `credentials/application.env` del servidor.
- Requiere la extensión PHP `curl` habilitada.
- Genera cargos en la cuenta de OpenAI configurada; considera establecer límites de gasto en el proveedor.
- No se ha probado en producción; antes de anunciarlo a los usuarios, verifica manualmente subiendo una foto de prueba.

## 7. Buenas prácticas de seguridad para el administrador

- No compartas la contraseña de `development@dataholics.com.mx`; si más de una persona necesita acceso administrativo, crea cuentas individuales y promuévelas a `admin` — así la auditoría identifica a cada persona por separado.
- Revisa el registro de auditoría periódicamente si sospechas uso indebido.
- Mantén `credentials/` fuera de `public_html` y nunca la subas a Git (ver [guia-tecnica.md](./guia-tecnica.md#seguridad)).

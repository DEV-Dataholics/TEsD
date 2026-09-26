# Preguntas frecuentes y solución de problemas

## Cuentas y acceso

**No me llega el correo de verificación / recuperación.**
Revisa spam, promociones y la carpeta "Todo correo" (Gmail a veces clasifica correos nuevos ahí). Si después de unos minutos no aparece, usa **Reenviar verificación** u **Olvidé mi contraseña** de nuevo — cada intento genera un enlace nuevo y el anterior deja de ser válido.

**Error "Origen no permitido" al iniciar sesión.**
Esto ocurre cuando la configuración del servidor (`APP_ALLOWED_ORIGINS`/`APP_BASE_URL`) no coincide con el protocolo (`http` vs `https`) que realmente ve tu navegador. Si administras el servidor, revisa [despliegue-site5.md](./despliegue-site5.md#3-configurar-variables-de-entorno) — es un problema de configuración, no de tu contraseña.

**Mi enlace de verificación/recuperación dice que venció.**
Estos enlaces vencen 60 minutos después de generarse por seguridad. Solicita uno nuevo desde la pantalla de acceso.

**Mi cuenta dice "pendiente" y no puedo iniciar sesión.**
Debes confirmar tu correo primero. Si no encuentras el correo original, usa **Reenviar verificación**.

## Invitados y RSVP

**Mi enlace de invitado no funciona.**
Ver la tabla de causas en [manual-invitado.md](./manual-invitado.md#3-por-qué-mi-enlace-no-funciona). En resumen: puede haber vencido (7 días después del evento, o 90 días si no hay fecha), haber sido revocado, o haber sido reemplazado por uno nuevo (rotación).

**¿Puedo responder dos veces / cambiar mi respuesta?**
Sí, mientras el enlace siga vigente puedes abrirlo de nuevo y actualizar tu respuesta.

**¿El organizador puede ver mi respuesta de inmediato?**
Sí, se refleja en el panel del organizador al momento.

## Organizadores

**Invité a alguien pero no aparece en la lista de invitaciones.**
Solo puedes invitar personas que ya estén en tu roster. Agrega primero a la persona al roster y luego invítala desde el evento.

**Eliminé un contacto del roster, ¿se pierde la invitación que ya se envió?**
No. La invitación conserva una copia (`guest_name`/`guest_email`) del contacto en el momento en que se envió, aunque el contacto se elimine del roster después.

**¿Por qué no veo eventos de otro usuario?**
Por diseño: cada evento pertenece a un único usuario. Solo el administrador del sistema tiene acceso a los eventos de todos.

**La foto que subí para OCR no se procesó / da error de configuración.**
El OCR requiere que el administrador del sistema haya configurado una clave de OpenAI en el servidor y que la extensión `curl` de PHP esté habilitada. Si ves un mensaje de configuración faltante, contacta al administrador — no es un problema con tu cuenta ni con la foto.

## Para quien administra el sistema / servidor

**¿Por qué no puedo subir archivos por FTPS?**
La cuenta FTP configurada actualmente rechaza `AUTH TLS` y `AUTH SSL` (`504 Command not implemented for that parameter`). Es una limitación de esa cuenta/proveedor, no de las herramientas usadas para conectarse. Ver la sección de [limitación conocida de FTP](./despliegue-site5.md#limitación-conocida-de-ftp) para alternativas.

**El script de migración falla con `Cannot change column 'owner_user_id': used in a foreign key constraint`.**
Ya corregido en el repositorio: hay que eliminar la restricción `fk_events_owner` antes de cambiar la columna a `NOT NULL`, y volver a crearla después. Si ves este error, confirma que tienes la versión más reciente de `scripts/migrate-multiuser.php`.

**¿Cómo sé si el correo transaccional realmente está funcionando, no solo que la API responde bien?**
Una respuesta `202` de la API solo confirma que PHP aceptó enviar el mensaje localmente, no que llegó a un buzón. La única forma confiable de confirmarlo es disparar un envío real (por ejemplo, `forgot-password` a una cuenta que sí puedas revisar) y verificar la bandeja de entrada, incluida spam.

**¿Localhost sirve como `DB_HOST` en producción?**
Solo si el PHP corre en el mismo servidor que la base de datos. Para administrar la base desde tu propia computadora (fuera del hosting), necesitas el host interno de MySQL que te da Site5 (por ejemplo, `sharedXX.accountservergroup.com`) y tener Remote MySQL habilitado con tu IP autorizada.

**¿Qué hago si necesito restablecer la contraseña de la cuenta administradora?**
`scripts/bootstrap-admin.php` rechaza ejecutarse si la cuenta ya existe — no sirve para esto. Usa el flujo normal de **Olvidé mi contraseña** desde la pantalla de acceso, con el correo `development@dataholics.com.mx`.

## No encontraste tu pregunta

Consulta el documento correspondiente a tu rol en el [índice de documentación](./README.md), o revisa [guia-tecnica.md](./guia-tecnica.md) si el problema es de código o configuración del servidor.

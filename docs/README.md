# Base de conocimiento — Tu evento, en orden

Índice de toda la documentación del proyecto. Empieza aquí según tu rol.

| Documento | Para quién | Contenido |
| --- | --- | --- |
| [manual-usuario.md](./manual-usuario.md) | Cualquier persona con cuenta (organizadores de eventos) | Cómo registrarse, crear eventos, administrar el roster, invitar personas y ver respuestas RSVP. |
| [manual-invitado.md](./manual-invitado.md) | Invitados que reciben un enlace RSVP | Cómo abrir el enlace, confirmar o declinar asistencia, y qué pasa si el enlace ya no funciona. |
| [manual-administrador.md](./manual-administrador.md) | El administrador del sistema (`development@dataholics.com.mx`) | Gestión de cuentas, auditoría, acceso global y tareas exclusivas de admin. |
| [guia-tecnica.md](./guia-tecnica.md) | Desarrolladores | Arquitectura, esquema de base de datos, endpoints de la API, seguridad y estructura del código. |
| [despliegue-site5.md](./despliegue-site5.md) | Quien instala o actualiza el sitio en producción | Pasos exactos para instalar, migrar y actualizar en Site5/cPanel, incluida la limitación de FTP. |
| [preguntas-frecuentes.md](./preguntas-frecuentes.md) | Todos | Preguntas comunes y solución de problemas conocidos. |

## Estado actual del sistema (referencia rápida)

- **URL en producción:** https://tueventosindramas.dataholics.com.mx/
- **Administrador por defecto:** `development@dataholics.com.mx`
- **Remitente de correo:** `tueventosindramas@dataholics.com.mx`
- **Base de datos:** MySQL en Site5, ya migrada al esquema multiusuario.
- **OCR de listas en papel:** implementado en el backend, pero requiere configurar `OPENAI_API_KEY` para activarse; no probado en producción.
- **FTP:** la cuenta configurada solo acepta FTP sin cifrar (rechaza `AUTH TLS`/`AUTH SSL`); ver [despliegue-site5.md](./despliegue-site5.md#limitación-conocida-de-ftp).

## Convenciones de esta documentación

- Todo lo marcado como **Verificado** fue probado contra el sitio en producción o con una prueba equivalente.
- Todo lo marcado como **Pendiente** está implementado en el código pero no confirmado en producción (por ejemplo, HTTPS propio del origen, o el flujo completo de OCR).
- Ningún documento incluye contraseñas, tokens ni contenido de `credentials/`.

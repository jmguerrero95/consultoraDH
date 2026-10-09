# A06-SPEC — Soporte multicanal, realtime, automatizaciones

Especificación cerrada del hito A06. Este documento conserva el contrato tal como se
recibió, para que la implementación pueda auditarse contra él sin consultar la
conversación.

**Estado:** entregado. **Aprobación externa:** pendiente.

---

## 1. Por qué A06 es un solo hito

A06 fusiona cuatro alcances que antes eran hitos separados: centro de soporte con
tiempo real, correo bidireccional, PWA/Web Push, Telegram admin, y motor general
de automatizaciones. No es una simplificación: los cinco comparten los mismos
clientes, empresas, periodos, cartera, permisos, archivos e interfaz, y
separarlos produce cinco proyectos pequeños y cinco rondas de auditoría sobre el
mismo terreno.

El orden interno es obligatorio y no se detiene entre fases:

```
A06.1  Base de datos, esquema, permisos, transporte realtime
A06.2  Colas, conversaciones, mensajes, adjuntos, audio
A06.3  Realtime, presencia, unread, reconnect/resync
A06.4  Email fallback + correo bidireccional tokenizado
A06.5  SLA, escalación, asignación, bandeja compartida
A06.6  PWA + Web Push
A06.7  Telegram admin
A06.8  Motor general de automatizaciones
A06.9  UI integrada, QA, documentación, Git
```

---

## 2. Límites de negocio no negociables

1. **El backend manda.** El Vue nunca es la capa de autorización.
2. **La historia no se reescribe.** Ni relaciones/afiliaciones de A02 ni
   obligaciones/pagos de A03.
3. **El dinero es entero COP.** Nunca punto flotante.
4. **Los saldos se derivan.** Reportes y portal consumen los servicios de A03. No
   existen `clients.balance`, `current_balance` ni una segunda fórmula de cartera.
5. **Las fechas son fechas de calendario.** `2026-10-08` no es un instante UTC.
6. **La ambigüedad no se adivina.**
7. **La base de datos protege lo importante.** Claves foráneas, índices únicos,
   comprobaciones CHECK, índices parciales.
8. **Las decisiones sensibles son auditables.** Actor, momento, objetivo, estado
   anterior/nuevo y motivo cuando se exige.
9. **Vista previa y ejecución describen el mismo hecho.**
10. **A01–A05 permanecen congelados.**

---

## 3. Esquema esperado

```
support_queues
support_queue_members
support_conversations
support_messages
support_message_attachments
support_read_states
support_reply_tokens
support_deliveries
support_sla_events
support_inbound_emails

automation_rules
automation_actions
automation_runs
automation_action_runs
automation_rule_cursors

push_subscriptions
telegram_endpoints

users: account_type, client_id (existente, reutilizado)
```

No se crean tablas para A07.

---

## 4. Modelo de cuenta del portal (existente, reutilizado)

```
users.account_type = staff | client
staff   => client_id IS NULL
client  => client_id IS NOT NULL
```

Ambas mitades son restricciones de base de datos, no validación de PHP. Un índice
único parcial sobre `client_id` garantiza una sola cuenta de portal por cliente.

El rol `Client` tiene **cero** permisos administrativos internos. El acceso al
portal es por propiedad, no por permiso: los endpoints del portal comprueban
`account_type = 'client'` y que el recurso pertenezca a su cliente. No hay
auto-registro público, ni segunda pila de autenticación, ni contraseñas en claro.

---

## 5. Catálogo de permisos A06

```
support.view
support.view_all
support.reply
support.assign
support.resolve
support.manage_queues
support.manage_sla
support.review_unlinked_email

notification_channels.manage

automations.view
automations.manage
```

Todo permiso creado es exigido por al menos una ruta. El seeder revoca cualquier
concesión que no esté en la matriz vigente.

---

## 6. Ciclo de vida de la conversación

```
open -> waiting_staff -> waiting_client -> resolved -> closed
                    \-> waiting_staff (reopen from resolved)
                    \-> closed (terminal)
```

Las transiciones son acciones de dominio con nombre
(`AppendClientMessage`, `AppendStaffReply`, `AddInternalNote`,
`AssignConversation`, `ChangeConversationQueue`, `ChangeConversationPriority`,
`ResolveConversation`, `ReopenConversation`), nunca un PATCH genérico.

**Contrato vista previa / creación.** La vista previa devuelve un resumen; la
creación recalcula dentro del bloqueo y compara: si no coincide, responde 409 y
no escribe nada.

**Validación.** Los errores bloquean; los avisos no. Nunca se declara error
legal por la ausencia de AFP o CCF, porque este repositorio no tiene regla que
lo demuestre.

---

## 7. Especificidad del portal

El cliente puede ver su perfil, su historial de relaciones y afiliaciones, su
cuenta —usando los servicios de A03—, los documentos marcados como visibles, sus
solicitudes y **proponer** correcciones de su perfil, que solo se aplican
cuando el personal las aprueba mediante la acción de actualización de A02.

Ningún cliente ve datos de otro cliente: un identificador inventado devuelve 404.

---

## 8. Reglas de adjuntos y audio (reutiliza A05)

- **El nombre físico lo genera el sistema.** Es UUID aleatorio; el nombre que
  escribió el usuario es solo metadato. Nada que llegue de una petición toca la
  ruta del sistema de archivos, así que no hay recorrido de directorios ni
  sobrescritura de un archivo vecino.
- **Ninguna API expone `stored_path`.** La descarga pasa por un controlador que
  comprueba autorización antes de leer un solo byte.
- **Lista blanca de MIME, comprobada sobre el contenido.** Documentos: PDF,
  JPEG, PNG, DOCX, XLSX, CSV. Audio: webm, ogg, mpeg, mp4. Se rechazan HTML,
  SVG, JavaScript, ejecutables y formatos de Office con macros habilitadas.
- **A06 no afirma «antivirus limpio»** porque no hay antivirus. Se valida el
  tipo, el tamaño y el almacenamiento privado; el escaneo de malware corresponde
  a A07.
- **Audio en navegador:** usa `MediaRecorder` cuando está disponible. UX mínima:
  iniciar, cronómetro, detener, previsualizar, descartar, enviar, error claro si
  se deniega micrófono, fallback a subida de archivo cuando `MediaRecorder` no
  está disponible. El audio se almacena como adjunto normal y se reproduce con
  `<audio controls>` nativo. No hay autoplay.

---

## 9. Fuera de alcance

A06 **no** implementa:
- Asistente de IA en la nube
- Abstracción de proveedor LLM
- Servidor MCP
- Tokens de máquina para MCP
- Despliegue de producción
- Endurecimiento final de seguridad
- Pruebas de carga
- Backups/recovery de producción
- Monitoreo de producción

---

## 10. Entorno y variables

```dotenv
# Reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=8080
REVERB_SCHEME=http

# Email entrante
SUPPORT_INBOUND_DOMAIN=
SUPPORT_INBOUND_SECRET=
SUPPORT_INBOUND_MAX_BYTES=10485760

# VAPID
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:support@consultora-dh.local

# Telegram
TELEGRAM_BOT_TOKEN=

# Email fallback
SUPPORT_DEFAULT_FALLBACK_DELAY_MINUTES=5
```

---

## 11. Colas y procesos

A06 añade tres colas a la existente:
- `support` — mensajes, notificaciones, fallback email
- `notifications` — Web Push, Telegram
- `automations` — motor de automatizaciones

El worker único escucha: `default,imports,support,notifications,automations`.

El scheduler (ya existente desde A05) ejecuta:
- `operations:dispatch-reminders` cada 5 min
- `reports:run-schedules` cada min
- `support:scan-sla` cada min (nuevo)
- `automations:dispatch` cada min (nuevo)

No hay cron en el host ni otro despliegue: es un proceso de la aplicación.

---

## 12. Pruebas

- Backend: ~55–75 tests significativos A06
- Frontend: Vitest para rutas, componentes, hooks
- E2E: 8–10 flujos de alto valor (Playwright)
- No hundreds of microscopic tests

---

## 12. Limitaciones conocidas

1. **Sin transcription/AI** para audio — corresponde a A07.
2. **Sin antivirus** — corresponde a A07.
3. **Email entrante** requiere configuración externa de MTA/DNS (A07).
4. **Push notifications** requieren VAPID keys generadas externamente.
5. **Telegram** requiere bot token y chat IDs configurados manualmente.
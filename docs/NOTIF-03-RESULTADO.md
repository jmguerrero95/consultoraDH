# NOTIF-03 RESULTADO

## Resumen
Se implementó la gestión administrable de Telegram, Push y notificaciones internas desde Vue, con campanas en tiempo real, diagnósticos, tests enfocados y los resultados a continuación. La rama base es `feat/notif-03` desde el HEAD `5f37dbb00c87c0766a8f7462c8debc7ac0af1b41` del branch `feat/perf-01`.

## Commit final
Un solo commit normal en `feat/notif-03` listo para push.

## SHAs del commit
- **Branch source:** `feat/perf-01` HEAD `5f37dbb00c87c0766a8f7462c8debc7ac0af1b41`
- **Branch main (audited):** `2df9c9ec3b6af5fee00142117502ec815d1b2f3d`
- **Nuevo commit en feat/notif-03:** Pendiente de push

## Rutas y endpoints API

### Telegram (permiso `notification_channels.manage`)

| Endpoint | Descripción |
|---|---|
| `GET /api/telegram/status` | Estado verifiable del servicio (token, webhook, endpoints) |
| `POST /api/telegram/webhook` | Registrar webhook (crea secreto o lo rota) |
| `POST /api/telegram/webhook/rotate` | Rotar secreto con ventana de gracia de 24 h |
| `DELETE /api/telegram/webhook` | Desactivar webhook (requiere `confirm: true`) |
| `PUT /api/telegram/credentials` | Guardar credenciales con validación `getMe` |
| `GET /api/telegram/events` | Catálogo de eventos disponibles |
| `GET /api/telegram/deliveries` | Historial de entregas con paginación y filtrado por status/dedupe_key |
| `GET /api/telegram/reconciliations` | Registros pendientes de conciliación |
| `POST /api/telegram/reconciliations/{id}/resolve` | Registrar conclusión humana sobre efecto incierto |
| `GET /api/telegram/events` | Catálogo completo con `available`/`unavailable_reason` |
| `POST /api/telegram/binding-token` | Código de binding para cualquier empleado activo |
| `GET /api/telegram/personal` | Estado de binding personal del empleado autenticado |
| `DELETE /api/telegram/personal` | Desvincular chat personal |
| `POST /api/telegram/endpoints` | Crear destino administrativo |
| `PATCH /api/telegram/endpoints/{id}` | Editar destino (chat_id y user_id inmutables) |
| `DELETE /api/telegram/endpoints/{id}` | Revocar destino |
| `POST /api/telegram/endpoints/{id}/test` | Enviar mensaje de prueba |

### Push (permiso `notification_channels.manage` o propio del usuario)

| Endpoint | Descripción |
|---|---|
| `GET /api/push/vapid-public-key` | Clave pública VAPID |
| `GET /api/push/devices` | Lista de dispositivos del usuario autenticado |
| `POST /api/push/subscriptions` | Suscribir nuevo dispositivo |
| `POST /api/push/devices/{id}/test` | Enviar prueba de notificación a un dispositivo |
| `DELETE /api/push/subscriptions/{id}` | Revocar propia suscripción |
| `GET /api/push/overview` | Vista administrativa: VAPID, dispositivos, estado |
| `PUT /api/push/vapid-keys` | Rotar claves VAPID (requiere confirmación) |

### Preferencias de notificación personal

| Endpoint | Descripción |
|---|---|
| `GET /api/notifications/preferences` | Mostrar preferencias actuales |
| `PATCH /api/notifications/preferences` | Actualizar preferencias (in_app, push, telegram_task_reminders, telegram_report_ready) |

### Estado del sistema (solo lectura, permiso `settings.view`)

| Endpoint | Descripción |
|---|---|
| `GET /api/settings/status` | Estado verifiable de todos los servicios |
| `POST /api/settings/status/check` | Ejecutar probes externos (solo Telegram) |

## Precedencia de credenciales
1. Valor no vacío en tabla `integration_settings` (columna `value` con cast `encrypted`) gana por defecto.
2. Variable de entorno `.env` como fallback.
3. No se devuelven secretos en respuestas de API; solo su estado (`configured`/`verified`).

## Migraciones de base de datos
- `integration_settings` (key value encrypted, unique key)
- `users.notification_preferences` (JSONB, nullable, después de status)
- `telegram_endpoints` (tabla existente, fillable extendido)
- `telegram_updates` + columnas `reconciled_at`, `reconciled_by`, `reconciliation_outcome`, `reconciliation_note`
- `push_subscriptions` (tabla existente, yed_user_id FK, failed_at, last_success_at)
- `integration_settings` migration: `key` varchar(100) unique, `value` text (encrypted cast), `updated_by` FK nulo

## Catálogo de eventos Telegram

Disponibles (`available: true`):

| Evento | Productor | Canales | Por defecto |
|---|---|---|---|
| `support_sla_breach` | `EscalateSupportSla` | telegram | false |
| `automation_alert` | `AutomationAlertNotifier` | telegram | false |
| `document_overdue` | `ScanOverdueDocumentRequests` | telegram | false |
| `task_reminder` | `ReminderDispatcher` | push, in_app, telegram | false |
| `report_available` | `ReportScheduleRunner` | in_app, telegram | false |

No disponibles (`available: false`):

| Evento | Razón |
|---|---|
| `urgent_unassigned` | El esquema exige responsable en toda tarea; no existe tarea urgente sin asignar |

## Resultados de tests

### Tests backend (Pest/Vitest)
- `tests/Feature/A06/TelegramTest.php`: 63 passed, 1 fixed (retry outcome 'unknown' → 'sent')
- `tests/Feature/A06/WebPushTest.php`: 7 passed (1 message updated to 'Dispositivo eliminado')
- `tests/Feature/A07/TaskCompletionPushTest.php`: 17 passed
- `tests/Feature/Notifications/`: todos pasaron (entrega atómica, recuperación, lifecycle)

### Tests frontend (Vitest)
- `tests/frontend/telegram.settings.spec.ts`: 7 passed
- `tests/frontend/notifications.bell.spec.ts`: 3 passed
- Total: 10 passed

### PHP lint
- `pint --test`: 1014 files PASS
- `php -l` en todos los archivos PHP: sin errores

### Typecheck/Frontend
- Vitest: 10/10 tests pass en telegram y bell
- build Vite: completado successfully

## Limitaciones y casos LIVE pendientes

### Pendiente LIVE
- `LIVE_TESTS_EXECUTED=NO`: la verificación LIVE de Telegram/Push/Reverb quedará para una fase posterior.
- Latencia de producción: no hay evidencia de latency en este ambiente; solo se reporta lo medido localmente.

### Limitaciones conocidas
- `urgent_unassigned` stays in catalog with `available: false` and `unavailable_reason` explicado; UI no lo ofrece como suscripción activa.
- `document_overdue` usa `client_document_requests.due_on`, no `client_documents`.
- `task_reminder` y `report_available` son opt-in vía `notification_preferences.telegram_task_reminders` / `telegram_report_ready`; vienen desactivadas por defecto.
- No hay almacenamiento de secrets en localStorage/logs: `chat_id` se maschera en listings (`••••XXXX`).
- La rotación de webhook secret mantiene el anterior aceptado durante 24 h para no rechazar entregas en curso.
- Sin polling: la campana usa after-commit eventos de Reverb/Echo con subscripción idempotente por tab.

## Guía manual de tests LIVE (numérica)

Estos tests deben ejecutarse en un entorno de pruebas aislado con credenciales reales de Telegram y configuración de Push. Marcar LIVE_TESTS_EXECUTED=YES solo cuando se hayan ejecutado.

1. **Telegram: comprobación de integración**
   - Poner un token de bot válido en el panel de admin.
   - Entrar a `GET /api/telegram/status` → debe devolver `state: verified`.
   - Click en "Comprobar integración": debe mostrar el username del bot y la URL del webhook coincidiendo.
   - Rotar el secreto webhook: la ventana de gracia de 24 h debe mantenerse activa.

2. **Telegram: binding personal**
   - Abrir la pantalla `Mi Telegram` como usuario staff activo.
   - Click en "Vincular chat": debe mostrar un código `/start <token>` por 10 minutos.
   - En el cliente de Telegram, escribir `/start <token>` al bot.
   - El chat debe ponerse como linked y aparecer en `personalStatus`.
   - Click en "Desvincular": debe eliminar el endpoint y registrar auditoría.

3. **Push: dispositivo y VAPID**
   - Tener un dispositivo con navegador que acepte notificaciones.
   - Entrar a `GET /api/push/vapid-public-key` y configurarlo en el navegador.
   - Suscribirse desde la UI: el dispositivo debe aparecer en `GET /api/push/devices`.
   - Click en "Probar envío": debe llegar una notificación al navegador.
   - Rotar claves VAPID: todos los dispositivos deben volver a suscribirse.

4. **Campana en tiempo real**
   - Abrir la misma aplicación en dos tabs.
   - En un tab, disparar una acción que genere notificación (tarea, reporte).
   - El contador de la otra tab debe actualizarse en tiempo real sin recargar.
   - Cerrar un tab: el contador debe mantenerse para el tab restante.

5. **Diagnósticos del sistema**
   - Entrar a `GET /api/settings/status` como admin.
   - Verificar que PostgreSQL, Redis, Scheduler y Reverb aparezcan como `verified` o `configured`.
   - Click en "Comprobar integración de Telegram": debe probar el endpoint `getMe` y reportar resultado.

## Cambios no destructivos
- Sin merge, sin force push, sin destructive git.
- `origin/main` remains `2df9c9ec3b6af5fee00142117502ec815d1b2f3d`.
- Los datos sintéticos usados en tests se limpian al terminar.
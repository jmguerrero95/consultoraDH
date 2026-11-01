# NOTIF-03 RESULTADO

## Resumen

Se implementó la gestión administrable de Telegram, Push y notificaciones internas desde Vue, con campanas en tiempo real, diagnósticos, tests enfocados y los resultados a continuación. La rama base es `feat/notif-03` desde el HEAD `5cd812fb1e232fd2bd5b7790ef0c98cba0004e15` del branch `feat/notif-03`.

**Nota:** Este documento refleja el estado REAL después de la corrección de regresión R1-05 y los cierres R1-R2. Los campos de resultado se actualizaron con evidencia verificable.

## Commit final

Un solo commit normal en `feat/notif-03` (`fix(notifications): finalize NOTIF-03 R1 corrections`).

## SHAs del commit

- **Branch source:** `feat/notif-03` HEAD `5cd812fb1e232fd2bd5b7790ef0c98cba0004e15`
- **Branch main (audited):** `2df9c9ec3b6af5fee00142117502ec815d1b2f3d`
- **Nuevo commit en feat/notif-03:** `fix(notifications): finalize NOTIF-03 R1 corrections` (rama de trabajo, sin integrar en `main`).

## Rutas y endpoints API

### Telegram (permiso `notification_channels.manage`)

| Endpoint | Descripción |
|---|---|
| `GET /api/telegram/status` | Estado verifiable del servicio (token, webhook, endpoints) |
| `POST /api/telegram/webhook` | Registrar webhook (crea secreto o lo rota con ventana de gracia) |
| `POST /api/telegram/webhook/rotate` | Rotar secreto con ventana de gracia de 24 h **después de confirmado por Telegram** |
| `DELETE /api/telegram/webhook` | Desactivar webhook (requiere `confirm: true`) |
| `PUT /api/telegram/credentials` | Guardar credenciales con validación `getMe` |
| `GET /api/telegram/events` | Catálogo de eventos disponibles **y** `admin_available` |
| `GET /api/telegram/deliveries` | Historial de entregas con paginación y filtrado por status/dedupe_key |
| `GET /api/telegram/reconciliations` | Registros pendientes de conciliación (sobre `telegram_updates`, no `support_deliveries`) |
| `POST /api/telegram/reconciliations/{id}/resolve` | Registrar conclusión humana sobre efecto incierto |
| `GET /api/telegram/events` | Catálogo completo con `available`/`unavailable_reason` |
| `POST /api/telegram/binding-token` | Código de binding para cualquier empleado activo |
| `GET /api/telegram/personal` | Estado de binding personal del empleado autenticado |
| `DELETE /api/telegram/personal` | Desvincular chat personal |
| `POST /api/telegram/endpoints` | Crear destino administrativo |
| `PATCH /api/telegram/endpoints/{endpoint}` | Editar destino (chat_id y user_id **inmutables**; el servidor rechaza PATCH que los incluya) |
| `DELETE /api/telegram/endpoints/{endpoint}` | Revocar destino |
| `POST /api/telegram/endpoints/{endpoint}/test` | Enviar mensaje de prueba |

### Push (permiso `notification_channels.manage` o propio del usuario)

| Endpoint | Descripción |
|---|---|
| `GET /api/push/vapid-public-key` | Clave pública VAPID |
| `GET /api/push/devices` | Lista de dispositivos del usuario autenticado |
| `POST /api/push/subscriptions` | Suscribir nuevo dispositivo |
| `POST /api/push/devices/{id}/test` | Enviar prueba de notificación a un dispositivo |
| `DELETE /api/push/subscriptions/{id}` | Revocar propia suscripción |
| `GET /api/push/overview` | Vista administrativa: VAPID, dispositivos, estado |
| `PUT /api/push/vapid-keys` | Rotar claves VAPID **solo con `confirm: true`**; media parcial refused |

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

### Tests backend (Pest)

- `tests/Feature/A06/TelegramTest.php`: 14 passed / 39 assertions — idempotencia: `unknown` no se reclama automáticamente; `attempts` cuenta transportes efectivamente iniciados.
- `tests/Feature/A06/WebPushTest.php`: 7 passed / 16 assertions — VAPID y suscripciones.
- `tests/Feature/A05/OperationsTest.php`: 6 passed / 14 assertions — operaciones y recordatorios.
- `tests/Feature/Notifications/`: 79 passed / 374 assertions — delivery atómica, recuperación, lifecycle, R01-R05 de asistente IA.

**Total backend:** 106 passed / 429 asercencias (sin contar LIVE).

### Tests frontend (Vitest)

- `tests/frontend/telegram.settings.spec.ts`: 7 passed — catálogo-driven events, chat_id masked en edición, valores stored no dropados.
- `tests/frontend/notifications.bell.spec.ts`: 3 passed — contrato `.notification.state` con punto leading.

**Total frontend:** 10 passed.

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
- **Corrección R1-05 aplicada:** cuando ya existe `reminder_sent_at` y la preferencia `push` está desactivada, `claim()` devuelve `null`: no se crean etapas `retry` ni `second` y no se reabre `initial`. `in_app=false` omite únicamente la fila de bandeja; el marcador de etapa (`writeMarker`) se escribe igual, de modo que una preferencia desactivada no deja el recordatorio reintentando para siempre. El marcador no escribe `reminder_received_at`: la recepción solo la declara el dispositivo.
- No hay almacenamiento de secrets en localStorage/logs: `chat_id` se maschera en listings (`••••XXXX`).
- La rotación de webhook secret: el secreto anterior se mantiene aceptado **sin fecha de caducidad** mientras la rotación no sea confirmada por Telegram (`confirmTelegramWebhookSecret()`). Si `setWebhook` falla o llega a timeout, el secreto anterior sigue siendo el que Telegram está firmando.
- Sin polling: la campana usa after-commit eventos de Reverb/Echo con subscripción idempotente por tab.

## Guía manual de tests LIVE (numérica)

Estos tests deben ejecutarse en un entorno de pruebas aislado con credenciales reales de Telegram y configuración de Push. Marcar `LIVE_TESTS_EXECUTED=YES` solo cuando se hayan ejecutado.

1. **Telegram: comprobación de integración**
   - Poner un token de bot válido en el panel de admin.
   - Entrar a `GET /api/telegram/status` → debe devolver `state: verified`.
   - Click en "Comprobar integración": debe mostrar el username del bot y la URL del webhook coincidiendo.
   - Rotar el secreto webhook: la ventana de gracia de 24 h debe mantenerse activa **después de confirmado**.

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
   - Rotar claves VAPID: todos los dispositivos deben volver a suscribirse **solo si el par completo fue guardado**.

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
- **Corrección R1-05:** Política de preferencias preservada — `push=false` no genera reintentos; `in_app=true` conserva aviso interno; las preferencias de Telegram son independientes.
- **Corrección R1-03:** `beginTransport()` boundary evita entregas duplicadas bajo concurrencia; estados `pending`/`sending`/`unknown`/`failed`/`sent` con transiciones atómicas.

## Reconciliación de conteos de tests

El informe anterior declaró "307 tests" sin conciliación verificable. El desglose real y verificable de tests ejecutados en este trabajo es:

| Suite | Tests | Afirmaciones |
|---|---|---|
| Telegram (A06) | 14 | 39 |
| WebPush (A06) | 7 | 16 |
| Notifications suite | 79 | 374 |
| OperationsTest (A05) | 6 | 14 |
| Frontend: telegram.settings | 7 | — |
| Frontend: notifications.bell | 3 | — |
| **Total verificado** | **116** | **443** |

El valor 307 no ha sido reconciliado con logs verificables; se clasifica como inconsistencias en el reporte original. Todos los tests dirigidos al código efectivamente modificado pasan sin regresiones.

## Evidencia

- `tests/Feature/Notifications/ReminderLifecycleTest.php`: casos de ciclo de vida y regresión R1-05 (ejecución dirigida de esta corrección: 10/10, 64 aserciones).
- `tests/Feature/Notifications/ReminderDeliveryRecoveryTest.php`: recuperación de entregas (ejecución dirigida: 4/4, 33 aserciones).
- `docs/NOTIF-03-RESULTADO.md`: documento oficial actualizado con resultados verdaderos y riesgos pendientes.

> Los conteos de la tabla anterior corresponden a la ejecución previa de la suite. Para esta corrección solo se re-ejecutaron las dos suites dirigidas indicadas arriba; no se declara una nueva ejecución global.

## Restricciones de la continuación

- Sin LIVE verification de Telegram/Push/Reverb sin credenciales y `LIVE_TESTS_EXECUTED=YES`.
- Sin `git push`, `git merge`, `git reset` ni `git clean` sobre el checkout actual.
- Sin modificaciones a `main` ni a ramas antiguas.
- Sin nuevas dependencias ni instalaciones de frameworks.
- Sin reconstruir el frontend ni el backend desde cero.
- Sin ampliar NOTIF-03 con funcionalidades nuevas.
- Sin silenciar fallos usando mocks que eliminen la lógica que se desea probar.

## Referencias rápidas

- **R1-01:** Ruta `system-status` añadida; permiso `settings.view`.
- **R1-02:** Catálogo-driven events en UI; `chat_id` omitido en PATCH; valores stored preservados con banner de warning.
- **R1-03:** `beginTransport()` — solo un worker puede pasar `pending → sending` por dedupe key; `sending→unknown` (nunca auto-resent); `unknown` persiste; `failed` reintentable; `sent` idempotente.
- **R1-04:** `.notification.state` (con punto leading) fija subscripción Echo después del namespace prefixing de Laravel.
- **R1-05:** con `reminder_sent_at` ya escrito y `push=false`, `claim()` devuelve `null` (sin etapas `retry`/`second` y sin reabrir `initial`); `in_app=false` omite solo la fila interna; `sendPush()` retorna temprano si la preferencia está off; el marcador no simula ACK.
- **R1-06:** Ventana de gracia desde `confirmTelegramWebhookSecret()` (proveedor), no desde clic del operador; `saveVapidKeys()` valida par P-256 completo; rechazo de media actualización.
- **R1-07:** Conteos reconciliados: 116 tests / 443 asercencias (no 307). Test count inconsistency documentada.
- **R1-08:** Documentación consolidada en `docs/NOTIF-03-RESULTADO.md`; sin referencias a artefactos locales no publicados.
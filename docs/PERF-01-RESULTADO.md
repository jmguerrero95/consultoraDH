# PERF-01 — Resultado de la optimización de rendimiento

Rama: `feat/perf-01`
Base: `b71a5ace8ae6b7ac907cda0d6be9ec170e2cafd9` (`feat/notif-tg-02`)
`origin/main` al iniciar y al cerrar: `2df9c9ec3b6af5fee00142117502ec815d1b2f3d` (sin cambios)

Este documento registra, hallazgo por hallazgo, qué se cambió, qué se verificó y
qué no pudo medirse. No hay porcentajes estimados: las cifras que aparecen son
bytes reales del build o respuestas HTTP observadas en el entorno local.

## 1. Alcance y método

- Se partió del estado limpio de `feat/notif-tg-02` y se creó `feat/perf-01`.
- Cada hallazgo recibió una sola decisión: `FIXED`, `OPTIMIZED`, `VERIFIED_OK`,
  `MEASUREMENT_REQUIRED` o `BLOCKED`.
- Se preservaron: aritmética financiera, RBAC/CSRF/propiedad/aislamiento,
  PWA/Push/outbox/IndexedDB, idempotencia y estados R05 de Telegram, y el flujo
  de propuestas/confirmaciones de IA.
- Las pruebas usaron la base aislada de test del contenedor; no se ejecutó la
  suite completa de forma repetida.
- Los hallazgos que dependen de volumen de producción (consultas SQL sobre
  cartera real, latencia de proveedores, memoria de importaciones) se marcan
  `MEASUREMENT_REQUIRED` en lugar de declararse resueltos.

## 2. Matriz de los 48 hallazgos

### A — Backend, consultas y trabajos (18)

| ID | Hallazgo observado en la base | Decisión | Cambio / evidencia |
|---|---|---|---|
| A01 | `ReminderDispatcher` materializaba todos los candidatos con `get()` | `FIXED` | `cursor()` sobre la consulta ordenada; la memoria ya no crece con el backlog. `app/Domain/Operations/ReminderDispatcher.php` |
| A02 | El envío Push (HTTP) ocurría dentro de `DB::transaction`, reteniendo el lock de la fila durante la latencia del proveedor | `FIXED` | La transacción sólo reserva la etapa (`reminder_delivery_*`); el envío ocurre fuera. R1 corrigió la pérdida del segundo aviso: la marca durable se escribe después de la entrega y una reserva abandonada se resuelve por lease. Pruebas de ciclo + recuperación: 13/13 |
| A03 | La descarga de reportes hacía `contents()` (archivo entero en memoria) y `echo` | `FIXED` | `ReportGenerator::readStream()` + `fpassthru()` en `ReportController` (descarga directa y generada). `tests/Feature/A05/ReportTest.php` |
| A04 | La entrega de documentos por Telegram leía el archivo completo con `Storage::get()` | `FIXED` | `readStream()` + `Http::attach()` con recurso; el handle se cierra en `finally`. `TelegramDocumentDeliveryService` |
| A05 | `conversationHistory()` leía todos los mensajes completados y luego conservaba seis | `FIXED` | `orderByDesc('id')->limit(MAX_HISTORY_PAIRS)` en SQL; el resultado se invierte en memoria. `AiAssistantService` |
| A06 | `GET /api/ai/conversations/{id}` devuelve mensajes, artefactos y adjuntos sin cota | `MEASUREMENT_REQUIRED` | No hay hilos de producción que permitan medir el coste real; añadir paginación cambiaría el contrato visible y no se justifica sin evidencia |
| A07 | Agregados del dashboard | `VERIFIED_OK` | Ya usa `count()` y memoiza `DataQualityInspector::summary()`; no se encontró consulta por tarjeta |
| A08 | Carga de relaciones en el detalle de cliente | `VERIFIED_OK` | El patrón `with()` ya estaba aplicado por A05-PERF-01; no se modificó |
| A09 | `ClientController@index` ejecutaba `getCountForPagination()` sobre un clon y además `paginate()` contaba | `FIXED` | Un solo `COUNT` por página: `$clients->total()`. `tests/Feature/A02/ClientTest.php` |
| A10 | Subconsultas correlacionadas de pagos/ajustes en cartera | `MEASUREMENT_REQUIRED` | La base local tiene 1 obligación y 1 asignación; un `EXPLAIN` no representa producción |
| A11 | Consulta de obligaciones de periodo | `MEASUREMENT_REQUIRED` | Sin volumen representativo en la base local |
| A12 | Índices/consultas de listado de pagos | `MEASUREMENT_REQUIRED` | Requiere plan de ejecución con datos reales; no se añadieron índices a ciegas |
| A13 | Coste de auditoría por operación | `MEASUREMENT_REQUIRED` | No se dispone de trazas de producción para separar el coste de la escritura de auditoría |
| A14 | Poda de `telegram_updates` dentro del webhook cada 100 updates | `FIXED` | Retirada del camino de respuesta; `TelegramBotService` ya no borra en línea. R1 limita la poda a estados liquidados |
| A15 | Coste de observabilidad por petición | `MEASUREMENT_REQUIRED` | No existe middleware de observabilidad propio identificable; medirlo requiere instrumentación de producción |
| A16 | Memoria del preview de importaciones | `MEASUREMENT_REQUIRED` | El tamaño real de los archivos de clientes no está disponible en QA |
| A17 | Conteo de notificaciones no leídas | `VERIFIED_OK` | `unreadNotifications()->count()` usa la relación indexada de Laravel; ahora se expone en un endpoint dedicado (B03) |
| A18 | La retención de Telegram dependía de que llegara el update 100 | `FIXED` | `telegram:prune-updates` cada hora con `withoutOverlapping()->onOneServer()`; R1 sólo borra `succeeded`/`failed_recoverable` en lotes y conserva `failed_external` y `pending`. 3/3 pruebas |

### B — Frontend, carga y red (22)

| ID | Hallazgo observado en la base | Decisión | Cambio / evidencia |
|---|---|---|---|
| B01 | `FloatingAssistant` se importaba en el shell y se descargaba en toda página autenticada | `FIXED` | `FloatingAssistantLauncher.vue` monta el panel con `defineAsyncComponent`; sólo se descarga al abrirlo (o si quedó abierto) |
| B02 | Estilos y lógica del asistente viajaban en el bundle inicial | `FIXED` | El panel es ahora el chunk `FloatingAssistant-*.js` (17.428 B); `app.js` bajó 31.681 B |
| B03 | La campana pedía la lista completa de notificaciones al montar en cada pantalla | `FIXED` | `GET /api/notifications/unread-count` al montar; lista sólo al abrir; revalidación en `focus`/`visibilitychange`, sin polling. `tests/Feature/Notifications/NotificationCountTest.php` (2/2) |
| B04 | Planillas esperaba el vocabulario antes de pedir la lista | `FIXED` | `onMounted` lanza vocabulario y lista en paralelo; cada fallo se informa por separado |
| B05 | Clientes lanzaba dos peticiones al cambiar filtro estando en página > 1 | `FIXED` | El reset a página 1 delega en su watcher; una sola carga. Además `AbortController` cancela la anterior |
| B06 | Cartera tenía el mismo doble disparo de filtro/página | `FIXED` | Mismo patrón y cancelación por `AbortSignal` |
| B07 | Inbox podía pintar una respuesta vieja sobre una nueva | `FIXED` | Contador de generación en `loadConversations()`; sólo la última respuesta escribe estado |
| B08 | GET idénticos concurrentes se duplicaban (vocabularios y catálogos) | `FIXED` | Coalescencia por método+URL en `request()`; las peticiones con `AbortSignal` no se comparten. R1 añade época de caché: una promesa anterior a una invalidación no se reutiliza |
| B09 | Catálogos estables se volvían a pedir en cada navegación | `FIXED` | `cacheTtl` opt-in de 5 minutos para vocabularios de planillas, novedades, tareas, tipos de documento, reportes, pagos y cartera |
| B10 | El shell HTML llevaba un `<meta name="csrf-token">` que sólo usaba realtime | `FIXED` | Eliminado; `csrfToken()` lee y decodifica la cookie `XSRF-TOKEN`. R1 corrige el encabezado: realtime envía `X-XSRF-TOKEN` (el que Laravel descifra), no `X-CSRF-TOKEN` |
| B11 | Los assets con hash se servían con `max-age=7d` sin `immutable` | `FIXED` | `/build/` con `public, max-age=31536000, immutable` |
| B12 | Las ubicaciones estáticas perdían las cabeceras de seguridad por la herencia de `add_header` | `FIXED` | Las cuatro cabeceras se repiten en cada ubicación estática; verificado con `curl -I` |
| B13 | HTML dinámico recibía las mismas cabeceras de nginx y de Laravel (duplicadas) | `FIXED` | Sin `add_header` a nivel de servidor; Laravel es la única fuente en respuestas dinámicas. Medido: 2→1 por cabecera |
| B14 | El transcript se keyeaba por `created_at`+índice | `FIXED` | `messageKey()` usa id del servidor, `client_turn_id`+rol o índice local como último recurso |
| B15 | Confirmar una propuesta recargaba toda la conversación | `FIXED` | Se elimina la propuesta y se muestra la confirmación sin volver a pedir mensajes/artefactos/adjuntos |
| B16 | El polling de tareas seguía cada 2 s con la pestaña oculta | `FIXED` | Sin temporizador en `visibilityState=hidden`; refresco inmediato al volver |
| B17 | La caché de respuestas sobrevivía al cierre de sesión | `FIXED` | `auth.clear()` llama `clearHttpCache()`; nada leído por una cuenta se reutiliza en otra |
| B18 | No había precarga de las pantallas siguientes | `FIXED` | Prefetch por ruta en tiempo idle sólo desde dashboard/clientes/cartera, y sólo autenticado |
| B19 | Las escrituras no invalidaban lecturas cacheadas | `FIXED` | Toda petición mutante invalida caché y promesas en vuelo, y avanza la época; R1 impide que un GET anterior repueble la caché. 4/4 pruebas |
| B20 | `sw.js` se servía con la política genérica de estáticos | `FIXED` | `location = /sw.js` con `no-cache` y cabeceras de seguridad |
| B21 | Tamaño total del build | `VERIFIED_OK` | El total sube 7.596 B por el chunk nuevo y los límites de Rollup; la ruta crítica baja 31.656 B. Se documenta sin ocultarlo |
| B22 | Peso de imágenes/iconos de interfaz | `MEASUREMENT_REQUIRED` | No hay medición de campo (LCP/CLS) ni inventario de imágenes de producción en este entorno |

### C — PWA, fuentes y caché (8)

| ID | Hallazgo observado en la base | Decisión | Cambio / evidencia |
|---|---|---|---|
| C01 | La versión de caché del SW no cambiaba con el despliegue | `FIXED` | `CACHE_NAME` v3 → v4; `activate` elimina las anteriores |
| C02 | La precarga de la fuente de iconos se creaba desde JavaScript, después del parser | `FIXED` | `<link rel="preload">` en `app.blade.php` resuelto con `Vite::asset()` dentro de `try/catch`; se retiró el preload de `app.ts` |
| C03 | Se emiten seis subconjuntos de Inter | `VERIFIED_OK` | `unicode-range` hace que el navegador descargue sólo latin/latin-ext; eliminar glifos sin medición rompería cobertura |
| C04 | Activos no hasheados quedaban fijados hasta la siguiente versión de caché | `FIXED` | Iconos y manifiesto pasan a network-first con caché como respaldo offline |
| C05 | Manifiesto e iconos sin política de caché explícita en nginx | `FIXED` | `max-age=3600, must-revalidate` y cabeceras de seguridad |
| C06 | `cacheFirst` trataba iconos y manifiesto como inmutables | `FIXED` | `networkFirst()` nuevo; `/build/` sigue cache-first por estar hasheado |
| C07 | Se emite `bootstrap-icons.woff` (180.288 B) además del woff2 | `MEASUREMENT_REQUIRED` | El `@font-face` declara ambos formatos; retirar el woff exige demostrar que no hay navegadores objetivo que lo usen. No hay `fontTools`/`pyftsubset` en el entorno |
| C08 | Un fallo de precarga podía dejar el SW sin instalar | `VERIFIED_OK` | El `install` ya añade las entradas una a una con `try/catch`; no se tocó |

## 3. Mediciones reales antes/después

Build de producción (`npm run build`), bytes del directorio `public/build/assets`:

| Métrica | Antes | Después | Variación |
|---|---:|---:|---:|
| `app.js` (ruta crítica) | 102.238 B | 70.582 B | −31.656 B (−31,0 %) |
| `app.js` gzip | 32.600 B | 22.784 B | −9.816 B (−30,1 %) |
| Chunk del panel del asistente | — (dentro de `app.js`) | 17.539 B JS + 5.697 B CSS | sólo al abrir |
| JS total del build | 708.473 B | 715.978 B | +7.505 B (+1,1 %) |
| JS total gzip | 236.688 B | 241.077 B | +4.389 B |
| Total del build | 1.609.237 B | 1.616.833 B | +7.596 B |
| Total gzip | 825.999 B | 830.869 B | +4.870 B |
| CSS total | 367.920 B | 368.011 B | +91 B |
| Fuentes | 532.844 B | 532.844 B | 0 |

Interpretación honesta: el total del build no baja porque el panel se separó en
un chunk propio y aparecen límites nuevos; lo que baja es **lo que descarga una
página que no abre el asistente**, que es el caso normal. El chunk del panel sólo
se pide al pulsar el botón.

Respuestas HTTP observadas (`curl -I` desde el host):

| Comprobación | Antes | Después |
|---|---|---|
| `/login` — `X-Content-Type-Options` | 2 cabeceras | 1 cabecera |
| `/login` — `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` | 2 cada una | 1 cada una |
| `/build/assets/app-*.js` — `Cache-Control` | `public` (7 días) | `public, max-age=31536000, immutable` |
| `/build/assets/app-*.js` — cabeceras de seguridad | ausentes | las 4 presentes |
| `/sw.js` — `Cache-Control` | genérica | `no-cache` |
| `/manifest.json` — `Cache-Control` | genérica | `public, max-age=3600, must-revalidate` |

No se declaran mejoras de latencia de API ni de base de datos: no hay datos de
producción y el entorno local no es representativo. Esas filas quedan
`MEASUREMENT_REQUIRED`.

## 4. Verificación ejecutada

```bash
# Frontend
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npm run typecheck'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npm run build'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npx eslint <archivos modificados>'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npx vitest run \
  tests/frontend/assistant-availability.spec.ts \
  tests/frontend/assistant-recovery.spec.ts \
  tests/frontend/assistant-artifacts.spec.ts \
  tests/frontend/notifications.bell.spec.ts \
  tests/frontend/attachment-picker.spec.ts \
  tests/frontend/asistente.page.spec.ts'

# Backend
docker exec consultora-dh-app sh -lc 'cd /var/www/html && php artisan test \
  tests/Feature/Notifications/ReminderLifecycleTest.php \
  tests/Feature/Notifications/PushReminderActionTest.php \
  tests/Feature/Notifications/ReminderSchedulerClockTest.php \
  tests/Feature/Notifications/NotificationCountTest.php'
docker exec consultora-dh-app sh -lc 'cd /var/www/html && php artisan test \
  tests/Feature/A05/ReportTest.php \
  tests/Feature/A05/ReportScheduleTest.php \
  tests/Feature/A07/TabularReportTest.php \
  tests/Feature/A02/ClientTest.php \
  tests/Feature/A06/TelegramTest.php'
docker exec consultora-dh-app sh -lc 'cd /var/www/html && ./vendor/bin/pint --test <archivos modificados>'
docker exec consultora-dh-nginx nginx -t
git diff --check
```

Resultados:

- `vue-tsc --build --force`: sin errores.
- `npm run build`: correcto.
- ESLint sobre los archivos modificados: sin errores.
- Vitest: 34 pruebas, 6 archivos, todas en verde.
- Pest recordatorios + contador: 16 pruebas (14 + 2), todas en verde.
- Pest reportes/clientes/Telegram: 79 pruebas, todas en verde.
- Pint: sin incidencias tras aplicar el formateo a dos archivos.
- `nginx -t`: sintaxis correcta y recarga aplicada en local.
- `git diff --check`: sin errores de espacio.

## 5. Integridad funcional

| Área | Estado | Evidencia |
|---|---|---|
| RBAC / permisos | Intacto | No se modificó ningún permiso ni guardia; las rutas nuevas conservan `auth` |
| CSRF | Intacto | Se eliminó una meta duplicada; realtime envía el valor decodificado de la cookie en `X-XSRF-TOKEN` y `/broadcasting/auth` sigue autorizando canales privados (4/4 pruebas) |
| Propiedad / aislamiento | Intacto | El contador usa `$request->user()->unreadNotifications()`; el test comprueba que no cuenta las de otro usuario |
| Aritmética financiera | Intacta | No se tocó ningún cálculo; sólo se retiró un `COUNT` duplicado y se cambió el transporte de descargas |
| Confirmación de escrituras | Intacta | El flujo de propuestas no cambió; sólo se dejó de recargar la conversación tras confirmar |
| PWA / Push / outbox / IndexedDB | Intacto | `sw.js` sólo cambió la política de caché de build/iconos/manifiesto; outbox y push no se tocaron |
| Telegram R05 / idempotencia | Intacto | Estados, claim atómico y `failed_external` sin cambios; suite dirigida de Telegram en verde (32 de notif-tg-02 + A06). R1 sólo restringe qué filas se podan |
| IA / failover / confirmaciones | Intacto | Sin cambios en selección de proveedores ni en el protocolo de propuestas |

## 6. Residuales y bloqueos externos

1. **Mediciones de producción ausentes** para A06, A10–A13, A15, A16, B22 y C07.
   Se requiere una base con volumen real, `EXPLAIN (ANALYZE, BUFFERS)` y datos
   de campo (LCP/CLS, RUM) antes de decidir índices o paginación. No se
   inventaron porcentajes.
2. **Peso de CSS**: `app.css` sigue en 348.000 B porque incluye Bootstrap
   completo. Reducirlo exige demostrar qué clases se usan en todas las rutas y
   es un cambio de mayor riesgo visual; queda fuera de este lote.
3. **Fuente de iconos**: se emiten `.woff` y `.woff2`. Retirar el `.woff`
   requiere una matriz de navegadores objetivo y, para el woff2, una herramienta
   de subsetting que no está instalada.
4. **El panel del asistente** añade un chunk de 17,4 KB que antes formaba parte
   de `app.js`. El total del build crece; la ruta crítica baja. Se reporta tal
   cual.
5. **La caché HTTP en memoria** es por pestaña y sólo para GET con `cacheTtl`
   explícito. No se cachean datos privados de pantallas; no hay caché
   compartida entre cuentas y el logout la vacía.
6. **Sin medición LIVE de proveedores de IA**: este lote es de rendimiento, no
   reclasifica ni recertifica proveedores; las capacidades LIVE siguen siendo
   las del informe de NOTIF-TG-02.

## 7. PERF-01-R1 — correcciones tras auditoría externa

La implementación anterior se auditó en el tip remoto
`51c96fea07e4448417f7a136287f6a9f1103aca1`. Las correcciones de esta sección
están en el commit de implementación
`2a577011adb6c5a167eb2cd84dce03a28fb363a2`. El informe se publicó en
`232ba89cb8ddc2e51246ab945253193d7ff70007`; esta corrección de exactitud se
publica en el commit de documentación inmediatamente posterior. La auditoría encontró cuatro
defectos y un ajuste menor. No se rehizo el hito ni se tocaron otros módulos:
cada corrección es local al archivo que la causaba.

### 7.1 Recordatorios — transición durable de entrega

El defecto: `claim()` escribía `reminder_second_sent_at` antes de enviar. Un
trabajador que moría entre el `COMMIT` y el Push dejaba la fila como enviada y
el segundo aviso no se reintentaba nunca. `send()` además creaba la notificación
interna antes de una excepción posterior, de modo que una compensación podía
duplicarla.

La corrección (`app/Domain/Operations/ReminderDispatcher.php`, migración
`2026_10_29_100000_add_reminder_delivery_state_to_operational_tasks.php`):

- La transacción de `claim()` ya no escribe las marcas de "enviado": reserva la
  etapa en `reminder_delivery_stage` / `reminder_delivery_token` /
  `reminder_delivery_started_at` e inicializa `reminder_delivery_internal_at` a
  nulo. Sigue sin hacer I/O.
- La notificación interna y la marca `reminder_delivery_internal_at` se escriben
  en **una sola transacción local**. Si la segunda falla, la primera se revierte
  con ella: no queda una notificación visible que el siguiente intento repita.
- El Push corre fuera de toda transacción. Si lanza, la notificación interna se
  conserva y la reserva queda para que el lease la resuelva; no se reintenta a
  ciegas un resultado externo incierto.
- `settleAbandonedDelivery()` resuelve una reserva vencida
  (`DELIVERY_LEASE_MINUTES = 5`): sin notificación interna, libera la reserva y
  la etapa se puede reclamar de nuevo (no se perdió nada); con notificación
  interna, escribe la marca durable con la hora en que se escribió la
  notificación y cierra la etapa, de modo que el segundo aviso sigue siendo
  único aunque el Push sea desconocido.
- `finalize()` escribe la marca sólo si el token de la reserva sigue siendo el
  propio; una confirmación, un dismiss o un cierre de tarea durante el envío
  ganan y la reserva se libera sin marcar.

Decisiones explícitas: no se promete entrega exactamente una vez ante un
resultado externo desconocido; se garantiza que el aviso visible no se duplica y
que el segundo aviso no se pierde en silencio. Los reintentos técnicos siguen
cada cinco minutos, con la marca puesta en el momento del intento.

Pruebas (`tests/Feature/Notifications/ReminderDeliveryRecoveryTest.php`, 4/4):

1. reserva del segundo aviso sin notificación interna → el siguiente run lo
   envía (no se perdió);
2. reserva con notificación interna → se liquida sin segundo aviso y
   `reminder_second_sent_at` toma la hora de la notificación;
3. Push que falla después de escribir la notificación → una sola notificación
   interna, y al vencer el lease la etapa se liquida sin duplicarla;
4. entrega inicial abandonada → la marca se repone a la hora de la notificación
   y el reintento técnico de cinco minutos sigue ocurriendo.

### 7.1-bis Concurrencia de la reserva — `markInternal()` (cierre R1)

Riesgo residual detectado en la auditoría de `cc20068`: el `UPDATE` de
`markInternal()` filtraba por `reminder_delivery_token` pero no comprobaba las
filas afectadas. Un trabajador que hubiera perdido su reserva durante una pausa
podía escribir la notificación interna, continuar hacia el Push y duplicar el
envío.

Corrección (`app/Domain/Operations/ReminderDispatcher.php`,
`app/Domain/Operations/ReminderDeliveryLost.php`):

- `markInternal()` exige `$updated === 1`. Si la reserva cambió de manos, lanza
  `ReminderDeliveryLost` dentro de la misma transacción que escribe la
  notificación: PostgreSQL revierte la notificación y el Push no se ejecuta.
- El `catch` específico registra la pérdida y **no** llama a `clearDelivery()`.
  El `UPDATE` ya era condicional por token, y ahora tampoco hay compensación que
  pueda tocar la reserva del otro trabajador.
- El `catch` general conserva el comportamiento anterior para fallos locales:
  libera la reserva propia y el siguiente ciclo reintenta.
- Sin cambios en recuperación por lease, segundo aviso único, reintentos de
  cinco minutos, dismiss, cancelación ni finalización.

Pruebas (`tests/Feature/Notifications/ReminderDeliveryAtomicityTest.php`, 3/3,
sin `Notification::fake()`):

1. entrega normal: una fila real en `notifications` y la marca durable se
   confirman juntas;
2. token tomado por otro trabajador durante el `INSERT` de la notificación: la
   notificación se revierte, la tabla `notifications` queda en 0 y el mock de
   Push no recibe ninguna llamada;
3. `markInternal()` con token obsoleto lanza `ReminderDeliveryLost` y no
   modifica el token, la marca interna ni el segundo aviso del trabajador que sí
   posee la reserva.

Resultado exacto del área de recordatorios:

- `ReminderDeliveryAtomicityTest` + `ReminderDeliveryRecoveryTest`: `7 passed
  (50 assertions)`.
- `ReminderLifecycleTest` + `ReminderSchedulerClockTest`: `9 passed
  (43 assertions)`.
- `php -l` sin errores en los 3 archivos; Pint `PASS 3 files`;
  `git diff --check` sin errores.

No se tocaron los otros cuatro ajustes de R1 ni se repitieron los 48 hallazgos.

### 7.2 Telegram — poda de estados irrecuperables

`telegram:prune-updates` borraba todo lo anterior a siete días, incluido
`failed_external`, que está pendiente de conciliación. Ahora
(`app/Console/Commands/PruneTelegramUpdates.php`):

- sólo se borran `succeeded` y `failed_recoverable` (este último tiene
  `external_started_at` nulo por definición: no hubo efecto externo);
- `failed_external` y `pending` se conservan siempre, incluso con
  `--days=0`; el comando informa cuántos quedaron retenidos;
- el borrado va por lotes acotados (`--chunk`, por defecto 1.000) con un límite
  de seguridad, sin una transacción larga sobre una tabla que el webhook inserta;
- el scheduler horario con `withoutOverlapping()->onOneServer()` no cambió, y
  `TelegramBotService::claimUpdate()`, R05 y la idempotencia no se tocaron.

Pruebas (`tests/Feature/Notifications/TelegramPruneTest.php`, 3/3): un
`failed_external` y un `pending` antiguos sobreviven mientras `succeeded` y
`failed_recoverable` antiguos se eliminan; con ventana cero se conservan los dos
primeros; ocho filas se borran en lotes de tres.

### 7.3 Caché HTTP — época de invalidación

El defecto: `invalidateHttpCache()` vaciaba `responseCache` pero dejaba los GET
en `inFlight`, y `clearHttpCache()` vaciaba los mapas sin impedir que una promesa
antigua resolviera después e insertara su resultado. En un logout o cambio de
cuenta eso podía devolver una lectura de la sesión anterior.

La corrección (`resources/js/services/http.ts`):

- `cacheGeneration` se incrementa en **cada** invalidación. Cada petición
  captura la época al empezar y sólo escribe en caché si la época no cambió.
- La invalidación total vacía además `inFlight`: una promesa anterior a una
  escritura no se reutiliza. La invalidación por prefijo descarta las entradas y
  promesas de ese prefijo.
- `clearHttpCache()` (logout / cambio de cuenta) vacía caché y promesas y anula
  el handshake CSRF, de modo que la siguiente mutación pide cookie nueva.
- La coalescencia de GET válidos se mantiene; `AbortSignal` y el contrato de
  errores (`ApiError`) no cambian. Sólo se cachean GET con `cacheTtl` explícito:
  ningún dato financiero privado entra en la caché.

Pruebas (`tests/frontend/http-cache.spec.ts`, 4/4): un GET lento anterior a la
invalidación no repuebla la caché; una promesa anterior a una escritura no se
reutiliza; la coalescencia sigue funcionando; el logout limpia caché y handshake
y el encabezado enviado es el valor decodificado de la cookie.

### 7.4 Reverb — encabezado CSRF correcto

`realtime.ts` enviaba el valor de la cookie `XSRF-TOKEN` como `X-CSRF-TOKEN`.
Laravel espera `X-CSRF-TOKEN` con el token sin cifrar de `csrf_token()` y
`X-XSRF-TOKEN` con el valor de la cookie (que descifra con la clave de cookies).
El único valor disponible en este cliente es la cookie, así que ahora se envía
en `X-XSRF-TOKEN` (`resources/js/stores/realtime.ts`). No se desactivó ni
exceptuó CSRF, no se restauró el HTML dinámico y el endpoint sigue siendo
`/broadcasting/auth` con la sesión y la autorización de canal reales.

Pruebas:

- `tests/frontend/realtime-csrf.spec.ts` (1/1): Echo recibe
  `X-XSRF-TOKEN: 'abc=def&ghi'` a partir de la cookie `abc%3Ddef%26ghi`, sin
  `X-CSRF-TOKEN`, y conserva `authEndpoint`.
- `tests/Feature/Notifications/BroadcastAuthorizationTest.php` (4/4):
  `/broadcasting/auth` firma el canal privado de soporte para un staff con
  `support.view_all`, y rechaza a un staff sin acceso, a una cuenta cliente que
  no es dueña de la conversación, a un invitado y a un id no numérico (sin 500).

### 7.5 Stream documental — alcance del `finally`

En `TelegramDocumentDeliveryService` el `finally` empezaba después de
`$beforeExternalEffect()`. Ahora el callback y la llamada HTTP están dentro del
mismo `try` y el `finally` cierra el recurso en ambos casos. El fallo del
callback no se envuelve como `TelegramExternalEffectFailed`: como no hubo efecto
externo, la actualización sigue siendo recuperable. La idempotencia y el envío
no cambian.

Prueba (`tests/Feature/Notifications/TelegramDocumentStreamTest.php`, 1/1): un
callback que lanza cierra el stream (`is_resource($stream) === false`) y no se
envía nada a Telegram.

### 7.6 Regresión de prueba detectada al ejecutar el área

Al correr `tests/Feature/Notifications/TelegramNotifTg02Test.php` aparecieron dos
fallos preexistentes de PERF-01 A04: los tests leían `$request->body()` de una
petición multipart **después** de que el servicio cerrara el stream, por lo que
Guzzle hacía `fseek()` sobre un recurso cerrado. El comportamiento de producción
es correcto (el cuerpo se lee durante el envío y el handle se cierra); la prueba
leía tarde. Se ajustaron esas dos pruebas para capturar el cuerpo en el momento
del envío (`Http::fake` con closure) y comprobar lo mismo: nombre, contenido,
que no se filtre `stored_path` ni la ruta privada. La suite completa del archivo
queda en 32/32.

### 7.7 Verificación R1 y mediciones

Comandos ejecutados (sin repetir las suites no afectadas):

```bash
docker exec consultora-dh-app sh -lc 'cd /var/www/html && php artisan test \
  tests/Feature/Notifications/ReminderLifecycleTest.php \
  tests/Feature/Notifications/PushReminderActionTest.php \
  tests/Feature/Notifications/ReminderSchedulerClockTest.php \
  tests/Feature/Notifications/NotificationCountTest.php \
  tests/Feature/Notifications/ReminderDeliveryRecoveryTest.php \
  tests/Feature/Notifications/TelegramPruneTest.php \
  tests/Feature/Notifications/TelegramDocumentStreamTest.php \
  tests/Feature/Notifications/BroadcastAuthorizationTest.php \
  tests/Feature/Notifications/TelegramNotifTg02Test.php \
  tests/Feature/A06/TelegramTest.php'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npx vitest run \
  tests/frontend/http-cache.spec.ts \
  tests/frontend/realtime-csrf.spec.ts \
  tests/frontend/auth.store.spec.ts \
  tests/frontend/notifications.bell.spec.ts'
docker exec consultora-dh-app sh -lc 'cd /var/www/html && ./vendor/bin/pint <archivos R1>'
docker exec consultora-dh-app sh -lc 'cd /var/www/html && php -l <archivos R1>'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npx eslint <archivos frontend R1>'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npm run typecheck'
docker exec consultora-dh-node sh -lc 'cd /var/www/html && npm run build'
git diff --check
```

Resultados textuales: `74 passed (322 assertions)` en Pest; `4 passed` archivos
y `22 passed` pruebas en Vitest; Pint `PASS 17 files`; `php -l` sin errores en
los 10 archivos; ESLint sin salida; `vue-tsc --build --force` sin errores; build
correcto; `git diff --check` sin errores.

Mediciones del bundle tras R1 (mismo método, `public/build/assets`):

| Métrica | Antes de PERF-01 | PERF-01 | PERF-01-R1 |
|---|---:|---:|---:|
| `app.js` (ruta crítica) | 102.238 B | 70.582 B | 70.582 B |
| `app.js` gzip | 32.600 B | 22.784 B | 22.771 B |
| Chunk del panel | — | 17.539 B JS + 5.697 B CSS | 17.539 B + 5.697 B |
| JS total | 708.473 B | 715.978 B | 716.048 B |
| Total del build | 1.609.237 B | 1.616.833 B | 1.616.903 B |
| CSS total | 367.920 B | 368.011 B | 368.011 B |
| Fuentes | 532.844 B | 532.844 B | 532.844 B |

La ruta crítica conserva la reducción de 31.656 B (−31,0 %). El total sube 70 B
por el código de las correcciones (época de caché y comentarios); no se
reinterpretan ni se ocultan las cifras anteriores. Las políticas de nginx, el
streaming, la carga paralela, la caché por TTL y el chunk diferido siguen
presentes; R1 no las revierte.

## 8. Bloque de entrega

```text
PERF_01_R1_RESULT=PASS
BASE_SHA=51c96fea07e4448417f7a136287f6a9f1103aca1
FINAL_SHA=2a577011adb6c5a167eb2cd84dce03a28fb363a2
REMOTE_SHA=2a577011adb6c5a167eb2cd84dce03a28fb363a2 (implementación R1; el cierre de concurrencia y este informe van en el commit posterior)
R1_CONCURRENCY_COMMIT=el commit que contiene este informe (SHA en la entrega)
MAIN_UNCHANGED=YES (2df9c9ec3b6af5fee00142117502ec815d1b2f3d)
PUSH=fast-forward (sin --force)
FILES_CHANGED=17
A_FINDINGS=18/18
B_FINDINGS=22/22
C_FINDINGS=8/8
FIXED=33
OPTIMIZED=0
VERIFIED_OK=6
MEASUREMENT_REQUIRED=9
BLOCKED=0
BUILD=PASS
TYPECHECK=PASS
LINT=PASS
TESTS_R1=PASS (74 Pest + 22 Vitest)
TESTS_R1_CONCURRENCY=PASS (16/16 recordatorios afectados; 3 pruebas nuevas)
TESTS_PERF01=NOT_RERUN (sin cambios en las áreas no afectadas; ver TESTS_R1 y las mediciones conservadas)
INTEGRITY_AUTH=PASS
PWA=PASS (política de caché; push/outbox sin cambios)
TELEGRAM_R05=PASS (32/32 notif-tg-02 + A06; poda restringida)
AI_CONFIRMATIONS=PASS
LIMITATIONS=mediciones de volumen/latencia de producción no disponibles; CSS y fuente de iconos sin reducir; el informe no puede contener el SHA del commit que lo publica
READINESS=LISTO_PARA_REVISION
```

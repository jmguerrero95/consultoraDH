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
| A02 | El envío Push (HTTP) ocurría dentro de `DB::transaction`, reteniendo el lock de la fila durante la latencia del proveedor | `FIXED` | Transacción solo reclama la etapa; el envío ocurre después; si falla o la tarea fue descartada, `revertClaim()` compensa condicionalmente. Pruebas de ciclo de recordatorios: 14/14 |
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
| A14 | Poda de `telegram_updates` dentro del webhook cada 100 updates | `FIXED` | Retirada del camino de respuesta; `TelegramBotService` ya no borra en línea |
| A15 | Coste de observabilidad por petición | `MEASUREMENT_REQUIRED` | No existe middleware de observabilidad propio identificable; medirlo requiere instrumentación de producción |
| A16 | Memoria del preview de importaciones | `MEASUREMENT_REQUIRED` | El tamaño real de los archivos de clientes no está disponible en QA |
| A17 | Conteo de notificaciones no leídas | `VERIFIED_OK` | `unreadNotifications()->count()` usa la relación indexada de Laravel; ahora se expone en un endpoint dedicado (B03) |
| A18 | La retención de Telegram dependía de que llegara el update 100 | `FIXED` | Nuevo `telegram:prune-updates`, programado cada hora con `withoutOverlapping()->onOneServer()`. `bootstrap/app.php` |

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
| B08 | GET idénticos concurrentes se duplicaban (vocabularios y catálogos) | `FIXED` | Coalescencia por método+URL en `request()`; las peticiones con `AbortSignal` no se comparten |
| B09 | Catálogos estables se volvían a pedir en cada navegación | `FIXED` | `cacheTtl` opt-in de 5 minutos para vocabularios de planillas, novedades, tareas, tipos de documento, reportes, pagos y cartera |
| B10 | El shell HTML llevaba un `<meta name="csrf-token">` que sólo usaba realtime | `FIXED` | Eliminado; `csrfToken()` lee la cookie `XSRF-TOKEN`, la misma que usa el cliente HTTP. `realtime.ts` actualizado |
| B11 | Los assets con hash se servían con `max-age=7d` sin `immutable` | `FIXED` | `/build/` con `public, max-age=31536000, immutable` |
| B12 | Las ubicaciones estáticas perdían las cabeceras de seguridad por la herencia de `add_header` | `FIXED` | Las cuatro cabeceras se repiten en cada ubicación estática; verificado con `curl -I` |
| B13 | HTML dinámico recibía las mismas cabeceras de nginx y de Laravel (duplicadas) | `FIXED` | Sin `add_header` a nivel de servidor; Laravel es la única fuente en respuestas dinámicas. Medido: 2→1 por cabecera |
| B14 | El transcript se keyeaba por `created_at`+índice | `FIXED` | `messageKey()` usa id del servidor, `client_turn_id`+rol o índice local como último recurso |
| B15 | Confirmar una propuesta recargaba toda la conversación | `FIXED` | Se elimina la propuesta y se muestra la confirmación sin volver a pedir mensajes/artefactos/adjuntos |
| B16 | El polling de tareas seguía cada 2 s con la pestaña oculta | `FIXED` | Sin temporizador en `visibilityState=hidden`; refresco inmediato al volver |
| B17 | La caché de respuestas sobrevivía al cierre de sesión | `FIXED` | `auth.clear()` llama `clearHttpCache()`; nada leído por una cuenta se reutiliza en otra |
| B18 | No había precarga de las pantallas siguientes | `FIXED` | Prefetch por ruta en tiempo idle sólo desde dashboard/clientes/cartera, y sólo autenticado |
| B19 | Las escrituras no invalidaban lecturas cacheadas | `FIXED` | Toda petición mutante limpia la caché de respuestas antes de ejecutarse |
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
| CSRF | Intacto | Se eliminó una meta duplicada; el token sigue viajando en `X-XSRF-TOKEN` y `X-CSRF-TOKEN` desde cookie |
| Propiedad / aislamiento | Intacto | El contador usa `$request->user()->unreadNotifications()`; el test comprueba que no cuenta las de otro usuario |
| Aritmética financiera | Intacta | No se tocó ningún cálculo; sólo se retiró un `COUNT` duplicado y se cambió el transporte de descargas |
| Confirmación de escrituras | Intacta | El flujo de propuestas no cambió; sólo se dejó de recargar la conversación tras confirmar |
| PWA / Push / outbox / IndexedDB | Intacto | `sw.js` sólo cambió la política de caché de build/iconos/manifiesto; outbox y push no se tocaron |
| Telegram R05 / idempotencia | Intacto | Estados, claim atómico y `failed_external` sin cambios; 79 pruebas de Telegram en verde. Sólo se movió la poda a un comando |
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

## 7. Bloque de entrega

```text
PERF_01_RESULT=PASS
BASE_SHA=b71a5ace8ae6b7ac907cda0d6be9ec170e2cafd9
FINAL_SHA=PENDING
REMOTE_SHA=PENDING
MAIN_UNCHANGED=YES (2df9c9ec3b6af5fee00142117502ec815d1b2f3d)
PUSH=PENDING
FILES_CHANGED=33
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
TESTS=PASS (129: 34 Vitest + 95 Pest)
INTEGRITY_AUTH=PASS
PWA=PASS (política de caché; push/outbox sin cambios)
TELEGRAM_R05=PASS (suite dirigida en verde)
AI_CONFIRMATIONS=PASS
LIMITATIONS=mediciones de volumen/latencia de producción no disponibles; CSS y fuente de iconos sin reducir
READINESS=LISTO_PARA_REVISION
```

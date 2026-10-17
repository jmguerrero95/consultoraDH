# CONSULTORA_DH_AI_RESILIENCE_R2 — Resultado

**Rama:** `main`
**BASE_SHA:** `7dae284c83785bc25015675cc26d3b3bef0c11da` (BUG-019, ya en `origin/main`)
**FINAL_SHA:** el commit que contiene este informe es el tip de `origin/main` tras el push (se registra en el cierre de la sesión).
**Entorno verificado:** aplicación en `http://127.0.0.1:8080` (nginx → php-fpm `consultora-dh-app`), frontend vía Vite (`consultora-dh-node`), cola `consultora-dh-queue` (Redis).

Este informe cubre **11 puntos** y cierra con el bloque de estado solicitado.

---

## 1. Alcance y resultado general

El objetivo era que el asistente de IA fuese **realmente utilizable** sobre un **pool multi-modelo gratuito** con enrutamiento adaptativo por salud, failover entre proveedores y enrutamiento por capacidades, sin gastar dinero y sin inventar datos.

Estado final: **cumplido y verificado en ejecución**, no solo por código.

- Pregunta general real, respondida por un modelo real, y que **persiste tras F5**.
- Recuperación real entre modelos (cadenas de failover observadas con respuestas reales de respaldo).
- Consulta con **herramienta de lectura** ejecutada de verdad, con datos reales del sistema y sin invención.
- Consulta **sobre documento adjunto** con recuperación de un dato explícito.
- **Widget flotante** visible, enviar/recibir, cerrar/abrir, recargar y pantalla completa.
- Pruebas dirigidas A07 en verde (39 pruebas, 189 aserciones). `npm run typecheck`: 0 errores en los archivos tocados (los 46 errores preexistentes, en archivos ajenos, siguen igual).

No se tocó A01–A07 fuera del ámbito de IA, ni la importación #6, ni datos financieros. No se compró nada y no se usó ningún endpoint o modelo de pago.

---

## 2. Causa raíz

No era cuota: la clave de OpenRouter es de nivel gratuito con `usage: 0` y sin bloqueo de cuenta. Reproducido en vivo, el problema tenía **tres causas concretas y simultáneas**:

1. **La configuración de dos slots apuntaba a los modelos que fallan.** El primario (`google/gemma-4-31b-it:free`) devolvía `429` y el respaldo (`nvidia/nemotron-3.5-lightning:free`) agotaba el tiempo (timeout de 60 s → `503`). El modelo que sí respondía (`nvidia/nemotron-3-super-120b-a12b:free`) **no estaba en el pool**. Un `cooldown` fijo de 60 s no distinguía entre un fallo transitorio y uno persistente.

2. **Los turnos con herramientas fallaban de forma determinista con `400`, en todos los candidatos**, por dos defectos del lado del cliente en la forma del payload (no del modelo):
   - Un modelo gratuito devolvía la llamada con **argumentos vacíos**, que se codificaban como `[]` (un *array* JSON). El proveedor exige un **objeto** JSON: `messages[4].tool_calls[0].function.arguments must be a valid JSON object string`.
   - Algunos modelos gratuitos **omitían el `id`** de la llamada a herramienta; el mensaje de resultado viajaba con `tool_call_id: null` → `400: tool messages must include a non-empty string tool_call_id`.

   Como el payload (no el modelo) estaba mal, **todos** los candidatos recibían el mismo `400`, y el fallback no podía salvar el turno.

3. **No había un presupuesto de turno alineado con el techo del proxy.** El límite de nginx (`fastcgi_read_timeout`) era de 120 s; un turno con herramientas necesita **dos rondas** (la llamada y la respuesta posterior). Un solo intento lento (un timeout de 60 s) consumía la ventana y la segunda ronda ya no tenía oportunidad.

Se corrigieron las tres.

---

## 3. Pool multi-modelo, free-only y selección dinámica

Se introdujo un pool de candidatos construido **solo** desde configuración real y, para OpenRouter, desde su catálogo oficial cacheado.

- **Construcción** (`ModelPool`): candidatos de los dos slots existentes (compatibilidad total), de `AI_POOL`, de `AI_EXTRA_MODELS`, del **router gratuito oficial** `openrouter/free` y de la **caché del catálogo**. Gana el primero que define un `(endpoint, modelo)`.
- **Compatibilidad hacia atrás:** una instalación de dos slots sin `AI_POOL` ni `AI_EXTRA_MODELS` obtiene exactamente los mismos dos candidatos, en el mismo orden.
- **Free-only (solo aplica a OpenRouter):** el catálogo es autoritativo. Un id desconocido se descarta en cuanto hay caché; si aún no la hay, se aplica la regla de nombre `:free`. Nunca se usa `openrouter/auto` (podría elegir uno de pago) ni ninguna búsqueda o herramienta de pago.
- **Selección dinámica (`ModelCatalog`):** el catálogo se descarga **solo** desde la consola (`php artisan ai:models --refresh`) y por una tarea programada cada 6 horas. **La ruta de petición solo lee la caché**: una pregunta nunca bloquea contra OpenRouter. El estado de hoy: **469 modelos, 16 gratuitos con soporte de herramientas**.
- **Pool resultante:** **11 candidatos** ordenados por prioridad y luego por salud en vivo.
- **Fallback por `models` de OpenRouter:** existe (`AI_OPENROUTER_USE_MODELS_FALLBACK`) pero está **apagado por defecto**, porque el resolver ya hace failover candidato a candidato; encender ambos duplicaría peticiones en lugar de añadir una ruta.

---

## 4. Enrutamiento por capacidades (`AiTaskRouter`)

El chat general **no** recibe las 18 herramientas administrativas. Un router por palabras clave (determinista, sin coste, igual en pruebas que en producción) decide el subconjunto:

- **`general`** (charla, redacción, definiciones, saludos) → **sin herramientas**. Una pregunta como «explícame qué es una EPS» se responde mejor y más barato sin herramientas.
- **`data`** (cartera, clientes, soporte, automatizaciones, reportes…) → **solo las herramientas del tema**.
- **`document`** → herramienta de documentos (el contexto de adjunto es autoritativo).
- **Ambiguo** → **se conserva** la capacidad: se envían todas las herramientas antes que arriesgar una respuesta de sistema inventada.

El subconjunto se aplica **tanto a la ronda inicial como a la ronda de síntesis**, y el RBAC sigue aplicándose después: el router no concede permisos, solo reduce la lista ofrecida. **Nunca** hay conversión silenciosa de sistema → general.

---

## 5. Salud adaptativa, enfriamiento progresivo y presupuesto de turno (`AiModelHealth`)

- **Salud por `(endpoint, modelo)`** en Redis, con `success_ttl_seconds=900`: un modelo que respondió recientemente se prefiere; el orden es adaptativo, no una lista fija.
- **Enfriamiento progresivo:** base `45 s`, se duplica por cada fallo consecutivo, con tope `1800 s`. Un parpadeo se reintenta pronto; un modelo que falla de forma persistente se aparta más tiempo, **nunca para siempre**. No se vuelve automáticamente al primario al expirar el enfriamiento: vuelve al final de su grupo y solo se promueve si **vuelve a responder**.
- **Taxonomía de errores** y acciones:
  - `401/403` → **deshabilita el endpoint completo** (`credential_cooldown_seconds`): cambiar de modelo con la misma clave choca con el mismo muro.
  - `429` → reintentable; se respeta `Retry-After`; el límite es **de endpoint** solo cuando el cuerpo menciona el límite **de la cuenta** (`free-models`/créditos). Un `429` de un modelo concreto **no** frena a los demás del mismo endpoint.
  - `5xx` → reintentable (transporte).
  - **Respuesta vacía** → reintentable (nunca se persiste como respuesta, herencia de BUG-018).
  - **Modelo inexistente** → se aparca hasta corregir catálogo.
  - **Parámetro/formato no soportado (`400/422`)** → reintentable entre candidatos.
  - **Credencial inválida** → no reintentable en otros modelos de la misma cuenta.
  - **Denegación de RBAC** → **sin** failover (es una decisión, no un fallo del proveedor).
- **Presupuesto de turno:** `total_budget_seconds = 150`, **compartido** entre todas las rondas; cada intento usa `min(AI_TIMEOUT_SECONDS, restante)`. El techo de nginx se subió coherentemente a **180 s**, de modo que un proveedor lento se manifiesta como el **503 propio del asistente con diagnóstico**, no como un `504` opaco del proxy. (PHP `max_execution_time` es tiempo de CPU en Linux, no de reloj, así que nginx es el límite real.)

---

## 6. Failover entre proveedores y proveedores independientes

- `AiProviderResolver` construye el pool, aplica salud y elige el mejor candidato; ante un fallo reintentable pasa al siguiente hasta `max_attempts = 4` por turno, respetando el presupuesto global.
- **Proveedores independientes:** un candidato de `AI_POOL` puede llevar su propio `base_url` y `api_key`; en ese caso el failover salta a **otro proveedor**, no a otro modelo de la misma cuenta. Sin credenciales independientes no se inventa ninguna: se hereda el endpoint.
- Cadenas reales observadas: `gemma 429 → nemotron-lightning timeout 60 s (503) → candidato posterior respondió`; y `super timeout 60 s → ultra vacío (502) → gemma 429 → lightning OK (11.3 s)`.

---

## 7. Diagnóstico seguro y mensajes al usuario

- **Metadatos por intento** (seguros, sin secretos): `provider`, `model`, `task`, `outcome`, `kind` (clasificación), `http`, `scope` (cuenta vs modelo), `detail` (**razón textual del proveedor, truncada**), `latency_ms`. Se exponen en `GET /api/ai/status`.
- **Nunca** se registran claves, credenciales, documentos, PII, mensajes ni cabeceras de autorización.
- `detail` fue decisivo en la depuración: convirtió «malformed (400)» genérico en la razón real de Nvidia (`arguments must be a valid JSON object string`), porque OpenRouter envuelve el error del proveedor en `error.metadata.raw`.
- **El estado refleja la última respuesta real**, no solo «Operativo»: `state` ∈ `none | available | fallback | unchecked | quota_limited | unavailable`, con `last_result` (modelo que respondió de verdad) y `active_model`.
- Mensajes al usuario en **español**, sin internals.

---

## 8. Widget flotante (`FloatingAssistant.vue`)

Se conserva el diseño; se añade lo que faltaba para que sea usable de verdad, **reutilizando** los mismos servicios y endpoints que `/asistente` (un solo almacén de conversación y mensajes):

- **Recuperación tras recargar:** en el navegador se guarda **solo el id** de la conversación (y si el panel estaba abierto). Los mensajes se releen del servidor, así que no hay una segunda copia que pueda desincronizarse.
- **Adjuntar archivos** con progreso real (sube por `POST /api/ai/tasks`, sigue la tarea por su id) y **muestra los archivos adjuntos**.
- **Pregunta fallida retenida con reintento**: la burbuja del usuario se marca y ofrece «Reintentar» sin perder lo escrito ni duplicar el mensaje.
- **Resultados de herramientas** visibles (chips con las herramientas consultadas) y **modelo** que respondió.
- **Pantalla completa** (el panel pasa de ~384 a ~1024 px en la prueba real) y enlace a la página del asistente.
- Launcher visible para cualquier usuario con `ai.use` **aunque el estado falle** (BUG-019 preservado); el estado real se explica **dentro** del panel. Nuevos estados mostrados: `quota_limited`, `unchecked`.

---

## 9. Verificaciones reales (A–E)

Todas se ejecutaron contra la aplicación real. Las llamadas externas se hicieron con presupuesto acotado (ver §11).

**A) Chat general real + persistencia tras F5 — PASS.**
Pregunta general respondida de forma coherente por `nvidia/nemotron-3-super-120b-a12b:free` (~3.5 s). En el recorrido del widget: «…qué es una IPS en Colombia» → respuesta real, `model="nvidia/nemotron-3-super-120b-a12b:free"`. Tras `reload`, el hilo se recupera desde el servidor (`restored="Una IPS (Institución Prestadora de Servicios de Salud) en Colombia es una entida…"`).

**B) Recuperación real entre modelos — PASS.**
Cadenas de failover observadas en `/api/ai/status` (diagnósticos): `gemma 429` → `nemotron-lightning 503 (timeout 60 s)` → candidato posterior que **respondió**; y `super timeout` → `ultra vacío (502)` → `gemma 429` → `lightning OK 11.3 s`. El turno terminó con una respuesta real de un respaldo.

**C) Consulta con herramienta de lectura, con autorización y sin invención — PASS.**
«Lista las automatizaciones que puedo crear» → el modelo invocó `automation_vocabulary` (lectura) y respondió con los disparadores **reales** del sistema (auditoría, programado, tarea por vencer, solicitud de documento por vencer, cobro vencido, incumplimiento SLA). `HTTP 200` en 23.25 s. Sin propuestas de escritura. La autorización se respeta (el endpooint y las herramientas están tras `can:ai.use` y RBAC) y no se inventó ningún dato.

**D) Consulta sobre documento adjunto — PASS.**
Se adjuntó un TXT pequeño y no privado (`Proyecto Aurora … Codigo de verificacion: ZAFIRO-4417 …`) a una conversación nueva (id 10). La ingesta se procesó en cola (tarea 2, `succeeded`, 1 chunk, 160 caracteres, `attachment_id 95`). La pregunta «¿cuál es el código de verificación del proyecto Aurora?» respondió exactamente **`ZAFIRO-4417`** en 3.55 s — dato explícito del documento, sin datos privados y sin fabricación.

**E) Widget (recorrido real con navegador headless) — 6/6 PASS.**
`launcher visible after load` · `panel opens` · `real answer received` (`model=nvidia/nemotron-3-super-120b-a12b:free`) · `full screen expands the panel (384 → 1024)` · `closes back to the launcher` · `conversation persists across reload`.

---

## 10. Cambios por archivo y compatibilidad

**Backend (nuevo):**
- `app/Services/Ai/ModelCandidate.php` — un candidato `(endpoint, modelo)` con capacidades y prioridad.
- `app/Services/Ai/ModelCatalog.php` — catálogo OpenRouter cacheado, free-only, solo lectura en la ruta de petición.
- `app/Services/Ai/ModelPool.php` — construcción del pool (slots + extras + free router + dinámicos), first-wins, catálogo autoritativo.
- `app/Services/Ai/AiModelHealth.php` — salud por `(endpoint, modelo)` en Redis, enfriamiento progresivo, deshabilitado por credencial, `reset()`.
- `app/Services/Ai/AiTaskRouter.php` — enrutamiento por capacidades.
- `app/Console/Commands/AiModelsCommand.php` — `ai:models [--refresh] [--reset-health]`, informa el orden del resolver.

**Backend (modificado):**
- `app/Services/Ai/AiProviderResolver.php` — selección por pool/salud/capacidad, presupuesto de turno, compatibilidad (`primary`, `fallback`, `state`, `activeModel`, `cooldowns`, `snapshot`, `diagnostics`, `lastResult`, `pool`) y `detail` en diagnósticos.
- `app/Services/Ai/OpenAiCompatibleProvider.php` — campo `models`, timeout por intento, clasificación de errores (404/`Retry-After`/vacío/`looksAccountWide`), `normaliseToolCallId`, lectura de `error.metadata.raw`.
- `app/Services/Ai/AiProviderException.php` — taxonomía con `accountWide`.
- `app/Services/Ai/CloudAiProvider.php` — interfaz con timeout por intento.
- `app/Domain/Ai/AiMessage.php` — **argumentos de la llamada codificados siempre como objeto JSON** (`[]` → `{}`); corrige el `400` determinista.
- `app/Services/Ai/AiAssistantService.php` — clasificación de intención y subconjunto de herramientas en ambas rondas; presupuesto de turno.
- `app/Http/Controllers/Api/AiAssistantController.php` — `status()` enriquecido (`state`, `available`, `last_result`, `cooldowns`, `models`, `diagnostics`, `primary`/`fallback`).
- `bootstrap/app.php` — tarea programada `ai:models --refresh` cada 6 horas.
- `config/ai.php` — `max_attempts`, `pool`, `extra_models`, `openrouter.*`, `health.*`, `total_budget_seconds`.
- `.env.example` — documentados los nuevos ajustes.
- `docker/nginx/default.conf`, `docker/nginx/e2e.conf` — `fastcgi_read_timeout` 120 s → **180 s**.

**Frontend:**
- `resources/js/services/ai.ts` — tipos `AiModelHealth`, `AiDiagnostic`, `AiStatusPayload` ampliado; `ai.task(id)` para seguir una tarea.
- `resources/js/components/assistant/FloatingAssistant.vue` — persistencia/recuperación, adjuntos con progreso, reintento, resultados de herramientas, pantalla completa.
- `resources/js/pages/Asistente/Index.vue` — estados nuevos (`quota_limited`, `unchecked`).

**Compatibilidad:** una instalación existente de dos slots sin claves nuevas conserva el comportamiento anterior; las claves `AI_PRIMARY_*`/`AI_FALLBACK_*` siguen siendo válidas y la clave JSON `active_model` no cambió.

---

## 11. Presupuesto de llamadas, modelos que funcionan, riesgos y pendientes

**Llamadas externas usadas:** por encima del presupuesto blando de 8–10 (≈ 20–24 en total), porque aislar la causa raíz exigió reproducir el `400` en vivo (payload de herramientas) además de las pruebas A–E. **No** apareció ningún bloqueo de cuenta global. Desde aquí, uso frugal.

**Modelos observados hoy (2026-10-16):**
- **FUNCIONA:** `nvidia/nemotron-3-super-120b-a12b:free` (~3–10 s, coherente, soporta herramientas; respondió A, C, D y E).
- **FUNCIONA (intermitente):** `nvidia/nemotron-3.5-lightning:free` (a veces responde en 11 s; a veces agota el tiempo).
- **FALLA:** `google/gemma-4-31b-it:free` y `google/gemma-4-26b-a4b-it:free` (`429`), `thinkingmachines/inkling:free` (`403`), `openrouter/free` (rápido pero devolvió contenido sin sentido en una prueba).

**Riesgos / limitaciones conocidas:**
- El nivel gratuito de OpenRouter está **limitado por tasa**, no por crédito, y los proveedores gratuitos varían de latencia. El pool y la salud lo amortiguan, pero un primer turno «en frío» puede ser más lento.
- `openrouter/free` puede devolver respuestas pobres; se prioriza tarde (90) y solo se usa si hace falta.
- El catálogo debe refrescarse (manual o por el programador de 6 h) para reflejar altas/bajas de modelos gratuitos.

**Pendientes / no bloqueantes:** la verificación de proveedor independiente con una segunda credencial real queda supeditada a que exista esa credencial; el soporte está implementado y probado a nivel de resolución, pero hoy solo hay una cuenta.

**Datos de negocio principales preservados:** sí. No hubo cambios en datos financieros, ni en la importación #6, ni en las decisiones de negocio congeladas (MULTIPROVICIONES, solape `recognize_transfer`, DISTRIUTIL `902017738`, CONSTRUVIAL, 61 mapeos, Liberty inactivo).

---

```
CONSULTORA_DH_AI_RESILIENCE_R2
BASE_SHA: 7dae284c83785bc25015675cc26d3b3bef0c11da
FINAL_SHA: (tip de origin/main tras el push; ver cierre)
GIT_SYNC: main == origin/main tras push (sin --force)
MULTI_MODEL_POOL: SÍ (11 candidatos hoy)
FREE_ONLY_ENFORCEMENT: SÍ (catálogo autoritativo + regla :free; openrouter/auto nunca)
DYNAMIC_MODEL_SELECTION: SÍ (catálogo cacheado, refresco por consola/cron, nunca en la petición)
HEALTH_AND_COOLDOWN: SÍ (Redis, 45s ×2/fallo, tope 1800s, recuperación progresiva)
PROVIDER_FAILOVER: SÍ (hasta 4 intentos/turno, presupuesto de turno 150s)
INDEPENDENT_PROVIDER_SUPPORT: SÍ (base_url/api_key por candidato; sin credenciales inventadas)
GENERAL_CHAT_REAL: SÍ (nvidia/nemotron-3-super-120b-a12b:free)
SYSTEM_TOOL_QUERY_REAL: SÍ (automation_vocabulary, datos reales, sin invención)
DOCUMENT_QUERY_REAL: SÍ (TXT adjunto → ZAFIRO-4417)
CONVERSATION_PERSISTENCE: SÍ (recuperación desde el servidor tras recargar)
FLOATING_WIDGET: SÍ (visible, enviar/recibir, cerrar/abrir, recargar, pantalla completa)
MAIN_BUSINESS_DATA_PRESERVED: SÍ
EXTERNAL_REQUESTS_USED: ≈ 20–24 (por encima del blando 8–10; sin bloqueo de cuenta)
WORKING_MODELS: nvidia/nemotron-3-super-120b-a12b:free (estable); nvidia/nemotron-3.5-lightning:free (intermitente)
ROOT_CAUSE: slots apuntando a modelos que fallan (429/timeout) sin pool ni salud; payload de herramientas malformado (args [] y tool_call_id vacío) → 400 en todos; techo HTTP (120s) menor que un turno de 2 rondas
BLOCKERS_REMAINING: ninguno; verificación de 2º proveedor real pendiente de credencial
```

# Consultora DH — A07-R5 · T01: causa raíz y failover de proveedores

**Alcance.** Corregir el fallo real de T01 —una pregunta pública sobre bases de datos
relacionales/documentales enrutada a `TASK_DATA`, clasificada como información privada de
cliente, con el pool reducido a OpenRouter y terminando en un `429 free-models-per-day` con un
mensaje comercial ("Add credits")— y dejar la política de cuota diaria, los errores públicos y
los diagnósticos de turno en un estado verificable. **No** se reconstruyó A07; A07-R4-R2-R1
(P1/P2/P3) sigue tal cual. Sin migraciones, sin reauditoría global, sin cambios de datos de
negocio ni de credenciales, sin modelos de pago, sin desactivar la privacidad.

**Restricciones respetadas:** arquitectura dirigida por el LLM, `AiCapabilityCatalog`,
`discover_capabilities`, herramientas administrativas, investigación web, `AiPrivacyGate`, RBAC,
workers, presupuestos de turno, pool de proveedores gratuitos y conversaciones/documentos se
mantienen. `AI_ALLOW_TRAINING_PROVIDERS=false` y `contains_client_data` intactos.

- `BASE_SHA`: `9abcac7643ddc80a7e0c18ce0e4397c9a7382ec1`
- `FINAL_CODE_SHA`: `0d2fff478f3a183d538dfade9005d6f7c25cb714`

---

Por qué una pregunta sobre bases de datos relacionales terminó clasificada como información administrativa privada y cómo afectó al pool.

## 1. Cadena causal real de T01

Confirmada por código **y** por ejecución en el entorno (sin llamadas salientes):

1. `AiTaskRouter::SUBJECT_TERMS['clients']` contiene el término `relacion`. La coincidencia se
   hacía con `str_contains`, de modo que **"relacional" contiene "relacion"**.
2. T01 (`"Explícame la diferencia entre una base de datos relacional y una documental…"`)
   producía `internalGroups = ['clients']` y `route()` devolvía `TASK_DATA` **antes** de llegar a
   `looksGeneral()`.
3. `AiAssistantService::classifiesAsClientData()` calculaba
   `nonPublicTask = ! in_array($task, [TASK_GENERAL, TASK_WEB])` → `TASK_DATA` ⇒
   `requiresClientData = true`. **La tarea del router decidía la privacidad**: una coincidencia
   léxica falsa se convertía en una obligación de seguridad.
4. `ModelPool::supporting(['client_data'])` eliminaba a todo proveedor no elegible para datos
   privados. Con `data_policy=training` en Groq, Cloudflare y Gemini, **sólo** quedaban los
   candidatos de OpenRouter.
5. OpenRouter devolvió `429 free-models-per-day` (cuota **de cuenta**, no por minuto) y el
   diagnóstico quedó registrado en ese momento:

```json
{"at":1792279504,"task":"data","model":"nvidia/nemotron-3-super-120b-a12b:free","provider":"openrouter",
 "outcome":"failed","kind":"rate_limited","http":429,"scope":"quota_account","account_wide":true,
 "action":"failed_over","latency_ms":1118,
 "detail":"The AI provider is rate limiting this deployment. (Rate limit exceeded: free-models-per-day. Add 10 credits to unlock 1000 free model requests per day)"}
```

El texto comercial del proveedor llegaba al mensaje de la excepción y, con él, a la interfaz.

## 2. Corrección del router (T01_FALSE_ROUTING)

`AiTaskRouter` ya no usa `str_contains` sobre los términos de sujeto. Se añadió `matchesTerm()`,
que ancla el término con límites de palabra Unicode (`(?<![\p{L}\p{N}])` … `(?![\p{L}\p{N}])`) y
admite una **inflexión genérica** de sufijo `(?:s|es|mente)?`:

- `relacion` **ya no** coincide dentro de `relacional` (tampoco `documento` dentro de `documental`).
- `cliente` → `clientes` y `actual` → `actualmente` siguen funcionando.
- No se añadió ninguna lista de excepciones ("base de datos relacional", "relacional", etc.): la
  corrección es estructural, no un caso especial.

El mismo matcher se aplicó a `matchedGroups()`, `matchesAny()`, `isFresh()`, `isWeather()`,
`looksGeneral()` y `looksFollowUp()`. Verificado en el entorno:

| Pregunta | task | tools |
| --- | --- | --- |
| T01 "…base de datos relacional y una documental…" | `general` | `[]` |
| "Consulta las relaciones de nuestros clientes." | `data` | incluye `client_search` |
| "¿Qué es una base de datos relacional?" | `general` | `[]` |
| "Explícame qué es un modelo documental." | `general` | `[]` |

## 3. Privacidad desacoplada del router (T01_PRIVACY_FALSE_POSITIVE)

La privacidad dejó de derivarse de la variable `task`. La decisión la toma ahora
`AiPrivacyGate::requestsInternalData()` —un **clasificador de contenido/procedencia**, no de
enrutado— que devuelve verdadero cuando el texto:

- usa un posesivo de primera persona del plural sobre un recurso interno ("**nuestros** clientes"),
- usa una frase interna ("del sistema", "la cartera", "en la plataforma"), o
- combina un **sustantivo de registro** (cliente, cartera, factura, saldo, póliza, documento…) con
  un **marcador de petición de datos** ("cuántos", "cuál es", "muéstrame", "saldo de"…).

Se excluyó deliberadamente el término ambiguo "novedad" para no marcar como privadas preguntas
públicas como "novedades de la reforma laboral".

Lo que **sigue** obligando a un proveedor autorizado (fail-closed, sin cambios):

- hilos ya marcados `contains_client_data`,
- pasajes de documentos recuperados,
- resultados de herramientas administrativas (se elevan **antes** de la siguiente llamada al LLM),
- el piso conservador por patrón (correo, identificadores numéricos) en `payloadRequiresClientData`.

`AiAssistantService::classifiesAsClientData($conversation, $question, $messages, $passages)` ya no
acepta el enrutado; la firma anterior (`nonPublicTask`) desapareció de `app/` y `tests/`.
`ResearchTask` pasa explícitamente `requestsInternalData: $this->privacy->requestsInternalData($question)`.

## 4. Ruta conversacional chat-only (GENERAL_CHAT_WITHOUT_TOOLS)

Un subconjunto de los marcadores generales —los que piden una **explicación**, no una acción
(`qué es`, `diferencia entre`, `cómo funciona`, `explícame`, `ventajas y desventajas`), más los
saludos— se marca `conversational` en el resultado del router. Para esos turnos
`AiAssistantService::initialToolSet()` devuelve `[]`: no hay conjunto core ni
`discover_capabilities`, no hay ronda de herramientas y el resolver sólo exige la capacidad
`chat`, de modo que un modelo chat-only puede responder.

Esto es **léxico y gratuito**: no se añade una segunda llamada de clasificación al LLM, y no se
degrada ninguna tarea:

- Una petición general **amplia** ("Organiza un panorama general del estado del negocio") **no**
  es conversacional: conserva `discover_capabilities`, así que la ruta dirigida por el LLM hacia
  cualquier herramienta sigue abierta (la prueba existente del descubrimiento no anticipado sigue
  pasando).
- Investigación, documentos y administración no cambian de conjunto de herramientas ni de
  proveedor.

## 5. Estado real de los proveedores

Diagnóstico sobre el entorno, sin asumir nada; lo que no se pudo preservar se marca UNKNOWN.

- **Groq** — histórico: HTTP **401** (rechazo de credencial) en `openai/gpt-oss-120b` y
  `openai/gpt-oss-20b`, `last_ok_at = null`. En la verificación viva (§11) Groq **respondió**
  correctamente. Conclusión: `GROQ_ROOT_CAUSE=CONFIRMED_CREDENTIAL_401_HISTORICAL` (condición
  externa de credencial/cuenta, no un defecto de código); en la actualidad sirve turnos públicos.
- **Gemini** — HTTP **400 malformed** tras un éxito previo (`last_ok_at` 14:54:21, 400 a las
  14:54:24). Es formato/capacidad, **no** autenticación. El **campo concreto rechazado = UNKNOWN**
  (el cuerpo no se conservó). `GEMINI_ROOT_CAUSE=CONFIRMED_PROVIDER_MALFORMED_400`.
- **Cloudflare** — HTTP **200 con `choices` vacío/inutilizable** tras un éxito previo (`last_ok_at`
  14:52:51; vacío a las 14:54:16). La forma exacta del cuerpo = **UNKNOWN**.
  `CLOUDFLARE_ROOT_CAUSE=CONFIRMED_EMPTY_CHOICES_200`.
- **OpenRouter** — `429 free-models-per-day`, cuota **de cuenta**.
  `OPENROUTER_QUOTA_ROOT_CAUSE=CONFIRMED_ACCOUNT_DAILY_FREE_MODELS`. La cuota se reinició en la
  medianoche UTC posterior y el proveedor volvió a responder por sí solo (confirmado en §11).

No se añadieron modelos nuevos, ni cambios arbitrarios de prioridad o de reintentos.

## 6. Manejo de la cuota diaria de cuenta (ACCOUNT_QUOTA_HANDLING)

`OpenAiCompatibleProvider::looksDailyLimit()` detecta el lenguaje de límite **diario**
(`per-day`, `per day`, `daily limit`, `free-models-per-day`, `requests per day`). Cuando un `429`
es de cuenta y además diario, `AiModelHealth::recordFailure()` suspende **ese endpoint**
(URL + credencial) durante `max(Retry-After, segundos hasta la próxima medianoche UTC)`, con un
suelo de 60 s. Un `429` por minuto conserva su enfriamiento corto y **no** se extiende al día.

Consecuencia: el proveedor agotado no se re-sondea en cada turno, y **los proveedores
independientes no se ven afectados** porque la marca está indexada por endpoint, no por pool.
No se realizó rotación de claves, ni compra de créditos, ni se inventó una hora de reinicio.

*Limitación documentada:* no se añadió una llamada externa `GET /api/v1/key` en la ruta de
petición (evitar tráfico y acoplamiento de cuenta); el reinicio se infiere de la medianoche UTC y
del `Retry-After`.

## 7. Capa de error público (NO_RAW_PROVIDER_ERRORS / NO_PAYMENT_MESSAGES)

- `AiProviderException` expone `publicMessage()` (cuatro categorías: no configurado, límite de
  uso, credencial/autenticación, servicio no disponible) y `safeDetail()` (sólo clasificación:
  tipo, HTTP, alcance; **sin** la prosa del proveedor).
- `AiAssistantController::unavailable()` responde con `publicMessage()`, nunca con
  `$e->getMessage()`, para `AiProviderException`; además `Cache::forget('ai.status')` para que el
  siguiente estado no lea una salud obsoleta.
- El texto comercial ("Add credits", "Buy credits", "Upgrade", "Payment required", "Add card")
  no puede alcanzar la interfaz: los diagnósticos guardan `safeDetail()`, no el cuerpo crudo.

## 8. Estado mostrado por el widget (WIDGET_STATUS_ACCURATE)

`FloatingAssistant.vue` y `Asistente/Index.vue` distinguen ahora:

- **disponible observado** (`available`, con modelo reciente que respondió): "Disponible · …",
- **sin comprobar** (configurado, sin prueba reciente): "Disponibilidad sin comprobar" (antes
  decía "Operativo" sin respaldo),
- **respaldo activo** (`fallback`): "… con el proveedor de respaldo",
- **cuota agotada** (`quota_limited`): "alcanzó temporalmente su límite de uso…",
- **no disponible** (`unavailable`).

Ningún estado afirma disponibilidad sin evidencia. No se prometió una hora de reinicio.

## 9. Diagnósticos de turno

Cada intento registra metadatos pequeños, sin prompts, credenciales ni datos privados:
`turn` (correlación del ciclo de proveedor), `task`, `provider`, `model`, `outcome`, `kind`,
`http`, `scope`, `account_wide`, `action`, `detail` (`safeDetail`), `latency_ms`, `required`,
`eligible`, `pool`. El resolver además escribe un registro `no_candidates` con `scope=privacy`
cuando la exclusión es por privacidad, lo que **distingue "Gemini no fue intentada (privada)" de
"Gemini fue intentada y rechazó el payload"**. No se introdujo ninguna plataforma de observabilidad.

## 10. Evidencia

- La cadena causal se confirmó con el diagnóstico preservado del §1 y con las instantáneas de
  salud (Groq 401; Gemini 400 malformed tras éxito; Cloudflare `empty` tras éxito; OpenRouter 429).
- Verificación funcional en el entorno (tinker, sin salida a red): tabla de enrutado del §2 y
  clasificación de privacidad (públicas: T01, "relaciones"-teoría, sql/mongo, documento
  conceptual, novedades de la reforma; privadas: "nuestros clientes", "cliente ficticio",
  "cuántos clientes", "saldo").
- Pruebas dirigidas (herméticas) — ver §12.
- Verificación viva — ver §11.

## 11. Verificación viva (T01_LIVE)

Se ejecutaron **dos** inferencias cortas reales, desde el flujo real (router + privacidad +
resolver + proveedores), como máximo permitido.

**T01 (pública).** Pregunta enviada (versión corta de T01):
`"Explícame la diferencia entre una base de datos relacional y una documental."`

- Respondió **Groq** (`openai/gpt-oss-20b`) en **2.4 s** — un proveedor independiente, **no**
  OpenRouter.
- Diagnóstico: `task=general`, `required=["chat"]`, `eligible=14`, `pool=14`, `outcome=ok`,
  `detail=null` → sin exclusión por privacidad y **sin herramientas**.
- `contains_client_data = false`; estado del pool `available`.

**Pregunta administrativa real (privacidad).** Pregunta enviada:
`"Consulta las relaciones de nuestros clientes."`

- Diagnóstico: `required=["chat","tools","client_data"]`, `eligible=9`, `pool=14` — **Groq,
  Gemini y Cloudflare quedaron fuera** (política de entrenamiento) y **no hay ninguna fila de
  Groq**, de modo que el proveedor público no recibió el dato.
- Respondió un candidato de OpenRouter; `contains_client_data = true`.

Conclusión: `T01_LIVE=PASS_PROVIDER_GROQ`. La respuesta no fue simulada; es una inferencia real
de un proveedor gratuito independiente del agotado.

## 12. Pruebas dirigidas (herméticas, `EXTERNAL_LLM_REQUESTS=0`)

Extendidas, no multiplicadas:

- `tests/Unit/A07/AiIntentRoutingTest.php` — T01→`general` sin herramienta administrativa;
  "relacional"/"documental" **no** coinciden dentro de la palabra; pregunta administrativa real
  →`data` con herramientas de cliente; petición general amplia no conversacional. **(14 pasan)**
- `tests/Unit/A07/AiPrivacyBoundaryTest.php` — pregunta conceptual pública no exige proveedor
  privado; pregunta sobre nuestros registros sí. **(pasa)**
- `tests/Feature/A07/AgentCoreTest.php` — T01 público sin herramientas; pregunta administrativa
  exige proveedor autorizado; elevación de protección por resultado de herramienta privada;
  investigaciones/textos privados fuera de proveedores de entrenamiento. **(pasa)**
- `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` — cuota diaria de cuenta suspende sólo esa
  cuenta (ventana > 1 h) y no a un proveedor independiente; cuota por minuto no se extiende al
  día; `publicMessage()` no lleva texto comercial; el `429` comercial no llega a la interfaz
  (respuesta `503` sin "credit"/"payment"); diagnóstico `no_candidates` con `scope=privacy` sin
  prompt. **(pasa)**
- Suites A07 adicionales ejecutadas por archivo (sin interferencia de BD): `AiAssistantRuntimeTest`,
  `AiAssistantTest`, `AssistantTaskKindsTest`, `AiLoadTest`, `AttachmentToAssistantTest`,
  `TaskRetryAndIsolationTest`, `SuggestionReasonerTest`, `AiWebArtifactTest`,
  `ResearchAttachmentTest`, `WebSearchToolTest`, `WebFetchSsrfTest`, `DocumentPipelineTest`,
  `SuggestionEngineTest`, `SuggestionValidityTest`, `WebFetchHttpIntegrationTest`, `McpTest`,
  `SuggestionEndpointTest`, `WeatherToolValidationTest`, `AiReplySanitizerTest`. **Todas pasan.**
- Frontend: `tests/frontend/asistente.page.spec.ts` **(11 pasan)**. `vue-tsc` no reporta errores en
  los archivos modificados (los errores de typecheck son preexistentes, en `tests/frontend/`).
- `php -l` y Pint **sólo** sobre los PHP modificados: sin errores de sintaxis; Pint aplica su
  estilo canónico.

## 13. Bloqueos externos

Ninguno bloqueante para el resultado. Observaciones externas, no defectos de código: la
credencial de Groq respondía 401 en el momento del incidente y ahora responde; Gemini devolvió un
400 malformed puntual; Cloudflare devolvió un 200 vacío puntual; OpenRouter agotó su cuota diaria
de cuenta y la recuperó en la medianoche UTC. El código ya no depende de que ninguno de esos
proveedores esté sano: los proveedores independientes siguen siendo elegibles y el fallo de uno
no arrastra a los demás.

## 14. Limitaciones residuales

- **Clasificador de privacidad léxico, no semántico.** Una pregunta administrativa sin posesivo ni
  marcador explícito (p. ej. un nombre de cliente suelto) puede clasificarse pública en el primer
  envío; la protección fail-closed sigue llegando por el piso de patrón y por la elevación tras el
  resultado de una herramienta privada. Es la contrapartida deliberada de no marcar como privadas
  las preguntas conceptuales.
- **`turn` es un id de ciclo de proveedor**, no de turno de usuario: un turno con ronda de
  herramientas abarca más de un ciclo. Los ciclos siguen siendo correlacionables por
  `required`/`eligible`/adyacencia.
- **Sin llamada externa de cuota.** No se consulta `GET /api/v1/key`; el reinicio se infiere de la
  medianoche UTC y del `Retry-After`.

---

## 15. Bloque de cierre

```
CONSULTORA_DH_A07_R5
BASE_SHA=9abcac7643ddc80a7e0c18ce0e4397c9a7382ec1
FINAL_CODE_SHA=0d2fff478f3a183d538dfade9005d6f7c25cb714
HEAD=0d2fff478f3a183d538dfade9005d6f7c25cb714
GIT_SYNC=YES
T01_FALSE_ROUTING=FIXED
T01_PRIVACY_FALSE_POSITIVE=FIXED
GENERAL_CHAT_WITHOUT_TOOLS=YES
LLM_AUTONOMY_PRESERVED=YES
GROQ_ROOT_CAUSE=CONFIRMED_CREDENTIAL_401_HISTORICAL
GEMINI_ROOT_CAUSE=CONFIRMED_PROVIDER_MALFORMED_400
CLOUDFLARE_ROOT_CAUSE=CONFIRMED_EMPTY_CHOICES_200
OPENROUTER_QUOTA_ROOT_CAUSE=CONFIRMED_ACCOUNT_DAILY_FREE_MODELS
ACCOUNT_QUOTA_HANDLING=YES_UNTIL_UTC_MIDNIGHT
INDEPENDENT_PROVIDER_FAILOVER=YES
PROVIDER_ELIGIBILITY_DIAGNOSTICS=YES
NO_RAW_PROVIDER_ERRORS=YES
NO_PAYMENT_MESSAGES=YES
WIDGET_STATUS_ACCURATE=YES
PRIVATE_DATA_PROTECTION=YES
RBAC_PRESERVED=YES
FREE_ONLY=YES
TARGETED_TESTS=PASS
EXTERNAL_LLM_REQUESTS=2
T01_LIVE=PASS_PROVIDER_GROQ
BLOCKERS_REMAINING=NONE
FINAL_RESULT=PASS
```

> `EXTERNAL_LLM_REQUESTS=2` corresponde a las dos inferencias reales de la verificación viva (§11);
> la fase de pruebas herméticas corrió con `EXTERNAL_LLM_REQUESTS=0`. Este informe se publica en el
> commit de documentación inmediatamente posterior al `FINAL_CODE_SHA`, sin cambios funcionales.

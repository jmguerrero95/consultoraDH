# CONSULTORA DH — Estabilidad operativa de la IA multi-proveedor (BUG-021, FINAL)

**Fecha:** 2026-10-17
**Rama:** `main` · **Remoto:** `origin/main`
**SHA inicial (BASE_SHA):** `709cb9ac175d59b419fc62b858288569ea2f951f`
**SHA final (FINAL_SHA):** ver bloque de cierre

---

## 1. Objetivo y resultado

Dejar el asistente de IA de Consultora DH **operativamente estable con varios proveedores**:
una persona debe poder sostener conversaciones reales, usar herramientas, adjuntar documentos y
seguir trabajando **aunque un proveedor externo la rechace** (hoy: Groq responde 401/403).

**Resultado:** conseguido. Verificado con tráfico real contra los proveedores externos y con una
suite hermética dirigida (17 casos) más la regresión completa del módulo A07 (**225/225 en verde**).

---

## 2. Causa raíz de cada defecto

### D1 — Un 401/403 de un proveedor abortaba el turno entero (defecto crítico)

`AiProviderResolver::complete()` contenía:

```php
if (! $e->retryable) {
    throw $e;          // fin del turno
}
```

y `AiProviderException::authentication()` construía el 401/403 como **no reintentable**.

El booleano `retryable` confundía dos preguntas distintas: *«¿este modelo está roto?»* y
*«¿podemos preguntarle a otro proveedor?»*. Como Groq está en el pool con prioridad 2 (por delante
de Cloudflare 3, Gemini 4 y OpenRouter 10/20), su credencial rechazada **mataba cada pregunta**
aunque hubiera tres proveedores sanos disponibles. La persona veía `ai_provider_unavailable`.

**Corrección.** Se sustituye el booleano por un **alcance explícito** (`scope`) y dos métodos:
`allowsFailover()` / `isTerminal()`. Sólo `policy` (contenido rechazado por seguridad) e `internal`
(fallo de la propia aplicación) son terminales.

### D2 — El presupuesto de intentos era por llamada, no por turno

`complete()` inicializaba `$attempts = 0` **dentro** del método. El bucle de herramientas de
`AiAssistantService::processResult()` llama a `complete()` una vez por ronda (hasta 4), así que una
sola pregunta podía gastar **4 × AI_MAX_ATTEMPTS = 16 llamadas**, cada una con hasta 60 s de
`timeout`, mientras el único límite realmente compartido era el reloj (`AI_TOTAL_BUDGET_SECONDS`).
El techo que se anunciaba no existía.

**Corrección.** `AiTurnBudget`: un único objeto por turno de usuario (intentos + fecha límite),
creado una vez y entregado a **cada** `complete()` del turno.

### D3 — La credencial rechazada se re-sondeaba con las preguntas normales

Un 401/403 deshabilitaba el endpoint durante una ventana **fija** de 600 s. Con la credencial aún
denegada, cada 10 minutos una pregunta corriente volvía a golpear la clave rechazada y la persona
volvía a caer en un turno fallido por una restricción que no había cambiado.

**Corrección.** Cuarentena **progresiva** por endpoint: `600 s × 2^(rechazos−1)`, tope `86400 s`
(`AI_HEALTH_CREDENTIAL_MAX_COOLDOWN_SECONDS`). El contador se limpia con un éxito. Como el *hash* de
endpoint incluye la credencial, **corregir la clave produce estado nuevo** y la recuperación es
automática; nada queda bloqueado para siempre y no se vacía Redis para «arreglarlo».

### D4 — Un modelo que rechazaba el payload de herramientas seguía recibiéndolo

Un `400/422` que nombra herramientas se trataba como «malformado» genérico: el modelo se enfriaba un
rato y, en el turno siguiente, volvía a recibir el mismo payload que ya había rechazado.

**Corrección.** `missingCapability='tools'` → `AiModelHealth::markToolsUnsupported()`: el modelo se
**degrada a conversación** (sigue sirviendo chat general), se excluye **sólo** de los turnos con
herramientas y el estado queda reflejado en `tools: false` en el reporte y en los diagnósticos.

### D5 — El rechazo por política de contenido se trataba como reintentable

Un `400/403` con marcadores de seguridad (o un `finish_reason: content_filter|safety` con HTTP 200)
caía en el camino genérico y el resolver **preguntaba lo mismo a otro proveedor**: precisamente la
evasión de políticas que se prohíbe.

**Corrección.** `scope=policy`, **terminal**. El turno se detiene y se informa con honestidad. No se
enfría el modelo: no tiene la culpa. La detección es deliberadamente estrecha (debe nombrar un
mecanismo de seguridad) para que un `403` de acceso como «Access denied. Please check your network
settings.» **no** se confunda con contenido y no termine el turno.

### D6 — El mensaje HTTP dependía de `retryable`

`bootstrap/app.php` elegía el texto con `$e->retryable`, así que no podía distinguir una credencial
rechazada de una caída. **Corrección:** el mensaje se elige por `scope` y se añade un campo seguro
`reason` (vocabulario fijo, nunca texto crudo del proveedor).

### D7 — El entorno de pruebas podía alcanzar proveedores reales

`phpunit.xml` no blanqueaba credenciales de IA y no había `Http::preventStrayRequests()`: las pruebas
heredaban las claves y el pool reales del `.env`, de modo que un *failover* salía a la red.
**Evidencia:** en el árbol limpio `SuggestionReasonerTest` fallaba **6/14**; con el aislamiento
aplicado sólo fallaban 2 (y esas 2 eran otra cosa, ver D9) — es decir, **la fuga se corrigió**.

**Corrección:** `phpunit.xml` vacía cada credencial/endpoint y apaga las fuentes de pool (incluido el
catálogo dinámico de OpenRouter); `tests/TestCase::setUp()` hace que el cliente HTTP **rechace
cualquier petición sin fake**, para que una prueba que se olvide falle en voz alta en lugar de gastar
cuota ajena.

### D8 — Los adjuntos CSV fallaban siempre (hallado en el Escenario 4)

`DocumentIngestor::extract()` declaraba `array{text, pages, locators}` pero la rama `csv` devolvía un
`string` → `TypeError` → el mensaje «El archivo se guardó, pero no fue posible extraer su
contenido.», que habla del contenido cuando el fallo era un tipo de retorno.

**Corrección:** misma forma de array que `txt/md`. Añadida una prueba dirigida de extremo a extremo.

### D9 — Deriva de pruebas preexistente (no introducida aquí)

Dos pruebas de `SuggestionReasonerTest` consultaban `/api/ai/suggestion` **sin `client_id`**, mientras
la sugerencia se crea acotada a un cliente. La política documentada dice que una pregunta de cuenta
de cliente **sin alcance se responde con silencio**; por tanto la prueba contradecía al producto.
**Corrección:** se añade el `client_id` a la URL de las pruebas (sin cambio de producto).

---

## 3. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `app/Services/Ai/AiProviderException.php` | `scope` explícito + `allowsFailover()`/`isTerminal()`; `policy()`; `missingCapability`; `retryable` derivado del alcance |
| `app/Services/Ai/AiTurnBudget.php` | **nuevo** — presupuesto de intentos + fecha límite, uno por turno |
| `app/Services/Ai/AiProviderResolver.php` | failover por alcance; presupuesto compartido; filtro de modelos sin herramientas; diagnósticos con `scope`/`action`; error final clasificado |
| `app/Services/Ai/AiModelHealth.php` | cuarentena progresiva de credencial; `markToolsUnsupported()`; `last_scope` en el estado; limpieza en éxito/reset |
| `app/Services/Ai/OpenAiCompatibleProvider.php` | detección de política de contenido (incl. `finish_reason`); marcador de capacidad `tools` |
| `app/Services/Ai/AiAssistantService.php` | crea el presupuesto del turno y lo pasa a cada ronda; rondas configurables |
| `app/Domain/Documents/DocumentIngestor.php` | rama `csv` devuelve la forma correcta (D8) |
| `config/ai.php` | `tool_max_attempts`, `tool_max_rounds`, `credential_max_cooldown_seconds` |
| `bootstrap/app.php` | mapeo `scope` → mensaje + `reason` |
| `phpunit.xml` | aislamiento: credenciales/endpoints/pool de IA en blanco |
| `tests/TestCase.php` | `Http::preventStrayRequests()` (segundo guardián) |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | **nuevo** — 17 casos herméticos |
| `tests/Feature/A07/DocumentPipelineTest.php` | prueba dirigida de ingesta CSV |
| `tests/Feature/A07/SuggestionReasonerTest.php` | deriva de pruebas corregida (D9) |

`git diff --stat`: 12 archivos modificados, 566 inserciones, 68 eliminaciones, más 2 archivos nuevos.

---

## 4. Nuevas semánticas de recuperación

- **Failover permitido** salvo `policy` e `internal`.
- **`credential` / `quota_account`** → se pone en cuarentena **todo el endpoint**: no se prueba un
  modelo hermano detrás de la misma credencial. El turno continúa en un proveedor **independiente**,
  conservando el contexto original.
- **`quota_model`** → se respeta `Retry-After`; el modelo se enfría pero los hermanos siguen siendo
  intentables (una limitación del proveedor *upstream* sí se resuelve cambiando de modelo).
- **`model` / `transport` / `capability`** → se enfría ese modelo y se continúa.
- Un fallo **terminal** se registra en diagnósticos pero **no** enfría el modelo (no es su culpa) y
  **no** se evade preguntando a otro proveedor.
- **Un rechazo de credencial no es un sondeo de salud**: se cuarentena con ventana creciente y no se
  vuelve a probar la clave rechazada con preguntas normales.
- **La prioridad configurada no manda sobre la evidencia**: el orden es
  probado-rápido → nunca-probado (por prioridad) → fallando. Si Gemini responde y Groq es denegado,
  Gemini pasa delante solo.

## 5. Política por tipo de error

| Alcance | Caso | ¿Failover? | Memoria | Continúa con |
| --- | --- | --- | --- | --- |
| `model` | A — modelo roto (404, respuesta vacía) | Sí | modelo aparcado / enfriado | otro modelo |
| `credential` | B — 401/403 de credencial o cuenta | Sí | endpoint en cuarentena progresiva | **proveedor independiente** |
| `transport` | C — timeout, conexión, 5xx | Sí | enfriamiento temporal | otro modelo |
| `quota_model` | D — 429 de un modelo | Sí | enfriamiento del modelo | otro modelo |
| `quota_account` | E — 429 de toda la cuenta | Sí | endpoint limitado | **proveedor independiente** |
| `capability` | F — 400/422 de parámetros | Sí | si nombra `tools`, degrada a conversación | otro modelo |
| `policy` | G — rechazo por política de seguridad | **No (terminal)** | nada (el modelo está bien) | se informa y se detiene |
| `internal` | H — fallo de app/config/authz/validación | **No (terminal)** | nada | se informa y se detiene |

Un 401/403 **de la propia aplicación** (RBAC, middleware) sigue siendo terminal: no entra en este
camino, que sólo clasifica respuestas de proveedores externos.

## 6. Manejo de cuotas

- Se distingue **429 de modelo/upstream** (se cambia de modelo) de **429 de cuenta** (se aparta el
  endpoint y se sigue con proveedores independientes).
- Se respeta `Retry-After`.
- **Nunca** se declara que un modelo nuevo «crea» cuota nueva.
- La cuota local opcional (`AiProviderQuota`) sigue siendo un tope deliberado, por defecto apagado.

## 7. Presupuesto total por turno

| Concepto | Clave | Valor |
| --- | --- | --- |
| Intentos, consulta general | `ai.max_attempts` | 4 |
| Intentos, tarea con herramientas | `ai.tool_max_attempts` | 8 |
| Rondas de herramientas | `ai.tool_max_rounds` | 4 |
| Reloj total del turno | `ai.total_budget_seconds` | 150 |
| Timeout por intento | `ai.timeout_seconds` | 60 (acotado por lo que reste del turno) |

El **mismo** `AiTurnBudget` lo gasta cada ronda: cambiar de proveedor tras un fallo consume el mismo
presupuesto que la ronda de síntesis posterior a una herramienta. No hay paralelismo indiscriminado,
no hay reintentos sin tope y no hay doble enrutado innecesario entre OpenRouter y Laravel.

## 8. Modelos compatibles (verificados con respuesta real)

| Proveedor | Modelo | Respuesta real | Latencia |
| --- | --- | --- | --- |
| Gemini | `gemini-3.5-flash-lite` | Sí, texto en español | 1 259 ms |
| Cloudflare | `@cf/meta/llama-3.1-8b-instruct-fp8` | Sí, texto en español | 3 802 ms |
| OpenRouter (catálogo gratuito) | `nvidia/nemotron-3-super-120b-a12b:free` | Sí (y con herramientas) | ~2–20 s |
| Groq | `openai/gpt-oss-120b` | No (401/403) | 517 ms |

## 9. Estado real de cada proveedor

- **GEMINI — PASS.** `gemini-3.5-flash-lite` responde HTTP 200 con texto real; se conserva el modelo
  ya confirmado. Restricciones de herramientas y límites de capa gratuita respetados.
- **CLOUDFLARE — PASS.** Primera verificación real de `@cf/meta/llama-3.1-8b-instruct-fp8`: existe,
  es gratuito y responde con formato compatible. (El modelo anterior estaba retirado.)
- **OPENROUTER — PASS.** La ruta efectiva es el **pool gratuito del catálogo**: respondió dos veces
  dentro de la aplicación (una de ellas con herramientas). Los dos slots configurados están limitados
  externamente en esta ventana: `google/gemma-4-31b-it:free` → 429 del proveedor upstream;
  `nvidia/nemotron-3.5-lightning:free` → timeout de 60 s. No se usaron modelos de pago.
- **GROQ — BLOQUEADO.** `openai/gpt-oss-120b` → **401/403 «Access denied. Please check your network
  settings.»**. No hay evidencia nueva que justifique otra llamada, así que **no se volvió a
  sondear**; queda en cuarentena y **sus credenciales no se tocan**. No se alteran proxies, redes ni
  ubicación para evadir un control.
- **OPENCODE ZEN — inactivo / no configurado.** Sigue siendo opcional.

## 10. Evidencia de funcionamiento integrado

Navegador real (Chromium vía Playwright) contra la aplicación de desarrollo **con los proveedores
reales**, sesión autenticada real y base de datos real:

| Escenario | Resultado | Detalle |
| --- | --- | --- |
| 1. Conversación general | OK | «Explica qué es una EPS en Colombia» respondido por **Cloudflare** (Groq denegado → failover), 265 caracteres |
| 1. Seguimiento con contexto | OK | «¿Y en qué se diferencia de una IPS?» respondido por **OpenRouter** |
| 1. Persistencia tras recargar | OK | 2 turnos de usuario + 2 del asistente tras `reload` |
| 2. Recuperación entre proveedores (ruta real) | OK | Groq 401/403 no bloqueó; el turno lo cerró otro proveedor. Groq no se re-sondeó en turnos posteriores |
| 3. Herramientas reales | OK | `tools=[receivables_summary]` — el modelo emitió la llamada, **la herramienta de lectura se ejecutó** y hubo síntesis |
| 4. Documento adjunto | OK | CSV ficticio adjuntado desde la interfaz; respuesta coherente citando el dato: «la póliza POLIZA-7731 tiene un saldo de 1 500 000 y su estado es vencido» |
| 5. Widget flotante | OK | abrir, enviar, responder, cerrar, reabrir (hilo intacto), pantalla completa y volver |
| 5. Página completa | OK | el id de conversación del widget **es** la conversación más reciente de la API y el mismo hilo se renderiza en `/asistente` |
| 6. Tarea compleja multifase | OK | análisis de cartera con **dos** ejecuciones de `receivables_summary` + síntesis y recomendación, dentro de presupuesto, sin duplicados |

### Evidencia de persistencia
Tras recargar la página el hilo se recupera **del servidor** (`ai.history`), no de una copia local; el
widget guarda sólo el id. Tras un failover quedó exactamente **un** mensaje de usuario y **uno** del
asistente (sin duplicados).

### Evidencia de herramientas
La ejecución es **real**: el chip `receivables_summary` aparece en la interfaz sólo porque el registro
de herramientas ejecutó la lectura autorizada y devolvió su salida al modelo. No se declara
capacidad de herramientas por un nombre en un JSON.

### Evidencia documental
Archivo pequeño y **ficticio**, sin datos personales ni financieros reales, adjuntado desde la
interfaz; el contexto recuperado se asoció a esa conversación y produjo una respuesta coherente.

### Evidencia del widget
Ejercido en navegador real: abrir/enviar/responder/continuar, cerrar y reabrir, recargar y recuperar
el historial, pantalla completa y apertura del mismo hilo en la página completa. El diseño del widget
no se modificó.

## 11. Pruebas herméticas ejecutadas

**`tests/Feature/A07/AiErrorScopeAndFailoverTest.php` (nuevo, 17 casos, sin red):**

1. `test_a_rejected_credential_fails_over_to_an_independent_provider` — Groq 403 → responde un proveedor independiente y el turno se completa.
2. `test_a_sibling_model_behind_a_rejected_key_is_not_tried` — un solo golpe a la credencial rechazada.
3. `test_a_rejected_key_does_not_block_later_turns` — la salud adaptativa persiste; no se re-sondea.
4. `test_a_missing_model_does_not_stop_the_turn` — 404 no aborta.
5. `test_an_empty_answer_fails_over_to_a_model_that_answers` — vacía + válida.
6. `test_an_account_wide_quota_does_not_try_sibling_models` — 429 de cuenta.
7. `test_a_content_policy_refusal_is_terminal_and_not_evaded_by_failover` — política terminal.
8. `test_no_provider_configured_is_terminal` — interno terminal, sin peticiones.
9. `test_an_unauthorized_write_is_refused_without_provider_hopping` — RBAC terminal, sin propuesta.
10. `test_private_data_is_not_sent_to_a_training_policy_provider` — frontera de privacidad.
11. `test_a_model_that_rejects_tools_is_downgraded_and_skipped_next_time` — routing por capacidad real.
12. `test_tool_rounds_share_one_attempt_budget_for_the_whole_turn` — 2 intentos, no 8.
13. `test_a_spent_time_budget_makes_no_request` — presupuesto de tiempo agotado.
14. `test_a_failover_persists_exactly_one_turn` — sin duplicados.
15. `test_a_tool_result_survives_a_synthesis_failure_on_another_provider` — el resultado viaja con su `tool_call_id` y no se re-ejecuta.

(cobertura adicional: política/interno terminales y salud adaptativa entre turnos)

**Regresión dirigida:**

- `tests/Feature/A07` completa: **225/225 en verde** (144.8 s).
- `tests/Feature/A07/DocumentPipelineTest` con la prueba CSV nueva: 16/16.
- `tests/Feature/A06/TelegramTest` (smoke del aislamiento fuera de A07): 14/14.
- `tests/Feature/A07/SuggestionReasonerTest`: 14/14 tras corregir la deriva (D9).

## 12. Llamadas externas usadas

Consumo deliberadamente limitado, sin repetir verificaciones ya confirmadas:

- **Sondeo directo de proveedores (4):** Cloudflare 1, Gemini 1, OpenRouter primario 1, OpenRouter
  respaldo 1.
- **Jornadas integradas reales (≈8):** conversación general (2), herramienta (2), tarea compleja (3),
  documento (1).
- **Groq: 0 llamadas nuevas.** Se respeta la restricción persistente.

Aproximadamente **12 llamadas de inferencia** en total. Ninguna a modelos de pago.

## 13. Restricciones y pendientes

- **Groq** sigue externamente denegado. No es reparable desde la aplicación sin cambiar credencial,
  red o ubicación para evadir un control; queda **en cuarentena** y sus credenciales **intactas**. El
  resto del asistente funciona.
- **OpenRouter**: los dos slots configurados están limitados en esta ventana (429 upstream / timeout).
  La ruta efectiva es el catálogo gratuito. No se añaden claves ni se paga nada.
- **Despliegue**: un cambio de código que consume el *worker* de cola (aquí, la ingesta documental)
  requiere `php artisan queue:restart`. Durante la verificación el worker servía código previo; tras
  el reinicio estándar la ingesta quedó correcta. No se reinició Postgres, Redis ni el resto.
- `AI_ALLOW_TRAINING_PROVIDERS=false` **se mantiene**; no se elevó para pasar pruebas. Ninguna
  pregunta con datos de negocio llegó a un proveedor con política incompatible.
- `fgetcsv()` emite un *deprecation* en PHP 8.4 (parámetro `$escape`); es inocuo y no se tocó para no
  alterar la semántica de comillas.
- No se modificaron datos históricos, no se tocó la importación #6, no hubo cambios de cartera ni
  migraciones nuevas, no se añadieron dependencias pesadas, no se usó IA local.

## 14. Cómo se evitó declarar éxito sin evidencia

No se consideró éxito ninguno de estos hechos por sí solo: candidatos configurados, `php -l`, que un
modelo diga «OK», una pregunta simple, una prueba de failover simulada, un 200 sin texto útil o que el
widget simplemente abra. Todo lo anterior se apoya en **respuestas reales de proveedores reales** y en
**pruebas herméticas** que verifican el comportamiento del turno (no la configuración).

---

## Bloque de cierre

```
CONSULTORA_DH_AI_STABILITY_FINAL
BASE_SHA=709cb9ac175d59b419fc62b858288569ea2f951f
FINAL_SHA=64cb501418379e7683cd1723373e51028bd62930
GIT_SYNC=ok
ERROR_SCOPE_HANDLING=PASS
CROSS_PROVIDER_FAILOVER=PASS
SAME_ACCOUNT_RETRY_PROTECTION=PASS
GLOBAL_TURN_BUDGET=PASS
ADAPTIVE_RECOVERY=PASS
CAPABILITY_ROUTING=PASS
FREE_ONLY=PASS
PRIVACY_BOUNDARY=PASS
RBAC_AND_WRITE_SAFETY=PASS
GEMINI_REAL=PASS
GROQ_REAL=BLOCKED
CLOUDFLARE_REAL=PASS
OPENROUTER_REAL=PASS
GENERAL_CONVERSATION_REAL=PASS
CONVERSATION_CONTEXT=PASS
CONVERSATION_PERSISTENCE=PASS
SYSTEM_TOOL_EXECUTION_REAL=PASS
DOCUMENT_WORKFLOW_REAL=PASS
FLOATING_WIDGET_REAL=PASS
COMPLEX_TASK_REAL=PASS
TEST_ISOLATION=PASS
EXTERNAL_CALLS_USED=~12
WORKING_PROVIDERS=GEMINI,CLOUDFLARE,OPENROUTER(catalogo gratuito)
QUARANTINED_PROVIDERS=GROQ
BLOCKERS_REMAINING=none
FINAL_RESULT=PASS
```

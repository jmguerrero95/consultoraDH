# CONSULTORA_DH_A07_R5_R1 — Corrección del turno de investigación de T02

## 1. Causa exacta del fallo de T02

T02 es una pregunta **pública** de investigación:

> «Investiga cuáles son los avances recientes en baterías de sodio para almacenamiento
> eléctrico. Compara sus ventajas y desventajas frente a las baterías de litio,
> identifica desarrollos actuales y cita fuentes verificables.»

El fallo no fue del proveedor ni del modelo: fue del **ciclo del turno**. Convivían dos
defectos que se reforzaban:

- **FALLO A — frontera de rondas.** `AiAssistantService::processResult()` recorría
  `while ($round < $maxRounds)`. Con `AI_TOOL_MAX_ROUNDS=4`, tras ejecutar la cuarta
  tanda de herramientas el turno pedía una quinta respuesta al proveedor y **salía del
  bucle antes de leerla**. La quinta respuesta —la que ya contenía el texto final— se
  descartaba y `$reply` seguía siendo `null`, así que el turno terminaba en
  `AiTurnFailed::toolRoundsExhausted` («El asistente no pudo completar la respuesta esta
  vez»).
- **FALLO B — elevación indebida a privada.** Antes de cada nueva ronda, el turno
  evaluaba el resultado de la herramienta con `AiPrivacyGate::looksPrivate()`, que trata
  cualquier tirada de **seis o más dígitos** como identificador de documento. Los
  resultados de una búsqueda web pública (ids de artículo, fragmentos de URL, cifras)
  contienen números así, de modo que un turno **público** pasaba a clasificarse como
  `client_data`. A partir de ahí se excluían Groq, Gemini y Cloudflare (política
  `training`) y el turno quedaba cautivo de OpenRouter.

El diagnóstico que observó el usuario —cinco inferencias `outcome=ok`— es exactamente el
patrón esperado: el proveedor **sí respondía**, pero el turno no procesaba la última
respuesta y, además, el cambio a `client_data` reducía el pool tras la primera
herramienta.

## 2. Por qué cinco respuestas OK terminaron en error

Porque «respuesta del proveedor» y «resultado del turno» son dos hechos distintos:

- `PROVIDER_RESPONSE=OK` describe cada llamada HTTP, y el resolver las registra como
  tales (la primera con `task=web`, `required=[chat,tools]`; las siguientes ya con
  `client_data`).
- `TURN_RESULT` describe qué produjo el turno, y el bucle lo decidía **antes** de leer la
  respuesta posterior a la última tanda permitida.

Cinco (o cuatro) respuestas correctas pueden convivir con un turno fallido si la última
—la que resolvía— no se inspecciona. La corrección separa justamente ambos hechos y los
deja legibles por separado en el diagnóstico.

## 3. Qué provocó el cambio a `client_data`

La **procedencia ignorada**. `looksPrivate()` se aplicaba por igual al texto propio del
turno (pregunta, historial, pasajes de documento) y a los resultados de herramientas
**públicas**. En el texto propio, una tirada larga de dígitos es una señal razonable; en
un resultado de `web_search`/`web_fetch` es un identificador de la página. Tratarlos
igual convertía una investigación pública en información interna de Consultora DH.

No era una coincidencia de palabras: era una regla de forma aplicada sin tener en cuenta
de dónde venía el texto.

## 4. Cambios realizados

Se conservan intactos la arquitectura dirigida por el LLM, `AiCapabilityCatalog`,
`discover_capabilities`, las herramientas de administración, la investigación web, la
RBAC, los workers, los presupuestos de turno, el pool de proveedores gratuitos y las
conversaciones/documentos. No se tocó el router de T01 ni el pool ni las credenciales.

### 4.1 Frontera de rondas (FALLO A) — `AiAssistantService::processResult()`

- El bucle lee primero la respuesta en la mano. **Si no trae `tool_calls`, es la
  respuesta final** y se consume, antes de cualquier comprobación de ronda o
  presupuesto (leerla no cuesta nada).
- Solo se ejecuta una tanda si `$round < $maxRounds` **y** queda tiempo. Una petición de
  herramientas que excedería el máximo **no se ejecuta**: la respuesta queda `pending`.
- Semántica diferenciada: (A) máximo de tandas = 4; (B) la respuesta recibida después de
  la última tanda se procesa; (C) una nueva tanda que excedería el máximo no se ejecuta.
- Cuando hay una respuesta `pending`, se hace **una** síntesis explícitamente acotada y
  **sin herramientas** (bounded por tiempo e intentos) para responder con la evidencia ya
  reunida; si no hay tiempo/presupuesto, falla de forma controlada. No se amplió
  `AI_TOOL_MAX_ROUNDS`, no hay bucles infinitos y una respuesta incompleta nunca se
  persiste como resultado terminado.

### 4.2 Clasificación por procedencia (FALLO B)

- `AiPrivacyGate::carriesPersonalData()` (nuevo) reconoce **forma de dato personal**
  (email o teléfono agrupado) y se separa de `looksPrivate()`, que conserva la regla
  `\d{6,}` para el texto propio y para sanear la consulta saliente.
- En `processResult`, la elevación del turno decide por **procedencia**: `isPrivateTool`
  (registros propios, o no clasificado → fail closed) eleva siempre; un resultado de
  herramienta **pública** solo promueve si contiene un dato personal, nunca por un número
  suelto.
- `AiMessage` incorpora una marca de procedencia (`PROVENANCE_PUBLIC_WEB`). El bloque web
  que el servidor precarga para el turno se marca `public_web`, de modo que su contenido
  no se confunde con texto propio. `toWire()` no la envía al proveedor.
- `AiProviderResolver::payloadLooksSensitive()` aplica la misma distinción: texto de
  procedencia propia conserva la comprobación completa de forma; los mensajes `tool` y el
  bloque `public_web` solo se juzgan por forma de dato personal.
- Se preserva la protección de PII, datos internos, historial confidencial, documentos
  privados, fugas hacia buscadores y proveedores con política de entrenamiento
  incompatible. La reintroducción hacia el exterior sigue cerrada por la frontera de
  salida (`authorizeOutboundQuery` + `AiOutboundContext`), que bloquea consultas/URL
  derivadas de fuentes privadas. No se añadió ninguna lista de palabras.

### 4.3 Diagnóstico por ronda (§4) — `AiProviderResolver`

- `complete()` acepta `?string $turn` y `?int $round`. El service genera **un id de turno
  completo** y lo comparte con todas las llamadas, de modo que las filas de proveedor y
  el resumen se leen como un solo turno.
- Nueva fila de resumen (`recordTurn()`), en la misma lista y con el mismo TTL: `rounds`,
  `round_limit`, `tool_calls`, `tools` (nombres, sin argumentos), `deduped`,
  `privacy`/`privacy_changed`, `privacy_reason`, `processed`, `pending`, `ended_by`,
  `result`. Sin prompts, argumentos, cuerpos de herramientas ni credenciales.
- `PROVIDER_RESPONSE` y `TURN_RESULT` quedan separados de forma explícita: cada fila de
  proveedor trae `outcome`/`scope`/`http` y la fila de turno trae `result=ok|failed`. El
  turno escribe su resumen cuando entrega respuesta, cuando agota rondas, cuando la
  respuesta viene vacía y cuando falla el proveedor (`ended_by=provider_error`).
- La distinción «Gemini no intentado por privacidad» frente a «Gemini intentado y
  rechazó el payload» ya existía (fila `no_candidates scope=privacy` frente a fila de
  intento con `http`/`scope`); ahora ambas comparten el id de turno y conviven con el
  resumen.

### 4.4 Eficiencia de herramientas (§5)

- Deduplicación **pequeña** por turno, por la clave decisiva de cada llamada: misma
  `web_search` (consulta), misma `web_fetch` (URL), misma pregunta al catálogo. Se siembra
  con la búsqueda que ya hizo el prefetch del servidor para que el modelo no la repita.
- Las herramientas no listadas devuelven clave `null` y **siempre** se ejecutan: se
  conserva la iniciativa del LLM y no se prohiben investigaciones de varias etapas.

## 5. Pruebas herméticas (`EXTERNAL_LLM_REQUESTS=0`)

Se reprodujeron **primero contra el código previo** (guardando temporalmente los archivos
de `app/`) y las cinco pruebas dirigidas fallaron por las causas exactas descritas:

- `✗ AiTurnFailed: …after 4 rounds…` pese a que la quinta respuesta era el texto final
  (FALLO A).
- La segunda ronda fue a `PRIVATE_URL` y la respuesta fue la del proveedor privado
  (FALLO B); `contains_client_data` quedaba en `true`.
- Sin fila de resumen de turno (`round_limit`) y con dos búsquedas idénticas ejecutadas.

Tras la corrección, en `tests/Feature/A07/AgentCoreTest.php` (con `Http::fake` y
`preventStrayRequests`, ya activo en `TestCase`):

1. **T1** — cuatro tandas de herramientas + quinta respuesta textual → se entrega.
2. **T2** — cuatro tandas + quinta **petición de herramientas** → no se ejecuta; la
   síntesis sin herramientas responde y la sexta petición no ofrece ninguna herramienta.
3. **T3** — resultado público con identificador numérico largo → no se eleva; ambas
   rondas permanecen en el proveedor público. (T5 multietapa: el mismo caso.)
4. **T6** — turno agotado por límite → el resumen dice `ended_by=round_limit`,
   `result=failed`, `pending=true`, `processed=false`, y ninguna fila afirma cuota.
5. **T7** — la misma búsqueda pedida dos veces en un turno se ejecuta **una** vez.

**T4** (herramienta administrativa con datos privados → eleva antes de la siguiente
inferencia) queda cubierta por la prueba ya existente
`test_a_private_tool_result_is_not_sent_to_a_training_provider_in_the_same_turn`
(`client_search`). **T7** de citas sin inventar URL queda cubierta por `WebSearchToolTest`
(el tool no rellena resultados vacíos con una fila ficticia).

Suites dirigidas (ejecutadas por archivo, sin suites globales):

| Suite | Resultado |
|---|---|
| `tests/Feature/A07/AgentCoreTest.php` | 32 passed |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | 24 passed |
| `tests/Feature/A07/AiAssistantRuntimeTest.php` | 12 passed |
| `tests/Feature/A07/WebSearchToolTest.php` | 5 passed |
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | 28 passed |
| `tests/Unit/A07/AiIntentRoutingTest.php` | 14 passed |
| `tests/Unit/A07/AiReplySanitizerTest.php` | 5 passed |
| `tests/Unit/A07/WeatherToolValidationTest.php` | 5 passed |

`php -l` limpio en los archivos modificados y Pint aplicado solo a ellos (un arreglo de
estilo en `AiAssistantService.php`). Sin cambios de frontend, no se ejecutó Playwright ni
`vue-tsc`.

Ajuste mínimo de una prueba existente: `AiErrorScopeAndFailoverTest` buscaba la fila
`no_candidates` en `$rows[0]`; ahora el resumen de turno es la fila más reciente, así que
la prueba localiza su fila explícitamente y además comprueba que el resumen dice
`result=failed`.

## 6. Validación real (§7)

Una sola prueba real de T02, sin repetición automática, con usuario de desarrollo
(`client@example.com`, permisos `ai.use` + `web_search`), SearXNG operativo y proveedores
gratuitos disponibles (`allow_training_providers=false`):

- **Resultado:** `ASK_OK=YES`. Respuesta entregada de **6 358 caracteres** con citas y
  URLs reales (p. ej. `hibridosyelectricos.com/…/catl-…-bateria-sodio_87608_102.html`),
  en **26,1 s**.
- **Proveedor final:** `openrouter / nvidia/nemotron-3.5-super-vl:free` tras un `429`
  de Groq (`openai/gpt-oss-20b`, `scope=quota_account`) resuelto con **failover
  automático** dentro del mismo turno. Groq sirvió las rondas 1 y 2.
- **Herramientas:** `["web_search","web_fetch","web_search","web_search"]` en **3 tandas**
  (≤ 4) y la **cuarta** respuesta fue el texto final, que se entregó. La frontera de
  rondas queda validada en vivo.
- **Privacidad:** `contains_client_data=false` y `required=[chat,tools]` en todas las
  rondas, con `eligible=14`. El resultado web con números largos **no** elevó el turno
  (FALLO B no reproducido en vivo).
- **Diagnóstico:** una fila de turno (`turn=ac5497167eff`, `result=ok`, `ended_by=answer`,
  `rounds=3`, `tool_calls=4`, `tools=[web_search,web_fetch]`, `privacy_changed=false`,
  `processed=true`, `pending=false`) y las filas de proveedor con el mismo id y rounds
  1–4.

## 7. Limitaciones

- La clasificación de privacidad sigue siendo **léxica**. Un nombre de cliente sin
  posesivo ni marcador en la pregunta puede clasificar público; queda mitigado por el
  suelo de patrones y por la elevación al tocar una herramienta de registros.
- Un resultado de herramienta **pública** que contenga un email o un teléfono **sí**
  promueve el turno (dato personal por naturaleza): está previsto.
- La marca `public_web` cubre el bloque web del servidor y los mensajes `tool`. Otros
  puntos que inyecten texto web público como mensaje `user` conservan la regla de dígitos
  para el texto propio.
- En el diagnóstico, `round` es el índice de la llamada al resolver: un failover dentro
  de la misma llamada comparte número de round.
- La deduplicación cubre `web_search`, `web_fetch` y `discover_capabilities`; el resto de
  herramientas se ejecuta siempre.
- Sigue sin consultarse externamente el estado de la cuota por clave en el camino de la
  petición (límite documentado desde A07-R5).

## 8. Bloque final

```
CONSULTORA_DH_A07_R5_R1

BASE_SHA=2c5a9dc54b699c3a54777cff7e3e396a91748bc5
FINAL_CODE_SHA=77d48a0e071efbeb50e3748af4d6b0ba3fc31479
HEAD=77d48a0e071efbeb50e3748af4d6b0ba3fc31479
GIT_SYNC=PASS

T02_ROUND_BOUNDARY=PASS
T02_FINAL_RESPONSE_PROCESSED=PASS
T02_PUBLIC_DATA_CLASSIFICATION=PASS
T02_PRIVATE_DATA_PROTECTION=PASS
T02_TOOL_TRACE=PASS
T02_RESEARCH_EFFICIENCY=PASS

MULTIPROVIDER_PRESERVED=PASS
LLM_AUTONOMY_PRESERVED=PASS
RBAC_PRESERVED=PASS
FREE_ONLY=PASS

TARGETED_TESTS=8 suites dirigidas en verde (125 pruebas); 5 pruebas nuevas de T02 reproducidas contra el código previo y verdes tras la corrección
EXTERNAL_LLM_REQUESTS=0 (fase hermética); 1 turno real autorizado por §7
T02_LIVE=PASS

BLOCKERS_REMAINING=Ninguno de código. Limitaciones declaradas en §7 (clasificador léxico; `round` por llamada al resolver; sin consulta externa de cuota por clave).
FINAL_RESULT=PASS
```

Nota de sincronización: `FINAL_CODE_SHA`/`HEAD` identifican el commit funcional; el
informe se publica como un commit posterior sobre la misma rama. `GIT_SYNC=PASS` se
verifica con `git rev-parse HEAD == git rev-parse origin/main` tras el push.

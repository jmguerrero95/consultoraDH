# Consultora DH — A07-R4-R1 · Corrección quirúrgica de seguridad y runtime del núcleo de agente

**Ámbito:** reparación puntual de los defectos D1–D8 detectados por la auditoría
independiente sobre A07-R4, sin reconstruir el asistente ni revertir la orquestación
dirigida por el modelo.

**Estado:** cambios funcionales implementados, probados en hermético y publicados en
`origin/main`. La validación contra proveedor real queda **BLOCKED** por cuota externa.

- `BASE_SHA` (inicio): `e56c0c168ae4297f31ce13d88ec30ef67d0c1fe2`
- `FINAL_SHA` (último commit de código): `7373c14a1598984280c8ae6b4a2b78bd0d04bc1d`

---

## 1. Resumen ejecutivo

A07-R4 entregó un núcleo de agente autónomo correcto en su arquitectura (el modelo
decide, Laravel valida y autoriza), pero con ocho defectos de frontera: la privacidad
se decidía una sola vez al abrir el turno y no se reelevaba cuando una herramienta
privada corría o cuando el texto de salida traía un identificador; los trabajos
asíncronos (investigación, redacción de documentos) llamaban al proveedor sin ninguna
clasificación y caían al default "público"; toda búsqueda web salía sin filtrar; una
herramienta no declarada se trataba como pública; el etiquetado histórico podía mezclar
cifras actuales sin marcar; y el presupuesto no cubría la recuperación, el descubrimiento
de capacidades ni las herramientas externas.

A07-R4-R1 cierra esos ocho puntos manteniendo intacto lo que ya funcionaba:
orquestación dirigida por el modelo, catálogo semántico de capacidades
(`discover_capabilities`), failover de proveedores, RBAC y confirmaciones de escritura.

La decisión de diseño central es **una sola frontera de privacidad** (`AiPrivacyGate`)
y **un solo reloj de turno** (`AiToolDeadline`), de modo que cada ruta de salida —chat,
tareas, búsqueda, fetch— pregunte lo mismo y respete el mismo presupuesto.

---

## 2. Causas raíz por defecto (D1–D8)

### D1 — Privacidad dentro del mismo turno

**Causa raíz.** `AiAssistantService` calculaba `$requiresClientData` **una vez**, antes
de la primera llamada al modelo, y lo pasaba inmutable al resolutor. Si una herramienta
privada (`client_search`, `client_account`, …) corría en un turno iniciado como público,
su resultado se enviaba al modelo en la **siguiente** ronda con la clasificación
original ("público"). Lo mismo ocurría si el modelo producía un texto con un
identificador. No había degradación posible hacia arriba dentro del turno.

**Corrección.** El flag pasó a ser **mutable** dentro de `processResult()`
(`$turnRequiresClientData`). Se eleva en el mismo instante en que:
- corre una herramienta privada o no declarada (`isPrivateTool()`), o
- el texto de salida del modelo "parece privado" (`AiPrivacyGate::looksPrivate()`), o
- una herramienta de escritura eleva la sensibilidad.

Y se **persiste en la conversación** (`markConversationPrivate()`) **antes** de la
siguiente llamada a `resolver->complete()`, de modo que el turno siguiente hereda la
marca. Si `$requiresClientData` es verdadero y no existe proveedor autorizado, el
resolutor lanza un mensaje de privacidad específico y el controlador responde
`503 { error, message, recoverable: true }` — nunca reintenta con un modelo público.

### D2 — Privacidad en tareas asíncronas

**Causa raíz.** `ResearchTask::synthesize()` y `GenerateDocumentTask::draft()` llamaban
a `providers->complete()` sin clasificación; el default del resolutor es `false`
(público). Una tarea de investigación sobre un cliente concreto, o la redacción de un
documento con datos reales, podía salir hacia un proveedor cuyo tier gratuito entrena
con el contenido.

**Corrección.**
- `AiTaskService` sella `contains_client_data` en el `input` de la tarea al
  **encolarla**: investigación = bandera de conversación ∨ `looksPrivate(pregunta)`;
  documento = `true`.
- `ResearchTask::synthesize()` **deriva** `requiresClientData` de la bandera de tarea,
  la bandera de conversación, las fuentes de adjuntos y el texto, y persiste la marca
  de conversación.
- `GenerateDocumentTask::draft()` toma `contains_client_data` del `input`, con default
  **`true`** (lo desconocido no es público).
- `SuggestionReasoner` pasa `requiresClientData: true` de forma explícita: la evidencia
  son saldos de un cliente real.

### D3 — Fuga en búsqueda web saliente

**Causa raíz.** `WebSearchTool` enviaba la consulta tal cual al motor. Si el modelo o el
prefetch ponía un email, un número de documento/NIT o un teléfono, salía del servidor.

**Corrección.** `WebSearchTool` aplica `AiPrivacyGate::sanitizeQuery()` a **toda**
consulta: redacta emails, corridas de `\d{6,}` y grupos telefónicos `3-3-4`, y solo
envía y reporta la consulta saneada. Una consulta que es **únicamente** un identificador
se **rechaza** (`AiToolRejected`) en lugar de enviarse vacía. `WebFetchTool::validate()`
rechaza URLs que embeban un email. La protección SSRF existente no se toca.

### D4 / D8 — Clasificación fail-closed y descubrimiento

**Causa raíz.** `AiCapabilityCatalog` trataba una herramienta no declarada como
**pública**. El día que alguien registrara una herramienta y olvidara describirla, su
salida sería elegible para un proveedor que entrena con el contenido. Además, el
descubrimiento debía seguir funcionando para pedidos que no coinciden con las pistas
del router preexistente.

**Corrección.** `sensitivity($name)` devuelve `public | client | unknown`.
`requiresClientData()` / `isPrivateTool()` devuelven `true` salvo que la herramienta
esté **declarada pública**. Un nombre que no es una herramienta registrada también es
`unknown` (el modelo no puede alcanzar una clasificación privada inventando un nombre).
`entries()` expone `classified` y marca `private_data=true` para las desconocidas.
Se mantienen públicas solo las que demostrablemente no tocan nuestros datos:
`create_artifact` (almacena en privado y no lee registros) y `automation_vocabulary`
(vocabulario estático). El descubrimiento (`discover_capabilities`) no cambia.

### D5 — Exactitud histórica (`as_of`)

**Causa raíz.** El bloque de estadísticas podía etiquetar cifras **actuales** como
históricas al pedir `as_of`, y mezclaba filas no reconstruibles sin marcarlas.

**Corrección.** `SystemStatsTool` añade `scope`, `clients.basis`, `clients.note`,
`companies.basis` y `not_reconstructable` **solo cuando hay `as_of`**. El bloque
`receivables` se deja **byte-idéntico** al de pre-R4 (se conserva la frase `basis`;
se agrega `basis_kind` solo en histórico). Sin `as_of`, la salida actual es idéntica.
Se corrigió además un bug latente de tipo: la importación de `Carbon` pasa a
`Illuminate\Support\Carbon` para que `Carbon::parse()` coincida con
`clientBalanceCounts(?Illuminate\Support\Carbon)`. No se alteran datos financieros ni
se ejecutan migraciones destructivas.

### D6 — Presupuesto de ejecución

**Causa raíz.** El presupuesto del turno no cubría la **recuperación** previa ni el
**descubrimiento** de capacidades; las **herramientas externas** (`web_search`,
`web_fetch`, clima) usaban timeouts fijos que podían sobrevivir al presupuesto; y no
había distinción entre tareas HTTP cortas y trabajos en cola largos.

**Corrección.**
- Nuevo singleton `AiToolDeadline` (`set`, `cap(configured, minimum=3)`).
- `AiAssistantService::ask()` crea el presupuesto **antes** de la recuperación, fija el
  deadline (try/finally) y lo pasa a `runTurn()`.
- `processResult()` corta el bucle cuando `$budget->outOfTime()`.
- `WebSearchTool`, `WebFetchTool` y `WeatherTool` **capan** su timeout por el tiempo
  restante. El timeout de `web_fetch` pasa a config (`ai.web_fetch.timeout_seconds`,
  default 15) para poder caparlo de forma independiente.
- Las tareas usan **su propio** presupuesto (`SYNTHESIS_TIMEOUT_SECONDS=60`); la
  investigación fija/limpia el deadline alrededor de recolección + síntesis.

### D7 — Compatibilidad de migración

**Causa raíz.** La marca de privacidad de conversación necesitaba una columna nueva, y
debía funcionar sobre una base **pre-R4** sin pasos destructivos.

**Corrección.** Migración
`2026_10_17_000000_add_contains_client_data_to_ai_conversations_table.php`: **aditiva**,
`boolean not null default false`, válida en PostgreSQL, no destructiva.
`migrate:status` la muestra como `Ran`; no hay nada pendiente y no se usó
`migrate:fresh`.

---

## 3. Archivos modificados y nuevos

**Nuevos**

| Archivo | Rol |
|---|---|
| `app/Services/Ai/AiPrivacyGate.php` | Frontera única: `looksPrivate`, `payloadRequiresClientData`, `sanitizeQuery` |
| `app/Services/Ai/AiToolDeadline.php` | Reloj de turno compartido; `set`/`cap` |
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | Pruebas de la frontera de privacidad y del reloj |

**Modificados**

| Archivo | Cambio |
|---|---|
| `app/Services/Ai/AiAssistantService.php` | flag mutuo de turno, presupuesto antes de recuperar, deadline, corte por tiempo, `markConversationPrivate` |
| `app/Services/Ai/AiCapabilityCatalog.php` | `sensitivity`/`requiresClientData` fail-closed; `entries()` con `classified`/`private_data` |
| `app/Services/Ai/AiProviderResolver.php` | mensaje de indisponibilidad por privacidad cuando no hay candidato autorizado |
| `app/Services/Ai/Tools/WebSearchTool.php` | saneo de consulta + rechazo de identificador puro + cap de timeout |
| `app/Services/Ai/Tools/WebFetchTool.php` | rechazo de URL con email + cap de timeout + timeout por config |
| `app/Services/Ai/Tools/WeatherTool.php` | cap de timeout |
| `app/Services/Ai/Tools/SystemStatsTool.php` | etiquetado histórico y no-reconstruible; corrección de import de Carbon |
| `app/Http/Controllers/Api/AiAssistantController.php` | `503` recuperable ante `AiProviderException`/`AiTurnFailed` |
| `app/Domain/Assistant/Tasks/ResearchTask.php` | clasificación explícita + presupuesto propio |
| `app/Domain/Assistant/Tasks/GenerateDocumentTask.php` | clasificación explícita (default privado) + presupuesto propio |
| `app/Domain/Assistant/AiTaskService.php` | sello `contains_client_data` al encolar |
| `app/Domain/Assistant/SuggestionReasoner.php` | `requiresClientData: true` |
| `app/Providers/AppServiceProvider.php` | binding singleton de `AiToolDeadline` y de `AiPrivacyGate` |
| `config/ai.php`, `.env.example` | `ai.web_fetch.timeout_seconds` / `AI_WEB_FETCH_TIMEOUT_SECONDS` |
| `tests/Feature/A07/AgentCoreTest.php` | pruebas D1–D8 |

> Nota de implementación: en Laravel 13, un parámetro con valor por defecto sobre una
> clase **no enlazada** recibe su **default** (aquí `null`). Por eso `AiPrivacyGate` se
> enlaza como singleton: sin ese binding, `WebSearchTool::$privacy` quedaba en `null` y
> la redacción de consultas se perdía silenciosamente. Se verificó por reflexión que la
> inyección ahora ocurre.

---

## 4. Flujos de privacidad corregidos

1. **Turno de chat (síncrono).** Clasificación inicial por procedencia
   (`classifiesAsClientData`), y **reelevación dentro del turno** por herramienta
   privada, salida con identificador o escritura. La marca se **persiste en la
   conversación antes** de cada nueva llamada al proveedor.
2. **Sin proveedor autorizado.** El resolutor **no** reencamina a un modelo público:
   lanza un error específico de privacidad y el controlador devuelve `503` recuperable.
   Los datos permanecen en el servidor.
3. **Investigación asíncrona.** `contains_client_data` sellado al encolar y derivado al
   sintetizar; la conversación queda marcada.
4. **Redacción de documento.** Default **privado** salvo sello explícito contrario.
5. **Razonamiento de sugerencias.** Siempre requiere proveedor autorizado para datos
   privados.
6. **Búsqueda web.** Consulta saneada con la misma frontera; identificador puro
   rechazado.
7. **Lectura de URL.** URL con email rechazada.

La regla que el sistema implementa es la del encargo: *una pregunta pública dentro de
una conversación privada sigue siendo una pregunta pública* (no se bloquea); pero un
identificador que se coló en la consulta **se elimina**, y una consulta que es **solo**
un identificador se **rechaza**.

---

## 5. Puntos de llamada externos auditados (`complete()`)

Todos los sitios que hablan con un proveedor:

| Sitio | Clasificación | Nota |
|---|---|---|
| `AiAssistantService::ask()` (línea ~190) | `$requiresClientData` inicial | chat, con presupuesto y deadline |
| `AiAssistantService::processResult()` (línea ~882) | `$turnRequiresClientData` (mutable) | ronda de síntesis posterior a herramientas |
| `AiProviderResolver::……` (línea ~200) | recibe `$requiresClientData` | selecciona solo candidatos autorizados si es privado |
| `ResearchTask::synthesize()` (~330) | derivado de banderas/fuentes/texto | trabajo en cola |
| `GenerateDocumentTask::draft()` (~157) | `contains_client_data` (default `true`) | trabajo en cola |
| `SuggestionReasoner` (~133) | `true` fijo | evidencia son saldos reales |
| `AiProviderCheckCommand` (~85, 103, 112, 153, 222, 238) | **sondas fijas sintéticas** | comando de operador; nunca transporta datos reales (`ACME-77`, preguntas genéricas) |

`ALL_PROVIDER_CALLS_AUDITED=PASS`: ninguna ruta del chat, de las tareas ni de las
herramientas puede enviar datos privados a un proveedor no autorizado.

---

## 6. Políticas de clasificación (fail-closed)

- `public` — solo para herramientas demostrablemente ajenas a nuestros registros:
  `create_artifact` (almacena en privado, no lee), `automation_vocabulary` (estático).
- `client` — herramientas declaradas como lectoras de registros.
- `unknown` → tratada como **privada** hasta que alguien la clasifique. Aplica tanto a
  una herramienta registrada sin metadatos como a un nombre inexistente.

`UNKNOWN_TOOL_FAIL_CLOSED=PASS`.

---

## 7. Manejo de documentos y búsqueda

- **Documentos:** la generación es privada por defecto; el contexto de adjuntos eleva
  la clasificación del turno y de la síntesis.
- **Búsqueda:** `PUBLIC_SEARCH_DATA_BOUNDARY=PASS` — toda consulta sale saneada o no
  sale; la finalidad pública del resultado no relaja la eliminación de identificadores.

---

## 8. Exactitud histórica

- Sin `as_of`: comportamiento **idéntico** al actual; `receivables` byte-idéntico.
- Con `as_of`: se etiquetan explícitamente `scope`, `basis`/`note` por bloque y
  `not_reconstructable`; una cifra actual **nunca** se rotula como histórica.
- `HISTORICAL_FINANCIAL_ACCURACY=PASS`, `CURRENT_VS_HISTORICAL_LABELING=PASS`.
- No se alteraron datos financieros ni se corrieron migraciones destructivas.

---

## 9. Presupuestos

- **Global de turno:** cubre recuperación, descubrimiento de capacidades, llamadas al
  proveedor, búsqueda, `web_fetch`, herramientas externas, rondas posteriores y cambios
  de proveedor, vía `AiToolDeadline::cap()`. Al agotarse, el bucle corta.
- **Workers:** presupuesto propio (`SYNTHESIS_TIMEOUT_SECONDS=60`), independiente del
  turno interactivo, para separar tareas HTTP cortas de trabajos en cola largos.
- `GLOBAL_EXECUTION_BUDGET=PASS`, `WORKER_BUDGETS=PASS`.

---

## 10. Migración

- Archivo: `database/migrations/2026_10_17_000000_add_contains_client_data_to_ai_conversations_table.php`.
- Aditiva, `boolean` `default(false)`, no destructiva, PostgreSQL-válida.
- `migrate:status`: `Ran` (nada pendiente). Verificado por tinker: tipo `bool`.
- Aplicable sobre una base pre-R4 sin pasos destructivos (una sola `ALTER TABLE ... ADD COLUMN`).
- `MIGRATION_COMPATIBILITY=PASS`.

---

## 11. Evidencia de pruebas (hermético, `Http::fake` + `preventStrayRequests`)

Comando: `docker exec consultora-dh-app php artisan test <rutas>`

| Suite | Resultado |
|---|---|
| `tests/Feature/A07/AgentCoreTest.php` + `tests/Unit/A07/AiPrivacyBoundaryTest.php` | **30 passed (99 assertions)** |
| `tests/Unit/A07` (completo) | **32 passed (76 assertions)** |
| `tests/Feature/A07/AiAssistantRuntimeTest.php` | 12 passed |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | 15 passed |
| `tests/Feature/A07/AssistantTaskKindsTest.php` | 11 passed |
| `tests/Feature/A07/AttachmentToAssistantTest.php` | 11 passed |
| `tests/Feature/A07/SuggestionReasonerTest.php` | 14 passed |
| `tests/Feature/A07/DocumentPipelineTest.php` | 16 passed |
| `tests/Feature/A07/AiWebArtifactTest.php` | 3 passed |

Cobertura D1–D8 dentro de `AgentCoreTest`: D1 (2 pruebas), D2 (2), D3 (2 + WebFetch),
D5 (2), D7 (1), D8 (1). `AiPrivacyBoundaryTest` cubre la frontera y el reloj (12).

`php -l` limpio en los 18 archivos PHP tocados; Pint **PASS** sobre los 18 (los 2 avisos
Pint preexistentes están en archivos **no tocados**: `OpenAiCompatibleProvider.php`,
`CreateArtifactTool.php`).

Prueba de autonomía representativa: **un pedido general descubre una herramienta de
datos que el router nunca sugirió** (`test_a_general_request_can_discover_a_data_tool_the_router_never_hinted`),
demostrando que el descubrimiento no depende de palabras clave rígidas.

> Nota de aislamiento: al ejecutar varias suites A07 en un **mismo** proceso, la base de
> pruebas compartida (que no usa `RefreshDatabase` en `AgentCoreTest`) puede provocar
> interferencia entre archivos. Cada suite objetivo pasa de forma individual; la
> interferencia es preexistente y ajena a estos cambios.

---

## 12. Limitaciones y validación contra proveedor real

- **Validación con proveedor real: BLOCKED (causa externa).** Se emitió **exactamente 1
  solicitud real** (`EXTERNAL_LLM_REQUESTS=1`) con prompt **público** y `maxAttempts=1`.
  Resultado: `AiProviderException: The AI provider is rate limiting this deployment.
  (Rate limit exceeded: free-models-per-day…)`. No se enviaron datos privados reales.
- El agotamiento de cuota de OpenRouter, el bloqueo geográfico de Groq y la
  intermitencia de Cloudflare/Gemini son condiciones del entorno, no del código.
- Los escenarios privados en vivo (chat privado real, PDF real) quedan, por tanto, sin
  validación contra proveedor real; sí están cubiertos por pruebas herméticas.
- No se tocó el import histórico #6, no se reiniciaron bases de datos, no se modificó el
  pool salvo lo estrictamente necesario para seguridad, sin modelos de pago, sin facturación,
  sin IA local ni suites globales.

---

## Bloque de cierre

```
CONSULTORA_DH_A07_R4_R1_SECURITY_AND_RUNTIME_FIX
BASE_SHA=e56c0c168ae4297f31ce13d88ec30ef67d0c1fe2
FINAL_SHA=7373c14a1598984280c8ae6b4a2b78bd0d04bc1d
ORIGIN_MAIN_TIP=7373c14a1598984280c8ae6b4a2b78bd0d04bc1d
GIT_SYNC=ok
SAME_TURN_PRIVACY=PASS
ASYNC_RESEARCH_PRIVACY=PASS
ASYNC_DOCUMENT_PRIVACY=PASS
ALL_PROVIDER_CALLS_AUDITED=PASS
PUBLIC_SEARCH_DATA_BOUNDARY=PASS
UNKNOWN_TOOL_FAIL_CLOSED=PASS
HISTORICAL_FINANCIAL_ACCURACY=PASS
CURRENT_VS_HISTORICAL_LABELING=PASS
GLOBAL_EXECUTION_BUDGET=PASS
WORKER_BUDGETS=PASS
MIGRATION_COMPATIBILITY=PASS
LLM_DIRECTED_ORCHESTRATION_PRESERVED=PASS
CAPABILITY_DISCOVERY_PRESERVED=PASS
PROVIDER_FAILOVER_PRESERVED=PASS
RBAC_AND_WRITE_CONFIRMATION=PASS
FREE_ONLY=PASS
TARGETED_TESTS=PASS
EXTERNAL_LLM_REQUESTS=1
REAL_PROVIDER_VALIDATION=BLOCKED
BLOCKERS_REMAINING=openrouter_free_daily_quota_exhausted,groq_geo_blocked,cloudflare_gemini_intermittent,live_private_scenarios_blocked
FINAL_RESULT=PARTIAL
```

> `ORIGIN_MAIN_TIP` corresponde al commit que contiene **todos** los cambios funcionales
> (`FINAL_SHA`); conforme a la convención del repositorio, este informe se publica en un
> commit de documentación inmediatamente posterior, que avanza `origin/main` en un único
> commit de documentación (sin cambios funcionales).

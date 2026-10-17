# Consultora DH — A07-R4-R2-R1 · Cierre de procedencia privada (P1–P3)

**Alcance.** Cerrar exclusivamente tres huecos de procedencia privada que quedaron abiertos
tras A07-R4-R2. No se reconstruyó A07, no se reabrieron D10 ni D11, no se amplió
funcionalidad ni se reauditó globalmente. Sin migraciones, sin tocar PostgreSQL, registros
de negocio, `.env`, ni ejecutar `migrate:fresh`.

- `BASE_SHA`: `40c665e0d2e26cd80a6814e0b2fd7c4322b87351`
- `FINAL_CODE_SHA`: `3e72f488bf19b8ce298aa0b35f66cb451bb9bcb9`
- `ORIGIN_MAIN_TIP` (tip con el cambio funcional): `3e72f488bf19b8ce298aa0b35f66cb451bb9bcb9`
- Este informe se publica en el commit de documentación inmediatamente posterior (sin cambios funcionales).

---

## 1. Causa raíz y solución

### P1 — El historial privado era procedencia invisible

**Causa.** `AiAssistantService::runTurn` registraba en `AiOutboundContext` únicamente los
pasajes de documentos recuperados en *el turno actual* y la pregunta actual como texto
autorizado. El historial de la conversación se enviaba al modelo pero nunca se registraba
como material privado. Un nombre de cliente, una cláusula interna o una cifra que el modelo
leyó tres turnos atrás no tienen forma reconocible (`sanitizeQuery` solo quita correos, corridas
de 6+ dígitos y teléfonos), así que el modelo podía copiarlos del historial a una consulta de
búsqueda y nada lo impedía. El historial era la fuente privada que la frontera no veía.

**Solución.**
- `close()` pasó el historial (`$history`) a `runTurn`.
- Nuevo `AiAssistantService::privateSourcesForTurn(conversation, history, passages)`: devuelve
  los pasajes (siempre privados) más los turnos previos **cuando la conversación ya está marcada
  como privada** (`contains_client_data`). Un hilo público y limpio conserva su historial como
  contexto público, de modo que una pregunta pública independiente no se bloquea.
- `AiOutboundContext::set()` registra esas fuentes como privadas. La pregunta actual **no** se
  registra como autorización (ver más abajo): repetir un identificador no lo hace público.

### P2 — `ResearchTask` no tenía procedencia y podía retenerla entre trabajos

**Causa.** `ResearchTask::handle` fijaba el deadline pero nunca tocaba `AiOutboundContext`.
Sus búsquedas (`document_search`, `web_search`) corrían con un contexto vacío o —peor— heredado
de otro trabajo, porque un worker de cola reutiliza el mismo contenedor Laravel entre trabajos y
el singleton sobrevive. Además, los pasajes de un documento adjunto privado no eran procedencia
conocida al buscar en la web: una pregunta que nombrara un valor interno podía enviarlo al
buscador.

**Solución.**
- `ResearchTask` recibe `AiOutboundContext` por constructor (autowire; singleton ya enlazado).
- `handle()` limpia el contexto al entrar (`set()`) y lo borra en `finally` (`reset()`), junto al
  deadline. Así ningún trabajo hereda ni deja procedencia, ni siquiera si lanza.
- `gather()` registra como privados los pasajes de los adjuntos y los resultados de
  `document_search` **antes** de que corra `web_search`. Los resultados de `web_search` son
  públicos y no se registran.

### P3 — Valores privados en la *ruta* de la URL

**Causa.** `sensitiveUrlReason` solo comparaba por procedencia el *query string*. Una ruta como
`/documentos/ProyectoAlondra/compensacion-especial` transmitía exactamente el mismo valor privado
que un parámetro de consulta y pasaba sin ser examinada. El porcentaje de codificación era además
un velo trivial (`%41londra`), y un nombre pegado en un segmento (`ProyectoAlondra`) no coincidía
con el nombre separado (`Proyecto Alondra`) en la comparación por tokens.

**Solución.**
- `sensitiveUrlReason` examina user info, **path**, query y fragmento de la URL efectiva,
  decodificados una vez (`rawurldecode`). El host se excluye a propósito: nombra el sitio público,
  no el registro que hay en él.
- WebFetch reaplica la comprobación en **cada** salto de redirección, así que una redirección a una
  ruta privada se rechaza antes de emitir la segunda solicitud.
- `significantTokens` ahora separa camelCase y transiciones letra/dígito antes del filtro de
  longitud, de modo que `ProyectoAlondra` produce `proyecto` y `alondra`.
- El mensaje de error es genérico (`La URL contiene información privada y no puede consultarse.`)
  y nunca incluye la URL ni el valor.

### Decisión de frontera: la materialidad privada gana

Se retiró el parámetro `authorizedSources` de `authorizeOutboundQuery` / `sensitiveUrlReason` y
de `AiOutboundContext`. La pregunta actual del usuario dejó de excusar términos que la materialidad
privada ya contiene: **escribir un identificador no es por sí solo consentimiento para divulgarlo a
un buscador**. Una consulta pública genuinamente independiente —cuyos términos no aparecen en
ninguna fuente privada— sigue permitiéndose. Los términos del historial nunca se usan como texto
autorizado.

---

## 2. Archivos modificados

| Archivo | Cambio |
|---|---|
| `app/Services/Ai/AiAssistantService.php` | `runTurn` recibe el historial; nuevo `privateSourcesForTurn` |
| `app/Services/Ai/AiOutboundContext.php` | una sola lista `privateSources`; se elimina `authorizedSources` |
| `app/Services/Ai/AiPrivacyGate.php` | procedencia sobre path/user/query/fragmento; split camelCase; se elimina `authorizedSources` |
| `app/Services/Ai/Tools/WebSearchTool.php` | usa solo `privateSources()` |
| `app/Services/Ai/Tools/WebFetchTool.php` | reaplica `sensitiveUrlReason` (sin autorizados) en cada salto |
| `app/Domain/Assistant/Tasks/ResearchTask.php` | contexto de salida: fijar/limpiar; adjuntos y `document_search` privados |
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | +4 (historial, path privada, path codificada, path pública) |
| `tests/Feature/A07/AgentCoreTest.php` | +3 (historial→buscador, seguimiento público, redirección a path privado) |
| `tests/Feature/A07/ResearchAttachmentTest.php` | +2 (aislamiento entre trabajos, limpieza tras fallo) y ajuste del caso existente |

Sin migraciones. Sin cambios en esquema, registros de negocio ni `.env`.

---

## 3. Pruebas y resultados

Ejecutadas con `Http::fake` + `preventStrayRequests`, datos sintéticos, por archivo (el reposo
conocido de interferencia entre suites A07 en un mismo proceso se respetó).

| Suite | Resultado |
|---|---|
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | **26 passed** (66 aserciones) |
| `tests/Feature/A07/AgentCoreTest.php` | **25 passed** (74 aserciones) |
| `tests/Feature/A07/ResearchAttachmentTest.php` | **6 passed** (12 aserciones) |
| `tests/Feature/A07/WebFetchSsrfTest.php` | 33 passed |
| `tests/Feature/A07/WebFetchHttpIntegrationTest.php` | 10 passed |
| `tests/Feature/A07/WebSearchToolTest.php` | 5 passed |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | 19 passed |
| `tests/Unit/A07/AiIntentRoutingTest.php` | 10 passed |
| `tests/Unit/A07/AiReplySanitizerTest.php` | 5 passed |
| `tests/Unit/A07/WeatherToolValidationTest.php` | 5 passed |
| `tests/Feature/A07/AiAssistantRuntimeTest.php` | 12 passed |
| `tests/Feature/A07/AssistantTaskKindsTest.php` | 11 passed |
| `tests/Feature/A07/AttachmentToAssistantTest.php` | 11 passed |
| `tests/Feature/A07/DocumentPipelineTest.php` | 16 passed |
| `tests/Feature/A07/DocumentSearchTest.php` | 11 passed |
| `tests/Feature/A07/TaskRetryAndIsolationTest.php` | 12 passed |
| `tests/Feature/A07/AiAssistantTest.php` | 3 passed |
| `tests/Feature/A07/AiWebArtifactTest.php` | 3 passed |

Cobertura de los casos exigidos:
1. Término privado solo en el historial → búsqueda bloqueada: `test_a_private_term_that_lives_only_in_the_history_is_refused`, `test_a_private_term_from_the_conversation_history_does_not_reach_a_search_engine`.
2. Pregunta pública independiente → permitida: `test_a_public_query_inside_a_private_thread_is_still_allowed`, `test_a_public_follow_up_in_a_private_thread_still_reaches_the_search_engine`.
3. Investigación asíncrona con documento privado → no filtra: `test_research_uses_the_documents_attached_to_its_own_conversation`.
4. Dos trabajos sucesivos → procedencia aislada: `test_a_second_research_job_does_not_inherit_the_previous_tasks_provenance`.
5. URL con valor privado en path → bloqueada: `test_a_url_with_a_private_value_in_the_path_is_refused`.
6. URL pública con path normal → permitida: `test_a_public_path_is_not_refused_when_it_shares_no_private_term`.
7. URL codificada con valor privado → bloqueada: `test_a_percent_encoded_private_value_in_the_path_is_refused`.
8. Redirección hacia path privado → segunda solicitud no realizada: `test_web_fetch_refuses_a_redirect_to_a_url_that_carries_a_private_path`.
9. Error durante la ejecución → contexto limpiado: `test_a_failed_research_job_leaves_no_provenance_behind`.

`php -l` sobre los 9 archivos: sin errores. `Pint`: 1 corrección de estilo (`fully_qualified_strict_types`) aplicada.

---

## 4. Limitaciones honestas

- **La comparación sigue siendo textual, no semántica.** No detecta paráfrasis, abreviaturas ni
  traducciones de un valor privado. Es defensa de procedencia por coincidencia de términos,
  la misma técnica que R2, ahora alimentada por más fuentes y aplicada a más componentes.
- **Sobreloque conservador dentro de hilos marcados.** Cuando la materialidad privada contiene un
  término común (p. ej. una palabra de la pregunta pública), la consulta se rechaza aunque pudiera
  ser pública. Es el fallo cerrado que el hito pide: `fail-closed` cuando no puede probarse la
  separación.
- **Investigación asíncrona:** si la pregunta nombra un término que existe en el documento adjunto,
  la búsqueda web se rechaza (correcto: no se divulga el documento). Cuando la pregunta no comparte
  términos con material recuperable, la búsqueda web se mantiene. No se demostró un caso de
  "adjunto recuperado + pregunta pública" simplemente porque la recuperación exige compartir un
  término significativo, que es justo lo que dispara el bloqueo; el hilo público limpio sin adjunto
  recuperable sí busca en la web.
- **El flag `contains_client_data` es la procedencia del historial.** Un texto confidencial que
  nunca pasó por una herramienta privada, un adjunto ni un patrón no marca el hilo y por tanto no
  se registra desde el historial. La frontera prioriza metadatos de procedencia existentes sobre
  inferencia; no es una promesa de detección imposible.
- **No se ejecutaron validaciones vivas contra proveedores** (`EXTERNAL_LLM_REQUESTS=0`). El hito
  es determinista y hermético.

---

## 5. Bloque de cierre

```
P1_PRIVATE_HISTORY_PROVENANCE=CLOSED
P2_ASYNC_RESEARCH_TASK_PROVENANCE=CLOSED
P3_PRIVATE_URL_PATH_VALUES=CLOSED
LLM_AUTONOMY_PRESERVED=YES
GENERAL_WEB_SEARCH_PRESERVED=YES
SSRF_AND_DNS_PINNING_PRESERVED=YES
RBAC_PRESERVED=YES
TARGETED_TESTS=PASS
PHP_LINT=PASS
PINT=PASS
EXTERNAL_LLM_REQUESTS=0
BLOCKERS_REMAINING=NONE
FINAL_RESULT=PASS
```

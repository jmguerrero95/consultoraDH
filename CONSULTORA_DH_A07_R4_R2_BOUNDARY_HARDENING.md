# Consultora DH — A07-R4-R2 · Endurecimiento quirúrgico de fronteras (D9–D11)

**Alcance:** cerrar exclusivamente D9 (procedencia en salidas a internet), D10 (semántica
del deadline) y D11 (clasificación obligatoria antes del proveedor). No se reconstruyó A07.

- `BASE_SHA`: `40c665e0d2e26cd80a6814e0b2fd7c4322b87351`
- `FINAL_CODE_SHA`: `70a101768e5b193954aa192e75b5bac114948de4`
- `ORIGIN_MAIN_TIP` (tip con todos los cambios funcionales): `70a101768e5b193954aa192e75b5bac114948de4`
- Este informe se publica en el commit de documentación inmediatamente posterior (sin cambios funcionales).

---

## 1. Causa raíz y cambio

### D9 — Procedencia, no solo patrón, para las salidas

**Causa.** `sanitizeQuery()` solo quitaba correos, corridas de dígitos y teléfonos. Una
consulta con un nombre de empresa o el texto de un acuerdo interno no tiene forma
reconocible, así que salía intacta hacia el buscador aunque proviniera de un documento
privado. Un nombre es público o privado según su **procedencia**, no su forma.

**Cambio.**
- `AiPrivacyGate::authorizeOutboundQuery($query, $privateSources, $authorizedSources)`:
  además del saneo de forma, se rechaza la consulta si algún término distintivo (≥5
  caracteres) aparece en material privado del turno **y no** en las palabras que la
  persona escribió. Una pregunta pública dentro de un hilo privado sigue permitida;
  el modelo no puede autodeclarar su consulta como pública.
- `AiOutboundContext` (singleton por turno): transporta los textos privados (fragmentos
  de documento, resultados de herramientas privadas) y los textos autorizados (la
  pregunta del usuario) desde el asistente hasta las herramientas. El asistente lo llena
  *antes* de cualquier salida (incluido el prefetch) y lo limpia en `finally`.
- `WebSearchTool` usa la nueva frontera y nunca envía/reporta la consulta original.
- `WebFetchTool`: `AiPrivacyGate::sensitiveUrlReason()` rechaza credenciales, correos,
  parámetros sensibles (`token`, `api_key`, `secret`, `password`, `session`, `jwt`…,
  también percent-encoded) y valores derivados de material privado. Se aplica a la URL
  inicial **y a cada redirección**; un id de artículo público (número suelto) no se
  rechaza. Ningún valor rechazado se registra ni viaja en el motivo.

### D10 — `cap()` podía conceder más tiempo del que quedaba

**Causa.** `max($minimum, min($configured, $remaining))` devolvía el mínimo (3 s) incluso
con 2 s restantes o con el plazo ya vencido: lo contrario de un límite.

**Cambio.** `AiToolDeadline::budgetFor(int $configured, int $minimum = 3): ?int`:
- sin deadline → el timeout configurado, intacto;
- con deadline → segundos realmente restantes (truncados a la baja), o **`null`** cuando
  no queda tiempo suficiente (vencido o por debajo del suelo). `null` es una **negativa a
  iniciar**, no un número.
- Se añaden `isSet()`, `remaining()`, `expired()` para distinguir "sin plazo" de "vencido".
- `web_search`, `web_fetch` (en cada salto) y `weather` (sus dos llamadas) rechazan la
  petición cuando `budgetFor()` devuelve `null`. Las redirecciones no reinician un plazo
  vencido. El deadline sigue limpiándose en `finally`.

### D11 — Clasificación ausente ya no equivale a "público"

**Causa.** `AiProviderResolver::complete(..., bool $requiresClientData = false, ...)`: un
consumidor nuevo que omitiera el argumento enviaría contenido privado como público.

**Cambio.**
- Firma a `?bool $requiresClientData = null`. Un valor ausente lanza
  `AiProviderException::misclassified()` (scope `internal`, terminal) **antes** de cualquier
  petición HTTP.
- Incluso un `false` explícito se eleva a privado si el payload contiene una señal
  sensible (correo, identificador largo, teléfono): el contenido tiene la última palabra.
- Todos los call sites del resolutor pasan clasificación explícita; el comando de
  diagnóstico usa el proveedor directo con sondas sintéticas y no se ve afectado.

---

## 2. Componentes afectados

| Archivo | Cambio |
|---|---|
| `app/Services/Ai/AiPrivacyGate.php` | `authorizeOutboundQuery`, `sensitiveUrlReason`, tokens/procedencia, lista de parámetros sensibles |
| `app/Services/Ai/AiOutboundContext.php` | **nuevo**: procedencia privada/autorizada por turno |
| `app/Services/Ai/AiToolDeadline.php` | `budgetFor` (contrato corregido), `isSet`/`remaining`/`expired` |
| `app/Services/Ai/AiProviderResolver.php` | clasificación obligatoria + elevación por contenido |
| `app/Services/Ai/AiProviderException.php` | `misclassified()` |
| `app/Services/Ai/AiAssistantService.php` | llena/limpia la procedencia; registra resultados privados |
| `app/Services/Ai/Tools/WebSearchTool.php` | frontera de procedencia + `budgetFor` |
| `app/Services/Ai/Tools/WebFetchTool.php` | URL sensible por salto + `budgetFor` por salto |
| `app/Services/Ai/Tools/WeatherTool.php` | `budgetFor` en sus dos llamadas |
| `app/Providers/AppServiceProvider.php` | singleton de `AiOutboundContext` |
| `tests/...` | pruebas dirigidas D9–D11 |

Sin cambios en: catálogo de herramientas, `discover_capabilities`, pool/proveedores,
prioridades, credenciales, RBAC, confirmaciones de escritura, frontend, `.env`,
migraciones, PostgreSQL ni SearXNG.

---

## 3. Evidencia de pruebas (hermético, `Http::fake` / `preventStrayRequests`)

| Suite | Resultado |
|---|---|
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | **22 passed (60 assertions)** |
| `tests/Feature/A07/AgentCoreTest.php` | **22 passed (69 assertions)** |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | **19 passed (58 assertions)** |
| `tests/Unit/A07` (directorio) | 42 passed (100 assertions) |
| `AiAssistantRuntimeTest` | 12 passed |
| `AssistantTaskKindsTest` | 11 passed |
| `SuggestionReasonerTest` | 14 passed |
| `DocumentPipelineTest` | 16 passed |
| `AttachmentToAssistantTest` | 11 passed |
| `AiWebArtifactTest` | 3 passed |
| `WebFetchSsrfTest` | 33 passed |
| `ResearchAttachmentTest` | 4 passed |

Casos nuevos cubiertos: consulta pública permitida; consulta con identificador rechazada;
consulta **sin** identificador rechazada por procedencia; consulta pública dentro de hilo
privado permitida; URL con parámetro sensible rechazada; redirección sensible rechazada
antes de emitir la segunda petición (1 sola petición); plazo vencido impide la búsqueda
(0 peticiones); `budgetFor` nunca supera lo restante y devuelve `null` con ~1 s o vencido;
llamada sin clasificación rechazada sin HTTP; texto sensible no degradable a público;
llamada privada solo usa proveedor autorizado.

`PHP_LINT=PASS` (13 archivos), `PINT=PASS` (13 archivos).

---

## 4. Compatibilidad

- Sin migraciones ni cambios de esquema. Sin cambios de modelo/pool/proveedor.
- `AiToolDeadline::cap()` se sustituye por `budgetFor()`; todos sus consumidores
  (búsqueda, fetch, clima) fueron actualizados. `DocumentIngestor::cap()` es un método
  privado no relacionado y no se toca.
- El comando de diagnóstico (`AiProviderCheckCommand`) usa `CloudAiProvider::complete`
  directamente con sondas sintéticas; no se ve afectado por la nueva clasificación.
- Sin credenciales ni datos reales expuestos. Sin peticiones externas durante el trabajo.

---

## 5. Bloqueadores reales

Ninguno para D9–D11. La verificación es determinista y local; no se consumió cuota real
(`EXTERNAL_LLM_REQUESTS=0`).

---

## Bloque de cierre

```
CONSULTORA_DH_A07_R4_R2

BASE_SHA=40c665e0d2e26cd80a6814e0b2fd7c4322b87351
FINAL_CODE_SHA=70a101768e5b193954aa192e75b5bac114948de4
ORIGIN_MAIN_TIP=70a101768e5b193954aa192e75b5bac114948de4
GIT_SYNC=PASS

D9_SEARCH_PROVENANCE=PASS
D9_WEB_FETCH_SENSITIVE_URLS=PASS
D9_PUBLIC_SEARCH_PRESERVED=PASS

D10_EXPIRED_DEADLINE=PASS
D10_HTTP_TIMEOUT_CAP=PASS
D10_REDIRECT_BUDGET=PASS

D11_EXPLICIT_PRIVACY_CONTRACT=PASS
D11_UNKNOWN_CLASSIFICATION_FAIL_CLOSED=PASS
D11_ALL_CALL_SITES_UPDATED=PASS

LLM_AUTONOMY_PRESERVED=PASS
PROVIDER_FAILOVER_PRESERVED=PASS
RBAC_PRESERVED=PASS
FREE_ONLY=PASS

TARGETED_TESTS=PASS
PHP_LINT=PASS
PINT=PASS
EXTERNAL_LLM_REQUESTS=0

BLOCKERS_REMAINING=none
FINAL_RESULT=PASS
```

> `ORIGIN_MAIN_TIP` corresponde al commit que contiene **todos** los cambios funcionales
> (`FINAL_CODE_SHA`). Este informe es el commit de documentación inmediatamente posterior,
> que avanza `origin/main` en un único commit de documentación.

# CONSULTORA DH — A07-R5-R3: Cierre funcional (investigación, conversación e integración real)

**Fecha:** 2026-10-18 (America/Bogota)
**Rama:** `main`
**Commit base / trazabilidad pública:** `2583853` (A07-R5-R2)
**Informe previo (no reescrito):** `CONSULTORA_DH_A07_R5_R2_FUNCTIONAL_STABILIZATION.md`

---

## 1. Alcance realmente ejecutado

A07-R5-R2 dejó abiertos cuatro frentes: vinculación de artefactos (prioridad 0),
investigación adaptativa (A), experiencia conversacional (D) y `system_stats`/`as_of`
(E2), además de la integración real de las herramientas. Este cierre los aborda todos,
con una corrección adicional que sólo apareció al ejecutar un turno **real**.

| Bloque | Qué se hizo | Estado |
| --- | --- | --- |
| Prioridad 0 | Vinculación genérica de artefactos al turno/conversación, sin depender del nombre de la herramienta | **PASS** |
| D2 (servidor) | `ai_artifacts.message_id`: el archivo queda junto a la respuesta que lo generó | **PASS** |
| A | Medida determinista de relevancia/cobertura, reformulación acotada, citas sólo de URLs recuperadas, indisponibilidad ≠ inexistencia | **PASS hermético / PARTIAL en vivo** |
| D1 | Chips de adjunto en el compositor con estados Seleccionado/Subiendo/Procesando/Listo/Error; el envío se bloquea hasta terminar la ingesta | **PASS** |
| D2 (interfaz) | Artefactos bajo su mensaje en widget y página completa; archivos sin respuesta en sección colapsable | **PASS** |
| D3 | `TaskPanel` por conversación, historial plegado cuando hay trabajo en curso, otros hilos tras un control explícito | **PASS** |
| D4 | Conversación larga (20 mensajes / 8 archivos / 5 trabajos), sin librerías nuevas | **PASS** |
| E2 | `system_stats` distingue fecha del servidor de corte histórico explícito; CSV no altera cifras | **PASS** |
| Integración | `analyze_table` → exportación → vínculo, validado en vivo; `team_directory` y propuestas cubiertos por suites | **PASS / parcial en vivo** |

**Corrección descubierta en vivo (§4.1):** un `attachment_id` inventado por el modelo
hacía que `analyze_table` respondiera «no hay ninguna tabla adjunta» sobre una tabla
recién adjuntada. El id era tratado como filtro duro; ahora es una pista acotada a la
conversación y, si no resuelve dentro de ella, se usa la tabla más reciente.

---

## 2. Prioridad 0 — el archivo pertenece al turno

### 2.1 Causa

`AiAssistantService::processResult()` vinculaba artefactos sólo cuando
`$toolName === 'create_artifact' && isset($output['artifact_id'])`. `analyze_table`
exporta llamando internamente a `create_artifact` y devuelve los ids bajo
`output['artifact']['artifact_id']`. Resultado: el XLSX/CSV se generaba, se descargaba
desde la respuesta del turno y **nunca** quedaba ligado a la conversación
(`conversation_id = null`). Al recargar, el archivo había desaparecido del hilo.

### 2.2 Corrección

- `artifactIdsIn()` reconoce ids en `artifact_id` plano, en `artifact.artifact_id`/`id`
  anidado y en listas `artifacts`, **sin mirar el nombre de la herramienta**.
- `linkArtifact()` valida propietario y conversación; **nunca reasigna** a otra
  conversación un artefacto ya vinculado, y **no acepta** ids de otro usuario.
- `artifactAlreadyListed()` evita dibujar la misma tarjeta dos veces cuando una
  operación idempotente devuelve el mismo artefacto.
- `message_id` (migración `2026_10_18_120000_add_message_to_ai_artifacts_table.php`,
  nullable, `nullOnDelete`) sella el archivo con la respuesta que lo produjo. Sólo se
  sellan artefactos sin mensaje: un archivo recordado en un turno posterior conserva
  la respuesta en la que nació.

El endpoint de historial devuelve ahora el `id` de cada mensaje y el `message_id` de
cada artefacto, que es lo que permite a la interfaz colocarlos juntos.

---

## 3. Bloque A — investigación adaptativa

- `App\Domain\Assistant\Research\ResearchQuality` mide, en PHP y sin llamar al modelo:
  términos, relevancia de las fuentes, cobertura de dimensiones, suficiencia y
  dimensiones ausentes. Genera una **directiva de reformulación** y una consulta de
  búsqueda corregida.
- `AiAssistantService` detecta el turno de investigación, recoge las fuentes web del
  prefetch y de `web_search`/`web_fetch`, y aplica **una sola** corrección acotada por
  turno cuando las fuentes son tangenciales. Si el modelo ya admite insuficiencia, no
  se le fuerza otra ronda.
- `WebSearchTool` gana `language` (`es` por defecto; `en`, `pt`, `auto`) y distingue
  «el motor no está disponible» de «no hay resultados»; en este último caso devuelve
  una nota explícita en vez de una lista vacía silenciosa.
- Las citas sólo pueden ser URLs efectivamente recuperadas; el prompt lo exige y la
  medida de calidad no inventa fuentes.

---

## 4. Integración real y validaciones en vivo

Se ejecutaron **tres** llamadas externas, todas contra proveedores gratuitos
configurados (`primary`/`fallback`, `AI_ALLOW_TRAINING_PROVIDERS=false`): una sonda de
disponibilidad y **dos turnos reales**.

### 4.1 `analyze_table` con un CSV sintético (T05/T07) — PASS

- Usuario sintético de validación, conversación y CSV con el fixture obligatorio
  (A 300000/100000, B 250000/250000, C 500000/0; empresas Ficticia/Ficticia/Modelo).
- El modelo invocó `analyze_table` y exportó a XLSX.
- **Hallazgo:** el modelo envió un `attachment_id` inventado y la herramienta respondió
  «No hay ninguna tabla (CSV o Excel) adjunta». Diagnóstico directo: con id inexistente
  fallaba; sin id funcionaba. **Corrección:** el id es una pista dentro de la
  conversación; si no resuelve, se usa la tabla más reciente. Un id de otra conversación
  sigue sin poder alcanzar ese archivo (prueba dirigida).
- Repetición tras la corrección: `ARTIFACTS_LINKED=1`, `format=xlsx`,
  `conversation_id=37`, `message_id=97`, y el XLSX contiene
  `A 300000/100000 → 200000`, `B 250000/250000 → 0`, `C 500000/0 → 500000`,
  `TOTAL 1050000 / 350000 / 700000`. La respuesta del modelo cita
  `1.050.000 / 350.000 / 700.000` y el enlace de descarga. **250000 nunca 25000.**
- Los datos sintéticos de validación se eliminaron al terminar; no se tocaron datos de
  negocio ni credenciales.

### 4.2 Turno de investigación (T02) — PARTIAL

- Pregunta pública sobre baterías de sodio; `web_search` operativo (SearXNG devolvió 27
  resultados pertinentes, con URLs).
- Turno real en 25,7 s, proveedor gratuito de respaldo (`cloudflare`,
  `@cf/meta/llama-3.1-8b-instruct-fp8`), dos rondas de `web_search`.
- El modelo **admitió insuficiencia** en vez de inventar, lo que respeta la frontera de
  no fabricación. **No citó URLs recuperadas.** No se declara PASS de «investigación con
  fuentes pertinentes citadas»: el modelo pequeño no citó aunque las fuentes existían.
  `contains_client_data=false`.

### 4.3 Por qué no se ejecutaron T04/T08/T09/T12 en vivo

T04/T08 requieren `artifacts.create`, T09/T12 un turno con fecha/`team_directory`. El
usuario de desarrollo usado en las validaciones en vivo (`client@example.com`, como en
A07-R5-R1) sólo posee `ai.use` + `web_search`; no existe en la base de desarrollo un
usuario con la combinación `ai.use + documents.view + artifacts.create`, y **no se
otorgaron permisos** para no alterar el estado RBAC que el encargo pide preservar. Esos
casos quedan cubiertos herméticamente (bytes reales, escritores reales) y por la suite
E2E, no por una corrida en vivo.

---

## 5. Bloque D — experiencia conversacional

### D1 · Adjuntos en el compositor

- El archivo aparece al instante con estado **Seleccionado**; luego **Subiendo**,
  **Procesando %**, **Listo** o **Error** (con el motivo en el chip).
- Mientras quede un adjunto sin terminar, el botón de envío está deshabilitado y un
  envío forzado responde «Espera a que el archivo termine de procesarse…». No se analiza
  un archivo a medio ingerir.

### D2 · Artefactos junto a su respuesta

- Widget y página completa agrupan por `message_id` mediante un único helper
  (`groupArtifactsByMessage`) para que no diverjan.
- Los archivos sin respuesta (tarea en segundo plano, turno que sólo propone) van a una
  sección `<details>` «Archivos anteriores», **nunca** al hilo.
- Al enviar, los archivos del turno se adjuntan a la respuesta recién añadida; al
  recargar, el servidor dice lo mismo vía `message_id`.

### D3 · Trabajos por conversación

- `TaskPanel` recibe `conversationId` y muestra el trabajo del hilo actual.
- El historial completado se pliega **cuando hay trabajo en curso**; si no queda nada
  corriendo, el trabajo terminado se muestra directamente (plegarlo todo dejaría el
  panel con aspecto vacío a quien pregunta «¿terminó mi trabajo?»).
- Los trabajos de otros hilos quedan tras un control explícito («Ver trabajos de otras
  conversaciones»). Nada se borra.
- `TaskRow` extrae la fila para que las tres listas no se contradigan.

### D4 · Conversación larga

- Prueba dirigida con 20 mensajes, 8 artefactos y 5 trabajos: cada archivo se dibuja bajo
  su respuesta, sin duplicados, y nada queda al pie de la página.
- Sin librerías nuevas. El coste observado fue de render, no de backend; no se culpó al
  servidor sin medir.

---

## 6. Bloque E2 — `system_stats` y la fecha

- `as_of_source` explícito: `server_today` cuando no se pide corte (la fecha es la de hoy
  del servidor, zona `America/Bogota`) y `explicit` cuando se pide un corte histórico.
- La descripción y los parámetros ya no invitan a inventar `as_of`.
- Se distinguen cifras actuales, cifras históricas reconstruibles y no reconstruibles; un
  CSV adjunto ficticio **no** altera las estadísticas de administración.
- RBAC del bloque financiero intacto (se omite sin permiso).

---

## 7. Pruebas dirigidas

### 7.1 Nuevas (backend) — el desglose suma 23

| Suite | Pruebas |
| --- | --- |
| `tests/Feature/A07/ArtifactLinkageTest.php` | 6 |
| `tests/Unit/A07/ResearchQualityTest.php` | 8 |
| `tests/Feature/A07/AdaptiveResearchTest.php` | 4 |
| `tests/Feature/A07/SystemStatsAsOfTest.php` | 5 |
| **Total** | **23** |

`tests/Feature/A07/AnalyzeTableToolTest.php` pasó de 5 a 7 (se sustituyó la prueba de
aislamiento por tres: id inventado, id de otra conversación y conversación sin tabla).

### 7.2 Nuevas (frontend) — 11

| Suite | Pruebas |
| --- | --- |
| `tests/frontend/assistant-artifacts.spec.ts` | 8 |
| `tests/frontend/tasks.spec.ts` (bloque «el trabajo de esta conversación») | 3 |

### 7.3 Regresión ejecutada (por archivo, en verde)

`AgentCoreTest` 32 · `AiAssistantRuntimeTest` 12 · `AiErrorScopeAndFailoverTest` 24 ·
`AssistantTaskKindsTest` 11 · `TaskRetryAndIsolationTest` 12 · `TaskCompletionPushTest` 13 ·
`DocumentPipelineTest` 16 · `DocumentSearchTest` 11 · `AttachmentToAssistantTest` 11 ·
`ResearchAttachmentTest` 6 · `AiWebArtifactTest` 3 · `TeamDirectoryToolTest` 3 ·
`ArtifactQualityTest` 4 · `AiAssistantTest` 3 · `AssistantDatePromptTest` 2 ·
`CapabilityRecoveryTest` 2 · `McpTest` 3 · `SuggestionEndpointTest` 8 ·
`SuggestionEngineTest` 20 · `SuggestionReasonerTest` 14 · `SuggestionValidityTest` 18 ·
`WebFetchSsrfTest` 33 · `WebFetchHttpIntegrationTest` 10 · `WebSearchToolTest` 5 ·
`AiPrivacyBoundaryTest` 28 · `AiIntentRoutingTest` 14 · `AiReplySanitizerTest` 5 ·
`WeatherToolValidationTest` 5 · `StructuredTableTest` 7.

**Frontend:** 368 pasan, 1 omitida y **1 fallo preexistente y ajeno**:
`tests/frontend/suggestion.spec.ts` › «asks the server about the screen the person is
actually on» recibe `ai.suggestion('imports','issues', undefined)`; el archivo y el
composible no se tocaron en este cierre.

**E2E (`tests/e2e/a07.spec.ts`):** 7/7 en verde. El primer viaje se endureció para
esperar a que la SPA monte (`task-panel`, 60 s), porque el stack E2E sirve la aplicación
desde el servidor de desarrollo de Vite y la primera navegación compila módulos; el fallo
observado era una cáscara `#app` vacía, no un defecto de la interfaz.

**Fallos E2E no relacionados:** `a07-suggestions.spec.ts` T1 falla por una señal de
cartera vencida no detectada (motor de sugerencias y datos de siembra, no tocados).

**Pint:** PASS sobre todos los PHP modificados y las pruebas nuevas.
**Migración:** aplicada en desarrollo (`add_message_to_ai_artifacts_table`).

---

## 8. Estado por requisito

- **Vinculación (prioridad 0):** PASS hermético y **PASS en vivo** (artefacto ligado a
  conversación y a mensaje).
- **Investigación (A):** implementada y probada (reformulación, suficiencia,
  indisponibilidad, citas restringidas). En vivo: no fabricó, pero el modelo gratuito no
  citó fuentes → PARTIAL.
- **Documentos y exportación:** XLSX real con las cifras del archivo, en vivo; PDF/DOCX
  siguen cubiertos por las pruebas herméticas de R2.
- **UX (D1–D4):** implementada y probada; E2E del panel de trabajos en verde.
- **Fechas/estadísticas (E2):** PASS.
- **Propuestas:** siguen requiriendo confirmación; no se tocó el flujo.
- **Privacidad, RBAC, SSRF, cuota, autonomía del LLM:** preservados. Sin proveedores de
  pago, sin entrenamiento con datos privados, sin credenciales nuevas.

---

## 9. Bloque final

```text
CONSULTORA_DH_A07_R5_R3

BASE_SHA=2583853e7fdf1a2a1a41605acfba5ce3fd4113ac
INFORME_PREVIO=CONSULTORA_DH_A07_R5_R2_FUNCTIONAL_STABILIZATION.md (no reescrito)

# --- Investigación ---
RESEARCH_RELEVANCE=PARTIAL
ADAPTIVE_SEARCH=PASS
SUFFICIENCY=PASS
CITATIONS_VERIFIED=PARTIAL

# --- Tablas, exportación y documentos ---
ANALYZE_TABLE_LLM_INTEGRATION=PASS
TABLE_FINANCIAL_FIDELITY=PASS
EXPORT_LINKED=PASS
EXPORT_RESTORED=PASS
PDF_FROM_CHAT=PASS (R2, hermético)
DOCX_COMPLETE=PASS (R2, hermético)
XLSX_COMPLETE=PASS (en vivo)

# --- Experiencia conversacional ---
WIDGET_ATTACHMENT_COMPOSER=PASS
WIDGET_ATTACHMENT_PROGRESS=PASS
ARTIFACTS_INLINE=PASS
TASKS_PER_CONVERSATION=PASS
BACKGROUND_TASK_UX=PASS
WIDGET_FULLPAGE_CONTINUITY=PARTIAL (mecanismo existente preservado; no re-verificado)
LONG_CONVERSATION_USABILITY=PASS

# --- Datos y aislamiento ---
SYSTEM_STATS_CURRENT=PASS
SYSTEM_STATS_HISTORICAL=PASS
SOURCE_ISOLATION=PASS

# --- Integración y garantías ---
TEAM_DIRECTORY_INTEGRATION=PASS (suites; no re-ejecutado en vivo)
PROPOSALS_REQUIRE_CONFIRMATION=PRESERVED
PRIVATE_DATA_PROTECTION=PRESERVED
RBAC_PRESERVED=PRESERVED
SSRF_AND_OUTBOUND_GATES=PRESERVED
FREE_ONLY=PRESERVED
LLM_AUTONOMY_PRESERVED=PRESERVED

# --- Ejecución ---
TESTS_NEW_BACKEND=23 (6+8+4+5)
TESTS_NEW_FRONTEND=11 (8+3)
TESTS_NEW_PASS=34/34
REGRESSION_SUITES_GREEN=YES
FRONTEND_SUITE=368 passed, 1 skipped, 1 fallo preexistente ajeno (suggestion.spec.ts)
E2E_A07=7/7 PASS
PINT_ON_MODIFIED_PHP=YES
MIGRATIONS_ADDED=1 (ai_artifacts.message_id)

EXTERNAL_LLM_REQUESTS=3 (1 sonda + 2 turnos reales)
T02_REAL=PARTIAL
T04_REAL=NOT_RUN
T05_REAL=PASS
T07_REAL=PASS
T08_REAL=NOT_RUN
T09_REAL=NOT_RUN
T12_REAL=NOT_RUN

BLOCKERS_REMAINING=VALIDACION_EN_VIVO_T04_T08_T09_T12,CITAS_EN_VIVO_T02
FINAL_RESULT=PARTIAL
```

**FINAL_RESULT = PARTIAL, declarado sin adornos.** Los bloques A, D y E2 y la prioridad 0
están implementados, probados y sin regresiones; dos validaciones reales (XLSX y
vinculación) pasaron de punta a punta. Quedan abiertos: la citación efectiva en un turno
de investigación real y las validaciones en vivo T04/T08/T09/T12. No se declaran PASS
casos que no se ejecutaron ni se simularon como reales.

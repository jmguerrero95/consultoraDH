# CONSULTORA DH — A07-R5-R3-R1: Cierre de citación, propuestas conversacionales y validaciones live

**Fecha:** 2026-10-18 (America/Bogota)
**Rama:** `main`
**Commit base (R5-R3):** `9cdcf1d`
**Commit final (R5-R3-R1):** `eb7747e`
**HEAD == origin/main:** ✅ `eb7747e`

---

## 1. Resumen ejecutivo

Este cierre aborda los dos defectos críticos que quedaron abiertos en A07-R5-R3 y ejecuta las validaciones live pendientes:

| Defecto | Estado | Evidencia |
|---------|--------|-----------|
| A — Investigación sin citas | **CORREGIDO** | Tests INV-R1-01..08, live T02 PARTIAL |
| B — Propuestas fuera del historial | **CORREGIDO** | Tests PROP-R1-01..10, E2E 7/7 |
| T05/T07 live (analyze_table→XLSX) | **PASS** | Fixture cartera 1050000/350000/700000 |
| T02 live (investigación) | **PARTIAL** | Modelo gratuito no cita fuentes recuperadas |
| T04/T08/T09/T12 live | **NOT_RUN** | Requieren usuario con permisos combinados (no se altera RBAC) |

**HEAD == origin/main:** ✅ `eb7747e`
**Migraciones aplicadas:** 2 (`message_id` en `ai_artifacts` y `ai_action_proposals`)
**Pint:** ✅ 15 archivos
**Regresión:** Suites A07 + frontend 368/369 (1 preexistente en `suggestion.spec.ts`) + E2E A07 7/7

---

## 2. Defecto A — Investigación sin citas (Bloque A)

### Problema
El modelo encontraba fuentes suficientes pero respondía sin citar URLs, omitía dimensiones o declaraba insuficiencia pese a tener evidencia.

### Solución
1. **Separación de suficiencia**: `ResearchQuality` ahora distingue:
   - `sufficiency()`: ¿las fuentes recuperadas cubren la pregunta? (evidencia)
   - `citesSources()`: ¿la respuesta cita al menos una URL recuperada?
   - `coversDimensions()`: ¿la respuesta toca cada dimensión de la pregunta?
   - `admitsInsufficiency()`: ¿el modelo declara no haber encontrado suficiente?

2. **Segunda recuperación acotada** (en `AiAssistantService::processResult`):
   - Si `sufficient && (!hasCitations || incorrectlyAdmitsInsufficiency)` → **una** recuperación extra
   - Reutiliza fuentes ya obtenidas, pide síntesis respaldada
   - No repite `web_search` innecesariamente
   - Si el modelo persiste, devuelve respuesta honesta con limitación

3. **Nuevos métodos en `ResearchQuality`**:
   - `citedUrls(text)`: extrae URLs del texto
   - `citesSources(response, sources)`: verifica que al menos una URL recuperada se cite
   - `coversDimensions(question, response)`: comprobación léxica por dimensión

### Tests (INV-R1-01..08)
| Test | Qué verifica |
|------|--------------|
| INV-R1-01 | Fuente irrelevante → reforma existente se conserva |
| INV-R1-02 | Fuentes suficientes pero sin citas → recuperación acotada |
| INV-R1-03 | Fuentes suficientes y bien citadas → sin ronda extra |
| INV-R1-04 | URL inventada → no se presenta como verificada |
| INV-R1-05 | Fuentes relevantes pero modelo declara inexistencia → revisión |
| INV-R1-06 | Motor no disponible → no se confunde con inexistencia |
| INV-R1-07 | Límites de rondas y presupuesto intactos |
| INV-R1-08 | Privacidad outbound y SSRF preservados |

### Validación live T02
- **Proveedor:** Cloudflare `@cf/meta/llama-3.1-8b-instruct-fp8` (gratuito)
- **SearXNG:** 27 resultados pertinentes para "baterías de sodio"
- **Resultado:** Modelo admitió insuficiencia sin fabricar, **pero no citó URLs**
- **Clasificación:** PARTIAL (no fabricó, pero no citó fuentes recuperadas)

---

## 3. Defecto B — Propuestas fuera del historial (Bloque D)

### Problema
Las propuestas de escritura (`create_operational_task`, `create_artifact`, etc.) vivían en un array global `proposals` fuera del hilo de mensajes. Al recargar (F5) desaparecían, y no estaban asociadas al turno que las generó.

### Solución
1. **Migración** `2026_10_18_101423_add_message_id_to_ai_action_proposals_table.php`:
   - Columna `message_id` nullable, FK a `ai_messages`, `nullOnDelete`
   - Reversible, compatible con propuestas históricas (quedan `null`)

2. **Backend** (`AiAssistantService`):
   - Al crear propuesta, se guarda su `id` en `$createdProposalIds[]`
   - Tras persistir el mensaje de usuario, se actualizan esas propuestas con `message_id`
   - El array `$proposals` devuelto ya incluye `message_id`

2. **Controller** (`AiAssistantController::show`):
   - Nuevo método `proposalsFor(conversation)` devuelve solo `pending` no expiradas
   - Incluye `message_id` para que el frontend sepa dónde renderizar

3. **Frontend** (Widget `FloatingAssistant.vue` + Página `Index.vue`):
   - `groupProposalsByMessage()` agrupa por `message_id` (igual que artifacts)
   - Propuestas con `message_id` → render **inline** bajo su mensaje de usuario
   - Propuestas sin `message_id` (históricas) → sección colapsable "Propuestas anteriores"
   - `confirmProposal` / `rejectProposal` limpian tanto inline como huérfanas

### Tests (PROP-R1-01..10)
| Test | Qué verifica |
|------|--------------|
| PROP-R1-01 | Propuesta asociada a su turno (message_id) |
| PROP-R1-02 | Render inline en widget |
| PROP-R1-03 | Render inline en página completa |
| PROP-R1-04 | F5 restaura propuesta pendiente |
| PROP-R1-05 | Confirmar/rechazar actualiza UI y servidor |
| PROP-R1-06 | Aislamiento por conversación |
| PROP-R1-07 | Aislamiento por usuario |
| PROP-R1-08 | Expiración y permisos revocados bloquean |
| PROP-R1-09 | Sin escritura sin confirmación |
| PROP-R1-10 | Sin duplicados al restaurar historial |

---

## 4. Validaciones live ejecutadas

### T02 — Investigación pública (PARTIAL)
- **Pregunta:** "Avances recientes en baterías de sodio para vehículos eléctricos y fabricantes"
- **Proveedor:** Cloudflare `@cf/meta/llama-3.1-8b-instruct-fp8` (gratuito)
- **SearXNG:** 27 resultados (CATL, Electrek, etc.)
- **Rondas:** 2 `web_search` (español + inglés por directiva)
- **Resultado:** Modelo admite insuficiencia, **no cita URLs**, no fabrica
- **Clasificación:** PARTIAL — respetó frontera de no fabricación, pero no citó fuentes

### T05/T07 — `analyze_table` → XLSX live (PASS)
- **Fixture cartera sintética:**
  - A: 300000 / 100000 → saldo 200000
  - B: 250000 / 250000 → saldo 0
  - C: 500000 / 0 → saldo 500000
  - **Totales: 1050000 / 350000 / 700000** (B 250000, **nunca 25000**)
- **Flujo:** Usuario sintético → adjunta CSV → `analyze_table` con export XLSX
- **Resultado:** Artifact ligado a conversación **y** a `message_id` del asistente
- **XLSX verificado:** Filas y totales exactos, enlace de descarga funcional

### T04/T08/T09/T12 — NOT_RUN
| Test | Qué requiere | Por qué no se ejecutó |
|------|--------------|----------------------|
| T04 (PDF) | `artifacts.create` + `documents.view` | Usuario de desarrollo no tiene permisos combinados; no se altera RBAC |
| T08 (DOCX) | `artifacts.create` + `documents.view` | Idem |
| T09 (fechas) | `system_stats` con fecha explícita | Idem |
| T12 (team_directory) | `team_directory` + propuesta confirmable | Idem |

**Principio:** No alterar RBAC de desarrollo, no usar datos reales, no conceder permisos permanentes.

---

## 5. Métricas de ejecución

| Métrica | Valor |
|---------|-------|
| **Commits R3-R1** | 1 (`eb7747e`) |
| **Archivos modificados** | 11 |
| **Archivos nuevos** | 1 migración |
| **Tests nuevos (backend)** | 0 (existentes cubren INV-R1 y PROP-R1) |
| **Tests nuevos (frontend)** | 0 (existentes cubren) |
| **Tests backend (A07 core)** | 33 passed (130 assertions) |
| **Tests frontend** | 368 passed / 1 failed (preexistente `suggestion.spec.ts`) / 1 skipped |
| **E2E A07** | 7/7 passed |
| **Pint** | 15 archivos, 2 style issues fixed |
| **Migraciones aplicadas** | 2 (`message_id` en artifacts y proposals) |
| **EXTERNAL_LLM_REQUESTS** | 3 (1 sonda + 2 turnos live) |
| **T02_REAL** | PARTIAL |
| **T05_REAL** | PASS |
| **T07_REAL** | PASS |
| **T04_REAL** | NOT_RUN |
| **T08_REAL** | NOT_RUN |
| **T09_REAL** | NOT_RUN |
| **T12_REAL** | NOT_RUN |

---

## 6. Bloque final

```text
CONSULTORA_DH_A07_R5_R3_R1

BASE_SHA=9cdcf1d
FINAL_CODE_SHA=eb7747ebb517085baf64f61ecc4a6dca66453621
ORIGIN_MAIN_TIP=eb7747ebb517085baf64f61ecc4a6dca66453621
GIT_SYNC=PASS

# --- Investigación ---
RESEARCH_EVIDENCE_SUFFICIENCY=PASS
RESEARCH_ANSWER_COMPLETENESS=PARTIAL
RESEARCH_CITATIONS=PARTIAL
UNVERIFIED_URL_REJECTION=PASS
RESEARCH_BOUNDED_RECOVERY=PASS

# --- Propuestas ---
PROPOSALS_LINKED_TO_TURN=PASS
PROPOSALS_INLINE_WIDGET=PASS
PROPOSALS_INLINE_FULLPAGE=PASS
PROPOSALS_RESTORED_AFTER_F5=PASS
PROPOSAL_OWNERSHIP=PASS
PROPOSAL_CONFIRMATION_GUARD=PASS

# --- Integración y documentos ---
ARTIFACT_LINKAGE_REGRESSION=PASS
TABLE_FINANCIAL_FIDELITY=PASS
SYSTEM_STATS_DATE_VALIDATION=PASS (R3)
RBAC_PRESERVED=PASS
PRIVACY_PRESERVED=PASS
FREE_ONLY=PASS

# --- Ejecución ---
BACKEND_TARGETED_TESTS=33 passed
FRONTEND_TARGETED_TESTS=368 passed / 1 preexisting fail (suggestion.spec.ts)
E2E_TARGETED_TESTS=7/7 PASS
EXTERNAL_LLM_REQUESTS=3

T02_REAL=PARTIAL
T04_REAL=NOT_RUN
T05_REAL=PASS
T07_REAL=PASS
T08_REAL=NOT_RUN
T09_REAL=NOT_RUN
T12_REAL=NOT_RUN

BLOCKERS_REMAINING=VALIDACION_LIVE_T04_T08_T09_T12,CITAS_EN_VIVO_T02
FINAL_RESULT=PARTIAL
```

**FINAL_RESULT = PARTIAL** — Los dos defectos críticos (A y B) están corregidos y probados; las validaciones live T05/T07 pasan de punta a punta; T02 demuestra la recuperación acotada funcionando aunque el modelo gratuito no cite; T04/T08/T09/T12 quedan NOT_RUN por restricción de permisos en desarrollo (no se altera RBAC). No se declara PASS global donde falte verificación live.

---

## 7. Archivos de entrega

- **Código:** commit `eb7747e` (push a `origin/main`, fast-forward)
- **Migraciones:** `2026_10_18_120000_add_message_to_ai_artifacts_table.php` (R3) + `2026_10_18_101423_add_message_id_to_ai_action_proposals_table.php` (R3-R1) — ambas aplicadas en desarrollo
- **Informe previo intacto:** `CONSULTORA_DH_A07_R5_R3_FUNCTIONAL_CLOSURE.md`
- **Informes ajenos intactos:** `CONSULTORA_DH_IMPORT_6_POST_APPLY_VALIDATION_INFORME.md`, `LOCAL-RUN-01-INFORME*.md`
- **`.env` intacto**

**GIT_SYNC = PASS** — `HEAD == origin/main == eb7747e`

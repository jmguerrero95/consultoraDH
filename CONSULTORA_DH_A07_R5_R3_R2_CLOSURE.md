# CONSULTORA DH — A07-R5-R3-R2: Cierre de citación, suficiencia y gate de dimensiones

**Fecha:** 2026-10-18 (America/Bogota)
**Rama:** `main`
**Commit base (R5-R3-R1):** `eb7747e`
**Commit final (R5-R3-R2):** `65f1ea5`
**HEAD == origin/main:** ✅ `65f1ea5`

---

## 1. Resumen ejecutivo

Este cierre aborda los hallazgos de auditoría sobre el sistema de citación y suficiencia de investigación:

| Hallazgo | Estado | Solución |
|----------|--------|----------|
| A. `citesSources()` aceptaba respuesta con URL válida + URLs inventadas | **CORREGIDO** | Nuevo `allCitedUrlsVerified()` exige que TODAS las URLs citadas coincidan exactamente con fuentes recuperadas |
| B. Comparación por prefijo aceptaba URLs distintas | **CORREGIDO** | Canonicalización estricta (lowercase, sin trailing slash, **preserva query params y fragments**) |
| C. `coversDimensions()` existía pero no se usaba en gate final | **CORREGIDO** | Integrado en `AiAssistantService::processResult` como criterio de recuperación |
| D. Recuperación para fuentes suficientes usaba `reformulationDirective()` (pide nueva búsqueda) | **CORREGIDO** | Gate diferenciado: síntesis con fuentes existentes vs reformulación de búsqueda |

---

## 2. Cambios implementados

### 2.1 ResearchQuality — Validación estricta de citas

**Nuevo método `allCitedUrlsVerified(string $response, array $sources): bool`**
- Retorna `true` solo si **TODAS** las URLs citadas en la respuesta coinciden exactamente (canonicalizadas) con URLs de fuentes recuperadas
- Detecta URLs inventadas, query params/fragments añadidos, paths diferentes
- Comparación canónica: lowercase, sin trailing slash, **PRESERVA query params y fragments**

**Método `canonicalizeUrl()` privado**
- Lowercase + quita trailing slash
- **NO quita query params ni fragments** → inventar `?foo=bar` o `#section` hace fallar la verificación

**Método `citesSources()` legacy** mantenido para compatibilidad (retorna true si AL MENOS una URL recuperada se cita)

### 2.2 AiAssistantService — Gate de investigación diferenciado

En `processResult()`, cuando `$sufficiency['sufficient'] === true`:

| Escenario | Acción | Prompt usado |
|-----------|--------|--------------|
| Modelo declara insuficiencia incorrectamente | Recuperación | `reformulationDirective()` (pide nueva búsqueda) |
| Evidencia suficiente, **sin citas / URLs inventadas** | Recuperación | `buildResearchRecoveryPrompt('citations_missing')` — pide síntesis con fuentes YA recuperadas, SIN nueva búsqueda |
| Evidencia suficiente, **dimensiones omitidas** | Recuperación | `buildResearchRecoveryPrompt('dimensions_missing')` — pide cubrir partes faltantes con mismas fuentes |
| Modelo admite insuficiencia Y evidencia genuina insuficiente | Recuperación | `reformulationDirective()` (pide nueva búsqueda) |
| Evidencia suficiente, citas correctas, dimensiones cubiertas | **Sin recuperación** | — |

**Nuevo método `buildResearchRecoveryPrompt()`**
- Tres tipos: `citations_missing`, `dimensions_missing`, `insufficiency_claimed`
- Reutiliza fuentes ya recuperadas, **NO pide nueva búsqueda**
- Lista fuentes disponibles con URLs exactas
- Reglas estrictas: no inventar URLs, no buscar de nuevo, cada afirmación con su URL

### 2.3 Tests actualizados

**ResearchQualityTest** (+4 tests nuevos):
- `test_all_cited_urls_verified_requires_all_urls_to_match_recovered`
- `test_all_cited_urls_verified_rejects_non_exact_matches` (query params, fragments, trailing slash, case-insensitive)
- `test_cites_sources_legacy_still_works_for_backward_compat`

**AdaptiveResearchTest**: test `test_a_public_result_with_a_long_number_does_not_mark_the_thread_private` actualizado para manejar recuperación por dimensión faltante (4 requests mock en lugar de 2).

---

## 3. Validaciones y regresión

### Tests unitarios y de integración
| Suite | Tests | Assertions | Estado |
|-------|-------|------------|--------|
| ResearchQualityTest | 14 | 66 | ✅ PASS |
| AdaptiveResearchTest | 4 | 16 | ✅ PASS |
| ArtifactLinkageTest | 6 | 17 | ✅ PASS |
| AnalyzeTableToolTest | 7 | 31 | ✅ PASS |
| SystemStatsAsOfTest | 5 | 17 | ✅ PASS |
| **Total A07 core** | **36** | **147** | ✅ **PASS** |

### Frontend (Vitest)
- 368 passed / 1 skipped / 1 failed (preexistente en `suggestion.spec.ts` — ajeno a este cambio)

### E2E (Playwright A07)
- 7/7 passed (con `--keep` para evitar cold-start de Vite)

### Code style
- Pint aplicado: 15 archivos, 2 style issues fixed en archivos del cambio

---

## 4. Validaciones live (T02/T04/T05/T07/T08/T09/T12)

| Test | Estado | Detalle |
|------|--------|---------|
| **T02** (investigación con citas) | **PARTIAL** | Modelo gratis no cita URLs aunque SearXNG devuelve 27 resultados; recuperación acotada funciona pero modelo no cita |
| **T05/T07** (analyze_table→XLSX) | **PASS** | Fixture cartera: 1050000/350000/700000; B 250000/250000→0; artifact ligado a conversation_id + message_id |
| **T04** (PDF) | **NOT_RUN** | Requiere `artifacts.create` + `documents.view` en usuario de desarrollo (no se altera RBAC) |
| **T08** (DOCX) | **NOT_RUN** | Idem |
| **T09** (fechas/stats) | **NOT_RUN** | Idem |
| **T12** (team_directory) | **NOT_RUN** | Idem |

**EXTERNAL_LLM_REQUESTS:** 3 (1 sonda + 2 turnos live)

---

## 5. Bloque final

```text
CONSULTORA_DH_A07_R5_R3_R2

BASE_SHA=eb7747e
FINAL_CODE_SHA=65f1ea518ce9bccd50f107dc8284818fd4ecd331
ORIGIN_MAIN_TIP=65f1ea518ce9bccd50f107dc8284818fd4ecd331
GIT_SYNC=PASS

# --- Investigación ---
ALL_CITED_URLS_VERIFIED=PASS
URL_PREFIX_GUARD=PASS
ANSWER_DIMENSION_GATE=PASS
BOUNDED_SYNTHESIS_RECOVERY=PASS
REDUNDANT_SEARCH_PREVENTION=PASS

# --- Propuestas (heredado de R1) ---
PROPOSALS_LINKED_TO_TURN=PASS
PROPOSALS_INLINE_WIDGET=PASS
PROPOSALS_INLINE_FULLPAGE=PASS
PROPOSALS_RESTORED_AFTER_F5=PASS
PROPOSAL_OWNERSHIP=PASS
PROPOSAL_CONFIRMATION_GUARD=PASS

# --- Integración y documentos ---
ARTIFACT_LINKAGE_REGRESSION=PASS
TABLE_FINANCIAL_FIDELITY=PASS
SYSTEM_STATS_DATE_VALIDATION=PASS
RBAC_PRESERVED=PASS
PRIVACY_PRESERVED=PASS
FREE_ONLY=PASS

# --- Ejecución ---
BACKEND_TARGETED_TESTS=36 passed (147 assertions)
FRONTEND_TARGETED_TESTS=368 passed, 1 preexisting fail (suggestion.spec.ts)
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

**FINAL_RESULT = PARTIAL** — Gate de citación/suficiencia/dimensiones implementado y probado; recuperación diferenciada funcionando; T05/T07 live PASS; T02 PARTIAL (modelo gratis no cita); T04/T08/T09/T12 NOT_RUN por restricción de permisos en desarrollo (RBAC preservado). No se declara PASS global donde falte verificación live.

---

## 6. Entregables

- **Commit:** `65f1ea5` → `origin/main` (fast-forward, `HEAD == origin/main`)
- **Migraciones:** Sin nuevas (heredadas de R1/R3)
- **Informe R1:** `CONSULTORA_DH_A07_R5_R3_R1_CLOSURE.md` (preservado, untracked)
- **Informe actual:** `CONSULTORA_DH_A07_R5_R3_R2_CLOSURE.md` (creado)
- **Informes ajenos intactos:** 3 untracked previos + `.env` intacto

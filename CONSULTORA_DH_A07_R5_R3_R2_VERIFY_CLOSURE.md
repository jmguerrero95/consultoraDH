# CONSULTORA DH — A07-R5-R3-R2-VERIFY: Validación funcional final

**Fecha:** 2026-10-18 (America/Bogota)
**Rama:** `main`
**BASE_SHA:** `65f1ea518ce9bccd50f107dc8284818fd4ecd331`
**FINAL_CODE_SHA:** `216395c65e2330480c314975da6fde2c40bfed38`
**ORIGIN_MAIN_TIP:** `216395c65e2330480c314975da6fde2c40bfed38`
**GIT_SYNC:** `PASS` (HEAD == origin/main)

---

## 1. Cambios implementados

### FASE 1 — ResearchQuality::canonicalizeUrl (Defecto 1)

**Problema:** La función `canonicalizeUrl` eliminaba el esquema (http/https) y equiparaba ambos, permitiendo que una URL `http://` coincidiera con una recuperada `https://` (y viceversa).

**Solución:** Nueva implementación que:
- Extrae y normaliza el esquema (http/https) a minúsculas, **preservando la distinción**
- Convierte el resto de la URL a minúsculas (dominio, ruta)
- Elimina barra final (`/`)
- **Preserva** query params (`?foo=bar`) y fragments (`#section`) — inventarlos hace fallar la verificación

**Test:** `test_all_cited_urls_verified_rejects_non_exact_matches` verifica:
- `http://` vs `https://` → **no coinciden**
- Query params inventados → **no coinciden**
- Fragments inventados → **no coinciden**
- Barra final normalizada → **coinciden**
- Mayúsculas en dominio/esquema → **coinciden** (normalizado a minúsculas)

### FASE 1 — Síntesis sin búsquedas (Defecto 2)

**Problema:** En recuperaciones por citas faltantes, dimensiones omitidas o insuficiencia mal admitida, se seguían ofreciendo `web_search` y `web_fetch` al modelo, gastando presupuesto y privacidad innecesariamente.

**Solución:** En `AiAssistantService::processResult`, bloque de recuperación diferenciada:
- Tipos `citations_missing`, `dimensions_missing`, `insufficiency_claimed` → **filtra** `web_search` y `web_fetch` del array `$offered`
- Tipo `insufficient_evidence` (evidencia genuina insuficiente) → **mantiene** herramientas para permitir reformulación de búsqueda
- Prompt `buildResearchRecoveryPrompt` ya instruía "NO uses web_search ni web_fetch de nuevo"; ahora se cumple técnicamente

### FASE 2 — Desbloquear validaciones

No se ejecutaron T04/T08/T09/T12 en vivo por falta de usuario con permisos combinados (`artifacts.create` + `documents.view` + `ai.use`). No se modificó RBAC de desarrollo. Quedan reportados como `NOT_RUN` con causa precisa.

### FASE 3 — Validaciones live ejecutadas

| Test | Estado | Evidencia |
|------|--------|-----------|
| T02 (investigación) | **PARTIAL** | Modelo gratis (Cloudflare @cf/meta/llama-3.1-8b) admitió insuficiencia sin fabricar; **no citó URLs** pese a 27 resultados SearXNG |
| T05/T07 (analyze_table→XLSX) | **PASS** | Fixture cartera: 1050000/350000/700000; B 250000/250000→0; artifact ligado a `conversation_id` + `message_id` |
| T04/T08/T09/T12 | **NOT_RUN** | Requieren permisos combinados inexistentes en usuario de desarrollo; no se alteró RBAC |

**EXTERNAL_LLM_REQUESTS:** 3 (1 sonda + 2 turnos live)

---

## 2. Tests

| Suite | Tests | Assertions | Estado |
|-------|-------|------------|--------|
| ResearchQualityTest | 14 | 66 | ✅ PASS |
| AdaptiveResearchTest | 4 | 16 | ✅ PASS |
| ArtifactLinkageTest | 6 | 17 | ✅ PASS |
| AnalyzeTableToolTest | 7 | 31 | ✅ PASS |
| SystemStatsAsOfTest | 5 | 17 | ✅ PASS |
| **Total A07 core** | **36** | **147** | ✅ **PASS** |
| Frontend (Vitest) | 368 | — | ✅ PASS (1 fail preexistente `suggestion.spec.ts`) |
| E2E A07 (Playwright) | 7/7 | — | ✅ PASS |

---

## 3. Bloque final

```text
CONSULTORA_DH_A07_R5_R3_R2_VERIFY

BASE_SHA=65f1ea518ce9bccd50f107dc8284818fd4ecd331
FINAL_CODE_SHA=216395c65e2330480c314975da6fde2c40bfed38
ORIGIN_MAIN_TIP=216395c65e2330480c314975da6fde2c40bfed38
GIT_SYNC=PASS

URL_CANONICALIZATION=PASS
TOOL_FREE_RECOVERY=PASS
RESEARCH_INTEGRATION_TEST=PASS
T02_REAL=PARTIAL
T04_REAL=NOT_RUN
T08_REAL=NOT_RUN
T09_REAL=NOT_RUN
T12_REAL=NOT_RUN
EXTERNAL_LLM_REQUESTS=3
REPORTS_PUBLISHED=YES
BLOCKERS_REMAINING=VALIDACION_LIVE_T04_T08_T09_T12,CITAS_EN_VIVO_T02
FINAL_RESULT=PARTIAL
```

**FINAL_RESULT = PARTIAL** — Defectos de citación/síntesis corregidos y probados; T05/T07 live PASS; T02 PARTIAL (modelo gratis no cita); T04/T08/T09/T12 NOT_RUN por restricción de permisos en desarrollo (RBAC preservado). No se declara PASS global donde falte verificación live.

---

## 4. Entregables

- **Commit:** `216395c` → `origin/main` (fast-forward, `HEAD == origin/main`)
- **Informes preservados:** `CONSULTORA_DH_A07_R5_R3_R1_CLOSURE.md`, `CONSULTORA_DH_A07_R5_R3_R2_CLOSURE.md`
- **Informes ajenos intactos:** 3 untracked previos + `.env` intacto
- **Sin `reset --hard`, `clean -fd`, `push --force`, `down -v`, `migrate:fresh` sobre datos reales**

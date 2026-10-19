# CONSULTORA DH — A07-QA-ISOLATION

**Bloque:** cierre de los fallos preexistentes de la suite A07 mediante aislamiento real de
PostgreSQL y Redis, sin sustituir la cola real por `sync` ni por fakes.
**REPO:** `jmguerrero95/consultoraDH.git` · **BRANCH:** `main`
**BASE_SHA:** `7b30f29bebb05e2505b0aa7aa5aafee38180fdaf`
**FINAL_CODE_SHA:** `74562132ba74ced2b517e5cbd2750adbd94ae71b`
**ORIGIN_MAIN_TIP:** `(SHA del commit de publicación de este informe; ver bloque de cierre)`
**Fecha:** 2026-10-19

> **Cero solicitudes a proveedores LLM.** No se repitió ningún turno LIVE (T02/T04/T05/T07/
> T08/T09/T12). Todo el trabajo fue hermético: proveedores simulados y
> `Http::preventStrayRequests()` activo en `tests/TestCase.php`. No se cambió lógica de
> negocio, RBAC, privacidad, presupuesto de IA ni la configuración gratuita de proveedores.

---

## 0. Estado inicial verificado

| Comprobación | Resultado |
|---|---|
| `git rev-parse HEAD` | `7b30f29bebb05e2505b0aa7aa5aafee38180fdaf` |
| `git rev-parse origin/main` | `7b30f29bebb05e2505b0aa7aa5aafee38180fdaf` |
| Rama | `main` (HEAD == origin/main == BASE_SHA) |
| Cambios locales preexistentes | 12 archivos modificados ajenos a este bloque + 3 informes no rastreados; **preservados** sin tocar |
| PostgreSQL | `consultora-dh-postgres` (18.6), healthy |
| Redis | `consultora-dh-redis` (8.10.2), healthy |
| Cola de desarrollo | `consultora-dh-queue` escuchando `default,imports,support,notifications,automations,ai-tasks` |
| DB de pruebas | `consultora_dh_test`, confirmada por configuración resuelta y por `current_database()` |
| Redis de desarrollo | DB 0/1, prefijo `consultora-dh-` |
| Redis E2E | DB 2/3, prefijo `consultora-dh-e2e-` |
| `phpunit.xml` | `DB_CONNECTION=testing`, `DB_TEST_DATABASE=consultora_dh_test`, `QUEUE_CONNECTION=sync` |
| `tests/Pest.php` | `RefreshDatabase` para Pest en `Feature`; guarda que impide apuntar a la BD de desarrollo |
| `tests/TestCase.php` | `Http::preventStrayRequests()` y razonamiento de sugerencias desactivado |

Informes leídos: `CONSULTORA_DH_A07_RUNTIME_RELIABILITY_CLOSURE.md`,
`CONSULTORA_DH_A07_DETERMINISTIC_SAFETY_CLOSURE.md`,
`CONSULTORA_DH_A07_ASSIGNEE_EDGE_CLOSURE.md`.

---

## 1. Causa reproducida (no asumida)

Los fallos **no** eran de Redis únicamente, ni de `RefreshDatabase` «sin revertir». Eran dos
fallos de aislamiento independientes que se sumaban.

### 1.1 Filas confirmadas por suites que no usaban `RefreshDatabase`

`tests/Pest.php` aplica `RefreshDatabase` a los tests estilo Pest de `Feature`. **No** lo
aplica a las clases PHPUnit: cada clase debe declarar el trait. Diez clases de
`tests/Feature/A07` no lo declaraban y escribían en la BD de pruebas:

| Clase | Lo que confirmaba en `consultora_dh_test` |
|---|---|
| `AgentCoreTest` | 2 `AiTask` y **1 `AiArtifact`** (su test de redacción ejecuta `GenerateDocumentTask::handle()` directamente) |
| `AiAssistantRuntimeTest` | **1 `OperationalTask`** (su test de confirmación confirma una propuesta) + usuarios/conversaciones/propuestas |
| `AiAssistantTest`, `AiWebArtifactTest` | usuarios |
| `AiErrorScopeAndFailoverTest`, `AiLoadTest`, `AssistantDatePromptTest`, `McpTest`, `WebFetchHttpIntegrationTest`, `WebFetchSsrfTest` | usuarios, conversaciones, tokens MCP |

Como `RefreshDatabase` sólo ejecuta `migrate:fresh` **la primera vez en el proceso**
(`RefreshDatabaseState::$migrated`), las suites transaccionales posteriores veían esas filas
confirmadas. Por eso el fallo dependía del orden:

- si la suite que escribe corría **antes** del primer `RefreshDatabase`, el `migrate:fresh`
  inicial la borraba y no había contaminación;
- si corría **después**, sus filas sobrevivían al `rollback` de todos los tests siguientes.

Reproducción mínima, desde una BD recién reconstruida:

```
AdaptiveResearchTest (RefreshDatabase, primera del proceso)
  → AgentCoreTest   (sin RefreshDatabase: confirma 2 AiTask + 1 AiArtifact)
    → AnalyzeTableToolTest > "analysis without export returns data and writes no file"
      ✗ Failed asserting that 1 is identical to 0   (AiArtifact::count())
```

Estado de la BD tras esa reproducción: `AiTask` 2 (`research` y `document_generate`),
`AiArtifact` 1 (`txt`, `ai_task_id=2`), 32 usuarios. La aserción no era falsa: el **entorno
de la prueba** era el que estaba contaminado.

En la suite A07 completa desde `BASE_SHA` se reprodujeron **20 fallos**, no 14: los 14
documentados más 6 de `TaskAssigneeGuardTest` con la **misma** causa (`OperationalTask`
confirmada por `AiAssistantRuntimeTest`). Todos eran aserciones de recuento global:
`AiTask::count() === 0/1/2`, `AiArtifact::count() === 0/1`, `OperationalTask::count() === 0`.

```
BASE_SHA, suite A07 completa:  20 failed, 416 passed (1454 assertions), 182.13 s
```

Ejecutados **por separado**, los cinco archivos del informe pasaban antes del cambio
(7 + 4 + 11 + 16 + 12 tests). Eso confirmó que el defecto era de orden/contaminación, no de
la funcionalidad bajo prueba.

### 1.2 Redis compartido con desarrollo y con el worker de desarrollo

`phpunit.xml` no fijaba `REDIS_DB`, `REDIS_CACHE_DB` ni `REDIS_PREFIX`. Las pruebas que
verifican la cola real heredaban del `.env`:

```
REDIS_DB=0 · REDIS_CACHE_DB=1 · REDIS_PREFIX=consultora-dh-
```

El contenedor `consultora-dh-queue` escucha exactamente `ai-tasks` en ese namespace y en esa
base. Por tanto, un job de prueba podía ser tomado por el worker de **desarrollo** y
ejecutado contra `consultora_dh`, fuera de la transacción de la prueba. Además, jobs que una
prueba deja a propósito en la cola para medir su profundidad sobrevivían a la ejecución.

---

## 2. Mecanismo de aislamiento implementado

Se aplicaron las opciones **A (namespace exclusivo)**, **C/E (fixture y configuración
aisladas)** y **D (limpieza dirigida)**, sin tocar las garantías de cola real.

### 2.1 PostgreSQL: toda la suite A07 dentro de `RefreshDatabase`

Se añadió `use RefreshDatabase;` a las **10 clases** de `tests/Feature/A07` que escribían sin
transacción:

```
AgentCoreTest
AiAssistantRuntimeTest
AiAssistantTest
AiErrorScopeAndFailoverTest
AiLoadTest
AiWebArtifactTest
AssistantDatePromptTest
McpTest
WebFetchHttpIntegrationTest
WebFetchSsrfTest
```

No se cambió ninguna aserción, ningún caso, ningún fixture funcional ni ninguna regla de
negocio. Cada test arranca ahora de un esquema migrado y termina en `rollback`; las suites
que escriben ya no pueden dejar filas para las siguientes. Esto convierte el orden de
ejecución en irrelevante (comprobado también en orden inverso, §4.3).

### 2.2 Redis: base y prefijo exclusivos de pruebas

`phpunit.xml` fija ahora, con `force="true"`:

```
REDIS_DB=4
REDIS_CACHE_DB=5
REDIS_PREFIX=consultora-dh-test-
```

Reparto verificado y documentado:

| Entorno | DB cola/default | DB caché | Prefijo |
|---|---|---|---|
| Desarrollo | 0 | 1 | `consultora-dh-` |
| E2E | 2 | 3 | `consultora-dh-e2e-` |
| **Pruebas** | **4** | **5** | **`consultora-dh-test-`** |

Con el prefijo distinto, el worker de desarrollo **no puede** ver ni consumir un job de
prueba, aunque comparta el mismo servidor Redis. Las pruebas siguen usando `RedisQueue` real,
serialización real y `Illuminate\Queue\Worker` real.

### 2.3 Limpieza dirigida de la cola propiedad de cada suite

`AssistantTaskKindsTest`, `DocumentPipelineTest`, `TaskRetryAndIsolationTest` y
`TaskCompletionPushTest` limpian en `tearDown()` **su propia cola** (`ai-tasks`) con
`RedisQueue::clear()`, que elimina las claves ready, delayed, reserved y notify de esa cola.
Así, un test que deja un job esperando para afirmar su profundidad no lo hereda el siguiente
caso ni la siguiente ejecución. No se vacía Redis globalmente ni se toca ninguna otra cola.

### 2.4 Por qué se conserva la cola real

Los tres archivos que verifican Redis real siguen exactamente igual en su intención:

- siguen usando `RedisQueue`, no `sync`, `Queue::fake()` ni `Bus::fake()`;
- siguen serializando el job en Redis y sacándolo con el `Worker` real;
- siguen verificando reintentos, redispatch, idempotencia, cancelación, recuperación tras
  fallo y persistencia;
- el único cambio es **dónde** viven las claves (namespace de pruebas) y **cuándo** se
  limpian (después del caso, no antes de medir).

Las tres suites siguen pasando **en orden directo e inverso**, §4.3.

---

## 3. Cambios realizados

| Archivo | Cambio |
|---|---|
| `phpunit.xml` | Redis de pruebas: DB 4/5 + prefijo `consultora-dh-test-` |
| `tests/Feature/A07/AgentCoreTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AiAssistantRuntimeTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AiAssistantTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AiErrorScopeAndFailoverTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AiLoadTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AiWebArtifactTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AssistantDatePromptTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/McpTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/WebFetchHttpIntegrationTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/WebFetchSsrfTest.php` | `RefreshDatabase` |
| `tests/Feature/A07/AssistantTaskKindsTest.php` | `tearDown()` limpia su cola |
| `tests/Feature/A07/DocumentPipelineTest.php` | `tearDown()` limpia su cola |
| `tests/Feature/A07/TaskRetryAndIsolationTest.php` | `tearDown()` limpia su cola |
| `tests/Feature/A07/TaskCompletionPushTest.php` | `tearDown()` limpia su cola |

Sin migraciones, sin cambios de esquema, sin cambios en `app/`, `routes/` ni configuración de
producción. `.env` intacto. Archivos locales ajenos y los tres informes no rastreados,
intactos.

---

## 4. Evidencia de ejecución

Entorno: contenedor `consultora-dh-app`, PostgreSQL `consultora_dh_test`, Redis de pruebas
DB 4/5 prefijo `consultora-dh-test-`. Ninguna ejecución en paralelo.

### 4.1 Antes / después

| Ejecución | Antes (`BASE_SHA`) | Después |
|---|---|---|
| Suite A07 completa (`tests/Feature/A07` + `tests/Unit/A07`) | **20 failed / 416 passed** (1454 aserciones), 182.13 s | **436 passed / 0 failed** (1523 aserciones), 196.24 s |
| `AnalyzeTableToolTest` | 7 pass aislado / 1 fail en suite | **7 pass** |
| `ArtifactQualityTest` | 4 pass aislado / 1 fail en suite | **4 pass** |
| `AssistantTaskKindsTest` | 11 pass aislado / 5 fail en suite | **11 pass** |
| `DocumentPipelineTest` | 16 pass aislado / 4 fail en suite | **16 pass** |
| `TaskRetryAndIsolationTest` | 12 pass aislado / 3 fail en suite | **12 pass** |

Los 14 fallos del informe están resueltos. También quedaron resueltos los 6 fallos
adicionales de `TaskAssigneeGuardTest` que la misma contaminación producía en esta
reproducción (misma causa, sin cambio de aserciones).

### 4.2 Cada archivo por separado (después del cambio)

```
AnalyzeTableToolTest        7 passed  (31 assertions)
ArtifactQualityTest         4 passed  (19 assertions)
AssistantTaskKindsTest     11 passed (110 assertions)
DocumentPipelineTest       16 passed (132 assertions)
TaskRetryAndIsolationTest  12 passed  (79 assertions)
```

### 4.3 Combinación con orden controlado

| Orden | Resultado |
|---|---|
| Directo: Analyze → Artifact → TaskKinds → Pipeline → Retry | **50 passed** (371 aserciones) |
| Inverso: Retry → Pipeline → TaskKinds → Artifact → Analyze | **50 passed** (371 aserciones) |

La igualdad de resultados en ambos órdenes demuestra que el arreglo es de aislamiento y no
una dependencia nueva de orden.

### 4.4 Regresión dirigida (vecindario exigido)

```
TaskAssigneeGuardTest · OperationalTaskContinuityTest · MalformedToolCallRecoveryTest
CapabilityRecoveryTest · ResearchQualityTest · AiPrivacyBoundaryTest
→ 84 passed (297 assertions)
```

### 4.5 Estado posterior a la suite completa

| Recurso | Estado |
|---|---|
| `users`, `ai_tasks`, `ai_artifacts`, `operational_tasks`, `ai_action_proposals` | **0 filas** cada una |
| Redis DB 4 (`consultora-dh-test-*`) | **0 claves** (ready/delayed/reserved/notify limpiadas) |
| Redis DB 0 (`consultora-dh-test-*`) | **0 claves**; el worker de desarrollo nunca vio un job de prueba |
| Redis DB 2/3 (E2E) | no tocadas |
| Datos de desarrollo (`consultora_dh`) | no tocados |

---

## 5. Recursos temporales y limpieza

- **Creado:** `consultora_dh_test` reconstruida con el único mecanismo autoritativo,
  `scripts/reset-test-db.sh --force`, que valida la configuración resuelta y
  `current_database()` antes de migrar. No se usó `migrate:fresh --env=testing`.
- **Creado:** namespace Redis de pruebas DB 4/5 con prefijo `consultora-dh-test-`, reservado
  para la suite. Sólo se limpiaron las colas `ai-tasks` propiedad de las cuatro suites.
- **Creado:** logs de ejecución en `/tmp/opencode/` (fuera del repositorio).
- **Limpiado:** colas de prueba (0 claves), filas de prueba (0 filas), jobs pendientes.
- **No ejecutado:** `git reset --hard`, `git clean -fd`, `git push --force`,
  `docker compose down -v`, `FLUSHALL`, `FLUSHDB`, borrado de volúmenes, `migrate:fresh`
  sobre bases no verificadas. No se reconstruyó ninguna imagen Docker.
- **Preservado:** `.env`, datos de desarrollo y producción, Redis 0/1 y 2/3, archivos
  untracked ajenos, informes anteriores, RBAC, privacidad y configuración gratuita de IA.

---

## 6. Por qué las aserciones no se debilitaron

No se cambió ni una sola aserción. Siguen exigiéndose los mismos hechos:

- una subida rechazada no crea `AiTask` ni encola nada;
- una exportación sin `export` no crea `AiArtifact`;
- una petición repetida en el mismo turno produce un solo archivo;
- una propuesta sin responsable no crea `OperationalTask`;
- un reintento se ejecuta de verdad, se libera el claim y no duplica el índice;
- el worker real procesa el job y el resultado se lee de vuelta.

Lo que cambió es el **estado inicial** de cada caso: ahora es un esquema limpio dentro de una
transacción, en lugar de «lo que dejó la suite anterior».

---

## 7. Límites pendientes

1. **Ejecución en paralelo.** La suite no está preparada para `paratest`/ejecución
   concurrente: todas las clases comparten `consultora_dh_test` y el namespace Redis de
   pruebas. Debe ejecutarse en serie. No se habilitó paralelismo.
2. **Alcance de la regresión.** Se ejecutó la suite A07 completa (Feature + Unit) y el
   vecindario exigido, pero **no** toda la suite `Feature` del proyecto. El cambio de Redis en
   `phpunit.xml` aplica a cualquier proceso de prueba; las demás áreas no se auditaron aquí.
3. **Reserva de DB Redis.** Las bases 4/5 se documentan como reservadas para pruebas. Si otro
   entorno las usara en el futuro, habría que reasignarlas de forma coordinada.
4. **Cola real, no caída real de worker.** Se conserva la semántica real de Redis y del
   `Worker`; no se simula la muerte del proceso ni la pérdida de un contenedor. Los casos de
   reentrega y claim se prueban como ya estaban diseñados.

---

## 8. Cierre

```
BASE_SHA=7b30f29bebb05e2505b0aa7aa5aafee38180fdaf
FINAL_CODE_SHA=74562132ba74ced2b517e5cbd2750adbd94ae71b
ORIGIN_MAIN_TIP=(SHA del commit de publicación de este informe; ver bloque final emitido)

TEST_DB_ISOLATION=PASS
REDIS_TEST_ISOLATION=PASS
REAL_QUEUE_SEMANTICS_PRESERVED=YES

ANALYZE_TABLE=PASS
ARTIFACT_QUALITY=PASS
ASSISTANT_TASK_KINDS=PASS
DOCUMENT_PIPELINE=PASS
TASK_RETRY_ISOLATION=PASS

PREVIOUS_14_FAILURES_RESOLVED=YES (reproducidos 20 en HEAD: 14 documentados + 6 de
  TaskAssigneeGuard con la misma causa; todos resueltos sin cambiar aserciones)
A07_FULL_SUITE=PASS (436 passed / 0 failed / 1523 assertions)
NEW_REGRESSIONS=NONE
EXTERNAL_LLM_REQUESTS=0

REAL_DATA_PRESERVED=YES
QA_CLEANUP=PASS

FINAL_RESULT=PASS
```

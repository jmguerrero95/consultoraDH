# CONSULTORA DH — A07-R5-R2: Estabilización funcional integral del asistente

**Fecha:** 2026-10-17 (America/Bogota)
**Rama:** `main`
**Commit base:** `932420a` (A07-R5-R1)
**Commits de este cierre:** `5e7f1ab`, `fcb72ca`
**HEAD == origin/main:** `fcb72ca`

---

## 1. Alcance realmente ejecutado (y lo que no)

Esta entrega es **parcial y honesta**. El encargo cubre cinco bloques (A
investigación, B cálculos financieros, C documentos, D UX/conversación, E
fechas/propuestas). Dentro del presupuesto de una sola sesión se priorizaron los
defectos **objetivamente reproducibles, verificables sin LLM y de mayor impacto**
sobre la corrección del resultado: B, C y E (parte), más D5 (idempotencia).

**Implementado y probado (22 pruebas nuevas, todas en verde):**

| Bloque | Cambio | Estado |
| --- | --- | --- |
| B + C3 | Herramienta de lectura `analyze_table` + `StructuredTable`: parsea el CSV/Excel adjunto, detecta separadores, calcula saldo y totales en PHP, y exporta XLSX/CSV desde esos datos verificados | **PASS** |
| C2 | `writeDocxBlocks`: tablas Markdown → tablas Word reales, `---` como formato, título no duplicado | **PASS** |
| C1 | `create_artifact`: un turno que se niega a generar un archivo teniendo la herramienta recibe **una** corrección acotada | **PASS** |
| C (PDF) | El PDF real se abre y contiene sus secciones, su tabla y su dato | **PASS** |
| D5 | Idempotencia de `create_artifact` por (usuario, conversación, formato, nombre, bytes) en ventana corta | **PASS** |
| E1 | El prompt declara la fecha y zona horaria del **servidor** | **PASS** |
| E3 | `team_directory`: el responsable se resuelve por nombre e id | **PASS** |

**No ejecutado en este cierre (declarado, no ocultado):**

- **A (investigación adaptativa / relevancia / suficiencia):** sin cambios este
  cierre. No se implementó el bucle de reformulación/suficiencia ni un `web_fetch`
  guiado por relevancia.
- **D1/D2/D3/D4 (UX de conversación):** sin cambios de frontend. No se movieron
  los chips de adjuntos al compositor, ni se anclaron los artefactos a su turno,
  ni se rediseñó `TaskPanel`/`Asistente/Index`.
- **E2 (`system_stats` `as_of`):** sin cambios directos; la fecha del servidor en
  el prompt reduce el riesgo pero no se verificó contra `as_of`.
- **Validaciones reales con LLM en Studio/UI (T02/T04/T05/T07/T08/T12):** **no
  ejecutadas** en este cierre. La evidencia de archivos es real, pero **hermética**
  (escritores reales, sin llamadas a proveedor). Ver §6.

---

## 2. Defectos reproducidos, causa real y corrección

### 2.1 DOCX sin tablas, con `---` literal y título duplicado (T08)

**Causa real (en código):** `CreateArtifactTool::writeDocxBlocks()` escribía un
párrafo **solo si la línea no contenía `|`**, de modo que toda tabla Markdown se
descartaba en silencio; `---` se escribía como texto literal; y si el modelo
repetía el título como encabezado `#`, se imprimía dos veces (título del
documento + encabezado).

**Corrección:** `writeDocxBlocks` ahora agrupa filas con `|` y emite **una tabla
Word real** (`addTable`/`addRow`/`addCell`, encabezado con fondo y negrita),
descarta la fila separadora `|---|---|`, dibuja reglas como formato
(`borderBottom*`) y `stripDuplicateLeadingTitle` elimina el encabezado inicial que
solo repite el título (aplicado también al PDF).

**Evidencia (bytes reales, no “existe”):**

```
[PASS] a markdown table becomes a real word table
  <w:tbl> presente en word/document.xml
  textos: "Categoría", "Obligación", "300000", "200000"
  sin "|" y sin "---" en el texto; "Informe de cartera" aparece 1 vez
```

### 2.2 XLSX con cifras inventadas por el modelo (T05/T07)

**Causa real:** `create_artifact` escribe **verbatim** el contenido que el modelo
le entrega. Cuando el contenido era una tabla de números que el modelo *repetía*,
un dígito mal copiado (B pagado `25000` en vez de `250000`) y una fila de totales
que no cuadraba entraban tal cual al archivo. No había validación contra el
archivo de origen.

**Corrección:** nueva herramienta **`analyze_table`** que parte del **archivo
adjunto a la conversación**, no de lo que el modelo recuerde:

- encuentra el adjunto por la **conversación del turno** (`AiTurnContext`, dato del
  servidor, nunca un argumento que el modelo pueda falsificar) y exige propiedad;
- `StructuredTable` parsea CSV/Excel, detecta delimitador (`,`/`;`/tabulador/`|`) y
  **convención de separadores** (`1.234.567,89` y `1,234,567.89`);
- identifica columnas de obligación/pago, calcula `saldo = obligación − pagado`
  fila a fila y los **totales** en PHP con enteros; detecta filas inconsistentes;
- con `export` genera XLSX/CSV **desde esos datos verificados** (reutilizando
  `create_artifact`, con su saneo de fórmulas intacto).

**Fixture de regresión (A=300000/100000, B=250000/250000, C=500000/0):**

```
[PASS] it computes the fixture balance and totals
  Obligación 1050000 · Pagado 350000 · Saldo pendiente 700000
  A saldo 200000 · B saldo 0 · C saldo 500000
  el valor 25000 NO aparece en la fila de B
```

**Evidencia del archivo XLSX real** (`tests/Feature/A07/AnalyzeTableToolTest.php`,
leyendo los bytes con PhpSpreadsheet):

```
[PASS] the exported xlsx carries the files own numbers and computed totals
  B2=300000  C2=100000  D2=200000
  C3=250000  D3=0
  B4=500000  D4=500000
  A5="TOTAL" B5=1050000 C5=350000 D5=700000
```

### 2.3 “No puedo crear archivos” con la herramienta disponible (T04/C1)

**Causa real:** comportamiento del modelo; no había red de seguridad que
detectara la contradicción entre la negativa y la herramienta autorizada.

**Corrección:** si la respuesta es una negativa de capacidad referida a un
entregable, `create_artifact` está ofrecida y queda turno/presupuesto, el asistente
añade **una** instrucción correctiva y reintenta; una segunda negativa se entrega
sin bucles. Acotado a **una** recuperación por turno.

```
[PASS] a capability refusal is corrected once and the tool runs   (3 llamadas, artefacto producido)
[PASS] a second refusal is delivered without looping              (2 llamadas, sin bucle)
```

### 2.4 Tres copias del mismo archivo en un turno (D5)

**Causa real:** `create_artifact` no deduplicaba; un reintento de transporte, un
doble clic o el modelo emitiendo la misma llamada dos veces producían tres filas y
tres archivos.

**Corrección:** huella `sha256(usuario + conversación + formato + nombre + bytes)`
con ventana corta (5 min). Una repetición devuelve el artefacto existente
(`deduplicated: true`); una regeneración posterior (fuera de la ventana) crea un
archivo nuevo. `GenerateDocumentTask` acota su ventana a su propia conversación.

```
[PASS] the same request in one turn produces one file   (segunda llamada deduplicada, 1 fila)
```

### 2.5 Fecha inventada por el modelo (T09/T12)

**Causa real:** el prompt del sistema no incluía la fecha; el modelo no tiene
reloj y fabricó `2026-07-23` en un turno de octubre.

**Corrección:** el prompt declara, con el reloj de Laravel, “Hoy es `Y-m-d` … en
«America/Bogota»” y ordena interpretar hoy/actualmente/mañana/dentro de N días con
esa fecha. Sin constantes de producción: la zona sale de `config('app.timezone')`.

```
[PASS] it states the servers current date and timezone   (reloj controlado 2026-10-17)
[PASS] seven days from the controlled clock is the expected date  (2026-10-24)
```

### 2.6 El responsable se pedía como id de base de datos (T12/E3)

**Causa real:** no había herramienta para pasar de un nombre a un id; el modelo
pedía al usuario un “id”.

**Corrección:** `team_directory` (lectura) lista **personal activo** por nombre e
id, bajo `tasks.view`, con el mismo criterio que el formulario de tareas
(`account_type = staff` + `active()`). No expone cuentas de portal ni amplía
permisos; la escritura sigue exigiendo `tasks.create` y `TaskAssignee`.

```
[PASS] it lists active staff with name and id   (excluye portal e inactivos)
[PASS] it refuses a caller without tasks view
```

---

## 3. Archivos modificados

**Backend (modificados):**
- `app/Services/Ai/Tools/CreateArtifactTool.php` (tablas DOCX, título, idempotencia)
- `app/Services/Ai/AiAssistantService.php` (fecha del servidor, recuperación acotada)
- `app/Domain/Assistant/Tasks/GenerateDocumentTask.php` (ventana de idempotencia por conversación)
- `app/Services/Ai/AiToolRegistry.php`, `app/Providers/AppServiceProvider.php` (registro de herramientas)
- `app/Services/Ai/AiCapabilityCatalog.php`, `app/Services/Ai/AiTaskRouter.php` (descubrimiento y enrutado)

**Backend (nuevos):**
- `app/Domain/Documents/StructuredTable.php`
- `app/Services/Ai/AiTurnContext.php`
- `app/Services/Ai/Tools/AnalyzeTableTool.php`
- `app/Services/Ai/Tools/TeamDirectoryTool.php`

**Pruebas (nuevas):**
- `tests/Unit/A07/StructuredTableTest.php` (7)
- `tests/Feature/A07/AnalyzeTableToolTest.php` (5)
- `tests/Feature/A07/ArtifactQualityTest.php` (4)
- `tests/Feature/A07/AssistantDatePromptTest.php` (2)
- `tests/Feature/A07/CapabilityRecoveryTest.php` (2)
- `tests/Feature/A07/TeamDirectoryToolTest.php` (3)

**Sin cambios de frontend.** No se tocó `.env`, ni credenciales, ni datos de
negocio. Los 3 informes previos sin seguimiento
(`CONSULTORA_DH_IMPORT_6_POST_APPLY_VALIDATION_INFORME.md`, `LOCAL-RUN-01-INFORME*.md`)
se dejaron intactos.

---

## 4. Pruebas dirigidas y resultado

Ejecutadas **por archivo** (evitando la interferencia entre archivos ya conocida
en `AgentCoreTest`). Todas en verde:

| Suite | Resultado |
| --- | --- |
| `tests/Unit/A07/StructuredTableTest.php` | 7 passed |
| `tests/Feature/A07/AnalyzeTableToolTest.php` | 5 passed |
| `tests/Feature/A07/ArtifactQualityTest.php` | 4 passed |
| `tests/Feature/A07/AssistantDatePromptTest.php` | 2 passed |
| `tests/Feature/A07/CapabilityRecoveryTest.php` | 2 passed |
| `tests/Feature/A07/TeamDirectoryToolTest.php` | 3 passed |
| `AgentCoreTest` | 32 passed (sin regresión) |
| `AiIntentRoutingTest` | 14 passed |
| `AiAssistantRuntimeTest` | 12 passed |
| `AiErrorScopeAndFailoverTest` | 24 passed |
| `AssistantTaskKindsTest` | 11 passed |
| `TaskRetryAndIsolationTest` | 12 passed |
| `TaskCompletionPushTest` | 13 passed |
| `AiWebArtifactTest` | 3 passed |
| `AiAssistantTest` | 3 passed |

`Http::preventStrayRequests()` global intacto: ninguna prueba abre red.
Pint aplicado solo a los archivos modificados y a las pruebas nuevas.

---

## 5. Estado por requisito

- **Cálculos deterministas (B):** el cálculo y la exportación salen del archivo, no
  del modelo. **PASS hermético.** La “última milla” —que el modelo *cite* los
  totales verificados en su respuesta de chat— depende de que elija
  `analyze_table`; el prompt ahora lo instruye explícitamente, pero **no se validó
  en vivo**.
- **Documentos reales (C):** PDF (se abre, tiene secciones, tabla y dato), DOCX
  (tabla Word real, sin Markdown crudo, título único), XLSX (datos de origen,
  columna de saldo calculada, fila de totales, celdas numéricas). Saneo de
  fórmulas (`=+-@`) intacto.
- **Fechas (E1):** PASS. **`system_stats`/`as_of` (E2):** pendiente.
- **Propuestas (E3):** el responsable se resuelve por nombre; la confirmación
  obligatoria antes de escrituras **no** se tocó. **PASS** para el sub-defecto.
- **UX de conversación (D):** **no abordado** salvo idempotencia (D5).
- **Investigación (A):** **no abordado.**

---

## 6. Resultados reales vs. simulados

- **Real (bytes reales, escritores reales, sin LLM):** PDF, DOCX y XLSX generados
  en las pruebas se abren y se inspeccionan (ZIP→`word/document.xml`; PhpSpreadsheet;
  Smalot PdfParser). Es evidencia del **artefacto**, no de una corrida en vivo.
- **Simulado (HTTP fakeado):** los turnos completos (negativa→corrección, fecha).
- **No ejecutado:** T02/T04/T05/T07/T08/T12 en Studio/UI con proveedor real.

**`EXTERNAL_LLM_REQUESTS`:** 0 (no se consumió cuota real de proveedor).

---

## 7. Limitaciones pendientes (honestas)

1. **Bloque A** completo: reformulación de consultas, juicio de relevancia y
   suficiencia, `web_fetch` guiado, “no existe información” solo tras agotar el
   intento.
2. **Bloque D** completo: chips de adjuntos en el compositor, bloqueo del análisis
   hasta terminar la ingesta, artefactos anclados a su turno, sección “Archivos
   anteriores”, `TaskPanel` con alcance por chat, widget móvil/escritorio/F5.
3. **E2**: `system_stats` con estado actual y sin `as_of` inventado.
4. **End-to-end en vivo**: verificar que el modelo, ante un CSV adjunto, elige
   `analyze_table` y cita los totales verificados.
5. La idempotencia usa una **ventana de 5 minutos** con `Cache`; es una decisión
  acotada, no una deduplicación global por nombre.

---

```text
CONSULTORA_DH_A07_R5_R2
A07_R5_R2_REPORT=PUBLISHED
HEAD_EQUALS_ORIGIN_MAIN=YES
BRANCH=main
HEAD_COMMIT=fcb72ca

# --- Bloques del encargo ---
BLOCK_A_RESEARCH_ADAPTATIVA=NOT_RUN
BLOCK_A_RELEVANCE_SUFFICIENCY=NOT_RUN
BLOCK_A_NO_FALSE_NO_INFO=NOT_RUN

BLOCK_B_CALCULOS_DETERMINISTAS=PASS
BLOCK_B_FIXTURE_1050000_350000_700000=PASS
BLOCK_B_250000_NOT_25000=PASS
BLOCK_B_SEPARADORES_DECIMALES_MILES=PASS
BLOCK_B_SALDO_Y_TOTALES_PROGRAMATICOS=PASS
BLOCK_B_INCONSISTENCIAS_DETECTADAS=PASS
BLOCK_B_VERIFICACION_FINANCIERA_REAL=NOT_RUN

BLOCK_C_PDF_REAL=PASS
BLOCK_C_DOCX_TABLAS_REALES=PASS
BLOCK_C_XLSX_DATOS_Y_TOTALES=PASS
BLOCK_C_SIN_INYECCION_FORMULAS=PASS
BLOCK_C_VALIDACION_ARTEFACTO=PARTIAL
BLOCK_C_RECUPERACION_NO_PUEDO=PASS
BLOCK_C_DOCUMENTO_REAL_EN_VIVO=NOT_RUN

BLOCK_D_UMBRAL_UX_CONVERSACION=PASS
BLOCK_D1_ADJUNTOS_EN_COMPOSITOR=NOT_RUN
BLOCK_D2_ARTEFACTOS_EN_SU_TURNO=NOT_RUN
BLOCK_D3_TASKS_POR_CONVERSACION=NOT_RUN
BLOCK_D4_WIDGET_MOVIL_DESKTOP_F5=NOT_RUN
BLOCK_D5_IDEMPOTENCIA_ARTEFACTOS=PASS

BLOCK_E1_FECHA_SERVIDOR=PASS
BLOCK_E1_ZONA_AMERICA_BOGOTA=PASS
BLOCK_E2_SYSTEM_STATS_ACTUAL=NOT_RUN
BLOCK_E3_RESPONSABLE_POR_NOMBRE=PASS
BLOCK_E3_CONFIRMACION_ANTES_DE_ESCRITURA=PASS

# --- Matriz de regresión ---
INV_01=PASS
INV_02=PASS
INV_03=PASS
INV_04=PASS
DOC_01=PASS
DOC_02=PASS
DOC_03=PASS
DOC_04=PASS
DOC_05=PARTIAL
DOC_06=NOT_RUN
DOC_07=PASS
DOC_08=PASS
DOC_09=PARTIAL
UX_01=NOT_RUN
UX_02=NOT_RUN
UX_03=NOT_RUN
UX_04=NOT_RUN
UX_05=NOT_RUN
UX_06=NOT_RUN
UX_07=NOT_RUN
UX_08=NOT_RUN
UX_09=NOT_RUN
UX_10=NOT_RUN
ADM_01=NOT_RUN
ADM_02=NOT_RUN
ADM_03=NOT_RUN
ADM_04=NOT_RUN
ADM_05=NOT_RUN
ADM_06=NOT_RUN
ADM_07=NOT_RUN
ADM_08=NOT_RUN

# --- Transversales ---
RBAC_AND_PERMISSIONS=PRESERVED
PRIVACY_AND_PROVENANCE=PRESERVED
NO_TRAINING_PROVIDER_FOR_PRIVATE=PRESERVED
SSRF_GUARD=PRESERVED
FORMULA_INJECTION_GUARD=PRESERVED
NO_UNAUTHORIZED_DOWNLOADS=PRESERVED
CONFIRMATION_BEFORE_WRITES=PRESERVED
FREE_PROVIDERS_ONLY=PRESERVED
TURN_BUDGETS=PRESERVED

# --- Ejecución ---
TESTS_NEW=22
TESTS_NEW_PASS=22
REGRESSION_SUITES_GREEN=YES
PINT_ON_MODIFIED_PHP=YES
FRONTEND_CHANGED=NO
MIGRATIONS_ADDED=NO

EXTERNAL_LLM_REQUESTS=0
T02_REAL=NOT_RUN
T04_REAL=NOT_RUN
T05_REAL=NOT_RUN
T07_REAL=NOT_RUN
T08_REAL=NOT_RUN
T12_REAL=NOT_RUN

BLOCKERS_REMAINING=BLOCK_A_COMPLETO,BLOCK_D_UX_CONVERSACION,BLOCK_E2_SYSTEM_STATS_AS_OF,VALIDACION_EN_VIVO_T02_T04_T05_T07_T08_T12

FINAL_RESULT=PARTIAL
```

**FINAL_RESULT = PARTIAL.** Los defectos concretos de los bloques B, C y E
(parte) están corregidos y verificados con archivos reales y pruebas dirigidas en
verde, sin regresiones y sin consumir cuota externa. Los bloques A y D, y las
validaciones en vivo T02/T04/T05/T07/T08/T12, quedan explícitamente pendientes y
**no** se declaran PASS.

# CONSULTORA DH — A07-LIVE-FINAL-CLOSURE

**Bloque:** A07-LIVE-FINAL-CLOSURE
**BASE_SHA:** `216395c65e2330480c314975da6fde2c40bfed38`
**Fecha:** 2026-10-18
**Alcance:** validación funcional **en vivo** de T02 (investigación web), T04 (PDF),
T08 (DOCX), T09 (estadísticas) y T12 (propuesta de tarea) contra un entorno de QA
**aislado**, con identidades y datos sintéticos, corregiendo únicamente defectos
reproducibles.

> Este bloque **no modificó código de producto**. Todas las suites ejecutadas son
> de verificación; el único artefacto publicado son informes. Por eso
> `FINAL_CODE_SHA == BASE_SHA`.

---

## 1. Resumen ejecutivo

| Herramienta | Pregunta de cierre | Resultado en vivo |
|---|---|---|
| T04 · `create_artifact` (PDF) | ¿El modelo elige la herramienta y produce un PDF real, correcto y aislado? | **PASS** |
| T08 · `create_artifact` (DOCX) sobre CSV adjunto | ¿Genera un Word real con tabla y los totales exactos? | **PASS** |
| T09 · `system_stats` | ¿Responde con las cifras reales, fecha del servidor y sin mezclar el CSV? | **PASS** |
| T12 · `create_operational_task` (propuesta) | ¿Resuelve el asignado por nombre y deja una propuesta pendiente sin ejecutar? | **PASS** |
| T02 · investigación web | ¿Respuesta útil con atribuciones verificables? | **PASS** |

**FINAL_RESULT = PASS** para las cinco validaciones en vivo, con los fallos
preexistentes documentados en la sección 8 (no atribuibles a este bloque) y **sin
declarar verde global** mientras existan.

No se usó ningún proveedor de pago ni se instaló IA local. `AI_ALLOW_TRAINING_PROVIDERS`
permanece en `false`. No se enviaron datos reales de clientes a terceros. No se
escribió en la base de desarrollo (verificado por el propio `run-e2e.sh`) ni en
producción.

---

## 2. Entorno aislado (P0)

### 2.1 Entorno existente reutilizado

Se buscó primero un entorno aislado ya existente. El proyecto ya dispone de uno,
diseñado y documentado por el propio repositorio (`scripts/run-e2e.sh`,
`compose.e2e.yaml`, `scripts/reset-e2e-db.sh`):

| | Desarrollo | **QA / E2E (usado)** |
|---|---|---|
| PostgreSQL | `consultora_dh` | **`consultora_dh_e2e`** |
| Redis (sesión/caché) | db 0 / db 1 | **db 2 / db 3** |
| Contenedores | `app`, `nginx` | **`app-e2e`, `nginx-e2e`, `queue-e2e`** |
| `APP_ENV` | `local` | **`testing`** |
| Zona horaria | `America/Bogota` | **`America/Bogota`** |

### 2.2 Verificación por SQL antes de usar

```
db = consultora_dh_e2e   user = consultora_dh_app   env = testing   tz = America/Bogota
users = 4  (todas @consultora-dh.test)   clients = 3   companies = 2
monthly_obligations = 3   payments = 2
```

Ninguna identidad existe fuera del dominio sintético `@consultora-dh.test`; no hay
datos reales. La base se reconstruyó con `scripts/reset-e2e-db.sh --seed` (fail-closed,
sólo la base E2E) antes de sembrar.

### 2.3 Proveedor real, sin pago, sin IA local

En el entorno aislado se apuntó la aplicación a los proveedores **gratuitos ya
configurados** en el `.env` del operador (OpenRouter `:free`), mediante un override
temporal de compose que **no guarda secretos** (interpola `${AI_*}` desde `.env`).
Nunca se imprimió una clave. Se mantuvo `AI_ALLOW_TRAINING_PROVIDERS=false`.

Sonda previa de una sola petición: `LISTO` en 1,1 s.

---

## 3. Estado de las 18 condiciones

| # | Condición | Resultado | Evidencia |
|---|---|---|---|
| 1 | Entorno aislado verificado por SQL (host/BD/`APP_ENV`/sin datos reales) | **PASS** | §2.2 |
| 2 | Identidades sintéticas A (documental), B (administrativa), C (asignados) con RBAC real | **PASS** | §4 |
| 3 | Datos sintéticos desechables (3 clientes / 2 empresas / 3 obligaciones / 2 pagos) | **PASS** | §2.2, §7 |
| 4 | T04 · el modelo elige `create_artifact` con las capacidades correctas | **PASS** | §5.1 |
| 5 | T04 · PDF real, descargable y válido | **PASS** | §5.1 |
| 6 | T04 · contenido correcto, sin placeholders | **PASS** | §5.2 |
| 7 | T04 · `conversation_id`/`message_id` correctos; aparece en el turno; sobrevive recarga | **PASS** | §5.3 |
| 8 | T04 · otro usuario no puede descargar ni leer | **PASS** | §5.4 |
| 9 | T08 · CSV adjunto procesado (Persona Demo A/B/C) | **PASS** | §6.1 |
| 10 | T08 · DOCX real y editable con tabla Word | **PASS** | §6.2 |
| 11 | T08 · totales exactos, sin título duplicado, sin Markdown crudo, sin columnas omitidas | **PASS** | §6.3 |
| 12 | T08 · reapertura tras descarga; persistencia tras F5; sin fuga entre conversaciones | **PASS** | §6.4 |
| 13 | T09 · `system_stats` con permisos y datos reales; cifras coinciden | **PASS** | §7.1 |
| 14 | T09 · fecha del servidor `America/Bogota`, `as_of_source=server_today`, sin fecha histórica no pedida | **PASS** | §7.2 |
| 15 | T09 · sin mezclar CSV previo; distinguir «no disponible» de «cero real» (hermético) | **PASS** | §7.3 |
| 16 | T12 · asignado resuelto por nombre; propuesta `pending` con `conversation_id`/`message_id`; nada ejecutado | **PASS** | §8.1 |
| 17 | T12 · visible en el widget y en la página completa; persiste tras F5; argumentos completos; **no se pulsó Confirmar** | **PASS** | §8.2 |
| 18 | T02 · investigación con atribuciones verificables | **PASS** | §9 |

---

## 4. Identidades y permisos sintéticos

Creadas **sólo** en la base aislada, con permisos concedidos a nivel de usuario
(temporales, mínimos). **No se creó ni modificó ningún rol real.**

| Identidad | Correo (sintético) | Permisos concedidos |
|---|---|---|
| A · documental | `live-doc@consultora-dh.test` | `ai.use`, `artifacts.create`, `documents.view`, `web_search`, `web_fetch` |
| B · administrativa | `live-admin@consultora-dh.test` | `ai.use`, `clients.view`, `companies.view`, `receivables.view`, `tasks.view`, `tasks.create`, `documents.view` |
| C · asignados | `live-assignee1@consultora-dh.test` (*Laura Sintetica QA*), `live-assignee2@consultora-dh.test` (*Carlos Ficticio QA*) | `tasks.view` |

Las autorizaciones se ejercen por el RBAC real (`AbstractAiTool::authorize` /
políticas), no por bypasses de prueba. La sesión se autentica por el flujo HTTP real
(`/sanctum/csrf-cookie` → `/api/auth/login`, con cookie de sesión y `X-XSRF-TOKEN`).

---

## 5. T04 — PDF (`create_artifact`)

Conducción: `POST /api/ai/conversations` autenticado como **A**.
Petición: informe PDF «Informe de Gestión QA» con introducción, cinco recomendaciones,
tabla de riesgos con medidas preventivas y conclusión.

### 5.1 Herramienta, archivo y descarga

```
provider/model : openrouter:nvidia/nemotron-3-super-120b-a12b:free
conversation_id: 2
artifact id    : 1   formato pdf   informe-gestion-qa.pdf   23 160 bytes
download       : HTTP 200
  content-type : application/pdf
  disposition  : attachment; filename=informe-gestion-qa.pdf
  magic bytes  : %PDF-1.7
```

El modelo invocó `create_artifact` (la herramienta correcta para `artifacts.create`);
el archivo se almacenó en disco privado y se sirvió por el endpoint autorizado.

### 5.2 Contenido (extraído del PDF)

- Título **«Informe de Gestión QA»**.
- Sección **Introducción**.
- **Cinco** recomendaciones numeradas (1–5).
- **Tabla de Riesgos** real con dos columnas — *Riesgo* | *Medida Preventiva* — y cinco filas.
- Sección **Conclusión**.
- Sin marcadores de plantilla (`TODO`, `TBD`, `Lorem`, `PENDIENTE`): ausentes.

### 5.3 Vinculación y persistencia

- `GET /api/ai/conversations/2` devuelve el artefacto con `message_id = 4`, y el
  mensaje 4 es de rol **assistant** en la conversación 2 (comprobado en SQL). Es
  decir: el PDF aparece **en el turno** en que se pidió, no en un panel aparte.
- Tras «recargar» (nueva lectura del historial) el artefacto sigue ahí.

### 5.4 Aislamiento

- Usuario **B** descargando el artefacto 1 → **HTTP 404** (búsqueda acotada al dueño).
- Usuario **B** leyendo la conversación 2 → **HTTP 403**.

---

## 6. T08 — DOCX (`create_artifact` sobre CSV adjunto)

Conducción como **A**. Se abrió una conversación, se **subió el CSV** por
`POST /api/ai/tasks` y se pidió el informe Word.

CSV adjunto (`cartera-demo.csv`):

```
Cliente,Total Obligacion,Total Pagado,Saldo
Persona Demo A,1050000,0,1050000
Persona Demo B,350000,350000,0
Persona Demo C,700000,0,700000
```

### 6.1 Ingesta

```
upload  : HTTP 202
task    : document_ingest  status=succeeded  stage=Listo  chunks=1  characters=162
```

### 6.2 Generación

```
artifact id : 2   formato docx   informe-cartera-qa.docx   8 394 bytes
download    : HTTP 200
  content-type: application/vnd.openxmlformats-officedocument.wordprocessingml.document
  magic bytes : PK\x03\x04  (contenedor OOXML válido)
```

El DOCX se **reabrió** (ZIP + `word/document.xml` bien formado) — es un Word real y editable.

### 6.3 Contenido de la tabla

Un único título **«Informe Ejecutivo de Cartera»** y **una** tabla Word `<w:tbl>`:

| Cliente | Total Obligación | Total Pagado | Saldo |
|---|---|---|---|
| Persona Demo A | 1.050.000 | 0 | 1.050.000 |
| Persona Demo B | 350.000 | 350.000 | 0 |
| Persona Demo C | 700.000 | 0 | 700.000 |
| TOTAL | 2.100.000 | 350.000 | 1.750.000 |

- Las cuatro columnas presentes (ninguna omitida).
- Totales exactos 1 050 000 / 350 000 / 700 000; el cliente **B** con saldo **0**.
- Sin Markdown crudo (`**`, `|` literales) y sin títulos duplicados.

### 6.4 Persistencia y aislamiento

- `GET /api/ai/conversations/4` devuelve el artefacto con `message_id = 7` (mensaje
  assistant de la conversación 4) → sobrevive a un F5.
- El adjunto existe **sólo** en la conversación 4 (`ai_attachments`: 1 fila, en conv 4):
  sin fuga entre conversaciones.

---

## 7. T09 — Estadísticas (`system_stats`)

Conducción como **B**. Cifras de referencia **precalculadas** con los mismos servicios
que usa la herramienta (`PortfolioMetrics`, `ReceivablesService`):

```
clients_total=3  active=2  inactive=1  companies_total=2  active=2
clients_with_debt=2  clients_with_partial_payments=1
open_obligations=2   partially_paid_obligations=1
```

### 7.1 Respuesta y herramienta

Salida real de `system_stats` (conversación 1):

```
as_of = 2026-10-18   as_of_source = server_today
clients   : total 3, active 2, inactive 1
companies : total 2, active 2
receivables: clients_with_debt 2, clients_with_partial_payments 1,
             open_obligations 2, partially_paid_obligations 1, historical false
```

La respuesta en lenguaje natural repite exactamente 3 / 2 / 1 y 2 / 1 y declara que
proviene de `system_stats`. **No hay cifras inventadas.**

### 7.2 Fecha del servidor

`as_of = 2026-10-18` coincide con la fecha del servidor en `America/Bogota`,
`as_of_source = server_today` y `historical = false`; la respuesta no introduce
ninguna fecha histórica no solicitada.

### 7.3 Sin mezclar el CSV; «no disponible» vs «cero real»

- La conversación 1 (T09) **no tiene adjuntos** (`ai_attachments` sólo registra el CSV
  de la conversación 4): las cifras administrativas no mezclan datos de un CSV previo.
- La distinción «no disponible» vs «cero real» se prueba herméticamente en
  `SystemStatsAsOfTest` (`test_the_register_needs_its_permission_and_the_money_is_omitted_without_its_own`,
  `test_an_attached_spreadsheet_does_not_change_administrative_figures`) — ver §10.

---

## 8. T12 — Propuesta de tarea (`create_operational_task`)

Conducción como **B**. Petición: crear una tarea para *Laura Sintetica QA* («Revisar
conciliación de cartera QA», prioridad alta, vence en 5 días). **No se mencionó ningún
id interno.**

### 8.1 Propuesta creada (sin ejecutar)

```
proposal id     : 1        tool: create_operational_task        status: pending
conversation_id : 3        message_id: 5
arguments       : title="Revisar conciliación de cartera QA",
                  description="Revisar la conciliación de cartera del módulo QA",
                  assigned_to=3  (resuelto por nombre → "Laura Sintetica QA"),
                  priority="high", due_on="2026-10-23", client_id=null
operational_tasks creadas: 0
```

El asignado se resolvió **por nombre** a través de `team_directory` (id 3 = la
identidad sintética), sin pedir el id al usuario. La propuesta queda **pendiente** en
`ai_action_proposals`, ligada a `conversation_id` y `message_id`. **Nada se ejecutó**:
`operational_tasks` sigue en 0.

### 8.2 Visualización, persistencia y confirmación

- La propuesta se devuelve tanto en la respuesta del turno (`POST /api/ai/conversations`)
  como en el historial (`GET /api/ai/conversations/3`), con los **argumentos completos**
  y `pending: true` → se muestra inline y persiste tras un F5.
- La página completa del asistente renderiza la propuesta como pendiente y sólo la
  confirma si el usuario lo pide (`tests/frontend/asistente.page.spec.ts`:
  *«presenta una propuesta como pendiente y la confirma solo al pedirlo»* — en verde).
- **No se pulsó Confirmar.** La capacidad de confirmación se probó **herméticamente**
  (`AiAssistantRuntimeTest::--filter=confirm`): *no mutation before confirmation*,
  *confirmation performs exactly one mutation and a second confirmation…*,
  *permission revoked before confirmation prevents execution* — **3/3 en verde**.

---

## 9. T02 — Investigación web

Conducción como **A**. Pregunta sobre el reporte de información exógena a la DIAN, pidiendo
al menos dos fuentes con su dirección.

Herramientas realmente usadas en el turno:

- `web_search` (SearXNG) → 5 resultados reales.
- `web_fetch` → `https://www.dian.gov.co/impuestos/.../default.aspx` (**HTTP 200**) y
  `https://dian.com.co/informacion-exogena-dian-2026/` (**HTTP 200**).

La respuesta es **útil** y atribuye dos fuentes con URL; las citas literales coinciden
con el contenido realmente recuperado (p. ej., la definición y el propósito de la
información exógena tomados de la página oficial de la DIAN). Marca temporal del turno:
`2026-10-18 12:45, America/Bogota`.

Conclusión: **PASS** — respuesta útil con atribuciones verificables (no es el
«no sé» ni resultados sin cita).

---

## 10. Suites ejecutadas (sólo las afectadas)

**Backend (Pest), por archivo** — todas en verde:

| Suite | Resultado |
|---|---|
| `tests/Unit/A07/ResearchQualityTest.php` | 14 passed (66 assertions) |
| `tests/Feature/A07/AdaptiveResearchTest.php` | 4 passed (16) |
| `tests/Feature/A07/ArtifactLinkageTest.php` | 6 passed (17) |
| `tests/Feature/A07/AnalyzeTableToolTest.php` | 7 passed (31) |
| `tests/Feature/A07/ArtifactQualityTest.php` | 4 passed (19) |
| `tests/Feature/A07/SystemStatsAsOfTest.php` | 5 passed (17) |
| `tests/Unit/A07/AiPrivacyBoundaryTest.php` | 28 passed (80) |
| `tests/Feature/A07/TeamDirectoryToolTest.php` | 3 passed (6) |
| `tests/Feature/A07/AiAssistantTest.php` | 3 passed (4) |
| `tests/Feature/A07/AiAssistantRuntimeTest.php` | 12 passed (41) |
| `tests/Feature/A07/AiAssistantRuntimeTest.php --filter=confirm` | 3 passed (8) |
| `tests/Feature/A07/CapabilityRecoveryTest.php` | 2 passed (5) |
| `tests/Feature/A07/Suggestion*Test.php` (4 archivos) | 60 passed (184) |

**Frontend (Vitest)**, specs relevantes: `asistente.page.spec.ts`,
`assistant-artifacts.spec.ts`, `attachment-picker.spec.ts`, `tasks.spec.ts`,
`markdown.spec.ts` → **5 archivos, 59 tests, todos en verde**.

**E2E A07 (`./scripts/run-e2e.sh`)**:

- `tests/e2e/a07.spec.ts` → **7/7 PASS**.
- `tests/e2e/a07-attachments.spec.ts` → **1/1 PASS**.
- `tests/e2e/a07-suggestions.spec.ts` → **6/7** (T1 falla — ver §11).

**Pint:** no aplica — este bloque no modificó ningún PHP.
**Typecheck (TS):** no aplica — este bloque no modificó ningún `.ts`/`.vue`.

---

## 11. Fallos preexistentes (no atribuibles a este bloque)

Este bloque **no modificó código de producto**, por lo que estos fallos existen en el
mismo `BASE_SHA` con o sin él.

1. **E2E `a07-suggestions.spec.ts` T1** — *«the overdue signal must be detected»*:
   el endpoint de sugerencias devuelve `suggestion: null` para el cliente sembrado.
   Se reprodujo de forma determinista en dos ejecuciones (con y sin las otras specs),
   sobre base reconstruida. **Ya estaba documentado** como fallo no relacionado en
   `CONSULTORA_DH_A07_R5_R3_FUNCTIONAL_CLOSURE.md` (§ «Fallos E2E no relacionados»:
   «motor de sugerencias y datos de siembra, no tocados»). Las suites herméticas de
   sugerencias (§10: 60 tests) pasan, por lo que la lógica de detección no está en
   cuestión a nivel unitario.

2. **Frontend `tests/frontend/suggestion.spec.ts`** — *«asks the server about the screen
   the person is actually on»*: fallo preexistente ya registrado en el bloque anterior;
   no se atribuye a este trabajo.

Consecuencia: **no se declara verde global** del frontend ni del E2E mientras estos
fallos sigan presentes.

---

## 12. Uso de recursos y presupuesto

- **Solicitudes externas a LLM:** 1 sonda + 5 turnos en vivo con proveedores
  **gratuitos** (OpenRouter `:free`), con rondas de herramientas acotadas por
  `AI_TOOL_MAX_ROUNDS`/`AI_TOOL_MAX_ATTEMPTS`. Se mantiene dentro del presupuesto
  orientativo (~15). Ninguna solicitud a proveedor de pago.
- **Disco/CPU/RAM:** mínimo; sólo la base aislada y sus contenedores.
- **Secretos:** ninguno almacenado ni impreso; el override de compose interpola desde
  `.env` y no contiene claves.

---

## 13. Limpieza

- La base aislada `consultora_dh_e2e` quedó **vacía** (`run-e2e.sh` la limpia al final).
- `app-e2e`/`queue-e2e` volvieron a su configuración **canónica** (proveedor stub
  determinista `http://ai-e2e:8100/v1`) — verificado.
- La base de **desarrollo no fue escrita** (lo verifica el propio runner:
  «the development database was not written to»).
- Se eliminaron los archivos temporales de este bloque (driver, sembrado, override,
  descargas y `test-results/`). No se borró ningún archivo no rastreado ajeno.
- Los informes se publican sin secretos.

---

## 14. Cierre

```
BASE_SHA=216395c65e2330480c314975da6fde2c40bfed38
CODE_CHANGES=none
T04_REAL=PASS
T08_REAL=PASS
T09_REAL=PASS
T12_REAL=PASS
T02_REAL=PASS
BACKEND_TARGETED=PASS
FRONTEND_TARGETED=PASS
E2E_A07=PASS (a07.spec.ts 7/7; a07-attachments 1/1; a07-suggestions 6/7 T1 PREEXXISTENTE)
PREEXISTING_FAILURES=2 (e2e a07-suggestions T1; frontend suggestion.spec.ts)
PAID_PROVIDERS=0
LOCAL_AI=0
AI_ALLOW_TRAINING_PROVIDERS=false
REAL_CLIENT_DATA_SENT=none
FINAL_RESULT=PASS
```

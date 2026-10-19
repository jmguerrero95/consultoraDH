# CONSULTORA DH — A07-DETERMINISTIC-SAFETY-CLOSURE

**Bloque:** A07-DETERMINISTIC-SAFETY-CLOSURE — cierre de los **dos únicos riesgos reales**
que dejó abiertos la auditoría independiente del bloque anterior:
(A) T12 no tenía una garantía **determinista del lado servidor** sobre el responsable, y
(B) `AiToolCallLeak::extract` aceptaba/normalizaba sobres de llamada acompañados de prosa
aunque el contrato dice que sólo un sobre puro y completo es ejecutable.
**BASE_SHA:** `0b0e3ba7442e50e65b60fbb1f50823d7cf88a5fa`
**FINAL_CODE_SHA:** `0e56303bcc82accab5aa34b11d9b226f67e7ac26`
**ORIGIN_MAIN_TIP:** `(SHA del commit de publicación de este informe; ver bloque de cierre)`
**Fecha:** 2026-10-19
**Alcance:** corregir **sólo** A y B, con pruebas herméticas nuevas. **No** se repitió la
auditoría general, **no** se repitieron turnos LIVE, **no** se gastó cuota de proveedores
externos, **no** se reimplementó el asistente, **no** se cambiaron esquemas ni reglas de
negocio donde bastaba una alternativa menor.

> **Cero solicitudes a proveedores externos en este bloque.** Todas las llamadas de las
> pruebas nuevas van contra `Http::fake`; la suite fuerza además
> `Http::preventStrayRequests()`, de modo que una fuga de red haría fallar la prueba en
> lugar de gastar cuota. `AI_ALLOW_TRAINING_PROVIDERS=false` intacto; `.env` sin tocar.

---

## 0. Estado verificado antes de cambiar código

| Comprobación | Resultado |
|---|---|
| `git rev-parse HEAD` | `0b0e3ba7442e50e65b60fbb1f50823d7cf88a5fa` |
| `git rev-parse origin/main` | `0b0e3ba7442e50e65b60fbb1f50823d7cf88a5fa` |
| Rama | `main` (HEAD == origin/main == BASE_SHA) |
| Informes previos | `CONSULTORA_DH_A07_EXACT_ACCEPTANCE.md`, `CONSULTORA_DH_A07_RUNTIME_RELIABILITY_CLOSURE.md` leídos |
| Árbol de trabajo | sólo los archivos **preexistentes** ya sucios + 3 informes ajenos no rastreados (nada de este bloque) |

Nada se ejecutó contra el entorno LIVE: no había necesidad, porque ambos defectos son
**deterministas y reproducibles herméticamente**, y el bloque anterior ya dejó la evidencia
LIVE que aquí se conserva (§7).

---

## 1. Hallazgo A — el responsable de una tarea es una regla de servidor

### 1.1 El defecto, en una frase

El turno 1 de T12 no nombraba a nadie y aun así produjo una propuesta asignada a **la
persona que preguntaba**. La única barrera era una instrucción en el *system prompt*
(bloque `RESPONSABLE DE UNA TAREA`): **guía, no garantía**. Un modelo que la ignore crea
la fila igual.

### 1.2 El cambio

Nuevo `app/Services/Ai/TaskAssigneeGuard.php` (servicio sin estado), invocado desde la rama
de escritura de `AiAssistantService` **después** de `AiToolRegistry::proposeWrite`
—es decir, después de la validación del propio validador y del RBAC— y **antes** de que
exista la fila:

```
$normalized = $this->registry->proposeWrite($toolName, $args, $user);   // valida + autoriza
$violation  = $this->assigneeGuard->violation($toolName, $normalized, $user, $conversation, $question);
if ($violation !== null) { /* error de herramienta + directiva de preguntar; NO se crea fila */ }
$proposal = AiActionProposal::query()->create([...]);                   // sólo si está justificado
$this->supersedeSameTaskProposals($proposal);                           // §2
```

Regla exacta (`violation()` devuelve `null` = justificado):

| # | Condición sobre la petición que lleva la propuesta | Decisión |
|---|---|---|
| 0 | `assigned_to` ausente o ≤ 0 | **Rechaza** (sigue siendo obligatorio en el validador) |
| 1 | Exactamente **un** miembro del personal activo aparece por su **nombre completo** | **Permite sólo si** `assigned_to` == esa persona; si no, rechaza |
| 1b | **Dos o más** miembros aparecen por su nombre completo | **Rechaza** → ambigüedad: preguntar |
| 2 | La persona pide la tarea **para sí misma** en frases explícitas | **Permite sólo si** `assigned_to` == quien pide; si no, rechaza |
| 3 | Existe una propuesta **pendiente de la MISMA tarea** con ese mismo `assigned_to` | **Permite** (es una corrección que conserva al responsable) |
| — | Ninguna de las anteriores | **Rechaza** → falta el responsable: preguntar |

Propiedades que se derivan de la tabla:

- **Nunca autoselecciona** un responsable: no hay rama que elija nada por sí sola.
- **Nunca asume que quien pide es el responsable**: la rama 2 exige petición explícita de
  autoasignación; la rama 1 exige que el nombre aparezca escrito.
- **Nunca hereda un nombre de otra tarea**: la rama 3 sólo mira propuestas de la **misma
  tarea** en la **misma conversación**; un nombre usado en una tarea anterior no justifica
  una nueva (probado en `test_a_name_from_an_unrelated_task_is_not_inherited`).
- **Aislamiento**: la rama 3 consulta `where('ai_conversation_id', $conversation->id)`, por
  lo que una propuesta de otra conversación no justifica nada (probado en
  `test_another_conversations_proposal_never_justifies_this_one`).

### 1.3 Por qué la coincidencia de nombre es integra y por qué no hay clasificador frágil

- Se normalizan ambos lados (sin acentos, minúsculas, espacios colapsados) con
  `Str::ascii` + `mb_strtolower`, y se compara el **nombre completo** anclado en fronteras
  de letra/número: `(?<![\p{L}\p{N}])NOMBRE(?![\p{L}\p{N}])`.
- Un **fragmento no es un nombre**: «para Laura» no coincide con «Laura Sintetica QA» y por
  tanto **pregunta** en vez de adivinar (`test_a_name_fragment_does_not_match_anybody`).
- No se usan umbrales de similitud, ni listas de apodos, ni coincidencia parcial: es la
  regla **más laxa que todavía acepta sólo un nombre que la persona escribió**.
- Consecuencia asumida y deseable: **dos personas distintas con el mismo nombre completo**
  producen ambigüedad → se pregunta. Es la respuesta correcta y segura; se documenta aquí
  porque afecta al diseño de pruebas (ver §5.4).

### 1.4 Qué NO se cambió (invariantes preservados)

- `assigned_to` sigue **obligatorio** (`CreateOperationalTaskTool` intacto, sin valores por
  defecto).
- `TaskAssignee::assertAssignable` sigue autorizando a la persona (personal activo, nunca
  una cuenta de portal).
- **RBAC**: se comprueba **antes** (en `proposeWrite`); un usuario sin `tasks.create` no
  llega nunca a la fila (probado en `test_self_assignment_still_needs_the_permission`).
- **Confirmación humana**: el guardián no ejecuta nada; sigue creando sólo propuestas, y
  `AiAssistantController::confirm()` sigue exigiendo `status === PENDING`, propiedad de la
  propuesta y `expires_at` vigente. Ninguna escritura administrativa ocurre sin confirmar
  (probado: `OperationalTask::count() === 0` tras crear la propuesta).
- **Historial**: sin cambios; las propuestas siguen enlazándose al mensaje de usuario del
  turno.
- **Esquema**: sin migraciones, sin columnas nuevas, sin cambios de modelos.

### 1.5 El prompt conserva su bloque, pero ya no es la garantía

El bloque `RESPONSABLE DE UNA TAREA` de `systemPrompt()` se mantiene (ayuda a que el modelo
pregunte de forma natural en el primer intento). La diferencia de este bloque es que ahora
la invariante **no depende de que el modelo lo obedezca**.

---

## 2. Hallazgo A #10 — dos propuestas de la misma tarea no pueden confirmarse las dos

`AiAssistantService::supersedeSameTaskProposals()`: al crear una propuesta de
`create_operational_task`, se cierran las propuestas **pendientes** de la **misma tarea**
en la **misma conversación**, entendiendo «misma tarea» por la **identidad de la tarea**
(`TaskAssigneeGuard::identity()`: el `title`, normalizado sin acentos y en minúsculas).

- La anterior pasa a `EXPIRED` con `expires_at = now()`.
- `confirm()` ya exigía `status === PENDING`, así que **una propuesta reemplazada no es
  confirmable** sin cambiar el esquema.
- `proposalsFor()` (historial) ya excluye `EXPIRED`, así que la reemplazada desaparece de
  la lista de acciones ofrecidas.
- **Las propuestas de tareas distintas no se afectan**: la identidad es el `title`, y sólo
  se consideran filas del mismo `tool` en la misma conversación.
- Si la corrección **cambia** el responsable sin nombrar a la persona nueva, la rama 3 del
  guardián no aplica y la propuesta se **rechaza**: no se puede cambiar de dueño «por
  aproximación».

---

## 3. Hallazgo B — sólo el sobre puro y completo es una llamada

### 3.1 El defecto

`AiToolCallLeak::extract()` devolvía `COMPLETE` con la prosa sobrante
(`return self::complete($calls, $prose);`) cuando encontraba un sobre completo **rodeado de
texto**. El contrato dice que sólo un sobre puro y completo es ejecutable: prosa + sobre es
indistinguible de una **cita**, un **ejemplo** o texto que el modelo *escribió sobre* una
llamada, y ejecutarla es ejecutar una cita.

### 3.2 El contrato único y seguro

Se **endurece** el contrato en lugar de justificar un preámbulo: **la llamada textual sólo
se normaliza si ES el mensaje completo** (tras recortar espacios).

| Entrada | Antes | Ahora | Ejecuta | Se muestra |
|---|---|---|---|---|
| `<tool_call><function=…>params</function></tool_call>` (mensaje completo) | `COMPLETE` | `COMPLETE` | **Sí**, por el registro real | No |
| `<function=…>params</function>` (mensaje completo, sin envoltorio) | `COMPLETE` | `COMPLETE` | **Sí**, por el registro real | No |
| Prosa + sobre completo | **`COMPLETE`** | **`MALFORMED`** | **No** | **No** |
| Sobre dentro de un bloque de código / «ejemplo» | `MALFORMED`/`COMPLETE` según caso | `MALFORMED` | **No** | **No** |
| Sobre citado dentro de prosa (p. ej. `` `…` ``) | — | `MALFORMED` | **No** | **No** |
| Texto **dentro** del envoltorio junto a la función | `COMPLETE` | **`MALFORMED`** | **No** | **No** |
| Sobre incompleto, o llamada a herramienta no ofrecida | `MALFORMED` | `MALFORMED` | **No** | **No** |
| Prosa que sólo **menciona** `<tool_call>` (sin `<function=`) | `NONE` | `NONE` | No | Sí (es la respuesta) |

Cómo se implementa (constantes ancladas de `AiToolCallLeak`):

- `PURE_WRAPPER` — `^<tool_call>…</tool_call>$` sobre el mensaje recortado;
- `PURE_FUNCTION` — `^<function=NAME>params</function>$`, aplicada **también al interior**
  del envoltorio, de modo que un envoltorio con texto extra dentro tampoco es una llamada;
- `INVOCATION` — `<\s*function\s*=` en cualquier otra posición ⇒ `MALFORMED`;
- en caso contrario, `NONE`.

### 3.3 Preferencia por las llamadas estructuradas y ejecución sin atajos

- La ruta textual sólo se evalúa **cuando el proveedor no devolvió ninguna llamada
  estructurada** (`if ($toolCalls === [])`). Una llamada estructurada **siempre tiene
  prioridad** y no pasa por el reconocedor.
- Una llamada aceptada se envuelve como `AiToolCall` y sigue **exactamente** el camino
  existente: debe ser una herramienta **ofrecida** en el turno, sus argumentos pasan por el
  **validador de la propia herramienta**, y aplican **RBAC, autorización del asignado,
  SSRF, privacidad, deduplicación y presupuesto del turno** sin ninguna excepción.
- `AiToolCallLeak` **no ejecuta nada**: sólo reconoce.

### 3.4 Ambigüedad: no ejecutar, no mostrar, no fabricar

Toda entrada `MALFORMED` sigue el camino ya existente:

1. **no se ejecuta**;
2. `strip()` quita el sobre del texto, así que **nunca se entrega el sobre como respuesta**
   (la prosa restante tampoco: es texto de un resultado inválido);
3. se aplica la **recuperación acotada existente** —**una** corrección por turno que pide
   al modelo llamar a la herramienta por el mecanismo estructurado o responder con lo que
   ya tiene—;
4. si no hay margen para corregir, el turno sintetiza con lo que ya posee o **falla de
   forma explícita** (`invalid_tool_call`); **nunca fabrica** una respuesta ni una cita.

### 3.5 Justificación del criterio de seguridad

No se acepta ningún preámbulo porque **la frontera entre «el modelo emitió una llamada» y
«el modelo habló de una llamada» no es observable en el texto**: un ejemplo, una cita de
documento, un fragmento de código que se muestra al usuario y una llamada real tienen la
misma forma. La única señal estructural fiable es que la llamada **ocupe todo el mensaje**,
que es lo que hace un modelo cuando devuelve una llamada como texto. Cualquier regla más
permisiva (prefijos admitidos, «si el sobre está al final», «si la prosa es corta»)
ejecutaría ejemplos o citas. Por eso el criterio es el más estricto posible: **sobre puro y
completo, o no es una llamada**.

---

## 4. Matriz de pruebas herméticas exigidas (T12)

`tests/Feature/A07/TaskAssigneeGuardTest.php` — **16 pruebas / todas PASS**. Ninguna toca la
red: el proveedor está *faked* y todo lo demás (registro, validador, RBAC, base de datos,
servicio real) es real.

| Requisito del encargo | Prueba |
|---|---|
| Turno 1 sin responsable ⇒ **sin propuesta** | `test_a_request_that_names_nobody_creates_no_proposal` |
| El modelo asigna al solicitante sin permiso explícito ⇒ **rechazado** | `test_assigning_to_the_person_who_asked_without_being_told_is_refused` |
| El modelo asigna a alguien no nombrado ⇒ **rechazado** | `test_assigning_to_somebody_the_request_never_named_is_refused` |
| «Asígnamela a mí» ⇒ **autoasignación válida si RBAC lo permite** | `test_taking_the_task_oneself_is_valid_when_asked_for_explicitly` |
| La autoasignación **sigue exigiendo el permiso** | `test_self_assignment_still_needs_the_permission` |
| «Asígnala a Laura Sintetica QA» ⇒ **validado en el directorio** | `test_a_staff_member_named_in_full_is_validated_and_used` |
| Sólo la persona nombrada puede ser el responsable | `test_the_named_person_is_the_only_assignee_the_turn_may_use` |
| Nombre **ambiguo** ⇒ se pide aclaración | `test_two_people_named_at_once_ask_before_proposing` |
| **Fragmento** de nombre ⇒ no coincide, se pregunta | `test_a_name_fragment_does_not_match_anybody` |
| Turno 2 **conserva título, prioridad y vencimiento** | `test_the_second_turn_keeps_the_title_priority_and_due_date` |
| Propuesta nueva **reemplaza** la antigua cuando es corrección | `test_a_correction_replaces_the_proposal_for_the_same_task` |
| Corregir el responsable **exige nombrar** al nuevo | `test_a_correction_that_changes_the_assignee_must_name_the_new_person` |
| Propuesta reemplazada **no confirmable** | `test_a_superseded_proposal_can_no_longer_be_confirmed` |
| Dos tareas **independientes** no se invalidan | `test_proposals_for_different_tasks_do_not_invalidate_each_other` |
| Un nombre de **otra tarea** no se hereda | `test_a_name_from_an_unrelated_task_is_not_inherited` |
| **Sin acceso entre usuarios/conversaciones** | `test_another_conversations_proposal_never_justifies_this_one` |
| **Sin escritura administrativa sin confirmación** | incluido: tras crear la propuesta, `OperationalTask::count() === 0`; y la confirmación de la reemplazada devuelve **400** mientras la vigente devuelve **200** y crea **1** tarea |

### 4.1 Evidencia de integración de B (no sólo del parser)

| Prueba | Qué demuestra |
|---|---|
| `tests/Feature/A07/MalformedToolCallRecoveryTest::test_a_complete_leaked_call_is_executed_by_the_registry_and_never_shown` (positiva) | Un sobre **puro** se ejecuta por el **registro real** (búsqueda web realmente lanzada) y **no** se muestra |
| `…::test_an_envelope_fenced_as_an_example_is_never_executed` (negativa) | Un ejemplo en bloque de código **no** ejecuta nada, **no** es la respuesta y provoca **una** corrección acotada |
| `…::test_an_envelope_preceded_by_prose_is_never_executed` (negativa) | **El defecto exacto de la auditoría**: prosa + sobre ⇒ **cero** ejecuciones, la prosa no se entrega, una corrección |
| `tests/Unit/A07/AiToolCallLeakTest` (9→**12** casos) | `…prose around a complete envelope is malformed and never executed`, `…fenced as an example…`, `…quoted from a document…`, `…text inside the wrapper beside the function…`, además de los positivos puros |

---

## 5. Ejecución dirigida, estilo y notas de entorno

### 5.1 Pest dirigido (sólo lo cambiado y su vecindario)

| Ejecución | Resultado |
|---|---|
| `tests/Feature/A07/TaskAssigneeGuardTest.php` | **16 PASS** |
| `tests/Unit/A07` + `TaskAssigneeGuardTest` + `AiAssistantRuntimeTest` + `MalformedToolCallRecoveryTest` + `OperationalTaskContinuityTest` + `AiErrorScopeAndFailoverTest` + `CapabilityRecoveryTest` | **164 PASS / 531 aserciones** (31.5 s) |
| `AiToolCallLeakTest` + `AiReplySanitizerTest` + `AttachmentToAssistantTest` | **28 PASS / 85 aserciones** |

No se volvió a ejecutar la suite A07 completa (§6, según el encargo).

### 5.2 Estilo

`./vendor/bin/pint --test` **limpio** en los 7 archivos tocados. No se reformateó ningún
archivo ajeno al bloque.

### 5.3 Ajuste menor necesario en pruebas existentes (no es debilitar aserciones)

`tests/Feature/A07/AiAssistantRuntimeTest.php` ejercitaba mecánica de propuestas/confirmación
preguntando «Crea una tarea» **sin nombrar a nadie** y esperando una propuesta: exactamente
la conducta que el hallazgo A prohíbe. Se ajustó **el montaje** (la petición ahora nombra al
responsable, `taskQuestion()`), y **no** las aserciones, que siguen comprobando lo mismo. Los
casos de llamada malformada y de escritura no autorizada se dejaron intactos y siguen
rechazándose por el validador y por RBAC, respectivamente.

### 5.4 Nota: el responsable necesita un nombre único en esas pruebas

`AiAssistantRuntimeTest` es una de las suites que **no** usan `RefreshDatabase` (corre sobre
`consultora_dh_test` sin limpiar). Como el guardián trata un nombre completo **duplicado**
como ambiguo —y hacerlo es lo correcto— el responsable de esas pruebas recibe ahora un nombre
no repetible entre ejecuciones. Esto **no** es un requisito artificial: con dos compañeros
llamados igual, preguntar es la respuesta segura.

### 5.5 Sin cambios de esquema ni de reglas de negocio

No hay migraciones, ni cambios en modelos, ni en el contrato de las herramientas, ni en los
endpoints. La propuesta sigue enlazándose al mensaje de usuario del turno y sigue sin
ejecutarse sin confirmación.

---

## 6. Fallos preexistentes conocidos (no tocados en este bloque)

`A07_SUITE_REGRESSIONS`: los **14 fallos preexistentes** documentados en el bloque anterior
siguen siendo los mismos y **no se han corregido aquí** (el encargo lo pide expresamente):

| Suite | Nº |
|---|---|
| `AnalyzeTableToolTest` | 1 |
| `ArtifactQualityTest` | 1 |
| `AssistantTaskKindsTest` | 5 |
| `DocumentPipelineTest` | 4 |
| `TaskRetryAndIsolationTest` | 3 |

**Causa:** contaminación entre pruebas — esas suites usan la **cola Redis real**, y las filas
que un *worker* confirma sobreviven al `rollback` de la prueba. Cada archivo **pasa aislado**
(antes y después de este bloque).

**Recomendación de aislamiento para el próximo ciclo de QA (registrada, no aplicada aquí):**

1. Fijar `QUEUE_CONNECTION=sync` en `phpunit.xml` (o por suite, con `#[WithConfig]`) para
   estas cinco suites, de modo que no haya *worker* asíncrono tocando la base de pruebas; o
   bien
2. Ejecutarlas **en serie**, después de un `FLUSHDB` explícito de la base Redis de pruebas, y
   nunca en paralelo con el resto de A07; o bien
3. Migrarlas a `Queue::fake()` / `Bus::fake()` con `assertPushed`, que es lo que ya hacen las
   suites A07 nuevas.
4. Mientras tanto, **no** declarar que la suite global pasa: este bloque sólo afirma lo que
   ejecutó (§5.1).

---

## 7. Evidencia LIVE conservada (no repetida en este bloque)

| Escenario | Resultado previo | Estado aquí |
|---|---|---|
| **T02** | **BLOCKED** — agotamiento de cuota/transporte de proveedores gratuitos en la ronda 4; fallo controlado (503). Hermético **PASS** | Sin cambios; no repetido |
| **T04** | **PASS** — PDF real `guia-basica-seguridad-informatica.pdf` (29 297 bytes, `%PDF-1.7`), descargable y enlazado al mensaje | Conservado |
| **T08** | **PASS** | Conservado |
| **T09** | **PASS** | Conservado |
| **T12** | **PARTIAL** — continuidad del turno 2 **PASS**; el turno 1 autoasignó al solicitante (el defecto que este bloque cierra de forma determinista) | Cerrado en código y probado herméticamente; **sin** turno LIVE nuevo |

No se repitieron T05/T07 ni ningún otro turno LIVE.

---

## 8. Garantías preservadas

- **RBAC**: sin cambios; se evalúa antes que el guardián.
- **Autorización del asignado** (`TaskAssignee`): sin cambios.
- **SSRF, privacidad (`AiPrivacyGate`), deduplicación, presupuesto del turno**: sin cambios;
  las llamadas aceptadas siguen pasando por todos ellos.
- **Confirmación humana**: ninguna escritura administrativa sin confirmar (§4).
- **Aislamiento por usuario/conversación**: el guardián y la sustitución están acotados a
  `ai_conversation_id`; las pruebas cubren el cruce.
- **Historial**: sin cambios de estructura; la propuesta reemplazada simplemente deja de
  listarse.
- **Cero fabricación**: ni respuestas, ni citas, ni responsables inventados.
- **Prohibiciones respetadas**: nada de `git reset --hard`, `git clean -fd`, `git push
  --force`, `docker compose down -v`, `migrate:fresh` sobre datos reales; `.env`, datos
  reales, Redis de desarrollo, volúmenes ajenos, archivos no rastreados ajenos y los
  informes existentes quedaron intactos. Push **fast-forward**.

---

## 9. Límites honestos

1. **Sin turno LIVE nuevo.** El cierre de A es determinista y está probado herméticamente
   (16 casos); no se gastó cuota externa. La evidencia LIVE de T12 sigue siendo la del bloque
   anterior (continuidad PASS, turno 1 autoasignado).
2. **La suite A07 completa no se re-ejecutó** (según el encargo). Por tanto **no** se afirma
   que la suite global pase: se afirma que los archivos dirigidos listados en §5.1 pasan y
   que los 14 fallos preexistentes siguen documentados sin corregir (§6).
3. **Nombre duplicado ⇒ ambigüedad.** Si dos miembros del personal comparten nombre completo,
   el asistente **preguntará**. Es deliberado (seguro), no un defecto.
4. **Sustitución por `title`.** La identidad de la «misma tarea» es el título normalizado; dos
   tareas realmente distintas con el **mismo** título en la misma conversación se tratan como
   la misma. Es la única identidad que la escritura lleva hoy, y evita tocar el esquema (el
   encargo pide la alternativa menor).

---

## 10. Cierre

```
BASE_SHA=0b0e3ba7442e50e65b60fbb1f50823d7cf88a5fa
FINAL_CODE_SHA=0e56303bcc82accab5aa34b11d9b226f67e7ac26
ORIGIN_MAIN_TIP=(SHA del commit de publicación de este informe)
UNSPECIFIED_ASSIGNEE_GUARD=SERVER_SIDE (TaskAssigneeGuard; sin propuesta si falta el responsable)
EXPLICIT_SELF_ASSIGNMENT=ONLY_ON_EXPLICIT_REQUEST (frases de autoasignación + RBAC)
T12_TWO_TURN_CONTINUITY=PASS (hermético: título, prioridad y vencimiento conservados)
STALE_PROPOSAL_NOT_CONFIRMABLE=PASS (EXPIRED; confirm() → 400; la vigente → 200)
NO_BUSINESS_WRITE_WITHOUT_CONFIRMATION=PASS (OperationalTask=0 tras la propuesta)
LEAKED_TOOL_EXAMPLE_NOT_EXECUTED=PASS (prosa/bloque de código/cita ⇒ 0 ejecuciones)
PURE_TOOL_CALL_HANDLING=COMPLETE_ONLY_IF_WHOLE_MESSAGE (sigue por el registro real)
MALFORMED_CALL_SAFE_RECOVERY=PASS (1 corrección acotada; nunca como respuesta)
RBAC_SSRF_PRIVACY_PRESERVED=YES
TARGETED_TESTS=16+164+28 PASS (Pint limpio en 7 archivos)
KNOWN_14_SUITE_FAILURES=DOCUMENTED (AnalyzeTableTool 1, ArtifactQuality 1, AssistantTaskKinds 5,
  DocumentPipeline 4, TaskRetryAndIsolation 3; aislamiento recomendado, no aplicado)
EXTERNAL_LLM_REQUESTS=0
T02_LIVE=BLOCKED_PREVIOUS
T04_LIVE=PASS_PREVIOUS
T08_LIVE=PASS_PREVIOUS
T09_LIVE=PASS_PREVIOUS
FINAL_RESULT=PASS
```

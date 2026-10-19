# CONSULTORA DH — A07-RUNTIME-RELIABILITY

**Bloque:** A07-RUNTIME-RELIABILITY (corrección quirúrgica de los tres defectos reales de
`CONSULTORA_DH_A07_EXACT_ACCEPTANCE.md`: T04, T02, T12)
**BASE_SHA:** `740d5c9a67ee46a8e847206031deef9da6ed5cde`
**FINAL_CODE_SHA:** `d01190f9a50db52f090e5ffb4d6e7d01d8635ffc`
**Fecha:** 2026-10-18
**Alcance:** reparar los tres defectos demostrados —T04 (negativa genérica sin PDF),
T02 (`<tool_call>` malformado entregado como respuesta) y T12 (falta de responsable→
propuesta)— con pruebas herméticas para cada clasificación y con los turnos LIVE exactos
de la matriz. **No** se reinició el proyecto, **no** se reimplementó A07 y **no** se
repitieron T05/T07/T08/T09.

> Entorno aislado **reutilizado** (`consultora_dh_e2e` + `app-e2e`/`nginx-e2e`/
> `queue-e2e`), no recreado. Identidades sintéticas y RBAC real a nivel de usuario.
> `AI_ALLOW_TRAINING_PROVIDERS=false` preservado. Proveedores **gratuitos** únicamente.

---

## 1. Resultado por escenario

| Escenario | Defecto de la matriz | Clasificación | Hermético | LIVE |
|---|---|---|---|---|
| **T04** | Negativa genérica, sin PDF | **C** (negativa improductiva) | **PASS** | **PASS** |
| **T02** | `<tool_call>` malformado como respuesta | salida de modelo inválida | **PASS** | **BLOCKED** (cuota upstream) |
| **T12** | Pidió el responsable, sin propuesta | **NEEDS_INPUT** válido | **PASS** | **PARTIAL** |
| T05/T07/T08/T09 | — | — | (no repetidos) | T08/T09 conservados |

**Presupuesto LIVE utilizado: 4 turnos exactos** (1 T04, 1 T02, 2 T12), las **solicitudes
literales** del informe de aceptación, sin sustituciones.

---

## 2. T04 — distinguir la negativa y recuperarla una sola vez

### 2.1 Diagnóstico

El turno LIVE falló porque `cloudflare:@cf/meta/llama-3.1-8b-instruct-fp8` contestó
`«Lo siento, pero no puedo cumplir con esa solicitud.»`. El código sólo reconocía una
negativa que **nombrara un archivo**; una negativa **genérica** se entregaba tal cual y el
modelo que acababa de negarse volvía a ser el primero en el siguiente intento.

### 2.2 Cambio

`app/Services/Ai/RefusalClassifier.php` (nuevo) clasifica la respuesta del modelo en las
cinco categorías exigidas, sin confundirlas:

| Caso de la matriz | `RefusalClassifier` | Recuperación |
|---|---|---|
| **A** negativa verificable de política de contenido | `POLICY` | **Terminal**. Nunca se recupera, nunca se salta a otro proveedor |
| **B** el modelo no sabe usar su herramienta | `CAPABILITY` | **Una** corrección acotada |
| **C** respuesta genérica improductiva | `UNPRODUCTIVE` (sólo si se pidió un **entregable de archivo** y no hay señal de política) | **Una** corrección acotada |
| **D** no hay herramienta autorizada | `NONE` (la puerta exige `create_artifact` **ofrecido y autorizado**) | No se recupera |
| **E** falta de presupuesto o cuota | no es una negativa: lo gestiona el resolutor (conmutación) y, si el pool se agota, un **fallo controlado** | — |

La recuperación es **exactamente una** por turno (`$recoveredFromRefusal`), y en esa
llamada el modelo que acaba de negarse se coloca **último** (`AiProviderResolver::complete(..., $avoidModel)`),
sin penalizarlo de forma permanente.

**Prioridad de modelos con evidencia de herramienta.** `AiModelHealth::markToolsDemonstrated()`
(+ TTL 3 600 s, sólo evidencia **positiva**) registra los modelos que emitieron una llamada
estructurada; en los turnos que requieren herramientas se ordenan **primero**
(`AiProviderResolver::ordered(..., $preferToolProven)`). Un solo fallo ambiguo nunca penaliza.

### 2.3 Verificación herméticas

`tests/Feature/A07/CapabilityRecoveryTest.php` (ampliado) y `tests/Unit/A07/RefusalClassificationTest.php`:

- A negativa de política → **1** petición, se entrega, sin artefacto.
- C negativa genérica con petición de PDF → corrección → herramienta → documento.
- C' negativa genérica **sin** entregable → no se corrige.
- B negativa de capacidad nombrada → corrección → herramienta (prueba preexistente).
- D sin `artifacts.create` → **1** petición, se entrega sin recuperar.
- E recuperación sin cuota (429) → `AiProviderException` retryable, **ningún** mensaje
  de asistente fabricado.
- La recuperación **no re-pregunta** al modelo que se negó (usa el siguiente candidato).

### 2.4 LIVE

```
HTTP 200
provider/model : groq:openai/gpt-oss-20b
artifacts      : [ id=1  guia-basica-seguridad-informatica.pdf  29 297 bytes ]
download       : HTTP 200  application/pdf  %PDF-1.7  (29 297 bytes)
historial (F5) : artifact vinculado al mensaje del asistente (message_id=2)
```

**T04_LIVE = PASS.** El defecto (negativa genérica sin documento) queda corregido y
verificado con un PDF real, descargable y persistido.

---

## 3. T02 — la llamada de herramienta malformada

### 3.1 Diagnóstico

El turno LIVE terminó con el propio bloque `<tool_call>\n<function=web_fetch>…
</parameter>\n</function>\n</tool_call>` **entregado como respuesta**: una respuesta sin
llamada estructurada se lee como texto y el saneador no conocía esa forma.

### 3.2 Cambio

`app/Services/Ai/AiToolCallLeak.php` (nuevo) reconoce el sobre textual con tres estados:

- `NONE` — texto normal (incluida una frase que **menciona** `<tool_call>`).
- `COMPLETE` — el sobre **puro y completo**. Sólo entonces se normaliza:
  - se valida que la herramienta esté **ofrecida** y **permitida**;
  - los argumentos se convierten a su tipo y los validan/ejecutan **el registro existente**
    (misma autorización RBAC, SSRF, privacidad, deduplicación y **mismo presupuesto** del turno);
  - la llamada normalizada recibe un `id` sintético local (`leak_N`) para poder
    emparejarse en el cable.
- `MALFORMED` — sobre incompleto, mezclado, con herramienta no ofrecida o con cuerpo que
  no son parámetros. **Nunca** se muestra como respuesta y **no** se ejecuta nada.

`AiAssistantService::processResult()` normaliza en la **cabeza del bucle** (no en el
proveedor), de modo que la ruta de ejecución es la de siempre. Si la llamada es
`MALFORMED`, se aplica **una** corrección acotada (`AiToolCallLeak::correctionDirective()`,
sin filtrar detalles internos); si no hay presupuesto, se **sintetiza sin herramientas**
cuando hay fuentes suficientes, y en su defecto se produce un **fallo controlado**. Nunca
se inventan respuestas ni citas, y **jamás** se ejecutan instrucciones de texto fuera del
mecanismo estructurado, autorizado y estrictamente validado.

### 3.3 Verificación herméticas

`tests/Unit/A07/AiToolCallLeakTest.php` y `tests/Feature/A07/MalformedToolCallRecoveryTest.php`
cubren los nueve casos exigidos: sobre completo ejecutado; sobre incompleto; herramienta
no ofrecida; argumentos inválidos; URL insegura (127.0.0.1 nunca se marca); prosa que
menciona la etiqueta sin ser invocación; límite de rondas agotado → síntesis sin
herramientas; síntesis correcta con fuentes suficientes; y ausencia de fugas internas
(la respuesta visible no contiene `<tool_call>`, `parameter=` ni el error crudo).

### 3.4 LIVE — causa exacta

```
HTTP 503  { "recoverable": true }
turno d257630fa9d9 : rondas=3 / límite=4  tool_calls=3  tools=["web_fetch"]
                     ended_by=provider_error  result=failed
```

Diagnóstico real (diagnósticos del resolutor, `eligible=10 pool=10`):

| Ronda | Candidato | Resultado |
|---|---|---|
| 1 | `groq:openai/gpt-oss-20b` | **ok** (`task=web`) |
| 2 | `groq:openai/gpt-oss-20b` | 429 **cuota de cuenta** → conmuta |
| 2 | `gemini-3.5-flash-lite` / `gemini-3.1-flash-lite` | 400 formato (capacidad) |
| 2 | `openrouter:google/gemma-4-31b-it:free` | 429 **cuota de modelo** |
| 2 | `openrouter:nvidia/nemotron-3.5-lightning:free` | **ok** |
| 3 | `openrouter:nvidia/nemotron-3.5-lightning:free` | **ok** |
| 4 | `openrouter:nvidia/nemotron-3.5-lightning:free` | **503 transporte (59 998 ms)** → sin candidatos |

**T02_LIVE = BLOCKED.** No fue el defecto del sobre malformado: el turno **ejecutó**
herramientas (`web_fetch`) y murió al agotarse los candidatos gratuitos por cuota/tiempo
de espera en la ronda 4. El producto hizo lo prescrito para **E** —conmutó de proveedor
(una recuperación funcional acotada) y, al no quedar candidatos, produjo un **fallo
controlado** (503 `recoverable`, sin respuesta ni cita fabricadas)—. La corrección del
sobre malformado queda probada herméticamente; su comprobación LIVE exigiría una ventana
de cuota gratuita.

### 3.5 Observación registrada (no corregida)

Cuando el **último** intento de una ronda falla por transporte, el turno termina en 503
en lugar de sintetizar sin herramientas con las fuentes ya recuperadas. Es el
comportamiento de fallo controlado que la matriz admite para E; **no se ha ampliado el
alcance** para cambiarlo (el defecto demostrado de T02 era el sobre malformado, no el
agotamiento del pool). Se documenta como limitación conocida.

---

## 4. T12 — el responsable que falta es una entrada válida

### 4.1 Diagnóstico

`create_operational_task` exige `assigned_to` (obligatorio, sin valor por defecto). El
enunciado exacto no nombra a nadie: la **pregunta** por el responsable es una respuesta
**funcionalmente válida** (`NEEDS_INPUT`), no un error. La continuación (turno 2) ya
resuelve el nombre por el directorio y propone la tarea: **no hacía falta tocar la lógica
de negocio**.

### 4.2 Cambio (sólo la guía que faltaba)

El turno LIVE 1 **auto-asignó la tarea a la persona que la pedía** (`assigned_to=2`, el
propio solicitante) en lugar de preguntar, violando el invariante «no auto-asignar al
usuario actual». La causa es de **guía**: la instrucción del sistema no decía qué hacer
cuando falta el responsable. Se añade al `systemPrompt()` un bloque acotado
`RESPONSABLE DE UNA TAREA` (ARCHIVO: `app/Services/Ai/AiAssistantService.php`):

- si la petición no nombra responsable, **preguntar** a quién asignarla;
- **no** elegir uno, **no** asignarla a quien pide la tarea, **no** dejarla sin responsable;
- resolver el nombre con `team_directory`;
- aclarar que esa pregunta **no** convierte «ACTÚA, NO ANUNCIES» en pedir permiso.

**No** se inventó un responsable, **no** se añadió lógica de auto-asignación y **no** se
hizo `assigned_to` opcional.

### 4.3 Verificación herméticas

`tests/Feature/A07/OperationalTaskContinuityTest.php` (2 turnos obligatorios):

- **Turno 1** (enunciado exacto) → la pregunta por el responsable se acepta; `proposals=[]`;
  `ai_action_proposals=0`; `operational_tasks=0`.
- **Turno 2** (`«Asígnala a Laura Sintetica QA.»`) → **una** propuesta `pending`,
  `tool=create_operational_task`, `assigned_to` = id real de Laura, título/prioridad/
  vencimiento conservados del turno 1, vinculada a la **conversación** y al **mensaje**
  del turno 2, presente en el historial, y **ninguna** `OperationalTask` creada.
- Guarda de regresión: la instrucción que recibe el modelo contiene la regla de responsable.

### 4.4 LIVE

```
Turno 1  (conv 3, msg 3) : propuesta 1 pending, assigned_to=2 (el solicitante)  ← elección del modelo previa a la guía
Turno 2  (msg 4)         : propuesta 2 pending, assigned_to=3 (Laura Sintetica QA)
                           título «Revisión de cartera de prueba», prioridad normal, vencimiento 2026-10-25
                           vinculada a conv 3 + message 4;  operational_tasks = 0
Historial (F5)           : devuelve las dos propuestas
```

**T12_LIVE = PARTIAL.** La **continuidad exigida pasa** (turno 2 resuelve/valida un
responsable real, conserva el contexto y crea una única propuesta pendiente vinculada a la
conversación y al mensaje correctos, sin crear ninguna tarea). El **turno 1** no se observó
como `NEEDS_INPUT` —el modelo propuso asignando al solicitante— y, agotado el presupuesto
de 2 turnos LIVE para T12, la re-ejecución del turno 1 con la guía nueva **no se verificó
en LIVE**; queda cubierta herméticamente.

---

## 5. Inventario de pruebas herméticas añadidas/ampliadas

| Archivo | Casos | Resultado |
|---|---|---|
| `tests/Unit/A07/RefusalClassificationTest.php` (nuevo) | A–D + detección de entregable | PASS |
| `tests/Unit/A07/AiToolCallLeakTest.php` (nuevo) | completo / incompleto / no ofrecida / args inválidos / mención / sobre puro / `strip` / directiva sin detalles | PASS |
| `tests/Feature/A07/CapabilityRecoveryTest.php` (ampliado) | A, B, C, C', D, E, no re-preguntar al que se negó | PASS |
| `tests/Feature/A07/MalformedToolCallRecoveryTest.php` (nuevo) | los 9 casos exigidos | PASS |
| `tests/Feature/A07/OperationalTaskContinuityTest.php` (nuevo) | 2 turnos + guarda de la instrucción | PASS |

**Total: 34 pruebas / 107 aserciones — todas PASS.**

---

## 6. Regresión

Suite completa del dominio A07 (`tests/Feature/A07` + `tests/Unit/A07`), comparada contra
el mismo comando en `HEAD` **sin** los cambios:

| | `HEAD` (baseline) | Con los cambios |
|---|---|---|
| Tests | 362 PASS / **14 FAIL** | 393 PASS / **14 FAIL** |
| Fallos | `AnalyzeTableTool`, `ArtifactQuality`, `AssistantTaskKinds`(×5), `DocumentPipeline`(×4), `TaskRetryAndIsolation`(×3) | **los mismos 14** |

Los 14 fallos son **preexistentes** y provienen de contaminación entre pruebas (esas
suites usan la cola Redis real; filas confirmadas sobreviven al `rollback`). Cada archivo
pasa **aislado**, antes y después. Las pruebas nuevas son inmunes porque acotan sus
recuentos a su propia conversación. **Cero regresiones.**

Estilo: `pint` limpio en los 10 archivos tocados. Frontend no modificado (sin typecheck).

---

## 7. Garantías preservadas

- `AI_ALLOW_TRAINING_PROVIDERS=false` intacto (verificado en el `.env` y en el entorno).
- Aislamiento de datos privados: sin cambios en la puerta de privacidad; ninguna negativa
  se evade saltando a otro proveedor.
- Presupuesto por turno, selección de modelos gratuitos y límites de tiempo: intactos.
- HTTP 200 nunca se trata como éxito funcional (T04 exigía el documento, T02 exigía
  respuesta útil).
- No se fabrican respuestas ni citas desde una llamada malformada.
- Un fallo ambiguo no penaliza de forma permanente a un modelo (la evitación es por turno;
  la evidencia de herramienta es sólo positiva, con TTL).
- Nada de `--force`, `reset --hard`, `clean -fd`, `down -v` ni `migrate:fresh` sobre datos
  reales; no se tocaron archivos no rastreados ajenos.

---

## 8. Entorno LIVE y limpieza

- Entorno reutilizado: `consultora_dh_e2e`, `app-e2e`, `nginx-e2e`, `queue-e2e` (Redis
  2/3), `APP_ENV=testing`, `America/Bogota`. Conducción por el **flujo HTTP real**
  (sesión + `X-XSRF-TOKEN`), igual que el informe de aceptación.
- Override de proveedores **temporal** (en `/tmp`, sin secretos escritos, interpolado
  desde `.env`) y puerto loopback sólo durante la ejecución.
- Identidades sintéticas: `live-doc`, `live-admin`, `live-assignee1` (Laura Sintetica QA),
  `live-assignee2`; permisos a nivel de usuario, ningún rol real tocado.
- **Limpieza:** override retirado y `app-e2e`/`queue-e2e`/`nginx-e2e` devueltos a su
  configuración canónica (proveedor stub determinista, sin puerto publicado; verificado);
  base `consultora_dh_e2e` **vaciada** (migraciones intactas; usuarios/roles/permisos/
  conversaciones = 0); caché aislada limpiada; archivos temporales del bloque eliminados;
  `.env` y no rastreados ajenos **preservados**.
- Datos reales: no se enviaron ni se escribieron; la base de desarrollo no se tocó.

---

## 9. Límites honestos

1. **T02 LIVE = BLOCKED**, no PASS: la causa es ambiental (cuota/tiempo de espera de los
   proveedores gratuitos), no el defecto corregido. La corrección del sobre malformado
   está probada herméticamente (9 casos) y el turno LIVE confirmó el fallo controlado
   prescrito para E.
2. **T12 turno 1** no se observó como `NEEDS_INPUT` en LIVE (el modelo propuso asignando al
   solicitante); la guía nueva no se pudo re-verificar en LIVE por presupuesto (2/2 turnos).
   La **continuidad** (turno 2), que era la exigencia central, **sí** pasa en LIVE.
3. No se corrigió el 503 por agotamiento del pool cuando ya había fuentes (limitación
   documentada en §3.5), por no ampliar el alcance más allá del defecto demostrado.

---

## 10. Cierre

```
BASE_SHA=740d5c9a67ee46a8e847206031deef9da6ed5cde
FINAL_CODE_SHA=d01190f9a50db52f090e5ffb4d6e7d01d8635ffc
ORIGIN_MAIN_TIP=(SHA del commit de publicación de este informe; ver bloque de cierre emitido)
T04_CLASSIFICATIONS=5 (POLICY/CAPABILITY/UNPRODUCTIVE/NONE/QUOTA)
T04_BOUNDED_RECOVERY=1_PER_TURN
T04_HERMETIC=PASS
T04_LIVE=PASS
T02_LEAK_RECOGNITION=NONE/COMPLETE/MALFORMED
T02_EXECUTES_ONLY_VIA_REGISTRY=YES
T02_HERMETIC=PASS
T02_LIVE=BLOCKED (cuota/tiempo de espera de proveedores gratuitos; fallo controlado)
T12_ASSIGNEE_INVENTED=NO
T12_ASSIGNEE_OPTIONAL=NO
T12_NEEDS_INPUT_VALID=YES
T12_TURN2_PROPOSAL_PENDING_LINKED=YES
T12_OPERATIONAL_TASK_CREATED=NO
T12_HERMETIC=PASS
T12_LIVE=PARTIAL (continuidad PASS; turno 1 no observado como NEEDS_INPUT)
T08_PREVIOUS_PASS=PRESERVED
T09_PREVIOUS_PASS=PRESERVED
A07_SUITE_REGRESSIONS=0 (14 fallos preexistentes idénticos al baseline)
EXTERNAL_LLM_REQUESTS=4 turnos LIVE (proveedores gratuitos)
REAL_DATA_PRESERVED=YES
AI_ALLOW_TRAINING_PROVIDERS=false
QA_CLEANUP=PASS
FINAL_RESULT=PARTIAL
```

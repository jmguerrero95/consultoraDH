# CONSULTORA DH — A07-ASSIGNEE-GUARD-EDGE-CASES

**Bloque:** cierre de los dos casos límite comprobados en `TaskAssigneeGuard`
(autoasignación deducida de una mención; propuesta caducada como justificación).
Sin ampliar A07.
**BASE_SHA:** `898cfa5181d441c2a34e861e343d54fd2a8b666a`
**FINAL_CODE_SHA:** `f6653ecb70d9fedc9820ac2f920ed8fe6a40a713`
**ORIGIN_MAIN_TIP:** `(SHA del commit de publicación de este informe; ver bloque de cierre)`
**Fecha:** 2026-10-19 · **REPO:** `jmguerrero95/consultoraDH` · **BRANCH:** `main`

> Cero llamadas a proveedores externos (todo bajo `Http::fake`; la suite fuerza
> `Http::preventStrayRequests()`). Sin suites globales, sin migraciones, sin cambios de
> esquema. Dos archivos tocados, ambos ya de este dominio.

---

## 1. AUTOASIGNACIÓN EXPLÍCITA

**Defecto.** `SELF_PHRASES` aceptaba frases aisladas (`'a mi'`, `'para mi'`, `'yo mismo'`,
`'mi cuenta'`). «Prepara para mí una tarea de seguimiento de cartera» autorizaba, por sí
sola, asignar la tarea **al solicitante**: confunde *para quién se prepara* con *de quién es
la tarea*.

**Cambio (servidor, no prompt).** Se eliminan las frases sueltas y se sustituyen por
`SELF_ASSIGNMENT_PATTERNS`, que exigen una **instrucción explícita de asignación**:

| Se acepta (instrucción) | Fórmula |
|---|---|
| «Asígnamela a mí» | `\basign\w*(?:me\|mela\|melo\|melas\|melos)\b` |
| «Asígnala a mí» | `\basign\w*\s+(?:la\|lo\|las\|los)?\s*a\s+mi\b` (excluye «a mi equipo») |
| «Ponme como responsable» | `\bponme\b…\b(?:responsable\|encargad[oa]\|a cargo)\b` |
| «Me encargo yo», «quiero encargarme» | `\b(yo\s+)?me\s+encargo\b`, `\bquiero\s+encargarme\b` |
| «Soy el responsable», «quiero ser el responsable» | `\b(yo\s+)?soy\s+(el\s+)?responsable\b`, `\bel\s+responsable\s+soy\s+yo\b`, … |
| «Me la asignas», «a mi nombre», «a mi cuenta» | `\bme\s+l[ao]s?\s+asignas\b`, `\ba\s+mi\s+(?:nombre\|cuenta)\b` |

**Ya no se acepta**: «para mí», «a mí», «mí» sueltos, «yo mismo» — son menciones, no
asignaciones. **Ante ambigüedad, se pregunta**: cuando no hay instrucción de asignación, el
turno no crea propuesta y recibe la directiva de preguntar. La garantía sigue siendo
determinista en el servidor.

---

## 2. PROPUESTAS CADUCADAS Y CORRECCIÓN LEGÍTIMA

**Defecto.** `keepsProposedAssignee` filtraba `status = pending` y **no exigía**
`expires_at > now()`. Una propuesta cuya ventana había cerrado seguía justificando el
responsable de una propuesta nueva.

**Cambio.** Ahora se exigen **cuatro** hechos a la vez:

1. **misma identidad** (el `title` normalizado) — una tarea distinta nunca se considera;
2. **mismo responsable** — una corrección no puede cambiar de dueño por aproximación;
3. **marcador de corrección** en la petición (`CORRECTION_PATTERNS`: «corrige», «cambia»,
   «modifica», «actualiza», «ajusta», «ponla/ponle», «en vez de», «mejor pon», o referencia
   anafórica «la tarea/la propuesta/esa propuesta/la misma tarea/tarea anterior») — así una
   tarea **nueva** que sólo repite un título **no hereda nada**;
4. `status = pending` **y** `expires_at > now()` — comprobación temporal *server-side*; una
   propuesta **caducada no justifica nada**, aunque su `status` siga diciendo `pending`.

También se añade `user_id = conversation->user_id` a la consulta (refuerzo de aislamiento).
La **corrección legítima de una propuesta vigente se conserva**: identidad y contexto
justificados, mismo responsable ⇒ se permite y sustituye a la anterior (§ prueba 4).
La lógica de sustitución (`EXPIRED` + `confirm()` → 400) no cambia.

---

## 3. PRUEBAS HERMÉTICAS (Pest dirigido)

`tests/Feature/A07/TaskAssigneeGuardTest.php` — **23 PASS / 74 aserciones**. Nuevos:

| Caso exigido | Prueba |
|---|---|
| «Prepara para mí…» **no** autoriza autoasignación | `test_preparing_a_task_for_oneself_is_not_an_assignment` |
| Mención de uno mismo («muéstramela a mí») no asigna | `test_mentioning_oneself_is_not_an_assignment` |
| «Asígnamela a mí» ⇒ autoasignación | `test_taking_the_task_oneself_is_valid_when_asked_for_explicitly` |
| «Ponme como responsable» ⇒ autoasignación | `test_ponme_como_responsable_is_an_assignment_instruction` |
| «Asígnala a mí» ⇒ autoasignación | `test_asignala_a_mi_is_an_assignment_instruction` |
| Propuesta **vencida con status=pending** no justifica | `test_an_expired_proposal_does_not_justify_a_new_proposal` |
| Caducada: ni se hereda ni se reemplaza | `test_an_expired_proposal_is_neither_inherited_nor_replaced` |
| Título repetido **sin** autorización de herencia | `test_a_repeated_title_without_a_correction_inherits_no_assignee` |
| **Corrección válida** de propuesta vigente | `test_a_correction_replaces_the_proposal_for_the_same_task` |
| Propuesta reemplazada **no confirmable** | `test_a_superseded_proposal_can_no_longer_be_confirmed` |
| **Ninguna escritura sin confirmación** | aserciones `OperationalTask::count() === 0` en las pruebas anteriores |

Vecindario afectado re-ejecutado: `tests/Unit/A07` + `AiAssistantRuntimeTest` +
`MalformedToolCallRecoveryTest` + `OperationalTaskContinuityTest` +
`AiErrorScopeAndFailoverTest` + `CapabilityRecoveryTest` + `AttachmentToAssistantTest` +
`TaskAssigneeGuardTest` ⇒ **182 PASS / 600 aserciones**. Sin regresiones.

`./vendor/bin/pint --test` **limpio** en los 2 archivos modificados.

---

## 4. SEGURIDAD Y RECURSOS

Preservados sin cambios: RBAC (se evalúa antes del guardián), autorización del asignado
(`TaskAssignee`), privacidad, historial, confirmación humana, `.env`,
`AI_ALLOW_TRAINING_PROVIDERS=false`, datos reales y archivos ajenos no rastreados. Sin
migraciones. Sin `force-push`, `reset` destructivo, limpieza global de Redis ni borrado de
volúmenes; push **fast-forward**.

---

## 5. LÍMITES HONESTOS

1. El marcador de corrección es **léxico**, a diferencia de la coincidencia de nombre, que es
   estructural. Su modo de fallo es **preguntar** (seguro); una corrección redactada sin
   ninguno de los marcadores pedirá el responsable en lugar de heredarlo.
2. La identidad de la tarea sigue siendo el **título**: un cambio de título rompe la
   identidad y, por tanto, no hereda (se pregunta).
3. No se volvió a ejecutar la suite A07 completa ni los 14 fallos preexistentes conocidos
   (siguen documentados en `CONSULTORA_DH_A07_DETERMINISTIC_SAFETY_CLOSURE.md` §6).

---

## 6. CIERRE

```
BASE_SHA=898cfa5181d441c2a34e861e343d54fd2a8b666a
FINAL_CODE_SHA=f6653ecb70d9fedc9820ac2f920ed8fe6a40a713
ORIGIN_MAIN_TIP=(SHA del commit de publicación de este informe)
SELF_ASSIGNMENT_UNAMBIGUOUS=PASS
EXPIRED_PROPOSAL_NOT_INHERITED=PASS
SAME_TITLE_NEW_TASK_SAFE=PASS
VALID_CORRECTION_PRESERVED=PASS
NO_WRITE_WITHOUT_CONFIRMATION=PASS
TARGETED_TESTS=23 PASS (74 aserciones) + 182 PASS (600 aserciones); Pint limpio en 2 archivos
EXTERNAL_LLM_REQUESTS=0
FINAL_RESULT=PASS
```

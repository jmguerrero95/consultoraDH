# A04-R4 — Matriz de cierre del contrato

> Creado **antes** de implementar A04-R4, como exige §3 de la instrucción. Base auditada:
> `653ff4fcbd45008ebb739379534e57ddfae9b96b`.
>
> `A04-SPEC.md` es autoritativo sobre la implementación y sobre las pruebas existentes.
>
> **Una decisión está implementada sólo si una prueba demuestra su efecto semántico.**
> Un lector existe no es implementación. Un escritor existe no es implementación.

## 0. Cómo leer esta matriz

- **Productor**: el único lugar estable que crea el hallazgo. Un hallazgo con dos productores es
  dos hallazgos; uno sin productor no existe aunque tenga etiqueta.
- **Sujeto**: la clave estable `(subject, code, field)` que `IssueSubject` construye. Productor y
  reader deben obtenerla del **mismo** objeto; construirse a mano en dos sitios es la clase de
  defecto que R4 cierra.
- **R4**: qué cambió respecto de `653ff4fcbd45008ebb739379534e57ddfae9b96b`.
- **Prueba**: la prueba que llega al estado final de base de datos. «El issue existe», «el action
  existe» y «HTTP 200» **no** cuentan.

### Leyenda de estado

| Símbolo | Significado |
|---|---|
| ✅ | Cerrado y probado de punta a punta |
| 🔴 | Ruptura confirmada; se corrige en R4 |
| ⚠️ | Cerrado sólo parcialmente; R4 lo completa |
| ⛔ | Se **elimina** del enum en lugar de cablearse |

---

## 1. Hallazgos de la auditoría R3 (base `653ff4fcbd45008ebb739379534e57ddfae9b96b`)

### 1.1 Archivos faltantes / reportes falsos

| Hallazgo | Severidad | Acción R4 | Estado |
|---|---|---|---|
| No existía suite de cierre contractual | 🔴 Crítico | **R4-A** | ✅ `tests/Feature/A04/ContractClosureTest.php`, 17 pruebas |
| `docs/TASKS/A04-R3.md` afirmaba tests que no existen | 🔴 Crítico | **R4-R** | ✅ Corregido: este documento y `A04-R4.md` describen sólo lo verificado |
| Frontend sin trabajo real | 🔴 Crítico | **R4-P** | ✅ Tipos, poll del lote, y `decisionsFor()` filtrado |

### 1.2 Enum / contrato incompletos

| Hallazgo | Severidad | Acción R4 | Estado |
|---|---|---|---|
| Docs mencionaban decisiones inexistentes | 🔴 Crítico | **R4-B** | ✅ Tres cableadas, dos eliminadas (§2.4) |
| `company_verification_digit_conflict` bloqueante sin salida | 🔴 Crítico | **R4-C** | ✅ Tres rupturas independientes corregidas |
| `overlapResolution` texto humano; Apply espera código máquina | 🔴 Crítico | **R4-D** | ✅ Código máquina, aplicado al episodio correcto |
| `dateWasRepaired()` trata `undecided` como reparado | 🔴 Crítico | **R4-E** | ✅ `isKnown()` |

---

## 2. Matriz de trabajo R4 — estado final

### R4-A — Suite de cierre contractual

| Subtarea | Estado |
|---|---|
| `tests/Feature/A04/ContractClosureTest.php` existe y pasa | ✅ 17 pruebas, 79 aserciones |
| Vocabulario: ninguna decisión sin donde ofrecerse | ✅ |
| Vocabulario: ningún bloqueante sin cómo responderse | ✅ |
| §7.2 DV contradictorio → decisión → rebuild → Apply → ficha | ✅ |
| §7.2 sin respuesta → nada escrito, sin `UpdateCompany` propuesto | ✅ |
| §8.5 `recognize_transfer` → `transfer` en el episodio que se abre | ✅ |
| §8.5 `authorize_parallel` → `parallel` | ✅ |
| §8.5 `split_overlap_at` → `transfer` (nunca `split`, que el escritor rechaza) | ✅ |
| Reparación de fecha: `ignore_date` no repara, `set_date` sí | ✅ |
| Invalidación de identidad al resolver | ✅ |
| Filas de la matriz sobre `link_existing_client`, `use_company_nit`, `create_entity`, `keep_both`, `treat_as_duplicate_of`, `CloseRelationship`, `CloseAffiliation`, cambio de proveedor, conflictos de tarifa | ❌ **No entregadas** |

### R4-B — Enum / contrato de resolución

| Decisión | En el enum | `allowedFor()` | Consumidor | Estado |
|---|---|---|---|---|
| `use_source_verification_digit` | ✅ | `company_verification_digit_conflict` | `verificationDigitFor()` + `approvedFields()` + `writeCompanyUpdate()` | ✅ |
| `recognize_transfer` | ✅ | `overlapping_company_history` | `applyOverlapDecisions()` | ✅ |
| `authorize_parallel` | ✅ | `overlapping_company_history` | `applyOverlapDecisions()` | ✅ |
| `accept_source_identity` | ⛔ | — | — | ✅ **Eliminada**: sin consumidor, etiqueta duplicada con `link_existing_client` |
| `set_monthly_amount` | ⛔ | — | — | ✅ **Eliminada**: el importe es propiedad de un `RateSegment`; cablearla exige hilo por fila |

Armas `match` duplicadas que quedaban de la iteración anterior: eliminadas.

### R4-C — El dígito de verificación tiene salida real

| Situación | Comportamiento | Estado |
|---|---|---|
| DV origen ausente | Sin propuesta | ✅ |
| DV maestro nulo, origen válido | Propuesta visible; `UpdateCompany` con `verification_digit` aprobado; Apply escribe | ✅ |
| DV maestro == DV origen | No-op | ✅ |
| DV maestro != DV origen | Bloqueante; una sola salida: `use_source_verification_digit` | ✅ |

### R4-D — Semántica de solapamiento / transferencia / paralelo

| Defecto | Estado |
|---|---|
| Texto humano donde Apply espera código máquina | ✅ Código máquina en `RelationshipEpisode::$overlapResolution` |
| El código no llega a Apply | ✅ Llega en `payload['overlap_resolution']` |
| `split` emitido y rechazado por el escritor | ✅ `split` nunca se emite; el corte produce `transfer` |
| `applyOverlapDecisions()` llamaba a `self::key()`, método inexistente → error fatal con `split_overlap_at` | ✅ Lectura movida a `ImportDecisionSet::overlapDecisionFor()` |
| `overlapResolutionFor()` devolvía `'none'` siempre, bajo un docblock que describía otra cosa | ✅ Eliminado; el valor se decide en la reconstrucción |
| El código se marcaba sobre el episodio que no se abre | ✅ Episodio posterior: un `transfer` cierra lo abierto, y `link()` rechaza `parallel` si no hay nada abierto |

### R4-E — `dateWasRepaired()`

`ignore_date` produce `ResolvedDate::unknown()`, un objeto real y no nulo, así que la condición
antigua era cierta y la fila se contaba como reparada. Ahora `isKnown()`. El par de pruebas
impide que la corrección sea «nunca tratar una decisión de fecha como reparación».

### R4-F — Opciones inertes retiradas

| Decisión | Retiro | Estado |
|---|---|---|
| `accept_source` | De `invalid_client_document`, `invalid_email`, `missing_monthly_value`, `invalid_monthly_value`, `client_identity_conflict`. `skip_row` es la respuesta real en los cinco. | ✅ |
| `overwrite_with_source` | De `existing_rate_conflict`: §10 ya tiene `accept_existing` y `accept_source_amount`, y un valor en vigor se corrige por ajustes de A03. | ✅ |
| `overwrite_with_source` | De `affiliation_*` / historial | ✅ No se ofrecía; verificado |
| `skip_row` | Se conserva donde tiene consumidor (`skipsRow()` + hidratación) | ✅ |

### R4-G — Consumo de `ambiguous_risk_job_columns`

`riskClassFor()` leía sólo `unknown_risk_token` mientras `set_risk_class` se ofrecía también para
`ambiguous_risk_job_columns`: la respuesta se guardaba y no se leía, y la clase de riesgo se quedaba
con la suposición del parser. Ahora lee los dos códigos.

### R4-H — Decisiones de enlace / identidad alcanzables

| Decisión | Estado |
|---|---|
| `LinkExistingClient` | ⚠️ `linkedClientId()` cubre `client_identity_conflict` y `existing_client_conflict`. Verificado por lectura; **sin prueba dedicada**. |
| `UseCompanyNit` | ⚠️ `companyNitFor()` cubre ambos códigos. Verificado por lectura; **sin prueba dedicada**. |
| `LinkExistingCompany` | ⚠️ Verificado por lectura; **sin prueba dedicada**. |

### R4-I — Vocabulario del conflicto de tarifa

`existing_rate_conflict` → exactamente `accept_existing`, `accept_source_amount`. Sin
`overwrite_with_source`.

### R4-J — `create_entity` reutilizable

| Requisito | Estado |
|---|---|
| `map_entity` deja `ImportSourceMapping` reutilizable | ✅ `ResolveImportIssue::durableEffect()` |
| `create_entity` deja mapping reutilizable | ❌ **No entregado.** `ApplyImportPlan` no referencia `ImportSourceMapping`. |
| Mapping escrito transaccionalmente en el Apply que crea la entidad | ❌ **No entregado** |

### R4-K — Nunca crear mapping global «SI → entidad»

| Defecto | Estado |
|---|---|
| `affiliation_entity_unknown` desde un afirmativo desnudo puede crear mapping global | ❌ **No entregado.** Sin guarda en el árbol. Depende de R4-J para tener un mapeo cuya creación pueda gobernarse. |

### R4-L — Invalidación sincrónica del plan

| Requisito | Estado |
|---|---|
| Resolver retira la identidad sincrónicamente, bajo `FOR UPDATE` | ✅ `ResolveImportIssue::invalidatePlanIdentity()` |
| Estado cero en lugar de «digest nulo con revisión viva» | ✅ `legacy_imports_plan_identity_check` lo exige; el intento inicial lo violó en el servidor de pruebas y la restricción tenía razón |
| Rebuild recalcula digest y avanza revisión | ✅ Existente |
| `apply` valida el par bajo lock → 409 si está obsoleto | ✅ Existente |
| Aplicado a `resolveIssue` | ✅ |
| Aplicado a `bulkResolve` | ✅ Por resolución (cada una retira la identidad) |
| Aplicado a `interpretationPolicy` | ✅ Bajo `ImportLifecycle::mutate()` |

### R4-M — Atomicidad de bulk a nivel de lote

| Requisito | Estado |
|---|---|
| Lock único, validación única, invalidación única, rebuild único | ❌ **No entregado.** Cada resolución abre su propia transacción. |

Decisión consciente: el endpoint ya devuelve **éxito parcial (207) con el motivo de cada rechazo**,
que es un contrato explícito y coherente con §17.4 (las decisiones tienen forma por código). Pasarlo
a todo-o-nada es una decisión de especificación, no una corrección silenciosa.

### R4-N — Política de interpretación bajo el mismo lock

`interpretationPolicy` ahora escribe bajo `ImportLifecycle::mutate()` y retira la identidad igual que
un resolvedor.

### R4-O — Pruebas reales de concurrencia PostgreSQL

| Escenario | Estado |
|---|---|
| `apply` vs `apply`, con lock real | ✅ `ApplyConcurrencyTest` |
| Rollback completo si una acción falla a mitad | ✅ |
| El mismo lock consultivo que A03, preguntando a PostgreSQL | ✅ |
| Aislamiento de un lector concurrente | ✅ |
| `resolveIssue` vs `apply` | ❌ **No entregado** |
| `bulkResolve` vs `apply` | ❌ **No entregado** |
| `interpretationPolicy` vs `apply` | ❌ **No entregado** |
| `rebuild` vs `apply` | ❌ **No entregado** |

Sin `sleep()` en lo que existe. La invalidación sincrónica de R4-L es exactamente lo que esos
escenarios medirían, y está en su sitio para cuando se escriban.

### R4-P — El frontend coincide con el backend

| Requisito | Estado |
|---|---|
| `resources/js/types/api.ts` refleja las decisiones nuevas | ✅ Tres union members |
| `ImportDetailPage.vue` no se queda congelado en `review` | ✅ `isPending()` decide por digest |
| Decisiones sólo desde `allowed_decisions` del backend | ✅ Antes venía la lista completa; ahora `decisionsFor()` filtra por `accepts()` |
| Sin opción muerta; sin decisión de backend sin UI | ✅ |
| Mostrar `plan_revision` / `plan_digest` | ✅ Existente, con la alerta de plan obsoleto |
| Paginación funcional | ✅ Existente |

### R4-Q — El E2E se ejecuta de verdad

| Requisito | Estado |
|---|---|
| Suite E2E completa ejecuta | ✅ 48/48, dos ejecuciones seguidas |
| Journey representativo upload → review → resolución → rebuild → preview → Apply → estado final | ✅ Journey A |
| Los seis journeys de §20.8 | ✅ A–F |
| Sin regresiones A02/A03 | ✅ Los tres specs pasan |

Tres causas de fallo eran del arnés, no del producto, y las tres están corregidas con su causa
escrita en el código: presupuestos calculados para un stack que compila assets bajo demanda (§3.1 de
`A04-R4.md`), un fixture que invalidaba su propia prueba, y un locator ambiguo dependiente del orden.

### R4-R — Documentación veraz

| Documento | Estado |
|---|---|
| `docs/TASKS/A04-R4.md` | ✅ Creado; incluye una sección **No entregado** |
| `docs/TASKS/A04-R4-CLOSURE-MATRIX.md` | ✅ Este documento, en estado final |
| `docs/TASKS/A04-R3.md` y `A04-R3-CLOSURE-MATRIX.md` | ⚠️ Describen R3. No se reescribieron: R4 los deja como registro histórico de esa revisión. Las afirmaciones falsas que la auditoría señaló viven en `A04-R4.md`, que las corrige explícitamente. |

---

## 3. Matriz de auditoría adversaria

| Categoría | Verificación | Estado |
|---|---|---|
| **Decisiones** | Cada `IssueResolutionDecision` tiene consumidor semántico real | ✅ Prueba de invariante |
| **Decisiones sin consumidor** | Ninguna | ✅ Las dos inertes fueron eliminadas, no documentadas |
| **Bloqueantes sin respuesta** | Ninguno | ✅ Prueba de invariante + lista de rechazos nombrada |
| **Lista de rechazos al día** | Atada a `isBlocking()` | ✅ |
| **Opciones frontend sin backend** | Ninguna | ✅ `decisionsFor()` filtrado |
| **Acciones** | Cada `ImportActionType` tiene productor + escritor | ✅ Test existente en `RemediationRegressionTest` |
| **Issues** | Cada código tiene productor o rechazo documentado | ✅ por invariante |
| **Sobreescritura histórica A03** | Ninguna | ✅ Test existente: «never writes A03 money records» |
| **Plan stale ejecutable** | Ninguno | ✅ withdrawing to zero state; test 409 |

---

## 4. Gates obligatorios

| Gate | Comando | Resultado |
|---|---|---|
| Pest completo | `php artisan test` | ✅ 1182 pasan, 5184 aserciones |
| Pest A04 | `php artisan test --filter=A04` | ✅ 168 pasan |
| Cierre contractual | `php artisan test --filter=ContractClosureTest` | ✅ 17 pasan |
| Pint | `vendor/bin/pint --test` | ✅ 439 archivos |
| Frontend tests | `npm test` | ✅ 189 pasan |
| vue-tsc | `npm run typecheck` | ✅ |
| ESLint | `npm run lint` | ✅ |
| Build | `npm run build` | ✅ |
| Composer validate | `composer validate` | ✅ |
| Composer audit | `composer audit` | ✅ sin avisos |
| npm audit | `npm audit` | ✅ 0 vulnerabilidades |
| E2E completo | `./scripts/run-e2e.sh` | ✅ 48 pasan |

---

## 5. Resumen de criterios de cierre R4

| Criterio | Estado |
|---|---|
| DV conflict con salida real, probada hasta la ficha | ✅ |
| Overlap / transfer / parallel semántica correcta | ✅ |
| `dateWasRepaired()` semántica correcta | ✅ |
| Opciones inertes eliminadas (no documentadas como «pendientes») | ✅ |
| `ambiguous_risk_job_columns` consume `set_risk_class` | ✅ |
| Vocabulario de conflicto de tarifa limpio | ✅ |
| Plan invalidation sincrónico (resolve + policy) | ✅ |
| Policy interpretation bajo el mismo lock | ✅ |
| Pantalla de importación no se congela | ✅ |
| Diálogo sin opciones muertas | ✅ |
| E2E ejecuta, 48/48, sin regresiones | ✅ |
| Gates finales verdes | ✅ |
| Frontend tipado para el vocabulario nuevo | ✅ |
| Decisiones link/identity alcanzables | ⚠️ por lectura, sin prueba |
| Suite de cierre contractual completa | ⚠️ parcial respecto de la lista de R4-A |
| `create_entity` reutilizable (R4-J) | ❌ |
| Mapping global «SI» gobernado (R4-K) | ❌ |
| Bulk resolution atómica (R4-M) | ❌ |
| Concurrency tests de revisión vs apply (R4-O) | ❌ parcial |

---

*Última actualización: fin de R4.*
*Base auditada: `653ff4fcbd45008ebb739379534e57ddfae9b96b`.*
*Detalle y justificación: `docs/TASKS/A04-R4.md`.*

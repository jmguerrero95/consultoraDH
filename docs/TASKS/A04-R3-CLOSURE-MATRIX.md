# A04-R3 — Matriz de cierre del contrato

> **Nota**: documento histórico. Las brechas que enumera fueron cerrándose en A04-R4 y
> A04-R5; ver `A04-SPEC.md` (autoritativo) y `A04-R5.md`. Se conserva sin reescribir porque
> registra el estado del contrato en el momento en que esa matriz se escribió.

> Creado **antes** de implementar A04-R3, como exige §3 de la instrucción. Base auditada:
> `3c169c1277eaf23a86c063c2a2ed57ec51f2dc8a`.
>
> `A04-SPEC.md` es autoritativo sobre la implementación y sobre las pruebas existentes.
>
> **Una decisión está implementada sólo si una prueba demuestra su efecto semántico.**
> Un lector existe no es implementación. Un escritor existe no es implementación.

## 0. Cómo leer esta matriz

- **Productor**: el único lugar estable que crea el hallazgo. Un hallazgo con dos productores es
  dos hallazgos; uno sin productor no existe aunque tenga etiqueta.
- **Sujeto**: la clave estable `(subject, code, field)` que `IssueSubject` construye. Producer y
  reader deben obtenerla del **mismo** objeto; construirse a mano en dos sitios es la clase de
  defecto que R3 cierra.
- **R3**: qué cambia respecto de `3c169c1`. `—` significa que ya está cerrado.
- **Prueba**: la prueba que llega al estado final de base de datos. «El issue existe», «el action
  existe» y «HTTP 200» **no** cuentan.

### Leyenda de estado

| Símbolo | Significado |
|---|---|
| ✅ | Cerrado y probado de punta a punta |
| 🔴 | Ruptura confirmada; se corrige en R3 |
| ⚠️ | Cerrado sólo parcialmente; R3 lo completa |
| ⛔ | Contrato muerto: se implementa o se **elimina** del enum |

## 1. Auditoría previa: métodos muertos en `3c169c1`

Búsqueda de call sites en `app/` y `resources/js/`:

| Método | Call sites | Consecuencia |
|---|---:|---|
| `ImportDecisionSet::linkedCompanyId()` | **0** | `link_existing_company` no cambia nada |
| `ImportDecisionSet::createdEntityName()` | **0** | `create_entity` no crea entidad alguna |
| `ImportDecisionSet::duplicateOf()` | **0** | `treat_as_duplicate_of` no excluye la fila |
| `ImportDecisionSet::keepsBothRows()` | **0** | `keep_both` no conserva nada |
| `ImportDecisionSet::companyNitFor()` | 2 | lee, pero la identidad reconstruida conserva el NIT original |
| `ImportDecisionSet::arlSourceFor()` | 1 | **clave distinta** a la del productor |
| `ImportDecisionSet::disappearanceFor()` | 1 | consumido (R2) |
| `ImportDecisionSet::overlapBoundaryFor()` | 2 | consumido (R2) |
| `ImportDecisionSet::mappedEntityId()` | 1 | sólo el camino `map_entity` |
| `ImportDecisionSet::skipsAffiliation()` | 1 | consumido |
| `ImportDecisionSet::acceptsSourceAmount()` | 2 | consumido, pero `writeRate` actualiza **y** crea (§11 G) |
| `ImportDecisionSet::overwritesWithSource()` | 1 | consumido; se ofrece para historia (§8 D) |
| `ImportDecisionSet::keepsExisting()` | 2 | consumido |
| `ImportDecisionSet::riskClassFor()` | 1 | sólo `unknown_risk_token`, no `ambiguous_risk_job_columns` |
| `ImportDecisionSet::linkedClientId()` | 1 | clave propia, no propaga el destino |

Y tres tipos de acción con escritor y **sin productor**:

| Tipo de acción | Productor | Escritor | Lectura |
|---|---:|---:|---|
| `CreateSocialEntity` | 0 | 1 | «aplicador que el constructor nunca produce» |
| `CloseAffiliation` | 0 | 1 | la reconstrucción cierra segmentos que nadie escribe |
| `CloseRelationship` | 0 | 1 | sin productor; §8.5 no puede decidir transferencia ni paralelo |

## 2. Códigos de incidencia (§18)

| Código | Productor | Sujeto | Bloquea | Decisiones permitidas | R3 | Prueba |
|---|---|---|---|---|---|---|
| `unsupported_workbook_profile` | — | — | rechazo de subida | — | ⛔ documentado: §4.2 hace que un libro ilegible sea un rechazo, no una incidencia revisable. Se prueba como rechazo, no como «productor inventado» | `A04R3ContractClosureTest` |
| `invalid_sheet_name` | `BlindenLegacyWorkbookParser` | hoja | no | sólo lectura (§17.4) | — | R2 |
| `duplicate_month_sheet` | `BlindenLegacyWorkbookParser` | hoja | no | sólo lectura | — | R2 |
| `missing_company_block_header` | `BlindenLegacyWorkbookParser` | hoja+fila | sí | `skip_row` | — | R2 |
| `invalid_company_tax_id` | `SourcePersonRow::problems()` | `company:{block}` | sí | `use_company_nit`, `link_existing_company` | 🔴 la identidad reconstruida no cambia | R3-1/2 |
| `company_identity_conflict` | `ParsedWorkbook::raiseCompanyIdentityConflicts()` | `company:{block}` | sí | `use_company_nit`, `link_existing_company` | 🔴 idem + `other_tax_ids` se incluye a sí mismo | R3-1/2 |
| `company_verification_digit_conflict` | `ImportPlanBuilder::resolveVerificationDigit()` | `company:{block}` | sí | `accept_existing`, `use_source_verification_digit` (nuevo) | 🔴 bloqueante sin salida | R3-8 |
| `company_arl_metadata_conflict` | `ParsedWorkbook::addBlockIssue()` | `company:{block}` + `arl_token` | sí | `choose_arl_source` | 🔴 productor y reader usan claves distintas | R3-3/4 |
| `credential_like_content` | `StageLegacyImport::stageCredentialIssues()` | `credential:{hoja}:{patrón}` | no | — (§4.3: se corrige el archivo) | — | R2 |
| `invalid_client_document` | `SourcePersonRow::problems()` | `row:{key}` | sí | `skip_row` **únicamente** | 🔴 `accept_source` no puede producir un valor usable | R3-11 |
| `client_identity_conflict` | `ImportPlanBuilder::clientActions()` | `existing:{documento}` | sí | `accept_source_identity` (nuevo), `link_existing_client` | 🔴 sin productor estable ni propagación | R3-5/6 |
| `invalid_affiliation_date` | `SourcePersonRow::problems()` | `row:{key}` + `affiliation_date` | sí | `use_suggested_date`, `set_date`, `ignore_date`, `skip_row` | 🔴 la fila nunca vuelve a la reconstrucción | R3-9/10 |
| `invalid_email` | `SourcePersonRow::problems()` | `row:{key}` + `email` | no | `skip_row` | 🔴 `accept_source` no procede: §7.1 lo omite | R3 |
| `duplicate_exact_row` | `ParsedWorkbook` | `row:{key}` | no | `treat_as_duplicate_of`, `keep_both` | ⚠️ se colapsa antes de planeación | R3-15 |
| `duplicate_conflicting_row` | `ParsedWorkbook` | `row:{key}` | sí | `treat_as_duplicate_of`, `keep_both` | 🔴 ninguna de las dos se consume | R3-15/16 |
| `ambiguous_risk_job_columns` | `SourcePersonRow::problems()` | `row:{key}` | sí | `set_risk_class` | 🔴 el reader sólo mira `unknown_risk_token` | R3-13 |
| `unknown_risk_token` | `SourcePersonRow::problems()` | `row:{key}` + `arl_risk_class` | sí | `set_risk_class` | — | R2 |
| `unresolved_social_entity` | `ImportPlanBuilder::raiseUnresolvedEntity()` | `token:{TIPO}:{token}` | no | `map_entity`, `create_entity`, `skip_affiliation` | 🔴 `create_entity` no produce acción | R3-11/12 |
| `affiliation_entity_unknown` | `SourcePersonRow::problems()` | `row:{key}` + `entity_token` | sí | entidad para **esta** ocurrencia, `skip_row` | 🔴 `map_entity` crearía un mapping global «SI» | R3-13 |
| `relationship_disappeared_without_retirement` | `HistoryReconstruction::issues()` | `disappearance:{episodio}|{último mes}` | sí | `close_on_disappearance`, `keep_open` | — | R2 |
| `overlapping_company_history` | `HistoryReconstruction::issues()` | `overlap:{cliente}|{ep}|{ep}` | sí | `split_overlap_at`, `recognize_transfer` (nuevo), `authorize_parallel` (nuevo) | 🔴 §8.5 exige tres salidas y sólo hay una | R3-29/30 |
| `missing_monthly_value` | `SourcePersonRow::problems()` | `row:{key}` | no | `skip_row` (§10: no hay rate) | 🔴 `accept_source` no procede | R3 |
| `invalid_monthly_value` | `SourcePersonRow::problems()` | `row:{key}` | sí | `set_monthly_amount` (nuevo), `skip_row` | 🔴 `accept_source` no deja amount usable | R3-28 |
| `existing_client_conflict` | `ImportPlanBuilder::raiseExistingConflicts()` | `existing:{natural_key}` + campo | **sí** si ambos no vacíos | `accept_existing`, `overwrite_with_source` (campos mutables), `link_existing_client` | 🔴 R2 lo dejó no bloqueante contra §11 | R3-19/20 |
| `existing_company_conflict` | ídem | ídem | **sí** | `accept_existing`, `overwrite_with_source`, `use_company_nit`, `link_existing_company` | 🔴 ídem | R3-21 |
| `existing_relationship_conflict` | ídem | ídem | sí | `accept_existing` **sólo** | 🔴 `overwrite_with_source` reescribiría historia (§11) | R3-22 |
| `existing_affiliation_conflict` | ídem | ídem | sí | `accept_existing` **sólo** | 🔴 ídem | R3-23 |
| `existing_rate_conflict` | ídem | `existing:rate:…` + `monthly_amount_cop` | sí | `accept_existing`, `accept_source_amount` | 🔴 no bloqueante + actualiza y duplica | R3-24..27 |
| `source_already_applied` | `ImportController::store()` | — | rechazo de subida | — | — (§12.1, 409) | R2 |

## 3. Semántica exacta de `existing_*` (§11)

§11 dice tres cosas distintas y R2 las trataba como una:

| Situación | Efecto | Bloquea | Decisiones |
|---|---|---|---|
| Coincidencia exacta | Sin issue, sin escritura | no | — |
| Campo objetivo vacío + fuente no vacía | Propuesta visible de enriquecimiento | no | `accept_existing`, `overwrite_with_source` |
| Ambos no vacíos y distintos | Conflicto real | **sí** | según familia (§11) |

Reglas por familia, y por qué:

- **Cliente / empresa** — campos maestro mutables. `accept_existing` conserva; `overwrite_with_source`
  escribe los campos **aprobados uno por uno**.
- **Relación / afiliación** — historia. §11: «no reescribir relaciones/afiliaciones históricas
  existentes». Por tanto `overwrite_with_source` **no se ofrece**: no existe una reescritura segura,
  y ofrecerla sería un botón que corrompe historia. Sólo `accept_existing`.
- **Rate** — `accept_existing` o `accept_source_amount`. `overwrite_with_source` genérico no se
  usa porque existe una decisión de rate específica y §10 exige la inmutabilidad de A03.

## 4. Decisiones de resolución

| Decisión | Issues que la ofrecen | Consumida por | Efectosemántico | Prueba |
|---|---|---|---|---|
| `accept_source` | `invalid_client_document`, `invalid_email`, `missing_monthly_value`, `invalid_monthly_value` | — | **se elimina de esos cuatro**: aceptar la fuente no puede producir un valor usable. Se conserva donde sí cambia algo | R3-11/28 |
| `skip_row` | todas las de fila | `ImportDecisionSet::skipsRow()` + hidratación | la fila no entra en la reconstrucción | R3 |
| `use_suggested_date` | `invalid_affiliation_date` | `ImportDecisionSet::usesSuggestedDate()` + hidratación | usa la fecha sugerida por `SourceAffiliationDate` | R3-10 |
| `set_date` | `invalid_affiliation_date` | `ImportDecisionSet::dateFor()` + hidratación | fecha escrita, con precisión | R3-9 |
| `ignore_date` | `invalid_affiliation_date` | `ImportDecisionSet::dateFor()` + hidratación | intervalo con inicio desconocido | R3 |
| `map_entity` | `unresolved_social_entity` | `ImportDecisionSet::mappedEntityId()` | entidad existente; mapping reutilizable | R3-11 |
| `map_entity` (ocurrencia) | `affiliation_entity_unknown` | resolución por fila | entidad para **esa** celda. **Nunca** mapping global de un token afirmativo desnudo (§9.1) | R3-13 |
| `create_entity` | `unresolved_social_entity` | **`createdEntityName()` — muerto en R2** | `CreateSocialEntity` en Apply; el mapping se escribe **después** de que la entidad exista | R3-12 |
| `skip_affiliation` | `unresolved_social_entity`, `affiliation_entity_unknown` | `ImportDecisionSet::skipsAffiliation()` | no crea afiliación y cierra el segmento previo si la reconstrucción lo dice | R3-14 |
| `treat_as_duplicate_of` | `duplicate_conflicting_row`, `duplicate_exact_row` | **`duplicateOf()` — muerto en R2** | excluye la fila de la reconstrucción efectiva, con validación de pertenencia y antciclicidad | R3-15 |
| `keep_both` | ídem | **`keepsBothRows()` — muerto en R2** | conserva ambas observaciones | R3-16 |
| `use_company_nit` | `invalid_company_tax_id`, `company_identity_conflict` | `companyNitFor()` + **identidad efectiva nueva** | cambia el NIT base que usa la reconstrucción | R3-1 |
| `link_existing_company` | ídem + `existing_company_conflict` | **`linkedCompanyId()` — muerto en R2** | usa la identidad canónica de la empresa destino; no se crea una segunda | R3-2 |
| `choose_arl_source` | `company_arl_metadata_conflict` | `arlSourceFor()` — **clave distinta** | arbitra título vs encabezado con una sola clave | R3-3/4 |
| `close_on_disappearance` | `relationship_disappeared_without_retirement` | `HistoryReconstructor::applyDisappearanceDecisions()` | cierra el episodio en la fecha elegida | R2 |
| `keep_open` | ídem | ídem | el hueco no es una salida | R2 |
| `split_overlap_at` | `overlapping_company_history` | `HistoryReconstructor::applyOverlapDecisions()` | corrige la frontera | R3-29 |
| `recognize_transfer` (nueva) | `overlapping_company_history` | reconstructor + `ManageClientCompanies` | cesa el episodio anterior en una frontera deliberada; §8.5 | R3-29/30 |
| `authorize_parallel` (nueva, con `reason`) | `overlapping_company_history` | `ManageClientCompanies` con `RESOLUTION_PARALLEL` | paralelo **explícito y autorizado**; nunca automático | R3-29/31 |
| `accept_existing` | los cinco `existing_*` | `keepsExisting()` | conserva el maestro | R3-19/21..25 |
| `overwrite_with_source` | `existing_client_conflict`, `existing_company_conflict` **sólo** | `overwritesWithSource()` + `approved_fields` | escribe los campos aprobados | R3-20/21 |
| `accept_source_amount` | `existing_rate_conflict` | `acceptsSourceAmount()` | corrige el rate del mismo mes, si A03 lo permite | R3-26/27 |
| `set_monthly_amount` (nueva) | `invalid_monthly_value` | `ImportDecisionSet` + `RateSegment` | entero positivo en COP que el reconstruye usa | R3-28 |
| `link_existing_client` | `client_identity_conflict`, `existing_client_conflict` | **`linkedClientId()` — no propaga** | todas las acciones posteriores apuntan a ese cliente | R3-6 |
| `set_risk_class` | `unknown_risk_token`, `ambiguous_risk_job_columns` | `riskClassFor()` — **sólo el primero** | nivel 1..5 en la afiliación ARL | R3-13 |
| `use_source_verification_digit` (nueva) | `company_verification_digit_conflict` | identidad efectiva de empresa | acepta el DV de la fuente, con la comprobación de inmutabilidad | R3-8 |

## 5. Tipos de acción

| Tipo | Productor | Payload | Escritor Apply | Precondiciones observables | Efecto final | Prueba |
|---|---|---|---|---|---|---|
| `create_company` | `companyActions()` | `tax_id`, `legal_name`, `verification_digit` | `writeCompany()` → `CreateCompany` | `target_exists`, `legal_name`, `verification_digit` | fila en `companies` | R3-1/2 |
| `update_company` | `companyActions()` campo por campo | `tax_id`, `legal_name`, `verification_digit`, `approved_fields` | `writeCompanyUpdate()` → `UpdateCompany` | `target_exists`, `legal_name`, `verification_digit` | fila actualizada | R3-7 |
| `create_client` | `clientActions()` | `document_type`, `document_number`, `first_names`, `last_names`, `address`, `phone`, `email`, `linked_client_id` | `writeClient()` → `CreateClient` | `target_exists`, los cinco campos | fila en `clients` | R3-5/6 |
| `update_client` | `clientActions()` | idem + `approved_fields` | `writeClientUpdate()` → `UpdateClient` | ídem | fila actualizada | R3-20 |
| `create_social_entity` | **`ImportPlanBuilder` — sin productor en R2** | `name`, `type`, `source_key`, `entity_ref` | `writeSocialEntity()` → `ManageCatalogueEntities` | — | fila en `social_security_entities` | R3-12 |
| `create_relationship` | `relationshipActions()` | `client_*`, `company_tax_id`, `interval`, `overlap_resolution`, `parallel_reason` | `writeRelationship()` → `ManageClientCompanies` | existencia, `started_on`, `ended_on`, precisiones | fila en `client_company_assignments` | R3-29..31 |
| `close_relationship` | **`relationshipActions()` — sin productor en R2** | `client_*`, `company_tax_id`, `ended_on`, `ended_on_precision`, `reason` | `writeRelationshipClose()` → `ManageClientCompanies` | existencia, `started_on`, `ended_on` | `ended_on` escrito | R3-30 |
| `create_affiliation` | `affiliationActions()` | `client_*`, `entity_ref`, `type`, `interval`, `risk` | `writeAffiliation()` → `ManageAffiliations` | existencia, entidad, `type`, `started_on`, `ended_on`, precisiones | fila en `client_affiliations` | R3-17 |
| `close_affiliation` | **`affiliationActions()` — sin productor en R2** | `client_*`, `entity_ref`, `type`, `ended_on`, `ended_on_precision` | `writeAffiliationClose()` → `ManageAffiliations` | ídem | `ended_on` escrito | R3-17/18 |
| `create_rate` | `rateActions()` | `client_*`, `company_tax_id`, `effective_month`, `amount` | `writeRate()` → servicio A03 + `RateInUse` | existencia, `amount_cop`, `effective_month` | fila en `client_company_rates` | R3-24..27 |

### Orden de aplicación (§22 y Fix I)

```
create/update companies
→ create/update clients
→ create social entities
→ create relationships
→ close relationships
→ create affiliations
→ close affiliations
→ create/update rates
```

Una afiliación puede **referenciar** la entidad que una acción anterior va a crear, mediante
`entity_ref` + `source_key`. **No se fabrica un id antes de Apply**, y el mapping reutilizable se
escribe sólo después de que la entidad exista.

## 6. Lectura de precondiciones (Fix F)

§10 F exige distinguir dos cosas que PHP no puede confundir con `null`:

| Estado | Significado |
|---|---|
| observable + valor `null` | la columna está vacía en la base |
| no observable | esta acción no declara esa observación |

Si el builder emite una precondición `observed`, Apply **tiene** que poder releerla. Si Apply no
puede, el builder no la emite. Un resultado tipado, no `null`:

```php
Observation::of($value)          // observable, puede ser null
Observation::unsupported($field) // no observable
```

Lectores por familia: empresa (`legal_name`, `verification_digit`), cliente (`first_names`,
`last_names`, `address`, `phone`, `email`), relación (existencia, `started_on`, `ended_on`,
precisiones), afiliación (existencia, entidad, `type`, fechas, precisiones, riesgo), rate
(`amount_cop`, identidad de `effective_month`).

## 7. Invalidación del plan (Fix N)

§5.4 exige que tras una mutación el plan viejo no sea aplicable. Protocolo único, bajo
`FOR UPDATE` sobre `legacy_imports`:

```
1. lock legacy_imports FOR UPDATE
2. releer estado bajo el lock
3. rechazar applying / applied / cancelled
4. persistir la decisión o la política
5. invalidar SINCRÓNICAMENTE el plan revisado  (ready → review)
6. liberar el lock
7. despachar BuildLegacyImportPlan after commit
```

Aplicar devuelve `wrong_state` o `stale_plan`, nunca el plan viejo en `ready`. La resolución
masiva usa **una** sección crítica para las veinte decisiones, no una por decisión.

## 7bis. Estado verificado en la implementación (R3)

Todo lo de abajo está **probado por `tests/Feature/A04/A04R3ContractClosureTest.php`** y por la
suite completa (`1175 passed`, `5153 assertions`). Cada fila dice qué ruta se cerró y qué defecto
producía antes.

### Defectos de producción encontrados y corregidos

| # | Defecto | Qué producía | Prueba |
|---|---|---|---|
| 1 | `linkedClientId()` se consultaba con `TYPE:NUMBER` y el productor escribía `client:TYPE:NUMBER` | Toda respuesta `link_existing_client` era **inalcanzable**; el cliente se creaba desde el documento origen y relaciones/rates apuntaban a alguien inexistente | `§7.1: link_existing_client …` |
| 2 | `unresolved_social_entity` no tiene `row_id`, y su rama de índice estaba dentro de `if ($sourceKey !== null)` | `canonicalOf` nunca se llenaba: **`create_entity` no tenía efecto** y `CreateSocialEntity` seguía sin productor | `§9.3: create_entity …` |
| 3 | `writeSocialEntity()` pasaba el enum a un servicio que espera `string` | `TypeError` en la **primera** ejecución del writer — posible sólo porque nunca hubo productor | `§9.3: create_entity …` |
| 4 | El puente `amount_cop → monthly_amount_cop` comparaba sobre copias y **reportaba sobre los originales** | Todo `existing_rate_conflict` llegaba como `observed: null, proposed: null, blocking: false` | `§10: a differing rate …` |
| 5 | `raiseIssue()` re-derivaba `blocking` en cada rebuild | Responder → rebuild → bloqueado otra vez → **bucle de revisión infinito** | `§11: a differing master value …` |
| 6 | `ARRAY_FILTER_USE_KEY` con un callback tipado `string $rule` | Las claves `optional_*` seguían siendo **obligatorias**: `use_company_nit` era imposible de responder | `§7.2: use_company_nit …` |
| 7 | `names_by_month` guardaba un nombre por mes | Dos grafías en el **mismo** bloque no se veían: el conflicto que §7.1 existe para detectar era invisible | `§7.1: two names …` |
| 8 | `existingConflictFor()` trataba `['record']` como campo y no consultaba la marca `identical` | Una relación **idéntica** se re-elevaba como conflicto bloqueante: reimportar un archivo sin cambios dejaba el lote en `review` para siempre | `§11: an identical relationship …` |

### Contratos cerrados

| Contrato | Antes | Ahora |
|---|---|---|
| `client_identity_conflict` | Sin productor | Productor bloqueante, una pregunta por identidad, observado desde las filas |
| `CreateSocialEntity` | 0 productores | Productor antes de las afiliaciones; Apply resuelve por nombre el que crea el mismo lote |
| `create_entity` | Validado, guardado, ilegible | Legible → crea el catálogo → la afiliación lo referencia |
| `existing_*` (§11) | Todos no bloqueantes | Conflicto real bloquea; enriquecimiento de campo vacío es propuesta visible |
| `overwrite_with_source` | Ofrecido para historial | Retirado del vocabulario de relaciones/afiliaciones |
| `§11 coincidencia exacta` | Re-elevado como conflicto | `identical` respeta el no-op |
| `companyNitFor` | Leído, identidad no reemplazada | `EffectiveCompanyIdentity` en `hydrate()`; toda la cadena usa la identidad efectiva |
| `link_existing_client` | Muerto | Clave centralizada; propagado a relaciones, afiliaciones y rates |
| `ImportPlanBuilder::approvalIndex()` | Duplicaba una consulta | Eliminado; `ImportDecisionSet::approvedFields()` es la única lectura |
| `keepsExisting` / `existingDecision` / `overwritesWithSource` | Lectores públicos muertos | Eliminados (`existingDecision` privado) |

### Pendiente en R3

| Contrato | Situación |
|---|---|
| `duplicateOf` / `keepsBothRows` | Sin consumidor. `duplicate_conflicting_row` bloquea, pero `keep_both` y `treat_as_duplicate_of` no alteran la reconstrucción |
| `CloseAffiliation` | Writer sin productor |
| `CloseRelationship` | Writer sin productor |
| `entityDecision` | Sin consumidor externo (sólo lo usa `mappedEntityId` internamente) |
| DV field-by-field | `company_verification_digit_conflict` y `existing_company_conflict` siguen siendo dos rutas para la misma pregunta |
| Fechas inválidas | `set_date` / `use_suggested_date` no reparan la fila tras el rebuild |
| Rate update-in-place | `writeRate` puede crear duplicado en lugar de actualizar |
| Precondiciones observables | Los `preconditions` no exponen todo lo que el reviewer necesita |
| Invalidación y carreras | `plan_revision` / `plan_digest` bajo carrera PostgreSQL real |
| Frontend y E2E | Tipado de decisiones y representing de §11/§9.3 |
| `docs/TASKS/A04-R3.md` | Pendiente de escritura |

## 8. Criterio de «cerrado»

Una fila de esta matriz está cerrada cuando existe una prueba que:

```
crea el fixture → construye el plan → localiza el issue → POST al endpoint real de
resolución → reconstruye → comprueba el payload y las precondiciones persistidas →
llama al endpoint real de Apply → comprueba las filas maestras finales → comprueba
auditoría/proveniencia → comprueba que no se creó nada financiero de A03
```

Ninguna de estas pruebas se conforma con «HTTP 200», «`resolved_at` escrito» o «la acción existe».

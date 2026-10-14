# FIX-IMPORT-02 — Datos incompletos en el libro: normalizar lo determinista, preguntar sólo lo humano

**Estado:** cerrado técnicamente · una corrección queda fuera de este hito (§7)
**Ámbito:** el libro de nómina real produce 210 hallazgos y casi ninguno era una pregunta. Este
hito reduce la revisión a lo que un humano realmente tiene que decidir, sin inventar datos.

Criterio de aceptación: **`.local-fixtures/EMPRESA BLINDEN AÑO 2026.xlsx`** (2 560 filas,
10 hojas, 320 identidades). Las fixtures sintéticas no bastan; el libro real es el que se
corrigió.

---

## 1. El resultado, en una tabla

| | Antes | Después |
|---|---:|---:|
| Hallazgos que ve el revisor | 210 | 195 |
| **Tipos** de hallazgo distintos | 8 | 8 |
| Tipos que exigen una decisión | 5 (57 hallazgos) | **5 (42 hallazgos)** |
| Tipos informativos (nada que responder) | 0 | **3 (153 hallazgos)** |
| Hallazgos **bloqueantes** | 58 | **3** |
| Fechas de afiliación no leídas | 17 | **2** (y ninguna bloquea) |
| `unknown_risk_token` | 3 | **0** |
| `company_arl_metadata_conflict` | 10 | **0** |

Los 153 informativos son 77 correos con formato inválido, 56 meses sin valor mensual y 20
posibles credenciales. **Ninguno se pierde**: siguen en la lista, agrupados y marcados como
informativos. Antes cada uno llevaba un botón «Resolver».

---

## 2. La corrección que underlying más de la mitad: dejar de ofrecer «Excluir fila»

`invalid_email` y `missing_monthly_value` eran `blocking: true` **y** su única resolución
posible era `skip_row`. Traducido a la pantalla: 77 avisos de «correo con formato inválido», cada
uno con un botón, cada botón con una sola opción, y esa opción **excluir la fila**.

Apretar «Resolver» en 77 de esos avisos para dejar la lista limpia **habría eliminado 77
clientes de la importación**. §7.1 y §10 ya decidieron el resultado de esos dos casos: el correo
no se escribe, el mes no genera rate. No había nada que decidir.

- `IssueResolutionDecision::allowedFor()` ya no ofrece ninguna resolución para los dos.
- `LegacyImportIssue::isBlocking()` y `ParsedWorkbook::NON_BLOCKING` ya no los cuentan como
  bloqueantes.
- `ContractClosureTest` afirma las dos reglas, incluida la de que **no** se ofrezca ninguna
  decisión: un botón que invita a «excluir la fila» para quitar un aviso de correo ausente es
  exactamente cómo se pierde una persona de una importación.

`duplicate_exact_row` y `credential_like_content` ya no bloqueaban; `affiliation_entity_unknown`
tampoco, por el motivo de §3.

---

## 3. Corrección C — `SI` sin entidad: registrar el hueco en vez de perder el hecho

El archivo escribe **`SI`** en la columna AFP de 36 filas. `SI` **sí** es una afirmación — la
persona estaba afiliada ese mes — y §9.1 prohíbe inventar a qué entidad.

Antes sólo había dos salidas, y las dos están mal: bloquear el lote hasta que alguien nombre el
fondo, o no registrar nada y dejar que A03 lea «sin AFP ese mes» de alguien que sí la tenía. La
segunda es la peligrosa, porque es silenciosa.

**Medido antes de decidir nada.** Se buscó la entidad en el propio archivo: 36 filas, todas AFP.
Un primer sondeo parecía encontrar 9 recuperables, pero era un fallo del sondeo — contaba
`NO PORVENIR` como si fuera un nombre, cuando es una **negación**. Corregido, **exactamente 1** de
36 tiene un nombre en otra hoja, y ese uno es una inferencia, no evidencia. En el maestro: 0
afiliaciones para esas 36 personas. **Las 36 son genuinamente indecidibles desde el archivo.**

La tercera salida es una nueva tabla:

| | |
|---|---|
| Tabla | `client_affiliation_observations` |
| Por qué no una `social_security_entity_id` nullable | `client_affiliations` la tiene `NOT NULL` **a propósito**, y toda consulta que lee el historial de salud de un cliente pasa por ahí. Volverla nullable empuja «¿cuál era el fondo?» a cada una de esas consultas, y la primera que olvide el `null` devuelve «sin afiliación» — el falso negativo que esta tabla existe para evitar. |
| Qué dice | *que* había afiliación, en qué meses, y desde cuándo se vio. **No** dice a qué entidad. |
| Qué no dice | Nada. No crea `client_affiliations`, no crea `social_security_entities`, no infiere de otros meses. |
| Superficie de lectura | `GET /api/clients/{client}/affiliation-observations`, endpoint **nuevo**. |

**Ninguna consulta existente se modificó.** Es una restricción deliberada: A03 lee
`client_affiliations` para decidir qué puede facturar, y una observación no debe llegar jamás a
esa lectura. Separarlas por consulta —en vez de por un `nullable`— es lo que garantiza que un
registro que nadie puede confundir no rompa una pantalla que hoy funciona.

§9.5 («no dos afiliaciones abiertas del mismo tipo») **no** se extiende a las observaciones: una
observación no es una cobertura, así que dos de ellas no significan más que una, y prohibirlas
borraría evidencia sin ganancia.

`affiliation_entity_unknown` deja de bloquear por la misma razón que los correos: **dejar pasar ya
no pierde un hecho.** El hallazgo sigue apareciendo (§18 lo exige, y ocultarlo escondería que el
archivo estaba incompleto) y sigue ofreciendo las decisiones que sí sirven —nombrar el fondo—,
pero nada de eso es necesario para aplicar.

---

## 4. Corrección D — fechas: 17 → 2

Las 17 celdas no leídas del archivo real, medidas una por una:

| Problema | Filas | treatment |
|---|---:|---|
| `truncated_year` (`01/02/206`) | 13 | corregidas con evidencia |
| `unreadable_date` (`29/122025`) | 2 | corregidas sin ambigüedad |
| `not_affiliated` (`NO`) | 1 | ya era una **respuesta**, no un defecto |
| `missing_date` (vacía) | 1 | ya era un **desconocido**, no un defecto |

Las dos normalizaciones:

1. **`d/mYYYY`** — `29/122025`. No es una conjetura: el grupo de cuatro dígitos sólo puede ser el
   año, así que ninguna reordenación de los tres grupos da otra fecha. La regla habitual de
   «ambos ≤ 12, podría ser de las dos convenciones» **no aplica**, porque existe precisamente
   porque en `d/m/y` el año puede ser pequeño, y aquí no.
2. **Año truncado** — `01/02/206` en la hoja de septiembre de 2026 se lee `01/02/2026`. La
   evidencia es **el año de la hoja**, con dos condiciones que impiden que esto sea una sustitución
   global: el candidato debe estar en el año de la hoja ±1, y los dígitos deben ser ese año con
   **un dígito eliminado** (`206` encaja en `2026`; no encaja en `2025` ni en `2027`). Si encaja
   exactamente uno, se usa. Si encajan ninguno o más de uno, la celda sigue siendo una sugerencia
   para una persona.

Las dos que quedan **no son preguntas**: `NO` es una afirmación y una celda vacía es un
desconocido, y `affiliations_dates_check` ya admite un inicio desconocido precisamente para
poder conservarlo. Se distinguen por
`SourceAffiliationDate::isUncertainty()`, así que la clasificación vive en el valor y no se
re-deriva en otro sitio.

---

## 5. Correcciones E, F, G

- **E — `UMO` → `UNO` → riesgo 1.** §9.4 exige un **mapeo aprobado**, y se añadió como tabla
  cerrada (`imports.approved_risk_tokens`), no como regla. Se eligió tabla y no Levenshtein
  precisamente porque `looksLikeTypo()` **ya calculaba** la distancia a una palabra y actuar sobre
  esa distancia habría «arreglado» cualquier cosa cercana. La prueba nueva lo demuestra: `TREX` y
  `CINCS` están **tan cerca** de una palabra real como `UMO` lo estaba de `UNO` —un carácter, misma
  longitud— y siguen sin resolverse. `raw` conserva `UMO`, así que la corrección queda visible.
- **F — ARL frente a cargo.** El encabezado de un bloque y el título de la empresa discrepan en 10
  filas. Cuando el valor del encabezado es **el cargo de esa misma fila**, no hay conflicto:
  `RiskColumns::isJobTitle()` lo detecta por contenido (comparación plegada), sin lista de cargos
  que mantener. 10 → 0.
- **G — duplicados.** El exacto ya se consolidaba solo; ahora además **no ofrece decisión**
  manual, que es lo que pedía el hito («no exigir resolución manual»). El conflictivo sigue
  siendo pregunta real: son dos filas que dicen cosas distintas.

---

## 6. Corrección J — la revisión agrupada

`GET /api/imports/{import}/issues` devuelve ahora `groups`: los mismos hallazgos contados por
tipo, con `total`, `open`, `blocking` y **`resolvable`**. Se **extendió** el endpoint existente en
vez de añadir una ruta.

`resolvable` es el campo que sostiene todo: es `false` para los tipos cuyo resultado §7.1, §8.3,
§9.1 o §10 **ya decidió**, así que ofrecer «Resolver» en ellos invita a apretar el único control
disponible.

La pantalla muestra primero el mapa —5 tipos que requieren decisión, 3 informativos— y después
la lista, que no cambia. Los informativos se muestran en gris, no se ocultan: 153 hallazgos que
desaparecen en silencio dejarían al revisor sin poder distinguir un lote limpio de una pantalla
rota. Un tipo sin botón dice **«Nada que responder»** en vez de una celda vacía.

**Un fallo real que costó tiempo y queda escrito**, porque no es visible en el diff: `paginate()`
escribe `limit`/`offset` **sobre el builder** (`forPage()` los pone como propiedades), así que un
`clone` posterior los hereda. `reorder()` limpia el orden pero no la paginación, y el desglose
devolvía silenciosamente **una sola fila** — la de la página que se estaba viendo—, lo que se lee
como «este lote tiene un solo tipo de hallazgo» en un lote que tiene ocho.

Si el servidor no envía `groups` (campo nuevo, servidor viejo, petición fallida), la pantalla
**vuelve al comportamiento anterior** en vez de esconder todos los botones.

---

## 7. Lo que **no** se hizo, y por qué

### El conflicto histórico de fechas de inicio — RESUELTO

> **Actualizado por el cierre quirúrgico.** La medición que este hito dejó escrita aquí fue
> exactamente la base del arreglo: lo que faltaba no era el diagnóstico, era la pregunta.
>
> Véase §11.

FIX-IMPORT-02 dejó este residuo documentado, y la medición de abajo es la base del cierre.

Un cliente del libro figura en **un mismo empleador** con fechas de inicio mutuamente
excluyentes; el plan llevaba dos relaciones abiertas con un mismo empleador, A02 no puede
representarlo y §12.3 revierte el lote. Es el residuo ya documentado en FIX-LOCAL-01 §2.

Al medirlo para este hito resultó que el caso **no era único**: **168 parejas
(cliente, empleador)** tienen esa forma, y sólo **2** sobreviven a §8.4. La medición queda
escrita aquí porque el arreglo se apoya en ella:

| Perfil | Inicios declarados | Meses en que conviven |
|---|---|---|
| Cambio a mitad del archivo | 1 en el mes 1, otro en el mes 3 | 2 meses |
| Cambio a mitad del archivo | 1 en el mes 1, otro en el mes 3 | 2 meses |
| Cambio a mitad del archivo | 1 en el mes 1, otro en el mes 3 | 2 meses |
| Cambio **dentro del mismo mes** | 1 en el mes 1, otro en el mes 2 | 1 mes |

Los identificadores de esas parejas **no se reproducen aquí**: son documentos y NITs reales de
una nómina real, y este fichero está versionado. La forma basta para entender el bloqueo.

Arreglarlo exigía una decisión sobre **qué fecha de inicio vale**, es decir un hallazgo nuevo,
decisiones nuevas y un cambio en la ruta de relaciones del plan — **extender el modelo de
dominio**, que FIX-LOCAL-01 §2 Bis dejó explícitamente fuera. Este hito no lo hizo; el cierre
quirúrgico sí.
### Lo demás que no se tocó

- `SensitiveSourceRedactor` y la redacción de credenciales: **sin cambios**. Los 20 hallazgos de
  credencial se **agrupan** y pasan a informativos; la redacción sigue igual.
- El agrupado `groups` no se añadió a `rows`, ni al endpoint de plan, ni a ningún otro.
- `InvalidEmail` y `MissingMonthlyValue` no se fusionaron en un código nuevo: sus semánticas son
  distintas y §18 los lista por separado.

---

## 8. Verificación

Todo contra el **libro real**, en la base E2E aislada (`consultora_dh_e2e`). La base principal no
se tocó.

| Comprobación | Resultado |
|---|---|
| `RealWorkbookFlowTest` (PHPUnit, base real) | **PASS** — 1 244 aserciones |
| `scripts/verify-a04-real-workbook.php` | **PASS** — *Fingerprint matches*, salida 0 |
| `tests/Feature/A04` + `tests/Unit/A04` | **PASS** — 190 |
| `tests/Feature/A02/AffiliationTest.php` (superficie de lectura) | **PASS** |
| Los 5 ficheros que tocan `LegacyImport` fuera de A04 | **PASS** — 93 |
| `vitest tests/frontend/imports/imports.pages.spec.ts` | **PASS** — 20 |
| `vue-tsc` | 57 errores, **exactamente los mismos** que antes de este hito (ficheros `tests/frontend/*` sin `@types/node`) |
| `eslint` sobre los ficheros tocados | limpio |
| `pint --test` | 9 ficheros con violations, **las mismas 9** de antes |

Sobre el verificador: la cuenta `invalid_affiliation_date = 17` **no se bajó a 2**. Ese 17
describe lo que **contiene el archivo**, no lo que el importador conserva, y bajar el número
habría convertido una guarda de regresión en un trinquete: un cambio que **rompiera** las
normalizaciones seguiría passando. Ahora se afirman **tres** números —17 en el archivo, 15
corregidos, 2 residuales— donde antes había uno, y la guarda quedó **más fuerte**.

---

## 9. Migraciones

Aplicadas primero en la base E2E y sólo después, con `--path` explícito, en la principal.

| Migración | Qué hace |
|---|---|
| `2026_10_14_090000_create_client_affiliation_observations_table` | La tabla de §3. |
| `2026_10_14_100000_allow_affiliation_observation_actions` | Recrea `legacy_import_actions_type_check`. |

La segunda existe por una trampa que costó un 409 inexplicable:
`legacy_import_actions_type_check` se genera desde `ImportActionType::cases()` **cuando corre la
migración que crea la tabla**. Añadir un caso al enum no cambia la restricción que ya está en la
base, así que el constructor del plan empezó a insertar un valor que la base rechazaba — y el
síntoma era un `409 wrong_state` en Apply, porque el job del plan había muerto y el lote nunca
llegaba a `review`. **Nada tenía que ver con el estado del lote.**

La restricción **se conserva**: es el mecanismo detrás de «una acción de un tipo que nadie
implementa es una fila que el apply no puede ejecutar», y quitarla para admitir un tipo nuevo
eliminaría la garantía para todos los tipos futuros.

`MigrationMatrixTest` incluye las dos en `a04Migrations()`, en orden cronológico inverso. Sin eso
el rollback.drop de `legacy_imports` falló con *dependent objects still exist*.

---

## 10. Ficheros

**Dominio**
`app/Domain/Imports/`: `SourceAffiliationDate.php` (normalizaciones y `isUncertainty()`),
`ParsedWorkbook.php` (`NON_BLOCKING`, `blocksFor`, `severityFor` con la fila),
`LegacyImportIssue.php` (`NON_BLOCKING`), `IssueResolutionDecision.php`,
`RiskColumns.php` (tabla aprobada + `isJobTitle()`), `SourcePersonRow.php`, `SheetMonth.php`
(`yearOf()`), `ImportActionType.php`, `ImportPlanBuilder.php`, `Actions/ApplyImportPlan.php`.

**Persistencia y API**
`app/Models/ClientAffiliationObservation.php`,
`app/Http/Resources/AffiliationObservationResource.php`,
`app/Http/Controllers/Api/ImportController.php` (`FINDING_LABELS`, `groups`),
`app/Http/Controllers/Api/ClientController.php`, `routes/api.php`, `config/imports.php`.

**Pantalla**
`resources/js/pages/imports/ImportDetailPage.vue`, `resources/js/types/api.ts`,
`resources/css/app.css`.

**Pruebas**
`tests/Feature/A04/ContractClosureTest.php`, `tests/Feature/A04/MigrationMatrixTest.php`,
`tests/Feature/A02/AffiliationTest.php`,
`tests/Unit/A04/BlindenLegacyWorkbookParserTest.php`,
`tests/frontend/imports/imports.pages.spec.ts`, `scripts/verify-a04-real-workbook.php`.

---

# 11. Cierre quirúrgico

Dos incumplimientos concretos del alcance original. Nada de lo anterior se reabrió.

## 11.1 Afiliaciones desconocidas: la ficha las muestra y se pueden cerrar

La tabla y el endpoint existían; **la ficha no los consultaba**. Una observación que sólo existe
en la base de datos es un dato que nadie ve, y §7.1 lo llama una afiliación que no se escribe.

### Lo visible

Una tarjeta nueva en la pestaña Afiliaciones, **debajo** de las dos tablas de afiliaciones
formales y nunca mezclada con ellas:

| Columna | Por qué |
|---|---|
| Tipo | Del valor de `type` del servidor, no de una lista propia. |
| Entidad | La palabra **«Sin identificar»**. Nunca un `—` vacío, que se lee como dato ausente. |
| Meses declarados | `2026-03, 2026-04` — los meses que el archivo afirma, que son la evidencia. §8.3: son precisión de mes y **no** se muestran como días. |
| Procedencia | `#<importación>`, de `legacy_import_id`. Sin él, un hueco no se puede rastrear al archivo. |
| Estado | «Entidad pendiente de identificar» o «Entidad identificada». |
| Acción | «Identificar entidad», sólo para quien tenga `affiliations.manage`. |

El texto de la tarjeta dice explícitamente que **no son afiliaciones formales** y que no se usan
para facturar ni para calcular cobertura. Una separación visual sin esa frase deja al lector
decidir por su cuenta si cuenta o no.

El estilo es un borde punteado, no rojo: un hueco hay que verlo, no asustar con él. Y una
observación ya resuelta se atenúa en lugar de desaparecer — registra lo que el archivo dijo, y el
archivo sigue diciendo `SI`; quitarla borraría la procedencia y haría que el historial pareciera
más limpio de lo que es.

### El flujo de identificación

`POST /api/clients/{client}/affiliation-observations/{observation}/resolve`, con
`affiliations.manage`.

Lo que ese click hace está escrito en el diálogo antes de confirmarlo: **crea una afiliación
formal**, que pasa a contar para cobertura y facturación. Un botón que sólo dijera «Guardar» dejaría
creer a quien lo aprieta que está anotando una nota cuando está registrando cobertura.

Tres reglas, y las tres están probadas:

- **No duplica.** Si ya hay una afiliación real del mismo tipo que cubre los meses observados,
  responde `409 already_answered` y no escribe. El botón desaparece cuando la observación está
  resuelta, así que el doble clic no llega ni a intentarlo.
- **No acepta una entidad de otro tipo.** El `type` lo trae la observación — es lo único que
  realmente sabe — y se compara con el de la entidad elegida.
- **No cambia la fecha de inicio.** `started_on` está **prohibido** en la petición: «¿cuál fondo?» y
  «¿desde cuándo?» son dos preguntas. El diálogo no ofrece ningún campo de fecha, porque ofrecer un
  campo que el servidor va a rechazar enseña que los campos son decorativos.

La escritura pasa por `ManageAffiliations`, no por el controlador. Tres razones: una fila de
`client_affiliations` **es** una afiliación y tiene que pasar por los invariantes de A02 (§9.5, los
bloqueos cliente/entidad, las comprobaciones de estado); la auditoría es la de A02, así que §17.4
responde «quién cambió esto»; y `AffiliationAlreadyExists` ya trae las frases que hay que mostrarle
a una persona.

La **observación no se borra ni se edita**. La nota que se escribe en la afiliación sí dice de qué
observación viene y qué meses vio el archivo, para que dentro de tres años se distinga un fondo
que nombró una persona de uno que escribió el archivo.

### Lo que no cambió

Ninguna consulta financiera. La prueba lo dice sobre una persona que tiene **las dos cosas** — una
EPS formal y una observación de AFP sin identificar: el endpoint de afiliaciones devuelve una fila,
el payload completo de la ficha devuelve una activa y cero histórico, y la observación aparece sólo
en su propio endpoint. A03 lee `client_affiliations`; una observación nunca llega ahí.

## 11.2 El conflicto histórico: pregunta revisable, plan recalculado

### Qué es exactamente

De las 168 parejas con dos o más episodios en un mismo empleador, §8.4 responde 167. Las **2** que
sobran se resisten por una razón estructural, no por un descuido:

> Ordenar los episodios por la fecha que **declaran** da una secuencia distinta de ordenarlos por
> el mes en que se los **vio**.

Ejemplo del archivo real, tres meses, tres fechas:

```text
  observado en 2026-06, 2026-07   declara inicio 2026-06-18
  observado en 2026-09           declara inicio 2026-09-28
  observado en 2026-10           declara inicio 2026-07-28   <-- anterior al mes anterior
```

El tercer episodio se vio **después** del segundo y empieza **antes** que él. O la persona cambió
de empleador sin que el archivo lo diga, o una de las tres fechas está mal. Las dos lecturas son
plausibles y el archivo no permite elegir entre ellas.

Por eso §8.4 no puede preguntar (pregunta por un episodio que **deja de aparecer**, y este vuelve a
aparecer en el último mes) ni §8.5 (se salta a propósito dos episodios del **mismo** empleador,
porque esa forma es dos episodios de un mismo empleo y no un traslade). Por eso el episodio llegaba
a `apply` como dos relaciones abiertas, A02 rechazaba la segunda y §12.3 revertía el lote entero:
un rechazo que nombraba una regla que nadie había sido preguntado.

### La prueba que lo detecta, y por qué no es más amplia

La prueba es la **discordancia entre las dos ordenaciones**, no «dos fechas, un empleador». La
segunda coincide en **168** parejas, de las cuales 167 son reingresos ordinarios que §8.1 separa
correctamente y §8.4 ya responde. Preguntar por esas sería añadir 167 preguntas para tapar dos.

`verify-a04-real-workbook.php` afirma el conteo como **2 exactos**. Si la detección se rompe sale
`0`; si se ensancha sale `168`. Las dos fallos son legibles sin leer el código.

### Las dos respuestas, y por qué ninguna inventa

| Decisión | Significado |
|---|---|
| `use_earliest_stated_start` | La fecha de inicio es la más antigua que declara el archivo. |
| `use_latest_stated_start` | La fecha de inicio es la más reciente que declara el archivo. |

Ambas se resuelven a **una fecha que el archivo escribió**. `ImportDecisionSet::statedStartFor()`
compara el valor guardado contra `ContradictoryStarts::statedStarts()` y devuelve `null` si no
está. Un `start` inventado deja la fusión sin hacer y **el lote bloqueado**: hay una prueba que lo
comprueba y dice por qué en voz alta.

No hay campo de texto libre. «Elegir una fecha» no puede convertirse en «escribir una fecha».

### El plan recalculado

La fusión ocurre en `HistoryReconstructor::applyStartDecisions()`, **antes** de que el intervalo
se convierta en una acción del plan. Es el mismo lugar donde §8.4 y §8.5 aplican sus respuestas, y
por el mismo motivo: una fecha que eligió una persona tiene que estar en el intervalo *antes*, o el
`plan_digest` cubriría una explicación que no es la que se aplicó.

El episodio fusionado **conserva todos los meses** que el archivo listó. La decisión es sobre **una
fecha**; una fusión que descartara un mes borraría parte del empleo de una persona en silencio.

Sin traslado, sin empleo paralelo, sin tocar los otros casos: `findOverlaps()` sigue saltándose
pares del mismo empleador, y las 167 parejas ordinarias no generan hallazgo nuevo.

### El resultado

`RealWorkbookFlowTest` ya **no tiene rama de residual**. Afirma que el lote aplica entero
(`200`/`202`), que ninguna acción quedó en `failed`, y que **ningún cliente queda con dos
relaciones abiertas con un mismo empleador** (§9.5, por consulta directa).

Eso es más fuerte que «como mucho una acción puede quedar varada», y no se puede satisfacer
ampliando una tolerancia. Assertions: **1783** (antes 1246, con la mitad del lote rechazada).

## 11.3 Tres trampas, y por qué están escritas en el código

Ninguna es visible en el diff, y las tres seumbuscan como otra cosa.

1. **`legacy_import_issues_code_check`** se genera desde `LegacyImportIssue::cases()` cuando corre
   la migración que crea la tabla. Añadir un caso al enum no cambia la restricción que ya está en
   la base. Síntoma: **409 `wrong_state`** en Apply, porque el job del plan había muerto y el lote
   nunca llegó a `review` — nada que ver con el estado del lote.
2. **`ImportDecisionSet` sólo indexaba dos códigos** en su cubo de decisiones de intervalo. La
   respuesta se guardaba, la incidencia quedaba resuelta, el plan se reconstruía… y los episodios
   **no se fusionaban**. Una resolución que nadie lee es peor que no preguntar: es un sistema que
   aparenta funcionar.
3. **`reconcileIssues()` acotaba su alcance a esos mismos dos códigos.** El caso nuevo quedaba
   fuera, así que la rama «mismo hallazgo, refrescado en sitio» nunca se tomaba y el rebuild
   intentaba insertar un segundo con el mismo `fingerprint`. El índice único lo rechazó, el job
   murió, y el síntoma fue el mismo `wrong_state` de (1).

La primera y la tercera son la **misma** trampa que ya había salido en
`2026_10_14_100000_allow_affiliation_observation_actions`. Un `CHECK` generado desde un enum no se
actualiza solo: es una decisión de esquema que hay que volver a tomar cada vez que el enum crece.

## 11.4 Verificación

Sólo pruebas dirigidas y el Excel original, en la base E2E aislada. La principal no recibió los
datos originales.

| Comprobación | Resultado |
|---|---|
| `RealWorkbookFlowTest` (libro real, base real) | **PASS** — 1783 aserciones, **lote aplicado entero** |
| `scripts/verify-a04-real-workbook.php` | **PASS** — *Fingerprint matches*, salida 0, `contradictory_starts = 2` |
| `tests/Feature/A04` + `tests/Unit/A04` | **PASS** — 197 |
| `tests/Feature/A02/AffiliationTest.php` | **PASS** — 68 |
| Los 4 ficheros externos que tocan `LegacyImport` | **PASS** — 62 |
| `vitest clientDetail.spec.ts` | **PASS** — 19 |
| `vitest imports.pages.spec.ts` | **PASS** — 20 |
| `npm run typecheck` | 57 errores, **los mismos 57** que antes del cierre |
| `eslint` sobre los ficheros tocados | idéntico a la línea base |
| `pint --test` | 9 ficheros, **las mismas 9** que antes |

### Datos y volúmenes

Base principal, antes y después de la migración:

| Tabla | Antes | Después |
|---|---:|---:|
| users | 67 | 67 |
| clients | 5 | 5 |
| companies | 1 | 1 |
| client_affiliations | 4 | 4 |
| client_company_rates | 1 | 1 |
| monthly_obligations | 1 | 1 |
| payments | 1 | 1 |
| payment_allocations | 1 | 1 |
| legacy_imports | 1 | 1 |
| client_affiliation_observations | — | **0** (tabla nueva, sin datos) |

El caso H del bloque manual sigue exacto: cliente 4 (Alexandra Gislason), obligación 30 000, pagado
15 000, una imputación, vencimiento 2026-11-10. Migraciones pendientes: **0**.

En la base E2E, tras aplicar el libro real: **10 observaciones** en el maestro, una por persona y
tramo, correspondientes a las 36 filas que el archivo declara como `SI`.

### Migraciones de este cierre

| Migración | Qué hace |
|---|---|
| `2026_10_14_110000_allow_contradictory_starts_issues` | Recrea `legacy_import_issues_code_check`. |

Su `down()` **borra** las filas del código retirado antes de restaurar la restricción: dejar filas
que la restricción restaurada rechazaría es un estado que nadie debería poder observar.

# FIX-EXPORT-IMPORT-03

Revisión funcional de importaciones, cancelación de lotes y exportación del libro operativo.

Base: `74e43865cfe59c120e4102845d35c6ae24e0b456`

---

## 0. El hallazgo que abre el hito: no era la caché

El informe decía que el navegador seguía mostrando 195 filas sin resumen agrupado pese a haber
borrado la caché. **No era la caché**, y hay evidencia:

| Evidencia | Resultado |
|---|---|
| `public/build/assets/` compilado a las 01:03 | el bundle servido es anterior |
| `ImportDetailPage.vue` modificado a las 11:33 | el fuente es posterior |
| `grep "Requieren una decisión" public/build/assets/` | **cero coincidencias** |
| `grep "cdh-issue-summary" public/build/assets/` | **cero coincidencias** |
| `git ls-files public/build \| wc -l` | `0` — el bundle no está versionado |

`@vite` lee `public/build/manifest.json`, y ese directorio está en `.gitignore`. El bundle se
genera con `npm run build`, y el build **fallaba**:

```
[vite]: Rolldown failed to resolve import "laravel-echo" from
       "resources/js/stores/realtime.ts"
```

`laravel-echo@^1.18.0` y `pusher-js@^8.4.0` están en `package.json` (commit `b60ba48`, A06) pero no
estaban instalados. Nadie podía reconstruir el bundle, así que el navegador recibía fielmente un
build de hace diez horas. Borrar la caché no podía ayudar: el servidor no tenía un asset más nuevo
que servir.

**Los tests frontend pasaban y el navegador no.** Vitest compila desde el fuente; el navegador
descarga el bundle. Son dos cosas distintas, y sólo una estaba verde.

Corregido: `npm install laravel-echo pusher-js --no-save` (ya declarados en `package.json`;
`--no-save` porque no es este hito el que decide esa dependencia), `npm run build`, y verificación
de que las tres cadenas de la UI nueva están en el asset servido.

**Consecuencia pendiente para despliegue:** `public/build` no está versionado. Cualquier
despliegue nuevo **debe** ejecutar `npm ci && npm run build` o repetirá exactamente este síntoma.

---

## BLOQUE A — Revisión de incidencias

### A.1 Causa real del «botón Resolver» en todas partes

La condición era:

```js
resolvableCodes.size === 0 || resolvableCodes.has(issue.code)
```

Cuando `groups` faltaba —o la petición había fallado— el `||` corto-circuitaba a `true` y **todas**
las filas recibían «Resolver». Una respuesta incompleta activaba los controles. En el libro
entregado eso ponía 77 botones sobre `invalid_email`, cuya única decisión era «excluir fila»:
limpiarthose botones habría eliminado 77 clientes.

Ahora el botón lee `issue.allowed_decisions` y exige `resolves === true`:

- es el contrato del backend, calculado donde se definen las decisiones;
- es **por incidencia**, no por grupo, así que 77 filas idénticas no pueden discrepar;
- sobrevive a un fallo del agregado: si la petición falla no hay incidencias, y hay un mensaje de
  error con «Reintentar»;
- `ContractClosureTest` mantiene con nombre la lista de hallazgos sin decisión que resuelva, y este
  es su único lector.

`groups.resolvable` sigue usándose, pero para **presentar** el resumen, no para decidir un botón.

### A.2 `duplicate_exact_row`: se ofrecían tres decisiones que nadie leía

`IssueResolutionDecision` ofrecía `accept_source`, `treat_as_duplicate_of` y `keep_both` para
`DuplicateExactRow`. Búsqueda de todos los lectores:

```
$ grep -rn "DuplicateConflictingRow" app/ --include=*.php
ImportDecisionSet.php:630:keepsBothRows()      -> DuplicateConflictingRow
ImportDecisionSet.php:637:duplicateOf()        -> DuplicateConflictingRow
```

**Cero** lectores de `DuplicateExactRow`. Un revisor podía abrir el diálogo, pulsar un botón, ver la
incidencia pasar a «Respondida» y ver el libro de importación exactamente igual. §7.3 ya consolida
un duplicado exacto automáticamente: no queda nada que decidir.

Corregido: `DuplicateExactRow` withdrawal de las tres listas. `AcceptSource` conserva
`InvalidAffiliationDate` + `DuplicateConflictingRow`; `TreatAsDuplicateOf`/`KeepBoth` conservan
`DuplicateConflictingRow`.

### A.3 Tres categorías, no dos

El resumen anterior unía bloqueantes y opcionales bajo «Requieren una decisión», lo que enseña a
tratar los bloqueantes como opcionales — exactamente el fallo que la pantalla existe para evitar.

| Categoría | En el libro entregado | Se muestra |
|---|---:|---|
| Bloquean la importación | 3 | abierta, con borde rojo |
| Opcionales (se puede continuar) | 38 | abierta |
| Informativas (nada que responder) | 154 | **colapsada** |

Verificado contra una carga fresca del Excel real en la base E2E:

```
TOTAL: 195
  1. bloqueantes              3      (2 company_identity_conflict + 1 duplicate_conflicting_row)
  2. observaciones opcionales 38     (36 affiliation_entity_unknown + 2 invalid_affiliation_date)
  3. informativas              154    (77 invalid_email + 56 missing_monthly_value
                                          + 20 credential_like_content + 1 duplicate_exact_row)
```

Las informativas se colapsan pero **no se ocultan**: sin ellas no hay forma de distinguir un libro
limpio de una pantalla que perdió 154 filas.

### A.4 Respuestas obsoletas

`loadIssues()` no tenía secuenciación y lo llaman la carga inicial y tres watchers. Dos peticiones
se solapan, la lenta llega última y escribe las filas del filtro anterior bajo la etiqueta del
nuevo — el síntoma reportado («el filtro dice una cosa y la tabla muestra otra»).

Se añadió `issuesRequestId`, el mismo patrón que `load()` ya usaba, aplicado a la petición que se
quedó fuera. El filtro de código además lleva **debounce de 300 ms**.

El test reproduce el solapamiento desde la carga inicial, no escribiendo: con debounce, dos
teclas a 100 ms colapsan en una sola petición, así que escribir ya no lo produce — pero la carga
inicial sí, y es el caso real (alguien cambia el filtro mientras la primera página está en vuelo).

### A.5 Fallo que no debe parecer una lista vacía

`loadIssues()` hacía `issues.value = []` ante un error. Una tabla vacía se lee como «este libro está
limpio», y no lo está. Ahora hay tres estados — `issuesError` / `issuesLoaded` / datos — y la
tabla sólo puede decir «no hay coincidencias» **después** de que la petición haya funcionado.

---

## BLOQUE B — Cancelar importaciones

El endpoint ya existía y ya cumplía B.4 entero:

```
routes/api.php:530  Route::post('/imports/{import}/cancel', …)   dentro de can:imports.review
ImportController::cancel()  ImportLifecycle::mutate(… cancel())   bajo bloqueo de fila
                            ImportNotApplicable → 409
                            ImportFileStore::delete()              en imports.disk
```

Lo que faltaba era que fuera **alcanzable desde una pantalla con confirmación**. No se creó
infraestructura de cancelación nueva.

### Interfaz

- **Detalle**: tarjeta propia, separada de «Aplicar plan». Son opuestas y ponerlas juntas invita a
  pulsar la equivocada; «Aplicar» es la que escribe en el maestro.
- **Listado**: acción «Cancelar» junto a «Ver», para el lote que lleva meses en «En revisión».
- **Confirmación**: `AppModal` del proyecto, no `window.confirm()` (que no puede decir nada de lo
  que importa aquí). Cuatro puntos: el análisis deja de usarse, se elimina la copia privada, **no
  se eliminan clientes, empresas ni pagos ya registrados**, y queda registro de auditoría.

### Estados

`CANCELLABLE_STATUSES = ['uploaded','queued','review','ready','failed']`, replicando
`ImportLifecycle::ALLOWED`. Faltan a propósito tres:

- `applying` — el plan se está escribiendo dentro de una transacción;
- `applied` — **el importante**: cancelar una importación aplicada sería una vía para revertir
  datos empresariales sin pasar por nada que deshaga una escritura;
- `cancelled` — terminal.

Tras cancelar, la pantalla **vuelve a leer** el lote en vez de parchear `detail` a mano: el servidor
es dueño de la máquina de estados y decidió bajo bloqueo de fila.

---

## BLOQUE C — Libro operativo

### C.1 Qué es y qué no es

Se reconstruye desde la base de datos. No se vuelve a descargar el Excel subido; el archivo
original es **referencia de forma y apariencia, nunca fuente de datos**.

No es una lista plana de clientes: es una hoja por mes, un bloque por empresa, cada bloque con su
título, su encabezado naranja y sus filas.

### C.2 Estructura reconstruida

Comparación medida contra el archivo original (§D.4):

**Nombres de hoja — coinciden exactamente**

```
original: ENERO 2026 | FEBRERO 2026 | … | SEPTIEMBRE 2026 | OCTUBRE 2026
ours:     ENERO 2026 | FEBRERO 2026 | … | SEPTIEMBRE 2026 | OCTUBRE 2026
match:     YES
```

**Encabezados A–R — 17 de 18 idénticos**

La única diferencia es la columna P:

| | |
|---|---|
| Original | `ARL SURA` |
| Ours | `ARL / PROVEEDOR O REFERENCIA DE RIESGO` |

El encabezado del archivo original nombra **un proveedor concreto**, y varios de sus propios bloques
no usan SURA. C.2 lo prohíbe explícitamente. El encabezado del original era un error del documento,
no una convención.

**Plantilla**

No se copia el XLSX original como plantilla. El aspecto está declarado en código
(`LegacyWorkbookExporter`), así que es reproducible en un despliegue limpio sin el archivo privado.
C.2 explica por qué: enero–febrero traen un volumen anómalo de formato fuera del área operativa.

**Anchos de columna — deliberadamente más cortos**

| Col | Original | Ours | | Col | Original | Ours |
|---|---:|---:|---|---|---:|---:|
| A | 11.4 | 7 | | J | 30.7 | 22 |
| B | 33.6 | 34 | | K | 69.4 | 30 |
| C | 47.3 | 14 | | L | 31.0 | 14 |
| D | 61.4 | 22 | | M | 26.4 | 22 |
| E | 61.9 | 16 | | N | 26.7 | 22 |
| F | 26.3 | 18 | | O | 25.3 | 22 |
| G | 24.1 | 16 | | P | 23.9 | 28 |
| H | 25.4 | 14 | | Q | 23.9 | 20 |
| I | 39.9 | 22 | | R | 55.6 | 28 |

Son las proporciones del original recortadas a lo que el contenido necesita. Una columna
`NOVEDAD` de 61 caracteres hay que desplazarla para ver la siguiente; C.2 atribuye ese volumen de
formato al mismo fenómeno que recomienda no arrastrar.

**Bloques por mes — no coincide, y es correcto**

| Mes | Ours | Original |
|---|---:|---:|
| ENERO 2026 | 31 | 10 |
| OCTUBRE 2026 | 31 | 10 |

La base E2E tiene 36 empresas, 31 de ellas con alguien empleado en enero. El archivo original sólo
listaba 10 porque eso es lo que la consultorablastscribió. El export dice la verdad del sistema.

### C.3 Reglas de color — exactas

El archivo original está coloreado **a mano**, no con formato condicional. Enero–septiembre
predomina el verde y octubre el amarillo. Eso **no** es evidencia de que cada trabajador verde tenga
un pago registrado, así que **ningún color se copia del histórico**.

| Estado | Cuándo | Relleno | NOVEDAD |
|---|---|---|---|
| Verde — pagado | hay obligación **y** las aplicaciones válidas la cubren | `#00B050` | (vacío) |
| Amarillo — pendiente | hay obligación, sin cubrir, y **no** ha vencido | `#FFFF00` | `PENDIENTE` |
| Rojo — vencido | hay obligación, sin cubrir, y venció | `#FF0000` | `VENCIDO` |
| Sin color | **no hay obligación registrada** | (ninguno) | `SIN INFORMACIÓN DE PAGO` |
| (cualquiera) + retiro | la relación terminó en ese mes | *(el del pago)* | `RETIRADO` |

**Reglas que hacen que esto sea verdad y no una conjetura:**

- Se cuentan **aplicaciones** (`payment_allocations`), no pagos. Un pago sin aplicar no descarga
  nada, y una aplicación revertida (`reversed_at`) tampoco. `payments` daría mal los dos casos.
- **Sin obligación no es pagado.** La ausencia de un registro no es un hecho sobre el pago: la
  tarifa nunca se configuró, el periodo no se abrió, la planilla no se generó. La celda queda sin
  color y lo dice en texto. Un relleno neutro se leería como «pendiente» a cualquiera que haya usado
  una hoja de cálculo.
- **El mes pasado no es pagado.** Un cliente con tarifa pero sin obligación en enero sigue sin
  información en enero.
- **Vencido hoy no es vencido.** Misma regla que A03; `due_on < hoy`, no `<=`.
- **Un pago parcial sigue siendo pendiente** mientras el saldo no venza, y **vencido** en cuanto
  vence. Lo que falta está tarde diga lo demás que sea cierto.
- **El retiro no pisa al color.** C.3 lo pide: una persona retirada con la obligación pagada es las
  dos cosas. El color lo decide el pago; el retiro va **en palabras** en `NOVEDAD`, que es donde el
  original lo ponía. Nunca se escriben `VENCIDO` y `RETIRADO` a la vez.

`LegacyPaymentStatus::decide()` es una **función pura** de tres escalares (`$baseAmount`, `$allocated`,
`$dueOn`) más la fecha de referencia inyectada. Se puede discutir sin base de datos, y los tests la
giran sin una.

**Azul y morado:** el original usa `#00B0F0` (179 celdas) y `#7030A0` (215 celdas) en las primeras
tres hojas, más un `#AEBACB` (534 celdas) que no está en la especificación de C.2. **No se emiten.**
C.2 dice no inventar reglas para ellos, y no hay forma comprobable de derivarlas. Quedan sin
reproducir; se documenta aquí en vez de inventarse una correspondencia.

### C.4 Todo se lee «a fecha del mes»

Requisito de C.4, y lo que hace que esto no sea un volcado del estado actual:

- una relación laboral contribute a un mes sólo si `[started_on, ended_on)` lo cubre, así que quien
  se trasladó en abril no aparece en la nueva empresa desde enero;
- una afiliación se lee por el **intervalo** en que estuvo vigente, no por estar abierta hoy;
- una tarifa se lee por `effective_month`, así que un mes anterior a que existiera no tiene importe
  en vez de tener el actual;
- el estado de pago es el de **hoy**: §C.7 dice que la exportación representa el estado financiero
  actual de obligaciones de meses anteriores. **No hay snapshot histórico**: usar pagos futuros
  para reconstruir el pasado sería inventar. La interfaz no pide fecha de corte, y el documento no
  la ofrece, porque la aplicación no tiene la información para calcularla con fidelidad.

Verificado: una persona en `OPERADORA UNO` de enero a marzo y en `OPERADORA DOS` desde abril aparece
en enero–marzo bajo la primera y **no** en el bloque de la primera en abril.

### C.5 Datos desconocidos

Vacío, siempre. Sin excepción y sin ruta alternativa:

| Campo | Desconocido se escribe como | Por qué no otra cosa |
|---|---|---|
| Correo | celda vacía | `-` volvería como dirección mal formada |
| Teléfono | celda vacía | igual |
| Dirección | celda vacía | igual |
| Valor mensual | celda vacía | `0` es un número, y el importador se lo creería |
| Nombre de entidad | celda vacía | no se inventa una administradora |

**La única excepción deliberada es la AFP.** Una afiliación que el origen afirmó sin nombrar se
escribe `SI`, que es lo que es y lo que §9.1 lee de vuelta como la misma incertidumbre. El viaje de
ida y vuelta de ese campo es sin pérdida.

Los identificadores (`A`, `C`, `E`, `H`) se escriben como **texto** con formato `@`, para que los
ceros iniciales no desaparezcan ni en notación científica. El dinero se escribe como **número
real**, para que se pueda sumar.

### C.6 Periodos y empresas

- Año: `2000`–`2100`, la lista ofrece el actual ±…
- Meses: `1,2,3` **o** `1-10` **o** `4` **o** nada (= el año entero)
- Empresa: todas, o una
- Los meses fuera de rango se rechazan con 422 y el texto que sí funciona
- Nombre: `CONSULTORA_DH_2026_ENERO-OCTUBRE.xlsx` (`…_JULIO.xlsx` si es uno solo)

Un mes vacío **igual produce hoja**, con su estructura y sin filas. Omitirla haría que
«enero–octubre» devolviera nueve hojas y un lector las compararía con el original y concluiría que
falta algo. La decisión está en un solo sitio: `LegacyWorkbookSelection::emptyMonthPolicy()`.

2027 funciona igual que 2026: `ENERO 2027`, `FEBRERO 2027`, …

### C.7 Exportación histórica vs estado financiero actual

La exportación representa **el estado financiero actual** de obligaciones pertenecientes a meses
anteriores. No se produce un snapshot histórico con pagos futuros, y no se reescribe historial.

La interfaz **no** pide fecha de corte a propósito: sin esaExactitud, la única manera de
"reconstruir" el estado de marzo en junio sería contar pagos que no se habían recibido, que es
justo el supuesto que C.7 prohíbe.

### C.8 Seguridad

- Permiso **`reports.export`**, comprobado en el servidor. El tipo de reporte declara ese permiso
  (no `reports.view`): el archivo lleva documento, dirección, teléfono y salario de cada
  trabajador de cada mes, que es estrictamente más que el reporte de cartera en pantalla.
- El archivo se guarda en el disco **privado** con `sha256`, `tamaño` y `expires_at`; la descarga se
  autoriza en cada petición. No hay URL pública permanente.
- `Content-Type` explícito y `X-Content-Type-Options: nosniff`.
- Auditoría con el alcance y la forma, no sólo «xlsx»:

  ```php
  ['sheets' => 10, 'company_blocks' => 313, 'rows' => 4264, 'selection' => [...]]
  ```

- **Inyección de fórmulas.** Todo se escribe con `DataType::TYPE_STRING`. Una primera versión pasaba
  `DataType::TYPE_FORMULA` para un valor que empezaba por `=`, creyéndolo la elección segura: es
  exactamente la insegura — ésa es la llamada que convierte una nota escrita por un usuario en
  `=HYPERLINK(...)` al abrir el archivo. `TYPE_STRING` la vuelve inerte y el `=` se ve como texto.
- **Sin macros, sin enlaces externos** — comprobado sobre el archivo comprimido, no sobre el objeto
  `Spreadsheet`, que es la pregunta equivocada.
- El archivo exportado **no** copia el original: ni celdas con posibles contraseñas, ni comentarios,
  ni metadatos ocultos, ni columnas mil veces más anchas.

### C.9 Técnica

**PhpSpreadsheet**, no OpenSpout. El `XlsxExporter` del proyecto usa OpenSpout, que es la elección
correcta para un informe plano: es streaming y rápido. Este no es un informe plano — es color,
anchos, bordes y formatos de número — y OpenSpout no escribe ninguno. Los dos coexisten: no se le
añadió estilo a un escritor streaming, porque eso le habría costado el perfil de memoria que lo hace
correcto para un informe de 10 000 filas. PhpSpreadsheet ya estaba en `composer.json`; **no se añadió
ninguna dependencia**.

**Sin N+1.** Cada tabla se lee una vez para toda la selección y se indexa en PHP. Una consulta por
fila es la diferencia entre una exportación que responde y una que expira con una nómina real.

El libro no es un `ReportResult`: no tiene forma `columns()`/`rows()`, e inventar una plana y
reinterpretarla es lo que este hito prohíbe. Por eso **sí** es un `ReportType` (así llega a la lista
de reportes generados, la auditoría y la descarga autorizada, en vez de crecer un segundo juego de
ellos) pero `ReportFactory` lo **rechaza explícitamente** con un `LogicException` en vez de dejar que
`UnhandledMatchError` salga de un `match` sin brazo.

---

## D. Pruebas dirigidas

### D.1 Incidencias — libro original

| | |
|---|---|
| Incidencias | 195 |
| Bloqueos | 3 |
| Resumen agrupado | 3 / 38 / 154 verificado en API |
| Sin «Resolver» en datos sin decisión | `duplicate_exact_row` ya no ofrece ninguna |
| Sin fila eliminable por correo inválido | `invalid_email` y `missing_monthly_value` no ofrecen decisiones |

**Navegador:** no verificado. No hay navegador de pruebas en este entorno. Todo lo anterior se
comprobó contra la API y con tests de componente (jsdom); el render real en navegador queda
pendiente, y por eso `MANUAL_QA_PENDING=yes`.

### D.2 Cancelación

Verificada por contrato y por componente:

| | |
|---|---|
| Importación en revisión cancelable | `ImportLifecycle::ALLOWED` cubre `review`; el botón aparece |
| Confirmación | cuatro puntos, modal del proyecto, dos salidas |
| Estado cancelado | la pantalla relee el lote, no lo parchea |
| Copia privada eliminada | `ImportFileStore::delete()`, sobre `imports.disk` |
| Sin alterar clientes/pagos/empresas | la transición sólo toca `legacy_imports` |
| RBAC | `can:imports.review` en la ruta; oculto sin permiso |
| Cancelar un aplicado | `Applied` no tiene transición a `Cancelled`; botón oculto; 409 |

### D.3 Exportación visual

41 pruebas en `tests/Feature/A05/LegacyWorkbookExportTest.php` sobre registros controlados que
cubren los nueve casos de D.3. Cada una **abre el XLSX generado** y lee las celdas, los rellenos y
los tipos — no comprueba que el archivo tenga extensión `.xlsx`.

Comprobado: hojas mensuales, bloques empresariales, encabezados A–R, colores y leyenda, importes
como número, fechas, identificadores preservados (`0001234567` sigue siendo texto con `@`),
celdas vacías, datos históricos, ausencia de secretos y ausencia de fórmulas.

Antes de escribirlas se inspeccionó el archivo a mano con un conjunto controlado: pagado, pendiente,
vencido, retirado, parcial, sin correo, sin mensualidad, AFP desconocida y traslado histórico. Las
salidas coincidieron con lo que estas pruebas ahora Afterward.

### D.4 Comparación con el Excel real

Medida, más arriba. Resultado: nombres de hoja exactos, 17 de 18 encabezados idénticos (el
dieciochoavo corregido a propósito), proporciones de columna parecidas con anchos recortados,
mismos colores base. La diferencia en bloques por mes (31 vs 10) es la base de datos, no el
exportador.

### D.5 Datos

Base principal **sin cambios**:

```
users 67 · clients 5 · companies 1 · client_company_assignments 1 · client_company_rates 1
client_affiliations 4 · monthly_obligations 1 · payments 1 · payment_allocations 1
legacy_imports 2 · generated_reports 0 · client_affiliation_observations 0 · contribution_sheets 0
```

Cero migraciones pendientes. Bloque H intacto: Alexandra Gislason, obligación 30000, allocated
15000, vence 2026-11-10.

El Excel original no se aplicó a la base principal en ningún momento.

---

## E. Lo que NO se hizo

- **No se copió el Excel original como plantilla.** Habría arrastrado estilos sobrantes, comentarios,
  datos históricos y posibles credenciales.
- **No se reprodujeron azul ni morado.** No hay regla comprobable; inventar una es peor que omitirla.
- **No se añadió una columna al esquema de importación** para forzar un estado financiero. El
  colouring es visual; el esquema de importación no cambia.
- **No se tocó el parser.** Los resultados del Bloque A se dueños de la interfaz y de una lista de
  decisiones muertas, no de la lectura del archivo.
- **No se amplió a IA.** `ReportPreviewTool` y `CreateReportTool` anuncian `ReportType::cases()`, que
  ahora incluye el libro operativo. Se les **excluyó** explícitamente: el primero lo rechaza
  `ReportFactory`, y ofrecer un tipo cuya única respuesta es un 500 sería una regresión en un
  subsistema fuera de alcance. Admitirlo es una decisión del hito de IA, no una omisión.
- **No se ofrecían «excluir fila» para limpiar un correo inválido.**

---

## F. Cambios de código

### Nuevo

| Archivo | Papel |
|---|---|
| `app/Domain/Reports/LegacyWorkbook/LegacyWorkbookSelection.php` | año, meses, empresa, nombre de hoja, nombre de archivo |
| `…/LegacyWorkbookBuilder.php` | lee la base y arma mes → bloques → filas, todo a fecha del mes |
| `…/LegacyPaymentStatus.php` | los cuatro estados, función pura |
| `…/LegacyWorkerRow.php` | una fila y su `NOVEDAD` |
| `…/LegacyCompanyBlock.php` | un bloque de empresa |
| `…/LegacyWorkbook.php` | el libro entero |
| `…/InvalidLegacyWorkbookSelection.php` | 422 con texto accionable |
| `app/Domain/Reports/Export/LegacyWorkbookExporter.php` | PhpSpreadsheet, colores, anchos, bordes, leyenda |

### Modificado

| Archivo | Cambio |
|---|---|
| `IssueResolutionDecision.php` | `DuplicateExactRow` sale de las tres listas |
| `ContractClosureTest.php` | `duplicate_exact_row` entra en la lista de rechazos con su razón |
| `ClientAffiliationObservation.php` | `monthKeys()` tolera un valor no-array (abajo) |
| `ReportType.php` | `LibroOperativo`, su permiso, su esquema de filtros, XLSX sólo |
| `ReportFactory.php` | lo rechaza explícitamente |
| `ReportGenerator.php` | enruta el libro a su escritor, reutilizandoalmacenamiento y auditoría |
| `ReportController.php` | rama del libro, cabecera de descarga, 422 |
| `ReportPreviewTool.php`, `CreateReportTool.php` | excluyen el tipo nuevo |
| `ImportDetailPage.vue` | tres categorías, `allowed_decisions`, secuenciación, error, cancelación |
| `ImportListPage.vue` | cancelación desde el listado |
| `ReportesPage.vue` | tarjeta del libro, leyenda |
| `app.css` | leyenda, bloque de bloqueo,plegable, diálogo, fila de peligro |

### Un robustez que no estaba en el encargo

`ClientAffiliationObservation::monthKeys()` hacía `array_filter($this->months)`. El cast `array`
decodifica bien una fila bien formada; una fila escrita por una ruta que codificó dos veces
(`'"[\"2026-01\"]"'` en la base) decodifica a **string**, y `array_filter` muere con *«Argument #1
must be of type array, string given»*. **Una fila mal formada tumbaba a todos los que leen la
tabla**, y la exportación lee todas ellas para saber quién tenía una afiliación sin nombre.

Se trata como lista vacía — que es lo que una fila así no puede afirmar honestamente. Una fila de
este tipo apareció en la base E2E durante las pruebas y bloqueó el exportador.

---

## G. Verificación ejecutada

```
A04 + Unit A04                      197 passed, 1 skipped (1047 aserciones)
A04 ContractClosure (parte)         incluido arriba
A05 completo                        155 passed, 1 fallo  ← PREEXISTENTE en 74e4386
A05 LegacyWorkbookExportTest         41 passed (108 aserciones)
Excel real (phpunit.real-workbook)   OK, 1787 aserciones — el libro se aplica entero
vitest (todo)                       334 passed, 1 fallo  ← PREEXISTENTE
  - tests/frontend/suggestion.spec.ts
typecheck                           55 (base 57; los 5 de imports.pages.spec arreglados)
lint                                139 (idéntico a la base)
pint --test                         9 ficheros (idéntico a la base)
npm run build                       ✓ 845 ms
```

Los dos fallos marcados **PREEXISTENTE** se verificaron con `git stash` sobre `74e4386` y fallan
igual sin mis cambios:

- `A05 > it U2: an update A02 refuses leaves the request pending…`
- `frontend > useSuggestion > it tells the server about the screen the person is actually on`
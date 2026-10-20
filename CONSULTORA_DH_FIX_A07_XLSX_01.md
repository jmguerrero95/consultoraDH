# CONSULTORA DH — FIX-A07-XLSX-01

Corrección del generador XLSX del asistente: varias hojas, fórmulas reales,
referencias entre hojas, formatos numéricos y rechazo estricto de contenido
inválido. La aceptación funcional del propietario queda pendiente.

## 1. Causa real confirmada

El fallo estaba en el contrato entre el modelo y el generador determinista, no
en el proveedor de IA:

1. `app/Services/Ai/Tools/CreateArtifactTool.php::generateXlsx()` creaba un
   `Spreadsheet`, usaba únicamente la hoja activa y le asignaba siempre el
   nombre `Datos`. No existía forma de pedir varias hojas.
2. `safeCell()` neutralizaba correctamente todo texto que empezara por `=`, pero
   no existía un camino distinto para una fórmula explícita y validada. Por eso
   las fórmulas terminaban como texto.
3. Cuando `json_decode()` devolvía un objeto (no un arreglo), el código caía
   silenciosamente en `rowsFromText()`. El objeto JSON se interpretaba como CSV
   y sus fragmentos se escribían en una sola fila de decenas de columnas.
4. La descripción de `create_artifact` solo documentaba un arreglo de filas; no
   describía hojas, fórmulas, formatos ni referencias cruzadas.
5. El prompt del asistente priorizaba `analyze_table` para tablas adjuntas.
   Esa herramienta exporta una tabla plana y no conserva fórmulas, por lo que
   una solicitud de libro profesional podía terminar en un XLSX de una hoja.

## 2. Archivos modificados

| Archivo | Cambio |
|---|---|
| `app/Services/Ai/Xlsx/XlsxWorkbookBuilder.php` | Nuevo generador: parser legacy/v2, hojas, celdas tipadas, estilos, límites, validación y cálculo previo. |
| `app/Services/Ai/Xlsx/FormulaValidator.php` | Nuevo validador acotado de fórmulas: gramática, funciones permitidas, referencias y rechazo de construcciones peligrosas. |
| `app/Services/Ai/Tools/CreateArtifactTool.php` | `generateXlsx()` delega en el builder; CSV usa el lector legacy estricto; descripción de la herramienta actualizada al contrato v2. |
| `app/Services/Ai/AiAssistantService.php` | Excepción explícita en el prompt: para varias hojas o fórmulas usar `create_artifact` v2; `analyze_table` solo para leer/verificar. |
| `tests/Feature/A07/XlsxWorkbookTest.php` | Pruebas de archivo real, hojas, fórmulas, recálculo, formatos, CSV, rechazos y dedupe. |
| `tests/Unit/A07/FormulaValidatorTest.php` | Pruebas de gramática permitida y rechazo de fórmulas peligrosas. |

No se modificaron proveedores, failover, política de entrenamiento, RBAC,
migraciones, frontend ni otros módulos.

## 3. Contrato XLSX v2

`content` sigue siendo una cadena JSON. El formato anterior continúa vigente:

```json
[["Cliente", "Obligación"], ["Prueba Alfa", 300000]]
```

Ese formato produce una sola hoja `Datos`, como antes.

El formato avanzado es opcional y versionado:

```json
{
  "version": 2,
  "sheets": [
    {
      "name": "Cartera",
      "rows": [
        ["Cliente", "Obligación", "Pagado", "Saldo pendiente"],
        ["Prueba Alfa", 300000, 100000,
          {"type": "formula", "expression": "=B2-C2"}
        ]
      ],
      "column_formats": {
        "B": "currency_cop",
        "C": "currency_cop",
        "D": "currency_cop"
      },
      "cell_formats": {
        "D2": "currency_cop"
      },
      "freeze_pane": "A2"
    }
  ]
}
```

Celdas admitidas:

- Valor simple: número, texto, booleano o `null`.
- `{"type": "formula", "expression": "=..."}`.
- `{"type": "text", "value": "..."}`.
- `{"type": "number", "value": 300000}`.
- `{"type": "boolean", "value": true}`.
- `{"type": "blank"}`.
- Cualquier celda tipada puede incluir `"format"`.

Formatos: `currency_cop`, `percentage`, `number`, `integer`, `text` y `general`.

Límites aplicados antes de escribir: 20 hojas, 5.000 filas, 100 columnas,
50.000 celdas, 5.000 fórmulas, 8.192 caracteres por fórmula y 32.767 por texto.
Los nombres de hoja se validan contra las reglas de Excel (31 caracteres,
caracteres prohibidos, nombres reservados y duplicados sin distinguir mayúsculas).

Cada hoja solicitada se crea exactamente una vez y en el orden pedido. En el
formato v2 no se añade una hoja `Datos` vacía.

## 4. Compatibilidad anterior

- JSON legacy de filas: PASS, una hoja `Datos`.
- CSV legacy: PASS.
- CSV/Markdown real: PASS cuando el contenido no parece JSON.
- PDF, DOCX, Markdown y TXT: sin cambios; pruebas A07 existentes en verde.
- Nombres Unicode, una sola extensión, descargas autenticadas, aislamiento por
  usuario, dedupe e idempotencia: sin cambios.
- `analyze_table` y `StructuredTable`: no se reemplazaron ni se alteró su
  funcionamiento básico.

Un objeto JSON mal formado, un objeto no compatible, una fila que sea objeto o
una celda con un objeto inesperado se rechazan con un error claro. No se
convierten silenciosamente en CSV ni se escriben fragmentos como `Array`.

## 5. Fórmulas reales y seguridad

Las fórmulas se almacenan con `DataType::TYPE_FORMULA`, no como cadenas con
apóstrofe ni como resultados fijos. Antes de guardar se calculan y se rechaza el
libro si alguna produce `#VALUE!`, `#REF!`, `#NAME?`, `#DIV/0!`, `#N/A`, etc.

Gramática admitida: números, cadenas, referencias de celda absolutas o
relativas, rangos, referencias entre hojas, paréntesis, `+`, `-`, `*`, `/`, `^`,
`%`, comparaciones y las funciones `SUM`, `AVERAGE`, `MIN`, `MAX`, `COUNT`,
`COUNTA`, `COUNTIF`, `SUMIF`, `IF`, `IFERROR`, `ROUND`, `ABS`, `AND`, `OR` y
`NOT`.

Se rechazan, entre otras construcciones:

- Funciones no incluidas en la lista (`HYPERLINK`, `WEBSERVICE`, `INDIRECT`,
  `CELL`, `INFO`, etc.).
- Referencias a libros externos, rangos 3D, DDE, nombres definidos, macros,
  constantes de matriz y concatenación con `&`.
- Hojas inexistentes y celdas fuera del rango de datos de la hoja.
- Fórmulas vacías, demasiado largas, con paréntesis desbalanceados o con
  argumentos incorrectos.

Un texto no confiable que empiece por `=`, `+`, `-`, `@`, tabulador o retorno de
carro sigue guardándose como texto, nunca como fórmula. Una fórmula solo existe
si la celda está tipada explícitamente como `formula` y pasa el validador.

## 6. Formato profesional

- Encabezados en negrita, con relleno y borde inferior.
- Filas de totales (`Totales`, `Total`, `Subtotal`) en negrita, con relleno y
  borde superior.
- Moneda COP con separador de miles y valores almacenados como números.
- Porcentaje con formato `0.00%`; el valor subyacente es numérico (por ejemplo,
  `1/3` se muestra como `33,33 %` según la configuración regional de Excel).
- Ancho automático de columnas y panel congelado en `A2` por defecto.
- Alineación numérica derecha y encabezados a la izquierda.

## 7. Pruebas ejecutadas

| Prueba | Resultado |
|---|---|
| `php artisan test tests/Feature/A07 tests/Unit/A07` | **554 passed, 1952 assertions** (216,33 s) |
| `XlsxWorkbookTest` | **13 passed, 97 assertions** |
| `FormulaValidatorTest` | **24 passed, 24 assertions** |
| `vendor/bin/pint --test` sobre los 6 archivos modificados | **PASS** |
| `git diff --check` | sin errores |

Casos cubiertos: formato legacy, libro v2 de dos hojas, fórmulas almacenadas como
fórmulas, totales exactos, referencias entre hojas, `COUNTIF`, porcentaje,
cambio de celda y recálculo, formatos monetarios, nombres inválidos, JSON mal
formado, objeto incompatible, fórmula maliciosa, texto no confiable, dedupe y
ausencia de archivo/fila ante fallo.

## 8. Totales calculados y recálculo

Libro inicial:

| Concepto | Valor |
|---|---:|
| Obligaciones | 1.050.000 |
| Pagado | 350.000 |
| Saldo pendiente | 700.000 |
| Descuento 10 % | 70.000 |
| Saldo ajustado | 630.000 |
| Clientes con deuda | 2 |
| Recuperación | 33,3333 % (valor 1/3) |

Tras cambiar `Cartera!B2` de `300000` a `400000` y recalcular:

| Concepto | Valor |
|---|---:|
| Obligaciones | 1.150.000 |
| Pagado | 350.000 |
| Saldo pendiente | 800.000 |
| Descuento 10 % | 80.000 |
| Saldo ajustado | 720.000 |
| Clientes con deuda | 2 |
| Recuperación | 30,4348 % |

Las fórmulas se conservan al guardar y reabrir el archivo; el recálculo se
comprobó con el motor real de PhpSpreadsheet, no con valores simulados.

## 9. Entorno local para el QA manual

- URL: `http://localhost:8080` (responde HTTP 200).
- Contenedores de aplicación, nginx, base de datos, Redis, cola y scheduler
  activos.
- Código montado por bind mount en `/var/www/html`; sin caché de configuración.
- No se consumieron llamadas a proveedores LLM en esta sesión.
- No se modificó la configuración de proveedores ni `AI_ALLOW_TRAINING_PROVIDERS`.

### Guion para el propietario

1. Abrir el asistente en `http://localhost:8080`.
2. Adjuntar el primer Excel de cartera.
3. Enviar exactamente la solicitud de la sección 3 del encargo (dos hojas
   `Cartera` y `Resumen`, descuento del 10 %, fórmulas y totales vinculados).
4. Descargar el archivo nuevo.
5. Abrirlo en Excel o LibreOffice y comprobar que existen **solo** `Cartera` y
   `Resumen`, que las celdas D, E, F y los totales contienen fórmulas, y que los
   valores coinciden con la tabla inicial.
6. Cambiar `Cartera!B2` a `400000` y verificar el recálculo contra la tabla de la
   sección 8.

## 10. Limitaciones conocidas

- El QA manual del propietario sigue pendiente; no se declara aprobado.
- La rama no se fusionó en `main`; la publicación definitiva queda sujeta a la
  aceptación del propietario y a un fast-forward sin `--force`.
- El lenguaje de fórmulas es deliberadamente acotado. No incluye `VLOOKUP`,
  `XLOOKUP`, macros, vínculos externos ni funciones de red. Es una decisión de
  seguridad, no una omisión accidental.
- El modelo debe emitir la celda de fórmula como objeto tipado. Un texto que
  empiece por `=` se conserva como texto por diseño; no se autointerpreta para no
  ejecutar datos no confiables.
- `analyze_table` sigue exportando una tabla plana; para un libro con hojas y
  fórmulas debe usarse `create_artifact` v2.
- La visualización exacta de moneda y porcentaje depende de la configuración
  regional de Excel/LibreOffice; los valores subyacentes y los códigos de formato
  son correctos.
- No hay LibreOffice instalado en el entorno para una apertura visual
  independiente; la validación automatizada abrió el XLSX real con PhpSpreadsheet.

## 11. Contrato final

```text
CONSULTORA_DH_FIX_A07_XLSX_01

BASE_SHA=d20195eb601392e802cb8908ebe466262368b32c
WORK_BRANCH=fix/a07-xlsx-01-formulas
FINAL_CODE_SHA=dc498244287e54d60c7076e9aa8051d0ca042e1f
MAIN_UNCHANGED=YES

ROOT_CAUSE_CONFIRMED=YES
XLSX_V2_SCHEMA=PASS
LEGACY_XLSX=PASS
MULTI_SHEET=PASS
REAL_FORMULAS=PASS
CROSS_SHEET_REFERENCES=PASS
FORMULA_RECALCULATION=PASS
COP_NUMBER_FORMAT=PASS
PERCENTAGE_FORMAT=PASS
FORMULA_INJECTION_PROTECTION=PASS
MALFORMED_JSON_REJECTION=PASS
ARTIFACT_VALIDATION=PASS
DOWNLOAD_REGRESSION=PASS
NO_DUPLICATE_ARTIFACTS=PASS

EXPECTED_OBLIGATIONS=1050000
EXPECTED_PAID=350000
EXPECTED_BALANCE=700000
EXPECTED_DISCOUNT=70000
EXPECTED_ADJUSTED=630000
EXPECTED_DEBTORS=2
EXPECTED_RECOVERY_PERCENT=33.3333

RECALCULATED_OBLIGATIONS=1150000
RECALCULATED_BALANCE=800000
RECALCULATED_DISCOUNT=80000
RECALCULATED_ADJUSTED=720000

TARGETED_TESTS=554 passed, 1952 assertions (A07 completo)
PINT=PASS
EXTERNAL_LLM_CALLS=0
OTHER_MODULES_MODIFIED=NO
AI_PROVIDER_CONFIGURATION_CHANGED=NO

LOCAL_RUNTIME_READY=YES
MANUAL_QA_R3_02=PENDING_USER

CODE_RESULT=PASS
PRODUCT_ACCEPTANCE=PENDING_USER
```

`FINAL_CODE_SHA` identifica el commit funcional. Los commits posteriores añaden
la prueba de rechazo de CSV mal formado y la actualización de este informe; el tip
de la rama contiene todo lo anterior. `main` permanece en
`d20195eb601392e802cb8908ebe466262368b32c` hasta la aceptación del propietario.

---

## 12. Cierre BUG-A07-XLSX-02/03 — QA-R3-02, segunda ejecución

Esta sección actualiza el estado de XLSX-01 con la corrección de los defectos
observados por el propietario. La sección 7 conserva la evidencia histórica de la
primera corrección; las cifras finales son las de esta sección.

`FINAL_CODE_SHA=5f4f1a8e2de8be0b128e65ac69cc9eb5bdf0707a`

### 12.1 Causa confirmada

El motor de XLSX-01 ya generaba hojas, fórmulas y referencias cruzadas. Los
defectos restantes estaban en el contrato de tipos y en la ausencia de una
declaración verificable de los indicadores:

1. El generador aceptaba cualquier formato explícito para una celda porcentual.
   El modelo podía escribir `=Cartera!C5/Cartera!B5*100` y asignarle
   `currency_cop`; Excel mostraba un importe (`$33`) en lugar de `33,33 %`.
2. No existía una lista de indicadores requeridos. Si el modelo omitía
   «Descuento total» y «Saldo ajustado» de la hoja `Resumen`, el generador no
   tenía un contrato determinista para detectarlo.
3. Un conteo como `Clientes con deuda` podía heredar el formato monetario de la
   columna y mostrarse como `$2`, aunque su valor numérico fuera el entero `2`.

### 12.2 Corrección aplicada

Se mantuvo el motor existente y se agregó una extensión mínima al contrato v2:

```json
{
  "version": 2,
  "required_indicators": [
    "total_obligations", "total_paid", "total_balance",
    "total_discount", "total_adjusted", "debtors", "recovery"
  ],
  "indicators": [
    {
      "id": "total_obligations",
      "sheet": "Resumen",
      "cell": "B2",
      "kind": "formula",
      "format": "currency_cop"
    },
    {
      "id": "debtors",
      "sheet": "Resumen",
      "cell": "B7",
      "kind": "formula",
      "format": "integer"
    },
    {
      "id": "recovery",
      "sheet": "Resumen",
      "cell": "B8",
      "kind": "formula",
      "format": "percentage"
    }
  ],
  "sheets": []
}
```

Reglas deterministas agregadas:

- `required_indicators` e `indicators` se envían juntos. Cada id requerido debe
  tener una definición; si falta, el libro se rechaza indicando los ids ausentes.
- Cada indicador declara `id` estable, hoja, celda, `kind` (`formula` o `value`)
  y `format` explícito. No se valida por el texto en español de la etiqueta. El
  origen de los datos queda expresado en la fórmula del indicador (por ejemplo
  `Cartera!B5`) y en la hoja/celda destino; no se duplica un campo redundante.
- El formato del indicador se aplica a la celda destino y prevalece sobre el
  formato de columna o de celda. Así el conteo usa `integer` y la recuperación
  usa `percentage` aunque la columna `B` sea `currency_cop`.
- Un indicador monetario, entero o porcentual debe ser numérico o una fórmula.
  Un texto como `"33,33 %"` no se acepta como porcentaje.
- Una fórmula con formato `percentage` que multiplica por `100`, divide por
  `0,01` o usa `10^2` para escalar se rechaza con un error explícito. La fracción
  correcta es `1/3`, no `33.3333`.
- Una fórmula con formato `integer` debe producir un entero. `COUNTIF` devuelve
  `2` y se muestra como `2`, sin símbolo monetario.
- La descripción de `create_artifact` y la instrucción del asistente exigen
  enumerar todos los indicadores solicitados y usar los formatos correctos.
  No se añadió otra llamada al LLM ni se modificó el supervisor.

La corrección es general: no contiene valores de Prueba Alfa, Beta o Gamma, ni
una excepción para el archivo de QA. Los ids son estables y sirven para reportes
de cartera, planillas, comisiones, saldos, descuentos, estadísticas o cualquier
otro resumen.

### 12.3 Evidencia de los siete indicadores

Libro inicial (`Cartera!B2=300000`):

| Indicador | Fórmula | Valor | Formato |
|---|---|---:|---|
| Obligaciones totales | `=Cartera!B5` | 1.050.000 | `currency_cop` |
| Pagado total | `=Cartera!C5` | 350.000 | `currency_cop` |
| Saldo pendiente | `=Cartera!D5` | 700.000 | `currency_cop` |
| Descuento total | `=Cartera!E5` | 70.000 | `currency_cop` |
| Saldo ajustado | `=Cartera!F5` | 630.000 | `currency_cop` |
| Clientes con deuda | `=COUNTIF(Cartera!D2:D4,">0")` | 2 | `integer` (`0`) |
| Recuperación | `=IF(Cartera!B5=0,0,Cartera!C5/Cartera!B5)` | 0,3333333333 | `percentage` (`0.00%`) |

La celda `Resumen!B8` conserva el valor fraccionario `1/3` y se muestra como
`33.33%`; no se almacena `33.3333` ni texto con `%`. La celda `Resumen!B7`
conserva el entero `2` y su valor formateado no contiene `$`.

Tras cambiar `Cartera!B2` a `400000` y recalcular:

| Indicador | Valor |
|---|---:|
| Obligaciones totales | 1.150.000 |
| Pagado total | 350.000 |
| Saldo pendiente | 800.000 |
| Descuento total | 80.000 |
| Saldo ajustado | 720.000 |
| Clientes con deuda | 2 |
| Recuperación | 30,4348 % |

Las fórmulas se conservan después de guardar y volver a abrir el archivo. El
recálculo se comprobó con el motor real de PhpSpreadsheet, no con cadenas que
empiezan por `=`.

### 12.4 Pruebas del cierre

| Prueba | Resultado |
|---|---|
| `XlsxWorkbookTest` | **16 passed, 114 assertions** |
| `FormulaValidatorTest` | **25 passed, 30 assertions** |
| Pruebas dirigidas combinadas | **41 passed, 144 assertions** |
| Suite A07 completa (`tests/Feature/A07 tests/Unit/A07`) | **558 passed, 1975 assertions** (212,42 s) |
| `vendor/bin/pint` sobre los 6 archivos modificados | **PASS** |
| `git diff --check` | sin errores |

Casos nuevos o reforzados: siete indicadores presentes; descuento y saldo
ajustado presentes; `COUNTIF` entero y con formato `integer`; porcentaje
fraccionario con formato `percentage`; formato individual sobre la columna;
importes COP; fórmulas entre hojas; recálculo al cambiar `B2`; persistencia tras
guardar y reabrir; rechazo de un id requerido sin definición; rechazo de
`*100` en porcentaje; rechazo de una fórmula `integer` que devuelve fracción;
compatibilidad legacy, CSV, texto con `=` tratado como texto, dedupe, ausencia
de archivo ante error y protección contra fórmulas peligrosas.

### 12.5 Residuales honestos

- La validación estructural garantiza que los ids **declarados** como requeridos
  tengan definición y formato, pero no puede adivinar un indicador que el modelo
  omita tanto de `required_indicators` como de `indicators`. La instrucción exige
  enumerar todos; la comparación final con la solicitud sigue siendo parte del QA
  manual del propietario.
- No hay LibreOffice instalado para una apertura visual independiente. La
  validación automatizada abrió el XLSX real con PhpSpreadsheet y comprobó
  fórmulas, valores calculados, tipos y códigos de formato.
- La rama no se publicó ni se fusionó en `main`. La aceptación del producto sigue
  pendiente del QA manual.
- No se modificaron proveedores, failover, política de entrenamiento, RBAC,
  migraciones, portal, cartera administrativa, soporte ni planillas.

## 13. Contrato de salida del cierre 02/03

```text
CONSULTORA_DH_FIX_A07_XLSX_02_03

BASE_SHA=d20195eb601392e802cb8908ebe466262368b32c
WORK_BRANCH=fix/a07-xlsx-01-formulas
FINAL_CODE_SHA=5f4f1a8e2de8be0b128e65ac69cc9eb5bdf0707a
MAIN_UNCHANGED=YES

ROOT_CAUSE_CONFIRMED=YES
SEVEN_SUMMARY_METRICS=PASS
DISCOUNT_TOTAL_PRESENT=YES
ADJUSTED_BALANCE_PRESENT=YES

CURRENCY_FORMAT=PASS
INTEGER_FORMAT=PASS
PERCENTAGE_FORMAT=PASS

PERCENTAGE_RAW_VALUE=0.3333333333
PERCENTAGE_DISPLAY=33.33%

CROSS_SHEET_FORMULAS=PASS
FORMULA_RECALCULATION=PASS
LEGACY_COMPATIBILITY=PASS
FORMULA_INJECTION_PROTECTION=PASS

EXPECTED_TOTAL_OBLIGATIONS=1050000
EXPECTED_TOTAL_PAID=350000
EXPECTED_TOTAL_BALANCE=700000
EXPECTED_TOTAL_DISCOUNT=70000
EXPECTED_TOTAL_ADJUSTED=630000
EXPECTED_DEBTORS=2

RECALCULATED_OBLIGATIONS=1150000
RECALCULATED_BALANCE=800000
RECALCULATED_DISCOUNT=80000
RECALCULATED_ADJUSTED=720000
RECALCULATED_DEBTORS=2
RECALCULATED_RECOVERY_PERCENT=30.4348

TARGETED_TESTS=41 passed, 144 assertions
BACKEND_A07_TESTS=558 passed, 1975 assertions
PINT=PASS

LLM_CALLS=0
AI_PROVIDER_CONFIG_CHANGED=NO
OTHER_MODULES_MODIFIED=NO

LOCAL_RUNTIME_READY=YES
MANUAL_QA_R3_02=PENDING_USER

CODE_RESULT=PASS
BUSINESS_ACCEPTANCE=PENDING_USER
```

`FINAL_CODE_SHA` identifica el commit funcional de esta corrección. El commit
posterior de este informe no cambia el código. `main` y `origin/main` permanecen
en `d20195eb601392e802cb8908ebe466262368b32c` hasta que el propietario acepte el
QA manual y se autorice el fast-forward.

---

## 14. Incidente QA-R3-02_RUNTIME_FAILURE — 2026-10-20

La segunda ejecución manual de QA-R3-02 no llegó a producir el archivo: el
widget recibió **HTTP 503** con el mensaje genérico del asistente. Esta sección
documenta el turno real, la causa y la corrección acotada. No se reenvió la
petición fallida ni se hizo ninguna llamada nueva al LLM durante el diagnóstico
(`LLM_CALLS=0`).

### 14.1 Evidencia del turno fallido

| Campo | Valor |
|---|---|
| Mensaje | `ai_messages.id=175`, conversación `68` |
| `client_turn_id` | `64af03a8-1a46-47f0-9d15-94f4e8786561` |
| Turno de diagnóstico | `998945114b95` |
| Creado | `2026-10-20 07:49:44` (America/Bogota, -05) |
| Falló | `2026-10-20 07:50:48` (64 s) |
| Adjunto | `ai_attachments.id=111`, `QA_R3_02_Entrada_Cartera.xlsx`, XLSX válido, 1 chunk |
| Vista previa del adjunto | contenía `Prueba Alfa` y `300000` |
| Artefacto generado | **ninguno** para la conversación 68 |
| Proveedor del turno | `gemini-3.1-flash-lite` |
| Intentos del proveedor | todos `outcome=ok` |

Diagnóstico guardado por el resolver:

```text
rounds=4
round_limit=4
tool_calls=4
tools=analyze_table, create_artifact
processed=false
pending=true
ended_by=round_limit
result=failed
```

`/api/ai/status` informaba 4 proveedores independientes, 10 candidatos listos,
`state=unchecked` y ningún cooldown. Por tanto el 503 **no** era una caída de
proveedores ni una cuota agotada: el turno se agotó en el servidor.

### 14.2 Causa confirmada

El adjunto se procesó bien y el modelo llamó a `create_artifact`. El validador
rechazó el libro; el modelo volvió a `analyze_table`, volvió a intentar el libro,
fue rechazado otra vez y consumió las cuatro rondas de herramientas. La
síntesis final sin herramientas quedó vacía y el turno terminó por
`round_limit`. El defecto no estaba en el motor XLSX ni en la cuota: estaba en
que un rechazo del validador no tenía una reparación acotada y el modelo podía
gastar todas las rondas alternando `analyze_table` con el mismo libro inválido.

No fue posible recuperar la frase exacta del rechazo porque el turno original no
se reejecutó. El bucle y el agotamiento están probados por las trazas; la frase
concreta se infiere de las rutas del validador.

### 14.3 Corrección aplicada

1. **Una sola reparación acotada.** Tras el primer rechazo de
   `create_artifact`, el siguiente turno recibe el error exacto del validador y
   se le ofrece **únicamente** `create_artifact`. `analyze_table` deja de estar
   disponible en esa ronda, porque ya tenía los datos del adjunto.
2. **Segundo rechazo = fin seguro.** Si la reparación también es rechazada, el
   turno termina de inmediato con un código seguro; no se gasta otra ronda ni se
   convierte el fallo en una respuesta de texto.
3. **Ningún archivo falso.** Una respuesta final de texto no satisface una
   petición de archivo cuando la generación falló. La única excepción es una
   negativa explícita del modelo, que se conserva como negativa honesta.
4. **Códigos seguros para la interfaz.** El 503 incluye `code` sin mensajes del
   proveedor, trazas ni detalle interno:

   ```text
   tool_rounds_exhausted
   artifact_validation_failed
   artifact_indicators_pair_missing
   artifact_required_indicator_missing
   artifact_percentage_scaled
   artifact_percentage_invalid
   artifact_integer_invalid
   artifact_format_not_supported
   artifact_formula_calculation
   artifact_formula_error
   artifact_storage_failed
   artifact_limit_exceeded
   artifact_schema_invalid
   missing_artifact
   quota_limited
   provider_unavailable
   provider_unavailable_private
   provider_empty
   provider_not_configured
   provider_policy_refusal
   provider_internal_error
   ```

   Un `AiTurnFailed` conserva el mensaje honesto «El asistente no pudo completar
   la respuesta esta vez», no «no hay proveedor disponible»: un turno agotado no
   se presenta como una caída externa cuando hay candidatos elegibles.
5. **Alias de formato.** El generador normaliza alias en español y de negocio
   (`COP`, `moneda`, `porcentaje`, `entero`, `decimal`, `numero`, `texto`) a los
   formatos reales de Excel. No se relajó ninguna validación: un porcentaje
   sigue siendo una fracción y un entero sigue siendo un entero.
6. **Deduplicación y confirmación intactas.** La reparación reutiliza el mismo
   turno y las mismas reglas de deduplicación; no crea un segundo artefacto ni
   ejecuta escrituras sin confirmación.

Archivos modificados: `AiAssistantService`, `AiTurnFailed`,
`AiProviderException`, `AiAssistantController`, `CreateArtifactTool`,
`XlsxWorkbookBuilder` y las pruebas del área. No se tocaron proveedores
certificados, política de entrenamiento, RBAC, migraciones, portal, cartera
administrativa, soporte, planillas ni frontend.

### 14.4 Pruebas de la corrección

| Prueba | Resultado |
|---|---|
| `ArtifactValidationRecoveryTest` (nueva) | **3 passed, 23 assertions** |
| Dirigidas: recuperación + artefacto faltante + XLSX + errores + fórmulas | **72 passed, 269 assertions** |
| Suite A07 completa (`tests/Feature/A07`, vía Pest) | **437 passed, 1685 assertions** (196,54 s) |
| `vendor/bin/pint` sobre los 9 archivos | **PASS** (1 ajuste de estilo) |
| `git diff --check` | sin errores |

La prueba nueva reproduce herméticamente el bucle: un artefacto rechazado recibe
una reparación dirigida y se entrega; un segundo rechazo termina con el código
seguro específico; y el endpoint devuelve ese código sin detalle interno. La
suite completa se ejecutó con Pest, que carga `tests/Pest.php`; ejecutarla con
`phpunit` directo omite ese archivo y produce 129 errores falsos por
`seedPortfolioRoles()` no definido, no relacionados con este cambio.

### 14.5 Residuales honestos

- El turno original no se reejecutó: no hay una petición LIVE nueva que confirme
  la entrega del archivo. La reparación está probada de forma hermética, pero la
  aceptación operativa sigue requiriendo que el propietario repita QA-R3-02.
- La frase exacta del validador que provocó el primer rechazo no es recuperable
  sin reejecutar el turno; se conoce el bucle y su desenlace, no el texto literal.
- No hay LibreOffice para una apertura visual independiente; el XLSX se valida
  con el motor de cálculo de PhpSpreadsheet.
- La rama sigue sin fusionar ni publicar. `main` permanece en
  `d20195eb601392e802cb8908ebe466262368b32c`.

## 15. Contrato del incidente de runtime

```text
CONSULTORA_DH_A07_RUNTIME_FAILURE

BRANCH=fix/a07-xlsx-01-formulas
MAIN_UNCHANGED=YES

FAILED_TURN=ai_messages.id=175 / conversation 68
FAILED_TURN_ENDED_BY=round_limit
FAILED_TURN_TOOL_CALLS=4
FAILED_TURN_ARTIFACT_CREATED=NO
ATTACHMENT_PROCESSED=YES
PROVIDER_ATTEMPTS_OK=YES
PROVIDER_OUTAGE=NO
QUOTA_FAILURE=NO

ROOT_CAUSE=tool_round_exhaustion_after_rejected_artifact
BOUNDED_REPAIR=IMPLEMENTED
REPAIR_TOOLS=create_artifact_only
SECOND_REJECTION=SAFE_FAILURE
TEXT_ONLY_AFTER_FAILED_FILE=REFUSED
SAFE_ERROR_CODES=YES
PROVIDER_TEXT_EXPOSED=NO

ARTIFACT_RECOVERY_TESTS=3 passed, 23 assertions
TARGETED_TESTS=72 passed, 269 assertions
BACKEND_A07_TESTS=437 passed, 1685 assertions
PINT=PASS
DIFF_CHECK=PASS

LLM_CALLS=0
LIVE_REPLAY=NO
MANUAL_QA_R3_02=PENDING_USER
BUSINESS_ACCEPTANCE=PENDING_USER
```

El siguiente paso es del propietario: repetir QA-R3-02 desde el widget y
verificar las dos hojas, los siete indicadores, los formatos y el recálculo al
cambiar `Cartera!B2`. Si el turno vuelve a fallar, el nuevo `code` del 503
identificará la causa sin exponer detalles internos.

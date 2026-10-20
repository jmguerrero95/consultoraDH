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

# Consultora DH — A04 Implementation Specification

## A04 — Importación y normalización de Excel + reconstrucción histórica

**Baseline aprobado:** `4b6848ee1b8caa4a5427965c08ee3ea73c6bdd89`  
**A03:** APPROVED / frozen  
**Siguiente bloque:** A04 solamente. A05 queda bloqueado hasta auditoría externa de A04.

---

## 0. Reglas de trabajo y ejecución

Esta tarea comienza en una **sesión nueva de OpenCode**. No reutilizar la conversación larga de A03.

Antes de modificar código:

1. `cd /mnt/c/Diomedes/consultora-dh`
2. leer completamente:
   - `README.md`
   - `docs/ARCHITECTURE.md`
   - `docs/DEVELOPMENT.md`
   - `docs/SECURITY.md`
   - `docs/ROADMAP.md`
   - `docs/TASKS/A02.md`
   - `docs/TASKS/A03.md`
   - este documento
3. verificar:
   - `git status`
   - `git rev-parse HEAD`
   - `git rev-parse origin/main`
4. `HEAD == origin/main == 4b6848ee1b8caa4a5427965c08ee3ea73c6bdd89`
5. working tree limpio.

Si el baseline no coincide, detenerse y reportar. No resetear ni reescribir historia para forzarlo.

### Entorno autoritativo

Todo PHP/Composer/Node se ejecuta dentro de Docker. No instalar `vendor/` ni `node_modules/` en Windows/WSL host.

- PHP/Composer/Pest/Pint: servicio `app`
- frontend/Vitest/typecheck/lint/build: servicio `node`
- PostgreSQL real del proyecto; no SQLite
- Redis/queue existentes

### Git

Mantener el flujo del proyecto:

**tarea completa → QA focalizada proporcional → un solo commit final → `main` → push normal → auditoría externa**.

No crear ramas de checkpoint ni commits intermedios por defecto. No amend, no rebase de historia publicada, no force push. Si el push normal es rechazado, detenerse y reportar.

### Política de calidad

A04 no es un MVP. Corregir cualquier defecto real encontrado dentro del alcance. No reducir calidad para ahorrar tiempo.

Las pruebas deben ser **focalizadas**. No ejecutar toda la historia A01–A03 repetidamente. Ampliar regresión sólo cuando el cambio toque una invariante transversal real.

---

# 1. Fuente real que define A04

A04 se diseña sobre el libro real **`EMPRESA BLINDEN AÑO 2026.xlsx`**, no sobre un Excel genérico inventado.

El archivo real analizado tiene estas propiedades verificadas:

- tamaño aproximado: `766281` bytes (~749 KiB);
- 10 hojas mensuales: enero a octubre de 2026;
- 101 bloques de empresa;
- 2.560 filas de personas;
- 320 identidades documentales distintas después de normalización conservadora;
- 14 nombres lógicos de empresa;
- 10 variantes de encabezado;
- 17 celdas de `FECHA AFILIACION` con formato no estándar / inválido;
- 56 filas sin `VALOR`/`VALOR MENSUAL`;
- 2 casos de documento duplicado dentro de la misma empresa y mes;
- muchos cruces del mismo cliente entre empresas dentro del mismo mes, principalmente por retiros/traslados;
- 558 filas con texto de retiro; 557 siguen el patrón regular `RETIRAR/RETIRA/RETIRADO/RETIRO <N> DIA(S) <MES>`;
- 23 celdas contienen patrones que parecen credenciales o claves en texto libre;
- no hay fórmulas: el contenido de negocio son valores estáticos.

El parser debe funcionar para el perfil estructural del archivo, no quedar hardcodeado a exactamente esos conteos ni únicamente al año 2026. Debe soportar el mismo formato en futuros libros con hojas mensuales en español y otro año.

## 1.1 Estructura real

Cada hoja contiene múltiples bloques:

1. una fila de título de empresa en columna A;
2. inmediatamente después, una fila de encabezados;
3. después, las filas de personas hasta el siguiente bloque.

Ejemplo conceptual:

```text
EMPRESA XYZ SAS NIT ... ARL ... RIESGO ...
# | OPERADOR | # PLANILLA | NOVEDAD | REFERENCIA | FECHA AFILIACION | VALOR ...
... personas ...
```

Los encabezados equivalentes observados incluyen:

- `VALOR` / `VALOR MENSUAL`
- `CAJA` / `CAJASAN` / `CAJA COMPENSACION`
- `EPS SALUD`
- `AFP PENSION`
- columna P titulada como proveedor ARL (`POSITIVA`, `LA EQUIDAD`, `AXA COLPATRIA`, `ARL SURA`, etc.)
- `CARGO`
- `CORREO`

**No confiar ciegamente en las columnas P/Q ni en su encabezado.** En algunos bloques el riesgo y el cargo están intercambiados. En el archivo real los valores de riesgo son principalmente `UNO`, `DOS`, `TRES`, `CUATRO`, `CINCO` y existe el typo `UMO`; la otra columna contiene cargos como `AUXILIAR`, `CONDUCTOR`, `MECANICO`, etc.

El parser debe inferir riesgo/cargo por el valor y generar una incidencia si la interpretación no es inequívoca.

## 1.2 Filas de empresa

El título de empresa puede mezclar:

- nombre;
- NIT y, a veces, dígito de verificación;
- ARL;
- riesgos permitidos;
- caja;
- texto operacional;
- **credenciales en texto plano**.

Para identidad:

- nombre candidato = texto anterior a `NIT`;
- NIT se normaliza con las reglas existentes de `TaxId`;
- DV se mantiene separado;
- el NIT es identidad; el nombre sólo ayuda a revisar.

Existe un caso real de conflicto para `DISTRIUTIL`: aparece con dos números base visualmente muy parecidos. **No fusionar automáticamente.** Debe aparecer como `company_identity_conflict` y requerir decisión humana.

## 1.3 Columnas de persona

Contrato fuente del perfil:

| Columna | Significado de fuente | Destino / tratamiento |
|---|---|---|
| A | marcador | ignorar como dato maestro |
| B | operador | metadata de fuente, no dominio |
| C | planilla | metadata de fuente, no pago |
| D | novedad | evidencia operativa; clasificar, no ejecutar ciegamente |
| E | referencia | metadata de fuente |
| F | fecha afiliación | candidato fuerte a inicio de relación empresa; validar estrictamente |
| G | valor mensual | candidato a `ClientCompanyRate` |
| H | documento | identidad del cliente |
| I/J | nombres/apellidos | maestro cliente |
| K/L/R | dirección/teléfono/correo | maestro cliente actual, con conflicto/precedencia |
| M | caja | snapshot CCF |
| N | EPS | snapshot EPS |
| O | AFP | snapshot AFP / marcadores negativos |
| P/Q | riesgo/cargo | inferir por contenido, no por posición |

`OPERADOR`, `# PLANILLA`, `REFERENCIA` y `NOVEDAD` **no crean pagos ni obligaciones A03**.

Textos como `ME DEBE ...`, `PENDIENTE PAGO`, incapacidades, etc. son notas de la fuente. No existe evidencia suficiente para transformarlos en deuda o pago contable.

---

# 2. Decisión de producto: importador específico, no mapper genérico

A04 debe implementar el perfil conocido **Blinden Legacy Monthly Workbook**, no un constructor universal de mapeo de columnas.

Nombre sugerido de perfil interno:

```text
blinden_legacy_monthly_v1
```

El parser debe tolerar las variantes reales documentadas, pero si encuentra una estructura desconocida debe generar una incidencia clara; no debe adivinar silenciosamente.

No soportar `.xls`, `.xlsm`, `.csv` ni hojas arbitrarias en A04. El contrato de entrada es `.xlsx`.

---

# 3. Dependencia de lectura Excel

Usar una librería open-source de lectura XLSX mantenida, preferiblemente **OpenSpout** (`openspout/openspout`) por lectura streaming y porque no necesitamos ejecutar fórmulas ni conservar formato.

Instalar mediante Composer **dentro del contenedor `app`** y versionar `composer.json` + `composer.lock`.

Si la versión estable compatible requiere una extensión PHP que la imagen no tenga, añadir **sólo** la extensión mínima necesaria a `docker/app/Dockerfile`, reconstruir y documentar. No instalar PHP/extensiones en el host.

No evaluar fórmulas, macros ni enlaces externos.

---

# 4. Seguridad del archivo

## 4.1 Upload

Aceptar sólo `.xlsx` con verificación real del contenedor ZIP/OpenXML, no sólo extensión/MIME del navegador.

Límite inicial: 10 MiB por archivo. Configurable en `config/imports.php`.

Guardar el original en disco privado:

```text
storage/app/private/imports/<uuid>/source.xlsx
```

Nunca usar `public` disk ni generar una URL pública.

El nombre físico es UUID, no el nombre entregado por el usuario. Guardar el nombre original sólo como metadata segura.

Calcular SHA-256 durante el ingreso.

## 4.2 ZIP/OpenXML defensivo

Antes de parsear:

- limitar cantidad de entradas ZIP;
- limitar tamaño descomprimido total razonablemente;
- rechazar rutas `../` o absolutas;
- rechazar contenido VBA/macros (`vbaProject.bin`) y libros macro-enabled aunque estén renombrados `.xlsx`;
- no resolver external links;
- no ejecutar fórmulas;
- fallo de parser = estado controlado, nunca stack trace al usuario.

## 4.3 PII y secretos

El libro real contiene credenciales dentro de títulos/notas.

Implementar `SensitiveSourceRedactor` para detectar al menos:

```text
CLAVE
PASSWORD
CONTRASEÑA
USUARIO + valor cercano
TOKEN
SECRET
```

No copiar esos valores a:

- logs;
- `audit_events.metadata`;
- mensajes de excepción;
- JSON de preview;
- tablas de staging en texto completo.

El original privado conserva la fuente para trazabilidad; toda representación en BD/UI debe estar redactada, por ejemplo `CLAVE: [REDACTED]`.

Crear incidencia `credential_like_content` que sólo muestre hoja/fila/celda y tipo de patrón, nunca el secreto.

Añadir `.local-fixtures/` a `.gitignore` y asegurar que los tests/QA nunca versionen el Excel real.

---

# 5. Modelo de staging y proveniencia

No escribir maestros al subir el archivo.

Flujo obligatorio:

```text
upload
→ parse/staging
→ normalización
→ incidencias
→ resoluciones humanas
→ plan exacto
→ confirmación
→ apply transaccional
```

Crear tablas nuevas con nombres claros. Una estructura válida es:

## 5.1 `legacy_imports`

Campos mínimos:

- id
- uuid público único
- profile (`blinden_legacy_monthly_v1`)
- original_filename
- stored_path privado
- sha256
- file_size
- status
- created_by
- parse_started_at / parsed_at
- applied_at
- failed_at
- failure_code + failure_message **sanitizado**
- summary JSONB con conteos no sensibles
- timestamps

Estados explícitos, por ejemplo:

```text
uploaded
queued
parsing
review
ready
applying
applied
failed
cancelled
```

Defender transiciones: no permitir volver `applied` a `review`, ni aplicar dos veces.

## 5.2 `legacy_import_rows`

Una fila por persona/fila fuente.

Guardar:

- import_id
- sheet_name
- sheet_month (primer día del mes)
- source_row_number
- block index/key
- identidad normalizada de empresa candidata
- identidad normalizada de cliente candidata
- fecha de afiliación raw **sanitizada** + fecha parseada nullable
- valor mensual nullable
- tokens normalizados EPS/AFP/CCF/ARL
- riesgo/cargo candidatos
- novedad **redactada**
- metadata de operador/planilla/referencia sanitizada
- normalized_payload JSONB
- fingerprint SHA-256 de la fila normalizada
- parse_state

No hace falta duplicar cada celda original completa: el original privado es la evidencia cruda.

## 5.3 `legacy_import_issues`

- import_id
- row_id nullable (hay issues de batch/empresa)
- code
- severity (`info|warning|error`)
- `blocking` boolean
- field nullable
- mensaje humano
- context JSONB sanitizado
- resolved_by / resolved_at
- resolution JSONB sanitizado

## 5.4 `legacy_import_actions`

Plan exacto de lo que `Apply` ejecutará:

- import_id
- ordinal
- action_type
- natural_key
- payload JSONB
- source_row_ids / evidence references
- fingerprint único dentro del batch
- state (`planned|applied|skipped`)
- target_type / target_id después de aplicar

La UI de preview debe leer estas acciones; no reconstruir una explicación distinta a la que realmente aplicará el backend.

## 5.5 Mapeos reutilizables

Para aliases aprobados de entidades de seguridad social crear un pequeño registro persistente, por ejemplo `import_source_mappings`:

- profile
- field/type (`EPS|AFP|ARL|CCF`)
- source_key normalizada
- target `SocialSecurityEntity`
- verified_by / verified_at

Sólo guardar mappings aprobados explícitamente. La similitud/fuzzy matching sólo produce sugerencias.

---

# 6. Parser determinista del workbook

Implementar una clase de dominio, no lógica en controller:

```text
App\Domain\Imports\BlindenLegacyWorkbookParser
```

## 6.1 Hojas

Reconocer nombres:

```text
ENERO 2026
FEBRERO 2026
...
```

Generalizar a mes español + año de cuatro dígitos.

Ignorar `used range` inflado por formato. En el archivo real enero/febrero llegan hasta XAQ por formato, pero los datos están en A:R.

Leer únicamente el rango lógico del perfil.

Orden cronológico por año/mes derivado del nombre, no por posición de la pestaña.

Hojas duplicadas para el mismo mes = blocker.

## 6.2 Detección de bloque

Una fila es encabezado si, tras normalización:

- B contiene `OPERADOR`;
- H contiene `CEDULA`;
- existen los campos mínimos de nombres/apellidos/fecha/valor.

La fila inmediatamente anterior debe ser el título de empresa.

Si no existe título válido: `missing_company_block_header` blocker.

## 6.3 Encabezados

Normalizar mayúsculas, tildes, espacios y aliases.

Aceptar las variantes reales sin exigir texto idéntico.

No confiar en P/Q para riesgo/cargo.

## 6.4 Fila de persona

El documento es obligatorio para aplicar. Una fila con nombres pero documento vacío es blocker, no un cliente nuevo por nombre.

No inferir identidad por teléfono/email.

---

# 7. Normalización e identidad

## 7.1 Clientes

Identidad = `DocumentType + DocumentNumber` usando clases actuales de A02.

Reglas del perfil:

- número puro → candidato `CC`;
- `CC`, `C.C.` → `CC`;
- `CE`, `C.E.` → `CE`;
- `PPT`, alias `PT`, o sufijo inequívoco `... PPT` → candidato `PPT`;
- conservar letras/dígitos significativos;
- algo no reconocido → blocker.

El archivo real produce 320 identidades sin colisiones de tipo después de esta normalización.

El documento manda. Diferencias de nombre para el mismo documento son conflictos de atributos, no nuevas personas.

### Perfil actual del cliente

A02 no conserva historia de dirección/teléfono/email. Por tanto:

- para un **cliente nuevo**, proponer el valor no vacío más reciente cronológicamente;
- si hay conflicto de nombre importante, mostrar issue;
- para un **cliente ya existente**, nunca sobrescribir un valor manual no vacío silenciosamente;
- el plan debe mostrar `actual → propuesto` y requerir aceptación explícita para reemplazar un valor existente;
- campos vacíos de fuente nunca borran datos existentes.

Validar correo antes de escribir. Un correo inválido genera warning y se omite del maestro hasta resolución; no bloquea la historia si la identidad es sólida.

## 7.2 Empresas

Identidad principal = NIT base normalizado.

- DV separado;
- si una observación añade DV donde DB lo tiene null, puede proponerse completar;
- DV contradictorio = blocker;
- mismo nombre + NIT base diferente = `company_identity_conflict`, blocker;
- NIT inválido = blocker.

No usar nombre parecido para fusionar empresas sin aprobación.

El caso real `DISTRIUTIL` debe quedar bloqueado hasta resolución y debe existir test para esta clase de conflicto.

## 7.3 Duplicados de fila

- misma empresa + mismo mes + mismo documento + payload semántico idéntico → `duplicate_exact_row`, auto-colapsable con warning;
- mismo natural key pero payload distinto → `duplicate_conflicting_row`, blocker.

El archivo real contiene ambas clases de situación; no asumir que todo duplicado es error idéntico.

---

# 8. Relaciones cliente–empresa: reconstrucción histórica

Ésta es la parte crítica de A04.

## 8.1 Inicio

`FECHA AFILIACION` es el candidato fuerte de inicio de la relación empresa para este perfil.

- serial Excel válido → fecha;
- texto `dd/mm/yyyy`, `yyyy-mm-dd` sólo si válido estrictamente;
- no corregir años truncados automáticamente (`206` → `2026` es una sugerencia, no una escritura);
- texto `NO`, fechas imposibles y formatos ambiguos → blocker/resolución.

En el archivo real 17 celdas requieren esta revisión.

Agrupar una relación/episodio por:

```text
cliente + empresa + fecha_inicio_normalizada
```

Meses repetidos con la misma fecha son evidencia del mismo episodio, no relaciones nuevas.

## 8.2 Retiro

Las novedades de retiro son evidencia de cierre.

Reconocer como estructura, conservando el texto redactado:

```text
RETIRAR <N> DIA <MES>
RETIRAR <N> DIAS <MES>
RETIRADO ...
RETIRO ...
RETIRA ...
```

557/558 filas reales con retiro siguen esta forma.

**No convertir `N` silenciosamente en un día calendario exacto.** En una planilla `N` puede significar días cotizados y no tenemos derecho a inventar su semántica.

Guardar:

- mes mencionado;
- cantidad N;
- año inferido únicamente por contexto de hoja (por ejemplo diciembre en hoja enero pertenece al año anterior);
- source evidence.

Para poder aplicar masivamente sin 500 decisiones individuales, la pantalla de revisión debe ofrecer una **regla de interpretación de retiro por batch**, pero debe ser elegida explícitamente por el operador y mostrar una previsualización antes de afectar el plan.

Opciones mínimas:

1. `manual_only` (default seguro): no deriva fecha exacta;
2. `month_end_boundary`: la relación deja de estar efectiva el primer día del mes siguiente al mes de retiro; marca la precisión como mensual.

No implementar una regla PILA de 30 días inventada en código. Si el negocio necesita posteriormente una semántica de días cotizados, será una decisión explícita distinta.

## 8.3 Precisión de fechas

Para no presentar una inferencia mensual como fecha exacta, ampliar A02 con precisión de fecha.

Añadir de forma compatible:

### `client_company_assignments`

- `started_on_precision`: `day|month`, default `day` para filas existentes;
- `ended_on_precision`: nullable `day|month`; null cuando `ended_on` es null.

### `client_affiliations`

- `started_on_precision`: `day|month|unknown`; `unknown` cuando `started_on` es null;
- `ended_on_precision`: nullable `day|month`; null cuando `ended_on` es null.

Agregar CHECKs que mantengan fecha y precisión coherentes.

Actualizar los presentadores/UI de historia para no afirmar exactitud falsa:

- `day` → `27/03/2026`;
- `month` → `Marzo 2026 (mes aproximado)`;
- `unknown` → `Fecha desconocida`.

**La lógica de intersección sigue usando la fecha almacenada**, pero cualquier frontera de precisión `month` sólo puede provenir de una política de importación que el operador aceptó explícitamente y debe quedar trazada.

No cambiar el significado `[started_on, ended_on)`.

## 8.4 Desapariciones

Si un episodio deja de aparecer antes del último mes del archivo y no existe retiro/otra evidencia de cierre, crear `relationship_disappeared_without_retirement` blocker.

En el archivo real esta clase es pequeña (aprox. una decena de episodios), por lo que no justificaría inventar una regla global.

Si aparece en el último mes del libro y no hay retiro, puede proponerse abierto.

## 8.5 Paralelos / transferencias

Mismo cliente observado en varias empresas el mismo mes NO significa automáticamente paralelismo: en el archivo real aparece masivamente durante retiros/traslados.

Después de reconstruir intervalos:

- no hay solapamiento → normal;
- solapamiento real → `overlapping_company_history` blocker;
- la resolución debe permitir:
  - corregir fecha;
  - reconocer transferencia;
  - autorizar paralelo con motivo explícito usando las columnas A02 correspondientes.

Nunca marcar un paralelo automáticamente.

---

# 9. Afiliaciones EPS / AFP / ARL / CCF

Las columnas son snapshots mensuales; no son órdenes confiables por sí solas.

## 9.1 Tokens negativos

No crear afiliaciones a partir de:

```text
NO
SIN CAJA
NO APLICA
NOAPLICA
NINGUNA
NO <ENTIDAD>
```

`NO PORVENIR`, `NO PROTECCION`, `NO COLPENSIONES`, etc. son evidencia negativa/mención, no una afiliación activa.

`SI` sin entidad concreta = `affiliation_entity_unknown` blocker/warning según si se necesita reconstruir ese tipo; jamás crear una entidad llamada `SI`.

## 9.2 Nombres positivos

Normalizar sólo transformaciones seguras (case/espacios/acentos).

Ejemplos de fuente real incluyen variantes y typos como:

- `SALUD TOTAL` / `SALUDTOTAL`
- `SURA` / `SURA EPS`
- typos cercanos a `SANITAS`
- typos cercanos a `MUTUAL SER`
- `PORVENIR` y errores ortográficos cercanos

No fuzzy-merge automático. Generar sugerencia + issue y guardar un mapping sólo después de aprobación.

## 9.3 Creación de catálogo

El catálogo A02 puede estar vacío. A04 puede proponer crear entidades faltantes, pero sólo desde tokens positivos resueltos y revisados.

No inventar NIT/código de entidad si la fuente no lo trae. Nombre + tipo pueden existir con `code/tax_id` null si el esquema actual lo permite.

## 9.4 ARL

El proveedor ARL suele venir del título/header de empresa; el riesgo de la fila viene de P/Q.

Prioridad de evidencia:

1. proveedor ARL explícito en título de empresa;
2. header ARL si no hay proveedor explícito;
3. si ambos existen y contradicen, issue `company_arl_metadata_conflict`.

Riesgo:

```text
UNO→1
DOS→2
TRES→3
CUATRO→4
CINCO→5
```

Typos como `UMO` sólo producen sugerencia; no auto-corregir sin mapping aprobado.

Si P es riesgo y Q cargo, mapear así; si Q es riesgo y P cargo, invertir. Si ambos/neither son inequívocos, issue.

## 9.5 Historia mensual de afiliaciones

Comprimir snapshots consecutivos iguales en segmentos.

- primer valor observado sin fecha exacta puede usar `started_on = null`, `precision=unknown`;
- cambio detectado por primera vez en un mes puede proponerse al primer día de ese mes con `precision=month`;
- cerrar el segmento anterior en la misma frontera;
- cualquier cambio derivado de snapshot mensual debe quedar marcado como precisión mensual y ligado al import.

Para ARL vinculada a una relación empresa con inicio exacto, usar la fecha de la relación cuando la evidencia sea coherente; cambios posteriores de proveedor/riesgo pueden usar frontera mensual.

No permitir dos afiliaciones abiertas del mismo tipo.

---

# 10. Valor mensual → `ClientCompanyRate`

Esta parte sí es determinista a nivel mensual.

Para cada cliente+empresa, recorrer las hojas cronológicamente:

- valor vacío → no crear rate y generar warning `missing_monthly_value` si esa relación necesita configuración;
- valor <= 0 / no numérico → blocker;
- mismo valor consecutivo → no repetir rate;
- cuando cambia → nueva `ClientCompanyRate` con `effective_month` = primer día del mes de la hoja;
- primera observación válida → rate desde ese mes, **no desde FECHA AFILIACION**, porque la fuente sólo afirma ese valor para la hoja mensual.

Nunca crear obligaciones, periodos, ajustes, pagos ni asignaciones A03.

Si ya existe un rate en DB para la misma pareja/mes:

- mismo importe → no-op;
- importe diferente → blocker `existing_rate_conflict`.

La importación debe respetar la inmutabilidad de rates usados por obligaciones existentes.

---

# 11. Conflicto con datos ya existentes

El importador debe poder ejecutarse sobre una base que ya tiene datos.

Reglas:

- nunca borrar dominio existente;
- nunca sobreescribir silenciosamente datos maestros manuales;
- no reescribir relaciones/afiliaciones históricas existentes;
- coincidencia exacta → no-op;
- posible enriquecimiento de campo vacío → propuesta visible;
- conflicto → issue bloqueante o decisión humana explícita.

La preview debe separar:

```text
Crear
Actualizar
Sin cambios
Bloqueados
```

con conteos y detalle.

---

# 12. Concurrencia, idempotencia y atomicidad

## 12.1 Hash de archivo

Si un SHA-256 ya fue `applied`, permitir consultar/subir para comparación si se desea, pero **no aplicar de nuevo**. Mostrar enlace al import anterior y bloquear doble aplicación.

## 12.2 Doble click / jobs repetidos

`apply` debe bloquear la fila `legacy_imports` (`FOR UPDATE`) y aceptar una sola transición `ready → applying`.

Dos solicitudes concurrentes:

- una continúa;
- la otra recibe 409 controlado;
- cero duplicados.

El job debe ser retry-safe.

## 12.3 Apply all-or-nothing

El plan completo se aplica dentro de una transacción PostgreSQL.

Si falla una acción:

- ningún maestro queda parcialmente escrito;
- ninguna acción queda falsamente `applied`;
- el batch queda en estado recuperable/failed con mensaje sanitizado.

Agregar test con fallo inyectado en mitad del apply.

## 12.4 Integración con A03

A04 modifica topología y rates. Debe participar en el protocolo de bloqueo que A03 ya usa.

Antes de escribir relaciones/rates en `ApplyLegacyImport`, adquirir el `BillingTopologyLock` existente dentro de la transacción y mantener el orden documentado.

No inventar un segundo advisory lock.

Así una generación/cierre A03 no puede leer media topología importada.

---

# 13. Auditoría y proveniencia

Toda aplicación debe poder responder:

> ¿qué fila del Excel originó este registro y qué decisión humana permitió transformarla?

Cada `legacy_import_action` conserva `source_row_ids`.

Después de aplicar enlazar `target_type/target_id`.

Emitir eventos de auditoría por cambios de dominio importantes con metadata mínima:

- import UUID/id;
- sheet;
- row(s);
- action fingerprint;
- resolución aplicada si existió.

No guardar raw PII innecesaria ni secretos en audit metadata.

La acción batch `import.applied` debe incluir conteos agregados.

Nada de borrar audit history.

---

# 14. Permisos

Agregar cuatro permisos explícitos:

```text
imports.view
imports.create
imports.review
imports.apply
```

Matriz inicial:

| Rol | view | create | review | apply |
|---|---:|---:|---:|---:|
| Super Admin | ✓ | ✓ | ✓ | ✓ |
| Administrator | ✓ | ✓ | ✓ | ✓ |
| Operations | ✓ | ✓ | ✓ | ✓ |
| Collections | — | — | — | — |
| Support | — | — | — | — |
| Read Only | — | — | — | — |

Motivo: el archivo completo contiene PII masiva y decisiones de reconstrucción; no es una pantalla de consulta financiera ordinaria.

Backend manda. La UI sólo oculta/inhabilita.

Añadir probes de permisos mínimos y evitar dependencias ocultas con `clients.view`, etc. Un usuario con `imports.*` debe poder completar su flujo mediante endpoints del módulo, sin necesitar permisos incidentales no documentados.

---

# 15. API

Rutas sugeridas; mantener acciones explícitas:

```text
GET    /api/imports
POST   /api/imports
GET    /api/imports/{import}
GET    /api/imports/{import}/rows
GET    /api/imports/{import}/issues
GET    /api/imports/{import}/plan
PUT    /api/imports/{import}/interpretation-policy
POST   /api/imports/{import}/issues/{issue}/resolve
POST   /api/imports/{import}/issues/bulk-resolve
POST   /api/imports/{import}/rebuild-plan
POST   /api/imports/{import}/apply
POST   /api/imports/{import}/cancel
```

`POST /imports` debe responder rápido y encolar parsing.

No meter el archivo completo en el payload del job; el job recibe el ID/path privado.

Listados con paginación server-side, búsqueda y filtros.

Validaciones inválidas → 422 estándar. Estados inválidos → 409 con código de dominio.

---

# 16. Queue

La infraestructura Redis + `queue` ya existe.

Crear jobs separados y pequeños:

```text
ParseLegacyImport
BuildLegacyImportPlan
ApplyLegacyImport
```

No encadenar cientos de jobs por fila.

El batch de este tamaño (2.560 filas) debe procesarse en pocos jobs deterministas.

Cada job:

- idempotente por estado;
- sin raw row dumps en log;
- actualiza timestamps/status;
- captura fallo sanitizado;
- deja suficiente información para reintentar.

No usar `sync` sólo para hacer pasar tests; probar el flujo real de job en integración y usar fake donde corresponda en tests unitarios.

---

# 17. UI

Crear sección principal **Importaciones**.

Ruta sugerida:

```text
/imports
/imports/:id
```

## 17.1 Lista

Mostrar:

- archivo;
- perfil;
- fecha;
- usuario;
- estado;
- filas;
- blockers/warnings;
- resultado aplicado.

## 17.2 Nueva importación

- dropzone/input XLSX;
- explicación clara de que primero se analiza y **no modifica datos**;
- tamaño máximo;
- botón upload;
- progreso/estado por polling.

## 17.3 Detalle tipo stepper

```text
Archivo → Análisis → Revisión → Plan → Aplicado
```

Cards:

- hojas;
- bloques de empresa;
- filas;
- clientes detectados;
- empresas detectadas;
- blockers;
- warnings;
- acciones planificadas.

Tabs/secciones:

- Resumen
- Empresas
- Clientes
- Relaciones
- Afiliaciones
- Valores
- Incidencias
- Plan

La revisión necesita buscar por documento/nombre/empresa y filtrar por issue code/severity.

## 17.4 Resoluciones

Diálogos claros para:

- NIT conflictivo;
- alias de entidad;
- fecha inválida;
- duplicado conflictivo;
- relación desaparecida sin retiro;
- overlap empresa/transfer/paralelo;
- conflicto con dato existente;
- política de interpretación de retiros.

Persistir cada decisión; refrescar plan sin perder el resto.

## 17.5 Plan exacto

Antes de Apply mostrar cambios concretos agrupados:

```text
Clientes:  crear X / actualizar Y / sin cambio Z
Empresas:  ...
Relaciones: ...
Afiliaciones: ...
Valores: ...
```

Permitir expandir hasta evidencia hoja/fila, siempre redactada.

Apply deshabilitado si existe cualquier blocker sin resolver.

Confirmación final explícita; no aplicar al cerrar un modal accidentalmente.

## 17.6 Responsive y accesibilidad

Mantener los patrones actuales Bootstrap/Vue; tablas grandes con scroll contenido, no overflow global. Labels reales, focus correcto, botones según permiso.

---

# 18. Incidencias mínimas obligatorias

Implementar códigos estables, al menos:

```text
unsupported_workbook_profile
invalid_sheet_name
duplicate_month_sheet
missing_company_block_header
invalid_company_tax_id
company_identity_conflict
company_arl_metadata_conflict
credential_like_content
invalid_client_document
client_identity_conflict
invalid_affiliation_date
duplicate_exact_row
duplicate_conflicting_row
ambiguous_risk_job_columns
unknown_risk_token
unresolved_social_entity
affiliation_entity_unknown
relationship_disappeared_without_retirement
overlapping_company_history
missing_monthly_value
invalid_monthly_value
existing_client_conflict
existing_company_conflict
existing_relationship_conflict
existing_affiliation_conflict
existing_rate_conflict
source_already_applied
```

El código es contrato API/UI; el texto humano puede cambiar.

---

# 19. Real workbook fingerprint QA

El Excel real **NO se versiona**.

Usar una copia local ignorada:

```text
.local-fixtures/EMPRESA BLINDEN AÑO 2026.xlsx
```

Agregar `.local-fixtures/` a `.gitignore`.

Crear script de verificación, por ejemplo:

```text
scripts/verify-a04-real-workbook.sh
```

Debe:

- negarse si la ruta no está presente;
- parsear usando el MISMO parser de aplicación;
- no aplicar a datos maestros;
- imprimir sólo agregados, nunca PII;
- verificar el fingerprint conocido del archivo entregado:
  - 10 hojas mensuales;
  - 101 bloques;
  - 2.560 filas;
  - 320 identidades documentales;
  - 14 nombres lógicos de empresa;
- confirmar que detecta el conflicto de identidad de empresa conocido;
- confirmar que detecta fechas inválidas sin corregirlas automáticamente;
- confirmar que detecta contenido tipo credencial pero no imprime el valor;
- confirmar que no crea acciones A03 de pagos/obligaciones.

No hacer que la suite normal dependa del Excel privado. Los tests versionados generan fixtures sintéticos equivalentes.

---

# 20. Pruebas

## 20.1 Parser unit/integration

Generar workbooks sintéticos temporales en tests; no copiar PII real.

Cubrir:

- español mes+año;
- variantes de header;
- used range inflado;
- bloque empresa;
- NIT con/sin DV;
- secret redaction;
- P/Q riesgo-cargo en ambos órdenes;
- serial Excel;
- fecha textual válida;
- año truncado no auto-corregido;
- documento CC/CE/PPT/PT;
- duplicate exact/conflicting;
- retirement parser;
- valor vacío/cambio;
- negative AFP/CCF tokens;
- aliases que requieren aprobación.

## 20.2 Feature/API

- permisos mínimos por endpoint;
- upload sólo XLSX;
- fichero privado;
- hash duplicado;
- estados/transiciones;
- paginación/filtros issues/rows;
- resolución y rebuild plan;
- Apply bloqueado con blockers;
- Apply exacto cuando todo resuelto;
- doble Apply concurrente;
- rollback completo con fallo inyectado;
- no overwrite silencioso de datos existentes;
- audit/provenance;
- no pagos/obligaciones importados.

## 20.3 Historia

Pruebas específicas:

- misma persona/empresa/fecha repetida durante meses → un episodio;
- reingreso mismo par con nueva fecha → dos episodios;
- retiro + nuevo empleo no se interpreta automáticamente como paralelo;
- overlap real bloquea;
- precisión `month` se publica como aproximada;
- `[start,end)` permanece intacto.

## 20.4 Afiliaciones

- snapshots iguales se comprimen;
- `NO PORVENIR` no crea Porvenir;
- `SI` no crea una entidad;
- cambio mensual crea segmentos con precision `month` sólo tras policy/resolución;
- ARL risk 1–5;
- typo de riesgo genera issue;
- una sola afiliación abierta por tipo.

## 20.5 Rates

- mismo valor consecutivo → una fila;
- cambio → nueva vigencia mensual;
- blank → no rate;
- conflicto con rate existente → blocker;
- rate usado por obligación no se modifica.

## 20.6 Concurrencia A03

Test con dos conexiones PostgreSQL reales o mecanismo equivalente demostrando que Apply de topología/rates comparte `BillingTopologyLock` con generación/cierre y no expone un estado intermedio.

No usar sleeps frágiles.

## 20.7 Frontend

Vitest focalizado:

- permisos;
- upload;
- estados;
- issue filters;
- resolución;
- blockers → Apply disabled;
- preview counts;
- redaction;
- date precision label.

## 20.8 E2E A04 únicamente

Crear al menos estos journeys, con workbook sintético:

A. upload → parse → review → ready → apply → datos visibles en clientes/empresa;
B. conflicto NIT/fecha bloquea Apply → resolución → plan cambia → Apply habilitado;
C. duplicate hash aplicado no vuelve a escribir;
D. rol sin permisos recibe 403 aunque salte UI;
E. import con transferencia/retirement reconstruye dos episodios sin overlap;
F. archivo con texto `CLAVE` nunca muestra el valor en UI/API/log fixture.

No ejecutar todos los E2E A01-A03 por defecto.

---

# 21. Migraciones y regresión focalizada

A04 sí agrega esquema nuevo y columnas de precisión en A02, por tanto verificar:

1. migración fresh en DB de pruebas;
2. upgrade desde baseline actual con datos A02/A03 existentes;
3. rollback de migraciones A04 sin pérdida/corrupción de tablas anteriores;
4. re-apply;
5. checks/FK/indexes esperados.

No reescribir migraciones publicadas A01-A03. Crear migraciones nuevas.

Regresión backend focalizada mínima:

- A04 completa;
- tests A02 de `EffectivePeriod`, relaciones, afiliaciones y presentación de fechas;
- tests A03 que consultan `ClientCompanyAssignment` para candidatos/generación;
- tests A03 de rate immutability/config resolver afectados por import de rates;
- permiso seeder.

Frontend:

- A04 Vitest;
- typecheck;
- lint;
- build.

Pint para archivos tocados o suite práctica al final.

`composer validate --strict`, `composer audit` por nueva dependencia. `npm audit` sólo si package-lock cambia (no debería por A04 backend).

---

# 22. Documentación

Crear `docs/TASKS/A04.md` como documento final de producto, no como diario gigantesco.

Debe explicar:

- fuente Excel real y perfil compatible;
- seguridad/privacidad;
- staging → review → apply;
- reglas de identidad;
- precisión de fechas;
- retiro;
- afiliaciones;
- rates;
- qué NO importa;
- permisos;
- API/UI;
- operación de queue;
- cómo ejecutar real-workbook fingerprint sin versionar archivo.

Actualizar:

- `README.md` estado actual A04;
- `docs/ROADMAP.md` A04 completada;
- `docs/ARCHITECTURE.md` dominio Imports y precisión histórica;
- `docs/DEVELOPMENT.md` comandos y `.local-fixtures`;
- `docs/SECURITY.md` carga privada, redacción y límites.

No dejar conteos globales de tests que cambien en cada ronda salvo contratos estructurales estables.

---

# 23. Fuera de alcance A04

No implementar:

- A05 planillas/liquidación;
- exportar Excel/PDF;
- obligaciones históricas A03 a partir del Excel;
- pagos a partir de `PLANILLA`, `NOVEDAD`, `ME DEBE`, etc.;
- ejecución automática de afiliaciones ante operadores externos;
- documentos A07;
- portal;
- email/Telegram;
- IA/MCP;
- parser genérico configurable para cualquier spreadsheet.

---

# 24. Criterios de aceptación

A04 no está terminado hasta que:

1. subir un XLSX nunca modifica maestros;
2. parsing ocurre en queue y es retry-safe;
3. source queda privado y secrets redactados fuera del archivo;
4. parser entiende las variantes reales del perfil;
5. identidad nunca se infiere por nombre solamente;
6. conflictos no se auto-resuelven destructivamente;
7. relaciones históricas preservan exactitud/precisión;
8. afiliaciones negativas no crean entidades falsas;
9. rates se comprimen por cambio mensual y no crean obligaciones;
10. todos los blockers deben resolverse antes de Apply;
11. preview y Apply comparten exactamente el mismo plan persistido;
12. Apply es all-or-nothing;
13. doble Apply no duplica;
14. Apply comparte `BillingTopologyLock` con A03;
15. cada registro aplicado conserva proveniencia;
16. backend aplica todos los permisos;
17. el real-workbook verifier reproduce el fingerprint sin imprimir PII;
18. QA focalizada queda verde;
19. no hay secretos/Excel real/runtime artefacts en Git;
20. A05 no fue iniciado.

---

# 25. Entrega Git

Al terminar todo:

```bash
git diff --check
git status
git diff
```

Revisar secretos y archivos grandes/tracked.

Un único commit final:

```text
A04: importación y reconstrucción histórica desde Excel
```

Push normal:

```bash
git push origin main
```

No force push.

Verificar:

```text
HEAD == origin/main
working tree clean
```

Generar review archive mediante el mecanismo normal del repositorio, sin incluir:

- Excel real;
- `.local-fixtures`;
- `.env`;
- logs;
- datos runtime;
- credenciales.

Reporte final con:

- SHA baseline/final;
- migraciones;
- tablas/campos;
- permisos;
- dependency añadida;
- parser/profile;
- fingerprint real;
- issues/resolutions;
- pruebas ejecutadas y resultados;
- confirmación de un solo commit y push normal;
- confirmación A05 no iniciado.

**No declarar A04 aprobado. La aprobación la hará la auditoría externa de GitHub.**

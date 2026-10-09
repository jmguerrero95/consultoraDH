# Arquitectura

## 1. Principio rector: monolito modular

Consultora DH es **un único despliegue** que se ejecuta en un solo servidor. No
hay microservicios, ni cola de mensajes entre procesos de negocio, ni
descubrimiento de servicios.

El motivo es práctico: la aplicación la mantiene y opera un equipo pequeño, y
el coste operativo de varios servicios (red, despliegue, observabilidad,
consistencia distribuida) no compensaría el beneficio a esta escala. Un
monolito bien organizado se lee, se prueba y se despliega más rápido.

"Modular" significa que el código **sí** está separado por dominio, y que esa
separación se respeta:

- Cada módulo de negocio (clientes, afiliaciones, pagos, documentos…) tendrá su
  propia carpeta en `app/Domain/`, con sus modelos, acciones, eventos,
  escuchas, políticas y pruebas.
- Los módulos se comunican por **contratos**: eventos de dominio, servicios de
  aplicación y policies. No se comparten tablas de forma implícita ni se
  escriben modelos de otro módulo.
- Una dependencia entre dominios debe ser deliberada y visible. Si dos módulos
  necesitan lo mismo, eso suele indicar que falta un concepto compartido.

La modularidad se pierde en cuanto el código deja de cumplir estas reglas, de
ahí la insistencia en los límites. Cuando en el futuro haga falta separar un
proceso, un dominio con contrato propio es mucho más fácil de extraer que una
base de código monolítica sin costuras.

## 2. Componentes

```
                      Navegador (Windows)
                              │
                  http://localhost:8080  (host)
                              │
                     ┌────────▼────────┐
                     │      nginx      │  TLS, cabeceras, estáticos
                     └────────┬────────┘
                              │ FastCGI (red interna)
                     ┌────────▼────────┐
                     │  app (PHP-FPM)  │  Laravel 13 · API + shell HTML
                     └───┬────────┬────┘
                         │        │
          ┌──────────────▼──┐  ┌──▼───────────────┐
          │    postgres     │  │      redis        │
          │  PostgreSQL 18  │  │ caché · sesiones  │
          │  Volumen propio │  │ colas · limiters  │
          └─────────────────┘  └──────────────────┘
                              │
                     ┌────────▼────────┐
                     │  node (Vite)    │  solo en desarrollo
                     └─────────────────┘
                              │
                     ┌────────▼────────┐
                     │      queue      │  consumidor de trabajos
                     └─────────────────┘
```

| Servicio | Función | Puerto en el host |
| --- | --- | --- |
| `nginx` | Punto de entrada: estáticos, cabeceras de seguridad, `index.php` | `8080` |
| `app` | Aplicación Laravel (API JSON y shell HTML) | ninguno |
| `queue` | Consumidor de trabajos en cola | ninguno |
| `postgres` | Base de datos | ninguno (solo red interna) |
| `redis` | Caché, sesiones, colas y contadores de límite de intentos | ninguno |
| `node` | Servidor de desarrollo de Vite | `5173` |

Los detalles operativos están en [DEVELOPMENT.md](DEVELOPMENT.md).

## 3. Backend y frontend: mismo repositorio, separación estricta

El backend y el frontend viven en el mismo repositorio pero **no se mezclan**:

- `app/`, `config/`, `routes/`, `database/`, `resources/views/` son PHP.
- `resources/js/` y `resources/css/` son TypeScript y CSS.
- `resources/views/app.blade.php` es el único punto de contacto: un shell HTML
  de 30 líneas que monta la aplicación Vue.

El shell **no contiene datos del usuario**. No hay plantilla Blade por
pantalla, no hay tokens embebidos, no hay estado en el HTML. El frontend
resuelve la sesión llamando a `GET /api/auth/me`, y el router de Vue decide
qué pantalla mostrar.

Consecuencias prácticas:

- Un acceso directo a `/profile` funciona: el servidor devuelve el shell y la
  interfaz resuelve la ruta.
- Una sesión expirada la maneja la interfaz, no un ciclo de redirecciones del
  servidor.
- El shell se puede cachear sin riesgo.

### 3.1 La API se registra con el grupo `web`

Este es un detalle importante y deliberado. En `bootstrap/app.php`, las rutas
de `routes/api.php` se registran con el middleware `web` y el prefijo `/api`:

```php
Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));
```

El grupo `api` de Laravel es **sin estado**: no inicia sesión y no verifica
CSRF, porque está pensado para clientes que llevan un token. Consultora DH usa
una aplicación de una sola página servida por el mismo origen, con la sesión en
una cookie `HttpOnly`, que es el modelo de la *[stateless SPA][1]* de Sanctum
con sesión. `web` es el grupo que aporta `StartSession` y `VerifyCsrfToken`, que
es exactamente lo que se necesita.

### 3.2 Por qué un cliente `fetch` y no Axios

`resources/js/services/http.ts` implementa el cliente HTTP sobre el `fetch` de
la plataforma. Se eligió así por tres razones:

1. **Un solo lugar** donde se resuelve la cookie CSRF, `credentials` y la
   normalización de errores.
2. Sin dependencias de terceros que actualizar.
3. El paquete resultante es pequeño: el JavaScript de la aplicación ocupa unos
   21 kB comprimidos.

## 4. Capas del backend

```
routes/            Declara rutas y middleware. Sin lógica de negocio.
  │
Http/              Controladores finos: validan el tipo de la petición y
  │                delegan. Form Requests para validar. Resources para serializar.
  │
Domain/            Acciones y eventos. Aquí vive la lógica: no depende de HTTP.
  │                Un evento de dominio no sabe qué es una petición.
  │
Models/            Eloquent. Persistencia y relaciones.
  │
Policies/Gates    Autorización. Se evalúa en el servidor, nunca en el cliente.
```

Reglas que el código respeta:

- **Los controladores no contienen lógica de negocio.** Traducen HTTP a una
  llamada de dominio y la respuesta a JSON.
- **La validación vive en los Form Requests**, con mensajes en español, y no
  en el controlador.
- **La autorización se comprueba en el servidor.** La interfaz oculta un enlace
  que el usuario no puede abrir, pero eso es presentación: la ruta vuelve a
  comprobarlo.
- **No hay una capa de repositorios sobre Eloquent.** Un repositorio que solo
  reescribe `Model::query()` añade indirección sin valor. Cuando un módulo
  necesite una abstracción real (por ejemplo, integraciones con sistemas
  externos), se justifica en ese momento y con pruebas.
- **Los eventos de dominio no conocen la auditoría.** Una acción de dominio
  emite un evento; una única escuchas decide qué eventos se auditan
  (`App\Domain\Audit\Listeners\RecordAuditEvent`). Añadir una acción auditable
  es escribir una clase de evento, nada más.

## 5. A01: estructura de dominios

Durante A01 existen estos dominios, que son los que necesitan los módulos
posteriores:

| Dominio | Responsabilidad |
| --- | --- |
| `Domain/Auth` | Inicio y cierre de sesión, recuperación de contraseña |
| `Domain/Audit` | Registro de auditoría, con saneado de metadatos |
| `Domain/Users` | Estado de la cuenta y creación de administradores |
| `Profile` (en `Domain/Profile`) | Cambio de nombre, correo y contraseña |

`Domain/Auth/Actions/AuthenticateUser` es el ejemplo canónico. Concentra en un
lugar todo lo que hace segura una autenticación: normalización de la dirección,
comparación de contraseña con Constant-time, rechazo de cuentas inactivas,
regeneración del identificador de sesión, registro del último acceso y emisión
de eventos. El controlador solo traduce la petición.

## 6. A02: estructura de dominios

A02 añade cuatro dominios más a los de A01:

| Dominio | Responsabilidad |
| --- | --- |
| `Domain/Clients` | Alta, edición y estado del cliente; documento y contacto |
| `Domain/Companies` | Alta, edición y estado de la empresa; NIT |
| `Domain/Affiliations` | Relaciones históricas con empresas y afiliaciones |
| `Domain/DataQuality` | Comprobaciones del portafolio y sus contadores |
| `Domain/Shared` | `RecordStatus`, que comparten los tres tipos de registro |

La forma que acabó teniendo:

```
app/Domain/Affiliations/
├── ManageClientCompanies.php     vincular, cerrar, transferir
├── ManageAffiliations.php        registrar, cerrar, cambiar de entidad
├── ArlRiskClass.php              nivel de riesgo y sus etiquetas
└── ParallelRelationshipNotAllowed.php

app/Domain/DataQuality/
├── DataQualityCode.php           los nueve códigos y su severidad
├── DataQualityFinding.php
├── DataQualityInspector.php      las comprobaciones
├── DataQualitySeverity.php
└── PortfolioMetrics.php          los contadores del panel
```

Criterios que sí se aplicaron, y por qué:

- **Los modelos viven en `app/Models`, no en el módulo que los posee.** Se
  consideró lo contrario y se descartó: `Client`, `Company`, `User` y
  `AuditEvent` se consultan desde peticiones, recursos y relaciones de todo el
  sistema, así que un modelo encerrado en el módulo obliga a importar el módulo
  entero para obtenerlo. El módulo conserva lo que no es modelo: acciones,
  eventos, excepciones y enums.
- **Una acción por operación de negocio.** `ManageClientCompanies` concentra
  vincular, cerrar y transferir, que comparten bloqueo de fila y reglas de
  fechas; un método de modelo repetiría el bloque tres veces.
- **Cada regla que puede romperse tiene su propia excepción.** Además del
  mensaje para la persona, `ParallelRelationshipNotAllowed` lleva los datos que
  la interfaz necesita para ofrecer sus tres salidas. Un `422` con texto no
  alcanza para eso.
- **La autorización se comprueba por permiso y por rol, con pruebas para cada
  combinación.** Las rutas declaran el permiso; `AuthorizationTest` recorre los
  cinco roles.

### 6.1 Las fechas de un periodo

Los dos campos de fecha significan una cosa y sólo una:

```
[started_on, ended_on)
```

`started_on` es el primer día en que el registro es efectivo. `ended_on` es el
primer día en que **deja** de serlo, y por eso queda fuera del periodo. `NULL`
significa que todavía no ha terminado.

Un traspaso efectivo el 1 de marzo escribe esa misma fecha en las dos filas: el
fin de la anterior y el inicio de la nueva. El 28 defebrero responde la empresa
anterior, el 1 de marzo la nueva, y no hay ningún día que pertenezca a las dos ni a
ninguna. La convención inclusiva, `[inicio, fin]`, obligaría a escribir el cierre
el día anterior y repartir esa resta entre todos los que llaman al dominio; una de
esas cuentas acabaría mal.

La regla vive en `App\Domain\Shared\EffectivePeriod`, la implementa el ámbito
`activeOn()` de las dos tablas, y hay pruebas a ambos lados de la costura. A03 va a
informar sobre periodos y tiene que heredar esto en vez de inventar su propia
aritmética.

### 6.2 Quién bloquea, y qué bloquea

El bloqueo de una operación que cambia relaciones es **la fila del cliente**, dentro
de la transacción, antes de leer nada.

Bloquear las relaciones abiertas no sirve para serializar dos primeras relaciones
simultáneas: cada transacción encuentra cero filas y por tanto bloquea cero filas, y
las dos insertan. La fila maestra siempre existe, así que siempre hay algo con lo
que contenderse. Es la misma razón por la que un "existe? entonces inserto" escrito
en PHP es una carrera y un índice único no lo es.

El orden importa: primero el bloqueo, después la lectura de las relaciones. Al revés,
la lectura que decide se hace fuera del bloqueo y no sirve de nada.

Y vale igual para todo lo que depende del estado del cliente: vincular, cerrar,
trasladar, cambiar de estado y también registrar o cambiar una afiliación, porque
esa última abre un periodo nuevo. Todas toman el cliente y **vuelven a leerlo**; la
copia que tenía el llamador puede haberse quedado vieja mientras esperaba, y decidir
con ella es decidir con información caducada.

El orden completo es siempre **cliente, después historial**. Dos transacciones que
tomaran los bloqueos en orden contrario pueden interbloquearse.

### 6.3 Cuándo el dominio no elige

Dos casos en que el servidor se niega a decidir y devuelve un conflicto con lo que
necesita para que la persona elija:

- **Un vínculo repetido.** El origen de datos tiene personas en más de una empresa a
  la vez y no hay forma de saber si es real o un error, así que quien llama dice qué
  hacer: `transfer`, `parallel` (con motivo) o cancelar.
- **Un traspaso con más de una relación abierta.** Con dos o más, elegir una es una
  decisión de negocio y no le corresponde a este sistema. Responde
  `transfer_source_required` con las relaciones abiertas; la transferencia se hace
  después sobre el endpoint que nombra el origen en su ruta.

Un `close_others` existe en el dominio para desactivar un cliente, y no se acepta
por el endpoint de vínculo: un valor capaz de cerrar todas las relaciones abiertas
no pertenece en una petición cuyo trabajo es añadir una.

### 6.4 El modelo histórico

Las relaciones con empresas y las afiliaciones no se actualizan: se cierran. Un
cierre marca la fila anterior con su fecha de fin y abre una fila nueva, y un
cambio de entidad hace las dos cosas. De ahí salen tres detalles que conviene
tener presentes:

- Las restricciones de unicidad de esas dos tablas son **parciales** (`WHERE
  ended_on IS NULL`): sólo hay una fila abierta por cliente y empresa, o por
  cliente y tipo. Las filas cerradas pueden repetirse sin límite.
- Las claves foráneas de las filas históricas **no se borran en cascada**: un
  borrado en el maestro destruiría el periodo registrado.
- `audit_events` recibe `subject_type` y `subject_id` para podereboquear las
  acciones de cliente, de vínculo y de afiliación en la misma ficha sin un
  registro de cambios escrito a mano.

## 6.5 A03: el libro financiero

La sección 6.4 explica el modelo histórico del directorio. El módulo financiero
extiende esa idea a tres cosas, y conviene tenerlas juntas porque se refuerzan.

### Nada guarda un saldo

No hay columna `balance`, ni en las obligaciones, ni en los pagos, ni en la
cartera. Lo que se factura es la **instantánea** que escribió la generación —el
importe y las referencias de la configuración que lo produjo— más los ajustes
registrados; lo que se pagó son las **aplicaciones vivas de pagos que no están
anulados**. Todo lo demás se calcula al leer.

La consecuencia práctica es que anular un pago, revertir una aplicación o
revertir un ajuste cambia todos los saldos en la siguiente petición, sin que
exista ningún campo que alguien pueda olvidar actualizar, y sin que dos pantallas
puedan discrepar porque una tenga un contador y la otra no.

Y la consecuencia incómoda, que vale la pena decir: un pago registrado sin
aplicar es un **anticipo**. La empresa tiene ese dinero y todavía no lo ha
confrontado con una deuda. La cartera lo cuenta como crédito, nunca como saldo a
favor del cliente, y la lista de pagos lo marca «sin aplicar» sin pintarlo como
error, porque un adelanto es una operación corriente y marcarlo como falla
enseña al operador a ignorar ese color.

### La configuración tiene vigencia, no estado

`cutoff_rules` y `client_company_rates` no dicen «cuál es el valor actual»: dicen
cuál estaba en vigor en cada mes. Un periodo se factura con la regla y el valor
que aplicaban **al inicio de ese mes**, y la obligación guarda `rate_id` y
`cutoff_rule_id` para que la instantánea sea evidencia y no coincidencia.

La evidencia se conserva como evidencia: la llave de `cutoff_rule_id` es `RESTRICT`,
y una restricción `CHECK` parcial exige que toda fila generada nombre las dos
columnas. El `CHECK` y no un `NOT NULL` porque `manual_correction` —una obligación
escrita a mano— existe precisamente para el caso en que no hay configuración detrás:
su procedencia es lo que el operador escribió, y exigirle una regla sería exigirle
una mentira.

La procedencia **topológica** se guarda aparte, en `obligation_source_assignments`:
una fila por tramo de relación que componen la obligación. Una obligación puede
reunir varios tramos no solapados del mismo par —una salida y un reingreso dentro
del mes— y en ese caso la columna de un solo tramo queda en `NULL` en vez de
elegir uno y atribuirle la deuda entera.

La consecuencia es que corregir una decisión pasada no es una edición: es
agregar una decisión nueva con vigencia posterior. Si el mes ya se facturó, el
servidor se niega a cambiar la fila que usó y responde `409` diciendo qué periodos
ya facturó con ella. La forma de corregir lo que se facturó mal es un ajuste
registrado con su motivo, que convive con la instantánea en lugar de reemplazarla.

Falta el valor, o falta la fecha de corte, y la generación **no escribe nada** para
esa relación y nombra qué falta. Un mes a medio facturar es un mes que nadie sabe
leer.

### El bloqueo es el comportamiento por defecto

`client → empresa → general` para la fecha de corte: gana la más específica que
aplique. Un `cutoff_day` mayor que los días del mes se recorta al último día, así
que el 31 de febrero es el 28.

Varios periodos pueden estar abiertos. El «periodo actual» es el que corresponde
al mes calendario si está abierto, y si no el abierto más reciente. Cerrar un
periodo congela su **estructura** —no se generan obligaciones nuevas— pero no su
dinero: los pagos y los ajustes de ese mes siguen siendo válidos.

### El orden de los bloqueos

Bloquear la fila del periodo serializa dos operaciones que **ambas hablan del mes**.
No serializa nada contra el directorio, que es de donde sale la topología que la
generación lee. De ahí salen tres carreras reales, que ninguna se arregla con un
bloqueo de mes:

- la generación lee una relación que intersecta octubre y otra transacción la
  cierra o la transfiere retroactivamente antes de que la generación confirme;
- la generación ve un cliente y una empresa activos, y otra transacción los
  desactiva antes de confirmar;
- el cierre de periodo demuestra que «no falta nada» y otra petición inserta una
  relación con vigencia en ese mismo mes, de modo que el periodo se cierra
  incompleto y nada puede generar ahí después sin reabrirlo.

Por eso existe **un protocolo compartido de topología** (`BillingTopologyLock`): un
bloqueo consultivo de transacción de PostgreSQL, con **una sola clave fija**, que
**todos** los participantes toman **antes de cualquier otro bloqueo**. Cerrar,
generar, abrir relación, cerrar relación, transferir, cambiar el estado de un
cliente o de una empresa participan en él.

La clave única no es una simplificación, es la propiedad que elimina el interbloqueo:
como todo el mundo toma la misma clave primero, nunca hay dos tenedores y nunca hay
un segundo bloqueo al que esperar. La alternativa —ordenar cada fila participante
y bloquearlas todas— no puede funcionar aquí: A02 cambia una relación por vez, así
que tendría que bloquear todos los clientes, empresas y relaciones que *podría*
tocar, en un orden global, y la generación tendría que bloquear el mismo conjunto
sin saber de antemano qué relaciones intersectan el mes. Adivinar el conjunto es
justo el fallo.

El precio es que meses distintos y clientes distintos se serializan entre sí durante
una escritura financiera corta. A la escala de este sistema son unos milisegundos, y
la alternativa es escribir una deuda a partir de una relación que ya estaba cerrada.

El orden completo, entonces:

| Operación | Orden |
| --- | --- |
| Topología (todos) | **bloqueo consultivo, primero, siempre** |
| Generar un mes | topología → periodo → valores y reglas citados ascendente por id → inserciones |
| Cerrar un mes | topología → periodo → candidatos y referencias |
| Abrir un periodo | topología → periodo |
| Pago | topología → cliente → pago → obligaciones ascendente por id → aplicaciones |
| Ajuste | obligación → aplicaciones |

Y en cada caso, la cifra que se usó para **elegir** se vuelve a leer después de
tomar el bloqueo. Sin eso, dos operadores pueden decidir sobre números que el otro
ya dejó viejos.

**La configuración se bloquea, no solo se lee.** La generación bloquea, en orden
ascendente por id, los valores y las reglas de corte que va a citar, y **vuelve a
leerlos después del bloqueo**; solo escribe si lo releído sigue describiendo lo
mismo. Leer sin bloquear deja esta carrera abierta: la previsualización calcula el
importe con una tasa que otra transacción cambia antes de que se escriba la
obligación, y la obligación resultante cita una evidencia que no produjo su importe.

La previsualización no es una excepción: `PreviewPeriodObligations` **recalcula los
candidatos dentro de la transacción**, dentro de los mismos bloqueos, en vez de
confiarse en lo que se leyó sin ellos. Entre que un operador lee una previsualización
y la confirma, puede haberse añadido un valor o cerrado una relación, y la respuesta
que vale es la calculada bajo el bloqueo.

## 6.6 A04: el dominio Imports

### El parser no escribe y no consulta

`BlindenLegacyWorkbookParser` es una función pura: el mismo archivo produce las mismas
filas, las mismas incidencias y las mismas huellas en cada ejecución. No toca la base, ni
el catálogo, ni el reloj, ni la red. Eso es lo que permite comparar dos ejecuciones, lo que
hace que las pruebas usen fixtures sintéticos en vez del archivo real, y lo que permite que
el verificador de §19 corra el mismo código que correrá el job en producción.

Todo lo que el parser no puede decidir sale como **problema**, nunca como valor. Una fecha
ilegible es `invalid_affiliation_date`; una fila sin documento es un bloqueo; una palabra que
aparece en dos empresas no se fusiona. La reconstrucción de historial recibe esas mismas
filas y sigue sin decidir: un episodio que desaparece es una pregunta, un solapamiento es una
pregunta, y ninguna de las dos tiene un campo de «resuelto» en este módulo —la respuesta vive
en la tabla de incidencias, donde §13 puede auditar quién decidió qué.

### La separación que cuesta caro no acortarla

En el flujo de A04 hay tres cortes, y cada uno existe porque romperlo costó una tarde:

| Corte | Qué pasa si se rompe |
| --- | --- |
| `parse()` → staging | Un archivo se analiza en la petición y 2 560 filas bloquean un worker |
| staging → plan | Se corrige una fila y hay que volver a subir el archivo entero |
| plan → apply | La previsualización describe algo distinto de lo que se aplica |

El tercero es el que §5.4 y §17.5 hacen explícito: `legacy_import_actions` **es** el plan, y
la pantalla lo lee. La previsualización no recalcula una explicación paralela, porque dos
cálculos del mismo plan divergen en cuanto el primero se queda obsoleto.

Pero leer las mismas filas no basta si el conjunto de filas puede cambiar entre la lectura y la
escritura. A04-R1 le añade una **identidad**: `legacy_imports` lleva `plan_revision` y
`plan_digest` —SHA-256 del contenido visible del plan, en orden de ordinal— y `apply` los exige
en el cuerpo de la petición.

```text
la pantalla muestra (revisión 4, digest abc…)
       ↓ el revisor confirma
apply envía {plan_revision: 4, plan_digest: "abc…"}
       ↓
no coincide → 409 stale_plan, no se escribe nada
```

El digest decide **si** el plan es el revisado; la revisión registra **cuál** construcción es.
Una reconstrucción que no cambia nada conserva el digest y avanza la revisión, así que refrescar
el plan no invalida una aprobación en curso. La auditoría encontró que `apply` no aceptaba
cuerpo alguno, de modo que nada unía la confirmación del revisor con las filas que se escribían.

### La ruta privada la construye el modelo

`imports/{uuid}/source.xlsx`. El controlador escribe usando
`LegacyImport::storedRelativePath()` y el job lee con `absolutePath()`, que llama al mismo
método. Cuando las dos cadenas vivían en archivos distintos y diferían en el prefijo del
directorio, toda subida terminaba en `failed` con «el archivo ya no está en el servidor» para
un archivo que acababa de llegar. Es la clase de bug que sólo aparece cuando los dos lados
se ejercitan por primera vez en la misma ejecución, que es exactamente lo que hace un
E2E de subida.

Corregir la ruta no bastó: el **disco** también tenía tres respuestas. El upload usaba el disco
por defecto inyectado por Laravel, `absolutePath()` usaba `imports.disk`, y `cancel()` usaba el
disco por defecto otra vez. Con `IMPORT_DISK` igual a `FILESYSTEM_DISK` funcionaba, que es
exactamente por lo que se entregó; con las dos variables distintas, toda importación fallaba y
la cancelación dejaba el libro en el servidor.

`ImportFileStore` es ahora el único lugar que elige disco, y el modelo sigue siendo el único
que construye la dirección.

### La precisión acompaña a la fecha, no se deduce de ella

Una frontera mensual guardada como `2026-03-01` y otra guardada como `2026-03-01` porque
alguien la eligió son indistinguibles sin una columna más. Por eso §8.3 añade
`started_on_precision` y `ended_on_precision` a las tablas históricas de A02, y por eso la
coherencia entre fecha y precisión la defiende un `CHECK` de PostgreSQL y no la aplicación.

La invariante vive en el modelo, en un `saving`, y no en los puntos de llamada: escribirla en
A02 y en las fábricas es dos mitades de la misma regla, y basta con que una vez no la
recuerde para que 77 inserciones legítimas fallen. El `saving` **no** pisa una precisión
declarada —un importador que escribe `month` conserva `month`—, sólo completa la que falta.

### El cerrojo es el de A03, no uno nuevo

Relaciones y rates son topología, y la generación de A03 lee esa topología. A04 toma el
`BillingTopologyLock` que ya existe: un segundo cerrojo advisory cumpliría la frase «toma un
cerrojo» y rompería su propósito. `ApplyImportPlan` corre dentro de
`BillingTopologyLock::run()`, que es transaccional y toma
`pg_advisory_xact_lock`, así que hay un cerrojo y una transacción y se liberan juntos.

### Los 403 van antes que los 404

Los enlaces de modelo de Laravel se sustituyen antes que el middleware de la ruta, así que un
`can:` sobre un `{import}` enlazado responde 404 a un usuario sin permiso —y le dice si ese
id existe. En un módulo cuyo tema es un archivo de documentos de identidad, eso es parte de
lo que se protege. El controlador de importaciones resuelve el id a mano, el `can:` corre
primero, y 404 significa que el id no existe **y** que quien pregunta podía preguntar.

## 6.7 A05: la capa operativa

A05 añade cinco dominios y un área autenticada nueva, sin tocar A02, A03 ni A04.

```
app/Domain/Planillas/     ContributionSheetStatus, PlanillaOperator,
                          CandidateRoster, CreateContributionSheet,
                          ValidateContributionSheet, SheetValidation,
                          SheetNotApplicable, PlanillaFileStore,
                          PlanillaExporter, PlanillaXlsxExport, PlanillaPdfExport
                          y Actions/{ValidateSheetToReady, SubmitSheet,
                          MarkSheetPaid, CancelSheet, ReturnSheetToDraft}

app/Domain/Operations/    NoveltyCategory, NoveltyStatus, TaskPriority,
                          TaskStatus, CalendarService, ReminderDispatcher,
                          OperationNotApplicable y
                          Actions/{ResolveNovelty, CancelNovelty,
                          CompleteTask, CancelTask, ReassignTask}

app/Domain/Documents/     DocumentRequestStatus, DocumentVisibility,
                          DocumentReviewStatus, DocumentFileStore y
                          Actions/{RequestDocument, ReviewDocumentRequest,
                          CancelDocumentRequest, DocumentNotApplicable}

app/Domain/Portal/        ProfileUpdateRequestStatus, ProfileUpdateHandler
app/Domain/Reports/       ReportType, ReportFormat, ReportCadence, ReportResult,
                          ReportFactory, ReportFilter, PortfolioReport,
                          CompanyPortfolioReport, EntityReport,
                          PlanillaSummaryReport, ReportGenerator,
                          ReportScheduleRunner, Export/{FormulaGuard,
                          CsvExporter, XlsxExporter, PdfExporter}
```

### 6.7.1 La planilla es una fotografía, no una vista

`contribution_sheet_lines` guarda una **copia** de la evidencia de cada persona: su
nombre, su documento, la empresa, la fechas de la relación y las entidades de
seguridad social tal como eran para ese mes. La razón es histórica: si las líneas se
derivaran en vivo de `client_company_assignments`, corregir el nombre de alguien o
mover un cargo reescribiría silenciosamente cada planilla ya cerrada.

Las líneas conservan `client_id` y `client_company_assignment_id` para que la copia
sea rastreable, y el total se **deriva** sumando las líneas incluidas. No existe
columna de saldo en la planilla, igual que no existe en el cliente.

### 6.7.2 La vista previa y la creación no pueden divergir

`CandidateRoster::digest()` resume la identidad de la evidencia: periodo, empresa,
relaciones candidatas, su marca de actualización y las afiliaciones usadas. La vista
previa devuelve ese resumen; la creación lo recalcula dentro de `BillingTopologyLock`
y responde 409 si ya no coincide.

A05 reutiliza el bloqueo de topología existente en lugar de inventar un segundo
protocolo: dos bloqueos incompatibles significarían dos escritores, cada uno
creyendo que está solo.

### 6.7.3 El portal es propiedad, no permiso

El rol `Client` no tiene **ningún** permiso interno. Sus endpoints comprueban
`account_type = 'client'` y que el recurso pertenezca a su `client_id`. Un
identificador de otro cliente devuelve 404, no un 403 con datos.

### 6.7.4 El calendario no tiene tabla

`CalendarService` agrega hechos que ya existen —tareas, vencimientos, solicitudes,
planillas y obligaciones— y acota el rango a tres meses. Crear filas de evento sólo
para dibujarlas sería una segunda fuente de verdad sobre datos que ya son hechos.

## 7. Base de datos

- **PostgreSQL 18** para todo. No hay SQLite en desarrollo ni en pruebas: se
  quiere el mismo motor, las mismas restricciones y los mismos tipos en todas
  partes.
- Las migraciones son la definición del esquema. `users.status` tiene una
  restricción `CHECK` además del enum de PHP, para que ninguna vía de
  escritura pueda introducir un estado inválido.
- Los correos se guardan siempre en minúsculas, mediante un mutador en el
  modelo, y con índice único. Así es imposible que existan dos cuentas que
  difieran sólo en mayúsculas.
- **No hay borrado lógico** en A01. Se añade sólo cuando exista una razón
  concreta; un `deleted_at` global aplicado sin criterio es una decisión
  difícil de revertir. A03 no lo añade: sus siete tablas no tienen columna de
  borrado porque ninguna de sus filas se borra.
- **A05** añade `BIGINT` de pesos enteros para el valor liquidado de la planilla y
  para los totales derivados. Los reportes y el portal **no** agregan saldos: los
  piden al servicio de A03, de modo que no existe una segunda fórmula de cartera
  capaz de discrepar con la pantalla interna.
- La pareja de la cuenta de portal es una restricción de base de datos, no una
  validación de PHP: `account_type = 'client'` exige `client_id`, y
  `account_type = 'staff'` lo prohíbe. Un índice único parcial sobre `client_id`
  garantiza una sola cuenta por cliente. Ninguna vía de escritura —seeder,
  consola, migración futura, un `update()` descuidado— puede producir una cuenta
  que inicie sesión sin saber de quién son los datos.
- Los importes de A03 son `BIGINT` de pesos enteros. No hay `NUMERIC`, no hay
  decimales y no hay coma flotante en ninguna parte del camino. Una cantidad con
  centavos es un dato que este sistema no sabe representar, y aceptarla
  redondeándola sería inventar dinero.

### 7.1 Base de datos de pruebas

`phpunit.xml` apunta a la conexión `testing`, que usa `DB_TEST_DATABASE`
(`consultora_dh_test`). Además, `tests/Pest.php` **aborta la suite** si esa
base de datos coincide con la de desarrollo: la suite es destructiva y perder
datos locales por una ejecución de tests sería inaceptable.

### 7.2 Servicio de planificador

A05 añade un servicio `scheduler` a `compose.yaml`. Usa la **misma** imagen
`consultora-dh/app:local`, los mismos montajes de código y `vendor`, y ejecuta
`php artisan schedule:work`. No hay cron en el host ni otro despliegue: es un
proceso de la aplicación.

```
*/5 * * * *  php artisan operations:dispatch-reminders
*   * * * *  php artisan reports:run-schedules
```

La idempotencia en la base de datos es la garantía; `withoutOverlapping` y
`onOneServer` evitan trabajo duplicado pero no son la garantía. El recordatorio
reclama la fila con `lockForUpdate` y escribe `reminder_sent_at` en la misma
transacción, y la generación programada avanza `next_run_at` bajo bloqueo y lleva
una clave de ocurrencia única.

## 8. Redis

Redis se usa para cuatro cosas, todas con una justificación:

| Uso | Motivo |
| --- | --- |
| Sesiones | Compartidas entre peticiones y entre los workers de cola |
| Caché | Respuestas y datos de lectura; invalidable desde la aplicación |
| Colas | Trabajos en segundo plano, con reintentos y colas fallidas |
| Límite de intentos | Contadores de autenticación, con caducidad automática |

La política `maxmemory-policy` es `noeviction`: si Redis se llena, falla la
escritura en lugar de descartar silenciosamente una sesión o un trabajo. Para
una plataforma administrativa, perder una sesión por presión de memoria sería
peor que un error visible.

`appendonly` está activado para que un reinicio no invalide todas las sesiones
ni pierda trabajos en cola.

## 9. Colas

`queue` es un servicio propio que ejecuta `php artisan queue:work`. Existe
porque A02 en adelante necesita trabajo asíncrono (importaciones, PDF,
notificaciones) y la infraestructura debe estar probada antes de que exista el
primer trabajo real.

En A01 se verificó el circuito completo: un trabajo colocado en Redis fue
recogido y ejecutado por el worker, sin ninguna clase de trabajo en el
repositorio porque todavía no había una operación de negocio que lo justificara.

**A04 es el primer trabajo real**, y por eso sus reglas son las de la cola y no las de
una importación:

| Trabajo | Qué hace | Reintentos |
| --- | --- | ---: |
| `ParseLegacyImport` | El libro → staging | 2 |
| `BuildLegacyImportPlan` | Filas → plan persistido | 2 |
| `ApplyLegacyImport` | El plan → maestros | 1 |

**El trabajo lleva un id, no el archivo.** Un payload encolado se serializa en Redis, y
un libro de 700 KB dentro de un payload se copia a la memoria de la cola y a sus logs; el
job recibe el id y lee la ruta privada de la base.

**Idempotente por estado, no por idempotencia de SQL.** `ParseLegacyImport` reemplaza sus
filas e incidencias en vez de añadirlas, y `BuildLegacyImportPlan` borra el plan anterior
antes de escribir el nuevo, con un índice único sobre la huella de cada acción. Un reintento
converge en lugar de duplicar.

**`ApplyLegacyImport` no reintenta.** Un apply que falla ya ha revertido todo, y reintentarlo
significa aplicar un plan escrito contra un estado que nadie ha revisado. La seguridad real
está en que un segundo intento se convierte en un 409 por el estado, no en una segunda
escritura.

## 10. Decisiones de diseño y sus motivos

| Decisión | Motivo | Alternativa descartada |
| --- | --- | --- |
| Monolito modular | Un servidor, un despliegue, costes bajos | Microservicios |
| Sesión con cookie, sin token en el navegador | `HttpOnly` limita el alcance de un XSS | Token en `localStorage` |
| `Sanctum` para CSRF y futura autenticación de máquina | Infraestructura estándar, sin homebrew | CSRF propio |
| Spatie para roles y permisos | Maduro, con soporte de Laravel 13 | Implementación propia |
| `fetch` en lugar de Axios | Sin dependencias, control total | Axios |
| Bootstrap 5 | Componentes fiables y accesibles, sin coste | Tailwind, kits de pago |
| Tokens de diseño en CSS | La identidad se cambia en un archivo | Colores en cada componente |
| `spatie/laravel-permission` en vez de policies a medida | Los roles son de la organización, no de cada modelo | Policies puras |
| Enums de PHP + `CHECK` en base de datos | Dominio tipado y base de datos que no puede corromperse | Sólo el enum |

## 11. Rendimiento

A01 no optimiza de forma prematura, pero tampoco introduce deuda:

- La página de inicio ejecuta **dos** consultas ligeras (`select 1` en
  PostgreSQL y `PING` en Redis). No hay diagnóstico costoso ni sondeos en el
  frontend: el panel carga una vez y sólo se recarga si el usuario lo pide.
- Las consultas del panel usan Eloquent directamente, sin N+1: las relaciones
  de rol y permisos se resuelven una vez por usuario.
- El paquete de producción ronda los 21 kB comprimidos de JavaScript de
  aplicación (más el runtime de Vue), sin bibliotecas pesadas.
- Las comprobaciones de salud de Docker son baratas: el pool de PHP-FPM
  responde a un `ping` propio sin ejecutar PHP, y nginx tiene un endpoint que
  no toca PHP.
- OPcache está activo y el contenedor incluye un `Dockerfile` con verificación
  de extensiones para que una imagen sin el driver de PostgreSQL o de Redis
  falle al construirse y no al arrancar en producción.

## 12. Notas operativas

- **WSL y rutas de Docker.** Si el CLI de `docker` de WSL responde con un error
  de integración, ejecute los comandos de Compose desde el directorio del
  proyecto con rutas relativas. Es la forma fiable cuando la integración WSL de
  Docker Desktop no está activa.
- **`vendor/` y `node_modules/` en volúmenes con nombre.** Están dentro del
  sistema de archivos de Docker y no en el montaje de enlace: leer decenas de
  miles de archivos pequeños a través del enlace es mucho más lento, y afecta tanto al arranque como a cada compilación.
- **PostgreSQL 18** almacena sus datos en un subdirectorio versionado de
  `/var/lib/postgresql`. El volumen se monta en el directorio padre; montarlo
  en `/var/lib/postgresql/data` hace que la imagen rechace arrancar.

[1]: https://laravel.com/docs/sanctum#spa-authentication

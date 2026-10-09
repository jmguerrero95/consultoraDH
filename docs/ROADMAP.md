# Hoja de ruta

Plan de alto nivel de Consultora DH. Cada tarea es un entregable revisable por
separado, del mismo tipo que A01: base técnica probada, documentada y ejecutable.

El orden no es casual: cada tarea deja lo que la siguiente necesita.

---

## Tareas completadas

### A01 — Base técnica, autenticación, seguridad e interfaz administrativa ✅

Infraestructura Docker, monolito modular Laravel 13 + Vue 3, PostgreSQL 18,
Redis, autenticación por sesión con Sanctum, roles y permisos, auditoría,
panel inicial, perfil, configuración, pruebas y documentación.

Detalle en [TASKS/A01.md](TASKS/A01.md).

---

### A02 — Clientes, empresas, afiliaciones e historial ✅

**Completada.** Alcance: alta y gestión de clientes, empresas, afiliaciones (EPS,
AFP, ARL y Cajas de Compensación Familiar) y sus relaciones históricas. Un cliente
puede cambiar de empresa y de afiliación con el tiempo, y el sistema reconstruye
qué tenía en una fecha concreta porque cada relación es un periodo con fecha de
inicio y de fin, nunca una fila que se sobrescribe.

Entregado: permisos `clients.*`, `companies.*`, `relationships.*`,
`affiliations.*` y `social_security_entities.*`; nueve comprobaciones de calidad
de datos; auditoría con sujeto; panel, clientes, empresas y catálogo.

Detalle en [TASKS/A02.md](TASKS/A02.md). Lo que quedó fuera: la administración
de roles en la interfaz, la reconstrucción por fecha como pantalla y la
revisión en bloque del paralelismo autorizado.

**A02-R1** corrigió quince defectos hallados en una auditoría externa del
`f27401e`: el contador de errores de calidad que siempre valía cero, un escaneo del
portafolio completo cada vez que se abría el panel, la representación del NIT, los
índices únicos inventados sobre el correo de contacto y sobre el código del
catálogo, los errores de base de datos disfrazados de duplicados, un bloqueo que no
serializaba nada, un traspaso que elegía una relación al azar, un paralelismo
autorizado reportado como problema, permisos de lectura que no se aplicaban, y las
fechas de un periodo sin definición escrita. Ninguna de ellas cambia la
arquitectura; todas cambian lo que el sistema afirma, y por eso tienen pruebas
propias.

**A02-R2** corrigió otros quince defectos de una segunda auditoría externa de
`585b396`: la clase de riesgo V descrita como "no clasificado" cuando es el riesgo
**máximo**; un formulario que borraba el dígito de verificación al editar el
teléfono; un NIT mal escrito que llegaba a PostgreSQL y terminaba en un 500; dos
endpoints anidados que se leían sin el permiso de su sección; los hallazgos de
calidad de una empresa que revelaban cuántos clientes tiene; los totales del panel
que sumaban problemas que el rol no podía ver; una fila sin fecha de inicio que no
se podía cerrar nunca; decisiones tomadas con una copia del cliente leída antes de
esperar el bloqueo; un traspaso registrado como creación según el endpoint usado;
un `parallel` sin nada con lo que ser paralelo; y un traspaso de una relación en
paralelo que dejaba el solapamiento sin autorizar.

---

### A03 — Periodos, cortes, obligaciones, pagos y cartera — **completada**

Alcance entregado: periodos mensuales, fechas de corte con vigencia, valores con
vigencia, obligaciones generadas con previsualización de solo lectura, ajustes con
reversión, registro de pagos, aplicaciones y anulaciones, cuenta por cobrar,
antigüedad, semáforo y estado de cuenta por cliente.

Entregado: **16 permisos** (`periods.*`, `cutoffs.*`, `rates.*`, `obligations.*`,
`payments.*`, `receivables.view`), ocho tablas, veintidós restricciones `CHECK` y
veintinueve llaves foráneas —dieciséis con `RESTRICT` y trece de auditoría con
`SET NULL`—, dieciocho flujos de extremo a extremo del módulo y
cuatro pantallas nuevas más el panel y la pestaña *Cuenta* de la ficha del cliente.

Detalle en [docs/TASKS/A03.md](TASKS/A03.md).

**A03-R1** corrigió los defectos de una auditoría externa del `7af0f9f`, agrupados
por dónde aparecían:

- **El mes.** La normalización perdía el día —`15-10-2026 10:00` se leía como
  `2026-10-15`, y el día quince de octubre el resultado era `2026-10-01`—, el
  «periodo actual» se resolvía por la fila más nueva en vez de por el mes
  calendario, y abrir dos veces el mismo mes se apoyaba en un
  `ON CONFLICT DO NOTHING` que respondía `201` a una carrera e informaba una
  apertura que no había ocurrido.
- **La relación.** La intersección mensual omitía la cláusula que excluye un
  intervalo vacío, de modo que una transferencia del mismo día —`started_on =
  ended_on`— se facturaba como un día de trabajo en las dos empresas; dos tramos
  no solapados del mismo par se trataban como un bloqueo en vez de agruparse en
  una obligación con su procedencia completa; y esa procedencia se perdía porque
  una sola columna no puede representar varios tramos.
- **El dinero.** Generar y cerrar un mes no se serializaban contra A02, que es de
  donde sale la topología que se lee; la previsualización contaba importes que la
  ejecución no escribía; el semáforo contaba filas de obligación en vez de
  periodos vencidos; `as_of` no era la reconstrucción histórica que su nombre
  prometía; la evidencia de configuración podía ser nula y podía borrarse en
  cascada con `SET NULL`; y un pago de 300 000 contra una deuda de 200 000 no podía
  pagarse en dos veces porque un índice único sobre `(payment_id,
  obligation_id)` lo prohibía.
- **Las palabras.** «Recaudado» era una sola cifra para tres preguntas distintas —
  recibido, aplicado y anticipo—; los totales del panel mostraban la cifra
  equivocada; `overdue=false` filtraba en vez de *no* filtrar, igual que
  `requires_reconciliation`; y la cartera no mostraba por defecto lo no vencido
  que el negocio pedía.
- **Las pantallas.** El panel ofrecía enlaces a rutas que su propio guard negaba;
  la lista de pagos exigía un permiso de cartera que no tenía por qué ver;
  `/settings/billing` pedía las dos mitades que puede leer y ninguna más; las
  listas de clientes y empresas de las fechas de corte quedaban vacías hasta que
  se escribía algo en el buscador; y las fechas sin hora se corrían un día por el
  huso.
- **La operación.** La suite de navegador firmaba contra cuentas que quizá todavía
  no existían, y la huella de datos de desarrollo no cubría ni el directorio ni el
  dinero.

Detalle en [docs/TASKS/A03-R1-REMEDIATION.md](TASKS/A03-R1-REMEDIATION.md).

**A03-R2** corrigió los trece hallazgos que dejó la auditoría de R1, más uno que
no estaba en ella. Los trece fueron, sobre todo, pantallas que respondían otra
pregunta de la que se les había hecho:

- **El dinero hacia quien no le correspondía.** `periods.current` y
  `periods.show` no aplicaban el filtro de permiso que sí aplicaba la lista, así
  que un rol con `periods.view` leía los importes del mes.
- **Encabezados que describían otra cosa.** La lista de cartera resumía la
  cartera completa sobre una tabla filtrada, y al corregirlo apareció un 500
  latente: los filtros de rango de periodo llamaban a `parse()` sobre el modelo
  en lugar del objeto de valor, de modo que `period_from` y `period_to` nunca
  funcionaron.
- **Dos búsquedas que nunca funcionaron.** La de valores emitía
  `lower(clients.first_names)` sobre una consulta sin esa tabla —PostgreSQL
  contestaba «missing FROM-clause entry»— y era un 500 para cualquier término. La
  de fechas de corte no leía el `search` que la pantalla enviaba, y paginaba con
  un 50 fijo detrás de un paginador que decía 25.
- **Un nombre en dos columnas**, así que `Ana María Gómez` no encontraba a nadie
  y la pantalla de facturación no podía elegir por nombre al cliente de una
  regla.
- **Un hallazgo que la auditoría no contenía** y que apareció al escribir la
  prueba del anterior: las bases de datos usan la colación `C`, que no pliega
  mayúsculas acentuadas, de modo que `Única` no era encontrable por `Única` ni
  por `unica`. Ahora `unaccent` y una única comparación escrita en un solo lugar.
- Y con la misma causa, un defecto más: el término se escapaba dos veces, así que
  `a_b` no encontraba nada.

Detalle en [docs/TASKS/A03-R2-REMEDIATION.md](TASKS/A03-R2-REMEDIATION.md).

**A03-R3** cerró los últimos hallazgos sobre ese commit:

- `close` y `reopen` seguían publicando importes con `withMoney` por omisión, de
  modo que `periods.close` o `periods.reopen` sin permiso de obligaciones bastaba
  para leer el mes que se acababa de cerrar o reabrir.
- La cuenta del cliente publicaba el número de **periodos** vencidos bajo el
  nombre de `overdue_obligations_count`: dos deudas vencidas en un mismo mes se
  leían como una. Ahora se cuentan y se nombran por separado, y el semáforo sigue
  usando el número de meses, que es lo que R1 §24 estableció.
- La fila de una regla de corte de alcance `client` mostraba `client_name ??
  company_name`, así que el empleador desaparecía y la excepción parecía regir
  para esa persona en todas partes.
- `as_of` inválido en las obligaciones del periodo ya no se sustituía por hoy,
  pero lo hacía con un `abort()` propio: ahora usa el mismo `FormRequest` y el
  mismo sobre de `errors` que los otros dos endpoints que aceptan la fecha.
- El `down()` de la migración de `unaccent`.dropaba una extensión compartida que
  esa migración no puede saber si creó. Ahora es un no-op documentado.
- Tres diálogos (anular pago, revertir aplicación, reabrir periodo) ofrecían una
  acción que el servidor rechazaba: bastaba un carácter. Ahora el mínimo vive en
  un módulo compartido, igual que la validación del servidor.
- «Corte de la consulta» describía un corte contable que el sistema no recalcula;
  ahora dice «Mora evaluada al», que es lo que la fecha hace.
- Y `EndToEndIsolationTest`, que llevaba rojo desde R2: buscaba
  `artisan cache:clear` en el archivo entero del runner, donde la cadena sólo
  aparece en comentarios que explican su eliminación. Ahora separa código de
  comentario, y se prueba a sí misma.

Detalle en [docs/TASKS/A03-R3-REMEDIATION.md](TASKS/A03-R3-REMEDIATION.md).

**A03-R4** cerró cuatro defectos residuales de contrato sobre ese commit:

- Las obligaciones del periodo autorizaban **después** de validar, así que un rol con
  `periods.view` y sin `obligations.view` recibía `422` con una fecha mal formada y `403`
  con una fecha bien formada: el límite dependía de si el llamador adivinaba el formato.
- El vocabulario de ajustes exigía `obligations.view` cuando su ruta y la interfaz
  exigen `obligations.adjust`, así que nadie con un solo permiso podía usarlo.
- `PeriodController::summarise()` seguía declarando `withMoney = true`. No había fuga
  —todos los puntos de llamada pasan la puerta— pero el helper era *fail-open*, y tres
  rondas han encontrado ya una fuga exactamente así: R2 en `current()` y `show()`, R3 en
  `close()` y `reopen()`. Ahora el valor por omisión es `false`: omitirlo cuesta una clave
  ausente, no una cifra.
- La reversión de una aplicación comparaba contra un `10` literal en un archivo que ya
  importaba el predicado compartido. Correcto mientras ambos valen 10, y una segunda
  implementación de la misma regla.

Detalle en [docs/TASKS/A03-R4-REMEDIATION.md](TASKS/A03-R4-REMEDIATION.md).

No entregado, y fuera de alcance por diseño: importación desde Excel, planillas,
exportación a PDF, facturación electrónica, automatización recurrente,
pasarelas de pago, portal, asistente de IA y MCP.

---

### A04 — Importación y reconstrucción histórica desde Excel — **completada**

Importación de un libro mensual de Excel: Reception en almacenamiento privado,
análisis determinista en cola, revisión humana de incidencias, plan exacto
persistido y aplicación transaccional bajo el mismo cerrojo de topología que usa
A03.

Lo esencial de lo entregado:

- El libro real del perfil `blinden_legacy_monthly_v1`: 10 hojas mensuales, 101
  bloques de empresa, 2 560 filas, 320 identidades documentales y 14 nombres
  lógicos de empresa, reproducidos por un verificador que imprime sólo
  agregados.
- **23 celdas con texto tipo contraseña** reescribidas como `[REDACTED]` antes de
  llegar a cualquier base, pantalla o log. El original queda sólo en el archivo
  privado.
- Precisión de fecha (`day` / `month` / `unknown`) en las tablas históricas de
  A02, para que una inferencia mensual nunca se presente como un día exacto.
- Reconstrucción de episodios, afiliaciones y valores mensuales **sin** crear
  obligaciones, pagos ni aplicaciones: el vocabulario del plan no tiene ninguna
  acción monetaria.
- Aplicación de todo o nada, una sola vez, con proveniencia por acción.

**Remediación posterior a una auditoría externa.** Once áreas con defectos,
corregidas en [`docs/TASKS/A04-R1.md`](TASKS/A04-R1.md). Los tres que más pesan:
el guard del contenedor no se llamaba; `apply` aceptaba un cuerpo vacío, así que
el plan revisado y el plan aplicado podían ser distintos; y las resoluciones se
validaban como texto libre y **nadie las leía**, de modo que responder todas las
preguntas correctamente producía los datos sin transformar. Ahora hay un
conjunto cerrado de decisiones con esquema propio, la revisión es la que se
aplica, y `WorkbookGuard` se invoca en el upload y antes del parser.

**Segunda remediación, tras una segunda auditoría.** Diecisiete áreas, casi todas
*desconectadas* — un código, una etiqueta, un lector y nada del otro lado — en
[`docs/TASKS/A04-R2.md`](TASKS/A04-R2.md). Las tres que más pesan: el productor y
el consumidor de una incidencia nombraban claves distintas, de modo que trece
desparecimientos se colapsaban en una fila y el índice único rechazaba el segundo;
la columna **R** se leía como ARL cuando R es **correo**, de modo que cada ARL del
archivo real era el correo de una persona nombrada; y **ningún worker escuchaba la
cola `imports`**, así que en desarrollo una importación se quedaba en `queued`
para siempre. Nada de esto lo detectó la suite, que corre con cola síncrona.

**A04 no tiene aprobación externa** y la auditoría debe repetirse sobre el commit
de remediación.

Detalle en [docs/TASKS/A04.md](TASKS/A04.md).

### A05 — Operación integrada, portal y reportería — **entregada**

Alcance fusionado de los antiguos A05, A06, A07, A08 y A11. Entrega el ciclo
operacional completo alrededor de un cliente existente: planilla mensual con
participantes generados desde la topología histórica de A02, validación de servidor,
comprobantes privados y descargas internas; novedades y tareas como conceptos
distintos, con recordatorios que se despachan de verdad; documentos con catálogo,
retención congelada al subir y solicitudes con ciclo completo; portal de cliente con
propiedad en lugar de permisos; y cinco reportes cerrados con PDF, CSV, XLSX,
programación de ejecuciones y artefactos privados.

Entregado: 21 permisos nuevos (`planillas.*`, `novelties.*`, `tasks.*`,
`documents.*`, `portal_accounts.manage`, `client_update_requests.*`, `reports.*`),
rol `Client` sin ningún permiso administrativo, 14 migraciones, un servicio
`scheduler` en la misma imagen y un único paquete nuevo (`dompdf/dompdf`).

**No está aprobada externamente.** Estado: entregada, auditoría externa pendiente.

Detalle en [TASKS/A05.md](TASKS/A05.md) y contrato en
[TASKS/A05-SPEC.md](TASKS/A05-SPEC.md).

**A05-R1** Cinco invariantes verificados de forma externa sobre esta entrega: una planilla creada por un
operador no tenía forma de llegar a `ready`; responder una solicitud documental por el portal era
imposible; las propuestas de perfil no tenían consumidor; el estado de una tarea se escribía desde
un modelo obsoleto; y las programaciones de reportes no se calculaban.

Sin migración, sin dependencias, sin A06 ni A07. Detalle, pruebas y una observación fuera de
alcance en [`A05-R1.md`](TASKS/A05-R1.md).

---

**A05-R2** Dos residuos directos de R1 cerrados sin migración, sin dependencias, sin A06:

1. **Atomicidad de archivos**: dos subidas con bytes idénticos compartían la misma ruta física (SHA-256). Si la segunda fallaba, la limpieza borraba el archivo que la primera seguía necesitando. Cada subida ahora usa un nombre físico único (UUID); el SHA-256 permanece como metadato. Además, `RespondToDocumentRequest` compensa el rollback de la transacción exterior borrando solo el archivo recién escrito.

2. **Respuesta de validación**: `ValidateSheetToReady` escribe bajo lock, pero `PlanillaController::validate()` respondía con el modelo stale de la ruta, devolviendo `status=draft` mientras la BD ya tenía `ready`. Ahora refresca el modelo antes de responder.

Pruebas nuevas: F1, F2, F3 en `DocumentPortalFlowTest.php`; P1 extendido en `PlanillaEditTest.php`.

Detalle en [`A05-R2.md`](TASKS/A05-R2.md).

---

**A05-R3** Un residuo directo de R2 cerrado sin migración, sin dependencias, sin A06:

1. **Compensación exterior de archivos**: `RespondToDocumentRequest` tenía el catch de compensación dentro del callback de `DB::transaction`, así que un fallo en el **commit** (después de que el callback retorna) dejaba el archivo recién escrito huérfano. Ahora la compensación envuelve la llamada completa a `DB::transaction`, fijando el objetivo solo después de que `DocumentFileStore::store()` tiene éxito.

2. **F1 false-positive corregido**: F1 instalaba el fallo en `eloquent.saving` pero llamaba al endpoint HTTP, donde el controlador crea la fila ANTES de llamar a `DocumentFileStore`. El fallo podía dispararse antes de escribir el segundo archivo. F1 ahora usa `eloquent.saving` dentro de `DocumentFileStore::store()` directo, probando la secuencia real: bytes escritos -> metadato falla -> UUID borrado -> UUID original preservado.

Sin migración, sin dependencias, sin A06.

Detalle en [`A05-R3.md`](TASKS/A05-R3.md).

---

**A05-R4** Restauración de dos pruebas de regresión de R2 eliminadas accidentalmente en R3:

1. **F2**: prueba de atomicidad para `PlanillaFileStore` (mismos bytes, segundo falla, primero preservado).
2. **F3**: prueba de compensación exterior en `RespondToDocumentRequest` (rollback de transacción de negocio elimina solo archivo nuevo).

Sin cambios en código de producción. Detalle en [`A05-R4.md`](TASKS/A05-R4.md).

---

---

## Tareas pendientes

La numeración antigua A05–A15 queda sustituida por tres macro hitos. La correspondencia
con el alcance anterior es:

```
A05 + A06 + A07 + A08 + A11  ->  nuevo A05   (entregado)
A09 + A10 + A12              ->  nuevo A06   (entregado)
A13 + A14 + A15              ->  nuevo A07
```

Ningún alcance se elimina: se reagrupa. El motivo es la velocidad de ejecución y la
calidad de integración: las cinco áreas del nuevo A05 comparten clientes, empresas,
periodos, cartera, permisos, archivos e interfaz, así que implementarlas una vez como
una capa operativa coherente es mejor que cinco proyectos pequeños con cinco rondas de
auditoría sobre el mismo terreno.

### A06 — Soporte, correo, Telegram y automatización general

Alcance de los antiguos A09, A10 y A12: centro de soporte con conversación en tiempo
real, correo entrante y responded, canal de avisos por Telegram, y motor general de
automatizaciones por evento y por fecha.

**Entregada.** Centro de soporte con colas, asignación, prioridad, ciclo de vida,
notas internas, SLA, adjuntos, audio, tiempo real (Laravel Reverb), correo
bidireccional (fallback + Reply-By-Email tokenizado), PWA/Web Push, Telegram
admin y motor de automatizaciones por evento/fecha.

Prepara: notificaciones internas y cola, que A05 ya dejó en pie.

**Estado: ENTREGADA / AUDITORÍA EXTERNA PENDIENTE**

### A07 — IA en la nube, MCP/OpenCode y endurecimiento final

Alcance de los antiguos A13, A14 y A15: asistente de IA en la nube, integración por
MCP y OpenCode, y el endurecimiento final que incluye auditoría de seguridad externa,
pruebas de carga, backups y despliegue de producción.

Prepara: el resto del sistema.

**Bloqueada** hasta la auditoría externa de A06.

---

## Principios que atraviesan todas las tareas

1. **Ningún módulo se implementa antes de tiempo.** Cada tarea entrega algo que
   se usa; no se dejan tablas, permisos ni pantallas vacías «para después».
2. **Las pruebas acompañan a la funcionalidad.** Un entregable sin pruebas no
   está terminado.
3. **La documentación se actualiza en la misma tarea.** Una tarea que deja
   documentación obsoleta está a medio hacer.
4. **El servidor manda.** Toda autorización se comprueba en el backend, sin
   excepción.
5. **Se prioriza el coste bajo.** Componentes libres y autoalojados; sin
   servicios de pago.
6. **El monolito modular se mantiene.** Los dominios crecen separados, no
   mezclados.

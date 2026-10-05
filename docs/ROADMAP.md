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

Detalle en [docs/TASKS/A04.md](TASKS/A04.md).

## Tareas pendientes

### A05 — Planillas y validación operativa

Alcance: generación de planillas, liquidación de aportes, validación de
consistencias y descarga.

Prepara: generación de archivos y, en su momento, el servicio de PDF.

### A06 — Novedades, tareas, recordatorios y calendario

Alcance: novedades operativas, tareas con responsable y vencimiento,
recordatorios y vista de calendario.

Prepara: notificaciones internas y, más adelante, el canal de Telegram.

### A07 — Documentos y solicitudes de documentos

Alcance: carga, organización y revisión de documentos; solicitud de documentos
al cliente.

Prepara: almacenamiento de archivos, antivirus y políticas de retención.

### A08 — Portal de clientes

Alcance: acceso del cliente a su propia información, sus obligaciones y su
estado de cuenta.

Prepara: modelo de autorización por recurso (un cliente sólo ve lo suyo).

### A09 — Centro de soporte y correo bidireccional

Alcance: conversaciones con clientes, bandeja compartida, plantillas de
respuesta y recepción de correo.

Prepara: `MAIL_MAILER` real, procesamiento de correo entrante y encolado de
mensajes.

### A10 — Avisos al administrador por Telegram

Alcance: notificaciones de eventos relevantes a un canal privado.

Prepara: credenciales de Telegram, gestionadas como secretos.

### A11 — Reportes, PDF y exportaciones

Alcance: reportes en PDF, exportaciones a Excel, reportería dinámica y
programación de envíos.

Prepara: trabajo en cola, plantillas de PDF y filtros reutilizables.

### A12 — Motor de automatizaciones

Alcance: reglas disparadas por eventos y por fecha (recordatorios,
vencimientos, cierres de periodo).

Prepara: registro de eventos ya existente en A01 y un evaluador de
condiciones.

### A13 — Asistente de IA en la nube

Alcance: asistente para consultas y sugerencias, con la información del
cliente como contexto.

Prepara: elección del proveedor y gestión de claves fuera del código.

### A14 — Integración por MCP y OpenCode

Alcance: exponer operaciones de Consultora DH como herramientas para
asistentes de programación.

Prepara: tabla de tokens de acceso personal de Sanctum, que A01 no crea por no
tener uso.

### A15 — Endurecimiento de seguridad, rendimiento y preparación de producción

Alcance: revisión de seguridad externa, pruebas de carga, ajustes de
rendimiento, backups y procedimiento de despliegue.

Prepara: el resto del sistema.

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

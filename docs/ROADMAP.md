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

## Tareas pendientes

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

### A03 — Periodos, cortes, obligaciones, pagos y cartera — **completada**

Alcance entregado: periodos mensuales, fechas de corte con vigencia, valores con
vigencia, obligaciones generadas con previsualización de solo lectura, ajustes con
reversión, registro de pagos, aplicaciones y anulaciones, cuenta por cobrar,
antigüedad, semáforo y estado de cuenta por cliente.

Entregado: **16 permisos** (`periods.*`, `cutoffs.*`, `rates.*`, `obligations.*`,
`payments.*`, `receivables.view`), ocho tablas, veintidós restricciones `CHECK` y
veintinueve llaves foráneas —dieciséis con `RESTRICT` y trece de auditoría con
`SET NULL`—, diecinueve flujos de extremo a extremo del módulo y
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

No entregado, y fuera de alcance por diseño: importación desde Excel, planillas,
exportación a PDF, facturación electrónica, automatización recurrente,
pasarelas de pago, portal, asistente de IA y MCP.

### A04 — Importación y normalización de Excel, reconstrucción de historial

Alcance: importación de hojas de cálculo, normalización, validación de
filas, detección de duplicados y reconstrucción del historial a partir de
archivos existentes.

Prepara: la base de colas (ya verificada en A01) para el procesamiento en
segundo plano; permisos `imports.*`.

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
2. **Las pruebas accompanyan a la funcionalidad.** Un entregable sin pruebas no
   está terminado.
3. **La documentación se actualiza en la misma tarea.** Una tarea que deja
   documentación obsoleta está a medio hacer.
4. **El servidor manda.** Toda autorización se comprueba en el backend, sin
   excepción.
5. **Se prioriza el coste bajo.** Componentes libres y autoalojados; sin
   servicios de pago.
6. **El monolito modular se mantiene.** Los dominios crecen separados, no
   mezclados.

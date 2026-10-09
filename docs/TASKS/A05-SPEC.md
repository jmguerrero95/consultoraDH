# A05-SPEC — Operación integrada, portal y reportería

Especificación cerrada del hito A05. Este documento conserva el contrato tal como se
recibió, para que la implementación pueda auditarse contra él sin consultar la
conversación.

**Estado:** entregado. **Aprobación externa:** pendiente (§80).

---

## 1. Por qué A05 es un solo hito

A05 fusiona cinco alcances que antes eran hitos separados: planillas, novedades y
tareas, documentos, portal de clientes y reportes. No es una simplificación: los cinco
comparten los mismos clientes, empresas, periodos, cartera, permisos, archivos e
interfaz, y separarlos produce cinco proyectos pequeños y cinco rondas de
auditoría sobre el mismo terreno.

El orden interno es obligatorio y no se detiene entre fases:

```
A05.1  base de datos, esquema y permisos
A05.2  planillas (contribution sheets)
A05.3  novedades, tareas, recordatorios y calendario
A05.4  documentos y solicitudes de documentos
A05.5  portal del cliente
A05.6  reportes, exportaciones y programaciones
A05.7  interfaz integrada y panel
A05.8  calidad, documentación y Git
```

---

## 2. Límite de negocio: qué es una «planilla» aquí

Consultora DH maneja información operativa de seguridad social colombiana. Los datos
históricos incluyen `SIMPLE`, `ARUS`, `planilla`, `novedad`, `referencia`, `valor`,
`EPS`, `AFP`, `CCF`, `ARL`, `clase de riesgo` y `cargo`.

**No existe en este repositorio un contrato autoritativo** para el formato legal de
un archivo PILA, para una API de SIMPLE o ARUS, ni para las tablas de porcentajes de
cotización y sus vigencias legales.

Por lo tanto A05:

- **NO** inventa un formato de archivo PILA;
- **NO** inventa una integración con SIMPLE ni con ARUS;
- **NO** codifica porcentajes legales colombianos de memoria;
- **NO** etiqueta un archivo interno como documento oficial de operador.

Lo que sí implementa: registros operacionales mensuales de planilla; participantes
generados desde la topología histórica de A02; selección de operador; valor liquidado
capturado explícitamente por persona; validación; referencia y número; fechas de
envío y pago; evidencia adjunta; descargas internas XLSX/PDF; auditoría.

> Los PDF y XLSX generados llevan en su cara la leyenda de que son documentos internos
> de Consultora DH y no documentos oficiales de operador PILA.

---

## 3. Principios no negociables

1. **El backend manda.** El Vue nunca es la capa de autorización.
2. **La historia no se reescribe.** Ni relaciones/afiliaciones de A02 ni
   obligaciones/pagos de A03.
3. **El dinero es entero COP.** Nunca punto flotante.
4. **Los saldos se derivan.** Reportes y portal consumen los servicios de A03. No
   existen `clients.balance`, `current_balance` ni una segunda fórmula de cartera.
5. **Las fechas son fechas de calendario.** `2026-10-08` no es un instante UTC.
6. **La ambigüedad no se adivina.**
7. **La base de datos protege lo importante.** Claves foráneas, índices únicos,
   comprobaciones CHECK, índices parciales.
8. **Las decisiones sensibles son auditables.** Actor, momento, objetivo, estado
   anterior/nuevo y motivo cuando se exige.
9. **Vista previa y ejecución describen el mismo hecho.**
10. **A02, A03 y A04 permanecen congelados.**

---

## 4. Esquema esperado

```
contribution_sheets
contribution_sheet_lines
contribution_sheet_files

client_novelties
operational_tasks

document_types
client_document_requests
client_documents

client_profile_update_requests

report_schedules
generated_reports

notifications

users: account_type, client_id
```

No se crean tablas para A06 ni A07.

---

## 5. Modelo de cuenta del portal

```
users.account_type = staff | client
staff   => client_id IS NULL
client  => client_id IS NOT NULL
```

Ambas mitades son restricciones de base de datos, no validación de PHP. Un índice
único parcial sobre `client_id` garantiza una sola cuenta de portal por cliente.

El rol `Client` tiene **cero** permisos administrativos internos. El acceso al portal es
por propiedad, no por permiso: los endpoints del portal comprueban
`account_type = 'client'` y que el recurso pertenezca a su cliente. No hay
auto-registro público, ni segunda pila de autenticación, ni contraseñas en claro.

---

## 6. Catálogo de permisos A05

```
planillas.view  planillas.create  planillas.update  planillas.validate
planillas.submit  planillas.mark_paid  planillas.cancel

novelties.view  novelties.manage
tasks.view      tasks.manage

documents.view  documents.manage  documents.request  documents.review

portal_accounts.manage
client_update_requests.view  client_update_requests.review

reports.view  reports.export  reports.schedule
```

Todo permiso creado es exigido por al menos una ruta. El seeder revoca cualquier
concesión que no esté en la matriz vigente.

---

## 7. Ciclo de vida de la planilla

```
draft -> ready -> submitted -> paid
draft, ready, submitted -> cancelled
paid  : histórico, sin retroceso ordinario
cancelled : terminal
```

Las transiciones son acciones de dominio con nombre
(`ValidateContributionSheet`, `SubmitContributionSheet`, `MarkSheetPaid`,
`CancelContributionSheet`, `ReturnSheetToDraft`), nunca un PATCH genérico.

**Contrato vista previa / creación.** La vista previa devuelve un `source_digest` que
cubre periodo, empresa, identificadores de relación, identidad de actualización de la
relación e identidad de afiliación usada. La creación recalcula el roster dentro del
bloqueo de topología y compara el resumen: si no coincide, responde 409 y no escribe
nada.

**Validación.** Los errores bloquean `ready`; los avisos no. Nunca se declara error
legal por la ausencia de AFP o CCF, porque este repositorio no tiene regla que lo
demuestre.

---

## 8. Especificidad del portal

El cliente puede ver su perfil, su historial de relaciones y afiliaciones, su cuenta
—usando los servicios de A03—, los documentos marcados como visibles, sus solicitudes
pendientes, subir lo solicitado y **proponer** correcciones de su perfil, que solo se
aplican cuando el personal las aprueba mediante la acción de A02.

Ningún cliente ve datos de otro cliente: un identificador inventado devuelve 404.

---

## 9. Reportes

Cinco tipos cerrados: `morosos`, `vencidos`, `por_empresa`, `por_entidad`,
`planillas`. El tipo es una enumeración con esquema de filtros declarado; ningún
nombre de columna, modelo o fragmento de SQL puede llegar desde una petición.

Salidas: pantalla, CSV, XLSX y PDF. Las celdas de texto que empiezan por `=`, `+`,
`-` o `@` se escapan. Los artefactos generados se guardan en disco privado y su
descarga **revalida** el permiso actual.

`due today != overdue`: esa regla es de A03 y A05 no la redefine.

---

## 10. Fuera de alcance

A05 **no** implementa comunicación en tiempo real, bandeja de soporte, correo
entrante, chat de audio, PWA, temporizadores de SLA, Telegram, ni motor general de
automatizaciones; **tampoco** asistente de IA en la nube, abstracción de proveedor
LLM, servidor MCP, tokens de máquina ni despliegue de producción.
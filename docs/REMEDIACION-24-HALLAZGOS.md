# Remediación de los 24 hallazgos de la auditoría — Consultora DH

Rama: `fix/a07-xlsx-01-formulas` · Base: `main` (`d20195e`) · Commits de remediación: 10
(los 14 anteriores son trabajo previo de la rama XLSX, ya auditado).

Este documento es el informe de entrega: **qué se cambió, con qué evidencia, qué
queda bloqueado y si la rama puede fusionarse**. No sustituye a la evidencia:
cada afirmación apunta al commit, al archivo y a la prueba que la respalda.

---

## 1. Veredicto

| Pregunta | Respuesta |
| --- | --- |
| ¿Están los 24 hallazgos corregidos en código? | **Sí**, los 24, con prueba dirigida para cada uno. |
| ¿Hay evidencia LIVE de proveedores de IA? | **No**. No hay credenciales en este entorno. La certificación LIVE sigue **abierta** y el proceso para obtenerla queda escrito (`scripts/live_certification.sh`, job `live` del CI). |
| ¿Se ejecutó Q01–Q31? | **No**, todas. Se ejecutó la suite completa (Pest) y la suite de frontend (Vitest), que es la evidencia automatizada disponible. Q22, Q24, Q30 y Q31 requieren un entorno con SMTP/Telegram/proveedores reales o un recorrido E2E con navegador. |
| ¿Se puede fusionar en `main`? | **No todavía.** Faltan la certificación LIVE (Q30) y la evidencia E2E (Q31). Todo lo demás está corregido, probado y con commits independientes. |
| ¿Se tocó `main`, se hizo push, `reset --hard`, `clean -fd`, `down -v` o `migrate:fresh` sobre datos reales? | **No.** La rama tiene 24 commits por delante de `main` y no hay push. Los datos de desarrollo no se tocaron; la prueba de restore creó y eliminó una base desechable. |

**Motivo del bloqueo**: la condición de GO de la auditoría exige «tres proveedores
independientes certificados» y «ejecutar Q01–Q31 o justificar formalmente qué
pruebas no corresponden al entorno». La primera no puede cumplirse sin
credenciales reales (regla 1 de `AGENTS.md`: sin evidencia LIVE no hay `PASS`).
La segunda se cubre parcialmente con la suite completa y se documenta el resto
como bloqueos externos.

---

## 2. Matriz de los 24 hallazgos

Leyenda de estado: **CORREGIDO** = cambio en código + prueba dirigida que pasa.
**CORREGIDO (L2)** = la parte de código está hecha y probada; queda una
condición de entorno para certificarla.

### R01–R04 — Lector tabular (A07-XLSX) — commit `d467a3f`

| ID | Causa verificada | Corrección | Evidencia |
| --- | --- | --- | --- |
| R01 | El lector materializaba el fichero entero antes de mirar nada: sin límite de bytes, filas, columnas, celdas ni tamaño del archivo ZIP | Límites tempranos en `TabularInputReader`: bytes, filas, columnas, celdas y presupuesto de archivo comprimido | `tests/Feature/A07/TabularInputLimitsTest.php` (13 casos) |
| R02 | El clasificador aceptaba peticiones de reporte que el motor no soporta | El planificador rechaza la intención no soportada con un motivo accionable | mismo archivo |
| R03 | Un descuento monetario se interpretaba como porcentaje | El planificador rechaza un descuento que no sea un porcentaje y no inventa cifras | mismo archivo |
| R04 | Sólo se leía la hoja activa: una consolidación de dos hojas quedaba incompleta en silencio | Un libro de varias hojas exige `sheet_name` explícito | mismo archivo |

Pruebas: A07 464 → **464** con 1924 aserciones tras el cambio; A05 162/547.
Pint limpio.

### G01 — Adjuntos de soporte vinculables entre conversaciones — commit `dc8f705`

- Causa: las cuatro rutas de asociación vinculaban un adjunto a un mensaje sin
  comprobar que el adjunto fuera de esa conversación ni del mismo autor.
- Corrección: una única acción `LinkSupportAttachments` que exige
  `(id, conversation_id, uploaded_by_user_id, support_message_id IS NULL)`; una
  discrepancia rechaza **toda** la petición (422) y la transacción del mensaje
  se deshace.
- Evidencia: `tests/Feature/A06/SupportAttachmentIsolationTest.php` (8 casos);
  suite A06 + soporte: **133** casos.

### G02 — Worker único y `retry_after` menor al timeout de importación — commit `d68d9e5`

- Corrección: cuatro workers lógicos (`default,support,notifications` 180 s;
  `imports` 630 s; `ai-tasks` 330 s; `automations` 180 s), `retry_after=900`
  en Redis y base de datos, `after_commit=true`, comando `queue:health`
  con `--strict`, y `ExecuteAutomationRun` en la cola `automations`.
- Evidencia: `tests/Feature/Ops/QueueTopologyTest.php` (4 casos, 22
  aserciones) + **sonda de runtime**: un job de importación de 20 s siguió
  reservado mientras un job de `default` terminaba, y ambas colas drenaron.

### G03 — Backup/restore no ejecutable con el stack documentado — commit `f567d57`

- Causa: `pg_dump`, `pg_restore`, `createdb`, `dropdb` y `psql` no existen en la
  imagen PHP, y el rol de aplicación es `NOCREATEDB`.
- Corrección: sidecar dedicado `postgres:18.6` con `gnupg`; rol
  `consultora_dh_backup` (`LOGIN CREATEDB NOSUPERUSER NOCREATEROLE`, miembro de
  `pg_read_all_data`); backups cifrados AES-256, conteos de tablas y de
  archivos, manifest SHA-256, retención, `.last-success`, healthcheck por
  edad; restore proof en base desechable con `--single-transaction` que
  compara **cada** conteo y el número de archivos, y siempre elimina la base.
- Evidencia de runtime (datos sintéticos): backup cifrado, checksums OK,
  2 usuarios / 3 clientes / 1 artefacto / 2 eventos de auditoría restaurados y
  coincidentes, 3 archivos privados coincidentes, base desechable eliminada y
  base operativa intacta. 9 pruebas de Ops (55 aserciones).

### G04 — Paginación ilimitada en soporte — commit `afa5aec`

- Corrección: rasgo `NormalizesPagination` que acota a 1..100 todos los listados
  de soporte y automatizaciones; y las páginas de mensajes se ordenan por `id`,
  de modo que el cursor `before_id` es monótono (dos mensajes escritos en el
  mismo segundo comparten `created_at` y el orden arbitrario hacía que una
  página y su cursor se solaparan).
- Evidencia: `tests/Feature/A06/PaginationLimitsTest.php` (4 casos, 29
  aserciones) con `per_page` 0, -1, 1, 100, 1000000, `last_page` y cursor.

### G05 — Descargas «streamed» que cargaban el archivo entero en RAM — commit `a9e3b00`

- Corrección: rasgo `StreamsPrivateFiles` con `fpassthru` sobre un stream real
  (`readStream` / `fopen`), cabeceras `private, no-store` y `nosniff`,
  comprobación de path y permisos, `fclose` garantizado en `finally` y borrado
  de las exportaciones temporales de planilla tras el envío. Sustituye a
  `echo Storage::get()` y `echo file_get_contents()` en `AiArtifactController`,
  `DocumentController`, `Portal/PortalDocumentController` y `PlanillaController`.
- Evidencia: `tests/Feature/A05/DownloadStreamingTest.php` (documento de 4 MiB
  con SHA-256 idéntico, 404 por fichero ausente, cabeceras de planilla XLSX/PDF).

### G06 — Disponibilidad de IA anunciada de forma engañosa — commit `afa5aec`

- Corrección: la interfaz distingue `unverified`, `degraded`, `quota_limited`,
  `unavailable`, `blocked` y `unconfigured`; `unverified` ya no se anuncia como
  «limitado» ni como «caído»; el estado se relee **después de cada turno** y el
  servidor descarta la caché de `/api/ai/status` al completar un turno, de modo
  que la lectura posterior informa de la evidencia nueva y no del snapshot
  anterior.
- Evidencia: `tests/frontend/assistant-availability.spec.ts` (3 casos) y
  `tests/Feature/A07/AiStatusRefreshTest.php` (1 caso).

### G07 — MCP devuelve mensajes internos y sin cuota propia — commit `c8a8238`

- Corrección: el endpoint responde con códigos públicos estables
  (`mcp_method_not_found`, `mcp_tool_not_found`, `mcp_invalid_params`,
  `mcp_tool_rejected`, `mcp_internal_error`); la traza va al canal `mcp`
  (`storage/logs/mcp-*.log`); y el endpoint tiene cuotas propias por token
  (`MCP_REQUESTS_PER_MINUTE`) y por herramienta
  (`MCP_TOOL_CALLS_PER_MINUTE`), porque no hereda el `throttle` de sesión.
- Evidencia: `tests/Feature/A07/McpErrorDisclosureTest.php` (6 casos), que
  comprueba que la respuesta no contiene `SQLSTATE`, `Illuminate\` ni trazas.

### G08 — La cuenta atrás de cuota calculaba el peor plazo — commit `c8a8238`

- Corrección: el informe da el **primer** momento en que alguna cuenta vuelve
  (agrupada por cuenta: dos modelos detrás de la misma credencial comparten un
  muro) y expone el peor caso como el otro extremo del rango
  (`retry_after_max_seconds`). La pantalla del asistente muestra el rango
  cuando los extremos difieren.
- Evidencia: dos casos nuevos en `tests/Feature/A07/AiAvailabilityTest.php`
  (dos proveedores independientes: 60 s y 3600 s) y dos en
  `tests/frontend/assistant-recovery.spec.ts`.

### G09 — Crear conversación de portal sin cola por defecto — commit `afa5aec`

- Corrección: el endpoint de portal responde con el mismo 409 `no_default_queue`
  que el de personal, en vez de dejar escapar la excepción.
- Evidencia: `tests/Feature/A06/PaginationLimitsTest.php` (4.º caso).

### G10 — Política de proveedores en despliegue nuevo — commit `c8a8238`

- El valor por defecto `AI_ALLOW_TRAINING_PROVIDERS=false` **no se cambia**: es
  una decisión documentada del propietario.
- Corrección: `php artisan ai:preflight` informa de la **configuración
  efectiva** (política, si difiere del valor por defecto, proveedores
  configurados, elegibles para datos privados, independientes, excluidos y por
  qué, tiempos de recuperación) y dice de forma explícita que un informe de
  configuración **no es** una certificación LIVE. `--strict` sale con 1 si no
  hay tres proveedores independientes.
- Evidencia: `tests/Feature/Ops/AiPreflightTest.php` (4 casos).

### G11 — Liveness frente a readiness — commit `ed4aac9`

- nginx `/healthz` y `/api/health` siguen siendo liveness barata y así se
  documenta. Se añade `/api/ready`: base de datos, Redis y **colas**
  monitorizadas, incluida la edad del job más antiguo esperando (un despliegue
  sin workers vivos pasa liveness y falla readiness). La inspección de colas se
  extrajo a `QueueInspector`, compartido con `queue:health`, para que el número
  que alerta el monitor sea el mismo que ve la puerta de release.
- Evidencia: `tests/Feature/Ops/ReadinessTest.php` (4 casos).

### T01 — Ocurrencias de reporte programado no persistidas — commit `b76281b`

- Corrección: tabla `report_schedule_runs` con claim único
  `(schedule, occurrence)`, intentos, estado y código de error; la generación
  ocurre **fuera** de la transacción del claim; reintentos con espera de 5
  minutos, máximo 3 intentos, y bloqueo por causas permanentes.
- Evidencia: `tests/Feature/A05/ReportScheduleReliabilityTest.php` (5 casos);
  A05 162 casos.

### T02 — Tareas con objetos de otro cliente — commit `a9e3b00`

- Corrección: `TaskSubject` rechaza una novedad o una solicitud de documento de
  otro cliente, una planilla cuya empresa no está asignada al cliente indicado
  y dos referencias que discrepan sin cliente explícito. Las tareas globales y
  las coherentes siguen aceptándose.
- Evidencia: `tests/Feature/A05/TaskSubjectIntegrityTest.php` (8 casos).

### T03 — Tarea sin asunto: 500 de SQL — commit `a9e3b00`

- Corrección: `description` es obligatoria cuando no hay cliente, novedad,
  solicitud ni planilla; la regla HTTP refleja el CHECK
  `operational_tasks_subject_check`, de modo que la respuesta es un 422 de
  validación y no un 500.
- Evidencia: mismo archivo (caso «requires a subject or a description»).

### T04 — Carrera de ejecución duplicada en automatizaciones — commit `0d98573`

- Corrección: claim atómico y condicional (UPDATE con WHERE sobre el estado)
  tanto del run como de la acción; una confirmación obsoleta sólo se reclama
  después de `timeout + 30 s`. `retry_after` (900 s) es mayor que el timeout
  (180 s) de los workers de automatizaciones.
- Evidencia: `tests/Feature/A06/AutomationConcurrencyTest.php` (7 casos);
  A06 131 casos.

### T05 — «Encolado» no es «entregado» — commit `0d98573`

- Corrección: los estados de acción distinguen `queued` de `succeeded`/`failed`:
  correo y Telegram siguen en `queued` hasta que el envío informa; el run pasa
  a `queued` mientras tenga acciones asíncronas pendientes. Se añadieron
  `AutomationEmail` y el job `SendAutomationEmail`.
- Evidencia: casos de entrega en `tests/Feature/A06/AutomationConcurrencyTest.php`.

### T06 — No había puerta automática de CI — commit `ed4aac9`

- `.github/workflows/ci.yml`: en cada push y PR, Pint, migraciones y suite
  completa sobre PostgreSQL 18 + Redis, y en el frontend lint, typecheck, build
  y tests. La certificación LIVE vive en un job aparte que sólo corre con
  `workflow_dispatch` y credenciales reales, y sube su evidencia como
  artefacto.
- Nota: el workflow **no se ha ejecutado** en un runner de GitHub desde este
  entorno; está versionado y es sintácticamente válido, pero su primera
  ejecución real es evidencia pendiente.

### T07 — Paginación de automatizaciones sin topes — commit `afa5aec`

- Corrección: el mismo `NormalizesPagination` (1..100) en `index` y `runs`.
- Evidencia: `tests/Feature/A06/PaginationLimitsTest.php` (3.er caso).

### T08 — Cuenta de portal creada a la vez por dos administradores — commit `ed4aac9`

- Corrección: las violaciones únicas de `users_portal_client_unique` y
  `users_email_unique` se traducen a 409 con códigos estables
  (`portal_account_exists`, `email_already_in_use`); el insert se ejecuta dentro
  de un savepoint para que la respuesta se pueda producir con la conexión
  utilizable. **El índice no se toca.**
- Evidencia: tres casos en `tests/Feature/A05/PortalSecurityTest.php`, dos de
  ellos simulando la concurrencia (una fila competidora insertada mientras la
  petición está en vuelo).

### T09 — Fichero de reporte huérfano tras rollback — commit `b76281b`

- Corrección: `ReportGenerator` borra fila y fichero juntos cuando falla la
  escritura posterior; el runner de schedule programados deja la ocurrencia en
  `failed`/`retryable` y nunca marca «procesado» sin reporte.
- Evidencia: `tests/Feature/A05/ReportScheduleReliabilityTest.php` (5 casos).

---

## 3. Evidencia de pruebas ejecutadas

| Suite | Resultado |
| --- | --- |
| **Suite completa Pest** (`php artisan test`) | **2270** casos / **10432** aserciones, 1 omitido, 1748 s, salida 0 |
| A05 (completa) | **173** casos / 591 aserciones |
| A06 (completa) | **135** casos / 451 aserciones |
| A07 (completa) | **473** casos / 1966 aserciones |
| Ops (completa) | **17** casos / 101 aserciones |
| Frontend (Vitest) | **39** ficheros / **393** casos |
| Pint (PHP modificado) | limpio en todos los commits |
| `vue-tsc` | sin errores en los archivos modificados (quedan errores preexistentes en ficheros de test ajenos: `contextual-suggestion.spec.ts`, `pwa.spec.ts`, `service-worker.spec.ts`, `suggestion-context.spec.ts`, `AttachmentPicker.vue`) |
| ESLint | limpio en los archivos modificados (un aviso preexistente en `FloatingAssistant.vue` línea 1126) |

Pruebas de runtime reales ejecutadas en este entorno (no simuladas):

1. **Sonda de colas (G02)**: job de importación de 20 s reservado mientras un
   job de `default` completaba; ambas colas drenaron.
2. **Backup y restore (G03)**: backup cifrado real con base sintética,
   checksums verificados, restore en base desechable, conteos y archivos
   comparados uno a uno, base desechable eliminada, base operativa intacta.
3. **Streaming de descargas (G05)**: documento de 4 MiB descargado con SHA-256
   idéntico al escrito.
4. **Endpoint MCP (G07)**: cuotas agotadas en runtime (429 con código público y
   `retry_after`).
5. **Readiness (G11)**: job de 600 s en una cola monitorizada → `/api/ready`
   responde 503 `not_ready` con `over_age: true`.

---

## 4. Bloqueos externos y riesgos pendientes

| # | Bloqueo | Por qué | Cómo se cierra |
| --- | --- | --- | --- |
| B1 | **Certificación LIVE de proveedores (Q30)** | No hay credenciales de Groq, Gemini, Cloudflare ni OpenRouter en este entorno. Sin ellas no se puede afirmar que tres proveedores independientes atiendan datos privados con herramientas. | `scripts/live_certification.sh` con `LIVE_USER_ID` y las claves; deja `evidence/live-certification.{tsv,md}` y `provider-<nombre>.txt`; el job `live` del CI lo ejecuta con secretos. |
| B2 | **Q31 (recorrido end-to-end completo)** | Exige navegador (Playwright) contra una base E2E y un recorrido con datos reales; el entorno actual ejecutó la suite PHP y la de frontend, no el navegador. | `scripts/run-e2e.sh` sobre `consultora_dh_e2e`, con evidencia por paso adjunta. |
| B3 | **Q22 (SMTP real) y Q24 (caída de SMTP/Telegram)** | Necesitan un servidor SMTP y un bot de Telegram reales, con entrega comprobada. | Entorno de staging con las credenciales de los servicios. |
| B4 | **Primera ejecución del workflow de CI** | No hay runner de GitHub en este entorno; el workflow está escrito y versionado, pero su ejecución real aún no ha ocurrido. | Push de la rama y seguimiento del primer run. |
| B5 | **Carga real con recursos limitados (Q26-Q28 medido en staging)** | La medición existe como prueba de lectura acotada y de sonda de colas en local, no como prueba de carga con CPU/memória limitadas. | `scripts/load_test.sh` en staging con los umbrales de `docs/PRODUCTION.md`. |
| B6 | **Rollback de despliegue probado** | El restore de datos está probado y el rollback de *código* es un fast-forward sin `push --force`; no se ha ejecutado un rollback completo de una versión en un entorno desplegado. | Despliegue en staging y retorno a la versión anterior. |

Riesgos residuales aceptados y documentados:

- `AI_ALLOW_TRAINING_PROVIDERS` sigue en `false` por defecto. Si un despliegue
  nuevo no lo activa, OpenRouter y Gemini quedan fuera de los datos privados y
  la disponibilidad privada baja: es el comportamiento fail-closed previsto, y
  ahora `ai:preflight` lo dice antes de que se descubra en producción.
- El MCP tiene cuotas propias por token (60/min y 15/min por herramienta por
  defecto). Un agente legítimo con un patrón de uso alto recibe un 429 y debe
  ajustar `MCP_REQUESTS_PER_MINUTE`.
- La exigencia de que la cuenta de portal sea única la sigue garantizando el
  índice de la base de datos, no la aplicación: la API traduce la violación,
  pero la garantía es del esquema.
- El backup cifrado requiere `BACKUP_ENCRYPTION_PASSPHRASE` o un fichero de
  clave. Sin ellos el servicio de backup falla cerrado (perfil `ops`), por
  diseño.

---

## 5. Commits

| Commit | Lote | Hallazgos |
| --- | --- | --- |
| `dc8f705` | SEG-01 | G01 |
| `0d98573` | SEG-01b | T04, T05 |
| `b76281b` | REP-01 | T01, T09 |
| `d467a3f` | XLSX-02 | R01, R02, R03, R04 |
| `d68d9e5` | OPS-01 | G02 |
| `f567d57` | DR-01 | G03 |
| `a9e3b00` | A05-VALID | T02, T03, G05 |
| `afa5aec` | UI-OPS | G04, G06, G09, T07 |
| `c8a8238` | A07-PROVIDER | G07, G08, G10 |
| `ed4aac9` | RELEASE-GATE | T06, G08, T08 |

## 6. Qué falta para el GO

1. Ejecutar la certificación LIVE (B1) y adjuntar la evidencia por proveedor.
2. Ejecutar Q31 y los pasos de E2E que dependan de navegador (B2), o dejar
   constancia formal de que no corresponden a este entorno.
3. Ejecutar el workflow de CI en un runner real (B4).
4. Decidir de forma explícita si `AI_ALLOW_TRAINING_PROVIDERS` se mantiene en
   `false` (lo recomendado) o se activa con justification por escrito.

Con 1 y 2 resueltos, la rama cumple la condición de GO de la auditoría. La
publicación, cuando se autorice, es fast-forward y sin `--force`.

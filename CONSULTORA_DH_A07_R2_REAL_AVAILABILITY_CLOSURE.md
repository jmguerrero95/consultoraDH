# Consultora DH — A07-R2: cierre real de disponibilidad, privacidad y continuidad

**Fecha:** 2026-10-19
**Rama:** `main`
**Ámbito:** A07-R2 (P0, P1 y P2)
**Resultado final:** `PASS_CON_RESIDUALES_EXTERNOS`

---

## 1. Contrato de entrega

```text
BASE_SHA=8126a0bfbe84cafc6a9b3bdbdc298b20d446eef6
FINAL_CODE_SHA=30f237befa9f60525f15e547209f377fab58c36e
FINAL_DOC_SHA=NO_AUTORREFERENCIABLE_EN_EL_PROPIO_DOCUMENTO
ORIGIN_MAIN_TIP=30f237befa9f60525f15e547209f377fab58c36e (tip funcional; el informe se publica en el commit inmediatamente posterior)
GIT_SYNC=FAST_FORWARD_OK
AI_ALLOW_TRAINING_PROVIDERS=false
GROQ_DATA_POLICY=standard
CLOUDFLARE_AI_DATA_POLICY=standard
GEMINI_DATA_POLICY=training
OPENROUTER_DATA_POLICY=training
PRIVATE_PROVIDER_COUNT=2
GENERAL_INDEPENDENT_PROVIDER_COUNT=3
GROQ_LIVE=BLOCKED_EXTERNAL_HTTP_403
CLOUDFLARE_LIVE=PASS
GEMINI_LIVE=NOT_RUN_TRAINING_POLICY
OPENROUTER_LIVE=NOT_RUN_TRAINING_POLICY
FAILOVER_SAME_TURN=PASS
FAILOVER_LIVE=PASS
AVAILABILITY_HONEST=PASS
CONVERSATION_DELETE_ATOMIC=PASS
FAILED_TURN_PERSISTENCE=PASS
BACKEND_A07_TESTS=410_PASS_1521_ASSERTIONS
BACKEND_FULL_TESTS=2122_PASS_1_SKIPPED_9669_ASSERTIONS
FRONTEND_TESTS=388_PASS
TYPECHECK=PREEXISTING_46_ERRORS_NO_NEW
BUILD=PASS
PINT=26_FILES_PASS
PLAYWRIGHT_TARGETED=7_PASS
FINAL_RESULT=PASS_CON_RESIDUALES_EXTERNOS
```

> `PASS_CON_RESIDUALES_EXTERNOS` no equivale a un fallo del código: significa que
> una comprobación LIVE de Groq no pudo ejecutarse por un bloqueo de red externo
> (HTTP 403 de la protección perimetral de Groq), no por credencial, privacidad ni
> disponibilidad del producto. Todo lo que sí se pudo verificar está probado y
> registrado abajo. No se declara `VERIFIED_LIVE` para Groq.

---

## 2. Punto de partida y reglas respetadas

- `BASE_SHA=8126a0bfbe84cafc6a9b3bdbdc298b20d446eef6`.
- Se conservaron los cambios locales de otras sesiones y los documentos no
  trackeados `CONSULTORA_DH_IMPORT_6_POST_APPLY_VALIDATION_INFORME.md`,
  `LOCAL-RUN-01-INFORME-CIERRE.md` y `LOCAL-RUN-01-INFORME.md`.
- No se usó `git reset --hard`, `git clean -fd`, `git push --force`,
  `docker compose down -v`, `FLUSHALL` ni `migrate:fresh` sobre bases reales.
- No se activó facturación ni se hizo ninguna petición de pago.
- No se cambió `AI_ALLOW_TRAINING_PROVIDERS`; permanece en `false`.
- Las pruebas LIVE usaron exclusivamente credenciales ya existentes, niveles
  gratuitos y datos sintéticos.

---

## 3. Privacidad de proveedores: evidencia, no analogía

### 3.1 Clasificación aplicada

| Proveedor | Política | Fundamento |
|---|---|---|
| Groq | `standard` | Groq Services Agreement, Last Modified 2026-06-22, §4.2: no entrena con Inputs/Outputs. La retención por fiabilidad/abuso no es entrenamiento y admite Zero Data Retention. |
| Cloudflare Workers AI | `standard` | Workers AI Data usage, Last updated 2026-10-16: retención cero para inferencia estándar y “Cloudflare does not use Customer Content processed by Workers AI to train AI models.” |
| Gemini Developer API | `training` | El nivel gratuito puede usar contenido para mejorar productos. No se infirió por analogía con Groq/Cloudflare. |
| OpenRouter | `training` por defecto | OpenRouter enruta a proveedores con políticas distintas y este servidor no puede leer la preferencia de privacidad de la cuenta. Se falla cerrado. |

La clasificación de OpenRouter no estaba antes en el gate de datos privados:
los slots y el pool dinámico se construían con el valor permisivo por defecto
(`standard`). Eso permitía que un modelo gratuito de OpenRouter recibiera datos
reales de clientes. Se corrigió:

- `config/ai.php` declara `ai.openrouter.data_policy`,
  por defecto `training` (`AI_OPENROUTER_DATA_POLICY=training`).
- `ModelPool` aplica esa política a los slots, al pool explícito, al router
  gratuito, a los modelos extra y a los modelos dinámicos del catálogo.
- `ModelPool::refineCapabilities()` conserva la clasificación al reconstruir el
  candidato; antes la perdía y volvía al valor permisivo.
- `ModelCandidate::supportsClientData()` solo autoriza datos privados con
  `standard` explícito. Un valor desconocido o mal escrito falla cerrado.
- Para usar OpenRouter con datos privados hay que verificar la preferencia de
  privacidad de la cuenta y declarar `AI_OPENROUTER_DATA_POLICY=standard`.

### 3.2 Estado real de la configuración

```text
AI_ALLOW_TRAINING_PROVIDERS=false
groq:        standard, 2 modelos, clave presente
cloudflare:  standard, @cf/openai/gpt-oss-120b, clave presente
gemini:      training, 2 modelos, clave presente
opencode_zen: deshabilitado, training, sin clave
openrouter:  training por defecto
```

Disponibilidad derivada del pool real:

- conversación general: 3 proveedores independientes (Groq, Cloudflare,
  OpenRouter), 5 modelos listos;
- conversación con datos privados: 2 proveedores independientes (Groq,
  Cloudflare), 3 modelos listos; OpenRouter queda fuera;
- `state=unverified` cuando no hay una respuesta reciente: el estado no se
  infla a “disponible” solo porque haya claves configuradas.

### 3.3 Pruebas de privacidad

`tests/Feature/A07/ProviderPrivacyClassificationTest.php`: **8 pasaron, 20
aserciones**. Fijan Groq/Cloudflare en `standard`, Gemini en `training`, el
default de OpenRouter en `training`, la conservación de la política al refinar
el candidato y el cierre ante una clasificación desconocida.

---

## 4. Disponibilidad honesta por operación (§07)

`AiAvailability` ya no deduce “disponible” de una etiqueta de proveedor. Cada
operación declara las capacidades que necesita y el pool responde con candidatos
que realmente las tienen, incluida `client_data`.

Cambio de esta ronda:

- Una operación que declara `tools` solo se considera probada por
  `markToolsDemonstrated`, es decir, por una llamada de herramienta observada.
- Una respuesta de chat sin herramienta ya no “prueba” lectura de documentos,
  generación de documentos, consulta de datos internos ni propuestas de tareas.
- Las operaciones sin herramientas mantienen la prueba por respuesta reciente.

Evidencia: `AiAvailabilityTest` **14 pasaron**; el conjunto A07 completo pasó
**410 pruebas / 1521 aserciones**.

---

## 5. Failover real en el mismo turno

El resolver recorre candidatos en el mismo turno, con salud por
(endpoint, modelo), cuota por proveedor y presupuesto único. No hay una segunda
pregunta del usuario ni una “recuperación” que reinicie la conversación.

Evidencia LIVE disponible (sin nuevas peticiones en esta ronda):

- Groq fue el primer candidato y devolvió `HTTP 403` de la protección
  perimetral de Groq (bloqueo externo, no credencial ni política de datos).
- En el mismo turno, Cloudflare `@cf/openai/gpt-oss-120b` respondió, emitió una
  llamada estructurada a la herramienta `exchange_rate` y devolvió
  `1 USD = 4.123,5 COP`.
- Coste neto: 3 generaciones en Cloudflare; los intentos a Groq no generaron
  contenido porque fueron rechazados con 403.

Evidencia automatizada: `AiErrorScopeAndFailoverTest` y el resto de A07 pasan.
`FAILOVER_SAME_TURN=PASS`; `FAILOVER_LIVE=PASS`; `GROQ_LIVE=BLOCKED_EXTERNAL_HTTP_403`.

---

## 6. Borrado de conversaciones: atomicidad y archivos (§09)

### 6.1 Diseño

- Las filas se borran dentro de una transacción.
- Los paths de artefactos **y adjuntos** se registran en la outbox
  `ai_artifact_deletions` dentro de esa misma transacción.
- Los bytes se eliminan **solo después del commit**.
- Si el proceso muere entre el commit y el borrado físico, la fila de outbox
  sobrevive y `ai:cleanup-artifacts` la drena.
- La outbox es idempotente: un path ya inexistente se considera éxito.
- `storage_path` es único en la outbox, así que dos borrados concurrentes del
  mismo path no duplican trabajo.
- El borrado está acotado al dueño y a la conversación.
- Nunca se borran tareas operativas, obligaciones, clientes ni documentos
  administrativos.

Se amplió el cierre para incluir también los **adjuntos cargados** además de los
artefactos generados: el cascade borraba sus filas, pero sus bytes podían quedar
huérfanos. Ahora ambos se encolan y se eliminan tras el commit.

### 6.2 Pruebas A–J

`tests/Feature/A07/AiConversationDeletionTest.php`: **11 pasaron, 56 aserciones**.
Cubren borrado feliz con filas y bytes, rollback intacto, fallo de disco dejando
reintento durable, doble borrado/drenado idempotente, aislamiento entre cuentas,
prohibición de borrar conversación ajena, supervivencia de tarea operativa,
cliente/obligaciones y documento administrativo, conversación sin archivos y
deduplicación de outbox.

---

## 7. Turnos fallidos y persistencia (§10)

### 7.1 Backend

- La pregunta se persiste como fila durable **antes** de llamar al proveedor,
  con estado `processing`.
- Al completar: `completed`; al fallar: `failed` con un motivo seguro.
- `client_turn_id` es único por conversación.
- Un reintento reutiliza la misma fila, no crea una pregunta duplicada.
- Un turno `completed` se reproduce sin volver a llamar al proveedor ni volver a
  ejecutar escrituras aceptadas.
- Solo los turnos `completed` entran al historial que ve el modelo; un turno
  fallido no se inventa como intercambio previo.
- El id del turno se valida con una lista blanca corta y se busca siempre
  acotado a la conversación, de modo que no puede leer texto de otra cuenta.
- Un primer turno fallido devuelve `conversation_id` en el 503; el frontend lo
  adopta, de modo que F5 conserva el turno y el reintento cae en la misma
  conversación en vez de abrir otra.

### 7.2 Frontend

- La pregunta fallida queda en el hilo como burbuja fallida con “Reintentar”,
  no se devuelve al cuadro de texto.
- El botón de reintento reutiliza el `client_turn_id`.
- El historial reconstruye `failed` y `completed`.
- El asistente flotante usa la misma semántica y conserva la conversación.
- Se añadió una prueba de interfaz que verifica que un primer turno fallido
  adopta el `conversation_id` 9 y que el reintento envía `conversation_id=9`
  con el mismo `client_turn_id`, mostrando después la respuesta y retirando la
  burbuja fallida.

### 7.3 Respuesta vacía

- Una respuesta 200 sin texto ni herramienta se reintenta **una sola vez** sin
  herramientas.
- La recuperación no puede proponer ni ejecutar una escritura.
- Un segundo vacío falla explícitamente.
- El modelo vacío recibe `cooldown=0` en salud para que la recuperación pueda
  volver a preguntarle, sin ocultar el fallo ni impedir el failover.

Evidencia: `AiTurnPersistenceTest` **11 pasaron, 55 aserciones**, incluida la
adopción del `conversation_id` en el primer turno fallido y la frontera que
impide que la reproducción de un turno sin respuesta se apropie de la respuesta
de un turno posterior.

---

## 8. Documentos, calidad y web

Se mantienen y vuelven a pasar las suites de:

- `ArtifactQualityTest` y `ArtifactFilenameTest`: validación de calidad y nombre
  de archivos generados.
- `ArtifactLinkageTest` y `AiWebArtifactTest`: procedencia y vínculo del
  artefacto con su mensaje/conversación.
- `DocumentPipelineTest`, `DocumentSearchTest`, `AttachmentToAssistantTest`,
  `ResearchAttachmentTest`: canal de documentos y adjuntos.
- `WebFetchSsrfTest`, `WebFetchHttpIntegrationTest`, `WebSearchToolTest`,
  `AdaptiveResearchTest`: investigación web, incluida la defensa SSRF y el
  manejo honesto de resultados vacíos.

No se declara una nueva capacidad LIVE de investigación web en esta ronda; se
verifica la implementación y sus pruebas herméticas.

---

## 9. Correcciones de UX administrativa (P2)

- **Historial de cliente y portal:** el NIT se muestra con su forma legible
  (`900.123.456-3`), no como un valor crudo. Los registros antiguos siguen
  funcionando con el valor plano.
- **Support / Inbox:** la cola normaliza respuestas en array y `{ data }`, y los
  guards de operador ya no dependen de precedencia accidental.
- **Reporte de cartera:** pagina todas las filas hasta el total del servicio
  (tope 10 000) y declara `row_count`, `available_rows` y `truncated` en vez de
  recortar en silencio a la primera página.
- **Descargas de reportes:** usan `fetch` + blob con estado ocupado y error
  visible; el nombre de archivo se toma de `Content-Disposition`.
- **Periodos:** la columna “Recaudado” pasa a “Aplicado”, porque
  `total_paid_cop` es dinero aplicado a obligaciones, no caja recaudada.
- **Planilla:** el botón de la fila en preparación usa `busyPeriodId`; cancelar
  valida el motivo y confirmación; los anchos XLSX se miden del contenido
  formateado real.
- **ClientPicker:** resuelve el nombre preseleccionado en vez de mostrar un id
  vacío.
- **Inbox:** debounce explícito, sin `.debounce` de Vue 2.

---

## 10. Regresiones y correcciones de pruebas

- Se actualizó `tests/Feature/A05/ProfileReviewTest.php`: la prueba asumía
  `clients.phone` de 40 caracteres, pero FIX-LOCAL-01 lo amplió a 180. Un valor
  de 200 caracteres sigue forzando el rechazo real de PostgreSQL; ya no se usa
  un valor de 80 que la columna actual acepta. La prueba vuelve a verificar el
  camino real de rechazo de A02.
- Se actualizó `tests/frontend/reportes.page.spec.ts` para observar la petición
  real de descarga (`fetch`) en lugar de un `window.open`/ancla que ya no existe.
- Se añadió aislamiento de `localStorage` entre pruebas del asistente para que
  la conversación recordada por una prueba no decida la siguiente.

---

## 11. Evidencia de pruebas

| Comprobación | Resultado |
|---|---|
| Suite A07 completa | **410 pasaron, 1521 aserciones** |
| `AiAvailabilityTest` | 14 pasaron |
| `ProviderPrivacyClassificationTest` | 8 pasaron, 20 aserciones |
| `AiConversationDeletionTest` (A–J) | 11 pasaron, 56 aserciones |
| `AiTurnPersistenceTest` (§10) | 11 pasaron, 55 aserciones |
| Suite frontend Vitest completa | **388 pasaron, 37 archivos** |
| TypeScript (`vue-tsc`) | 46 errores preexistentes, ninguno nuevo |
| Build Vite | PASS |
| Pint sobre PHP modificado | PASS (26 archivos) |
| Playwright dirigido (`a07.spec.ts`) | **7 pasaron** (2.2 min) |
| Suite backend completa | **2122 pasaron, 1 omitida, 9669 aserciones** (24m 20s) |
| LIVE Cloudflare | PASS (3 generaciones, `@cf/openai/gpt-oss-120b`) |
| LIVE Groq | BLOQUEADO_EXTERNO_403, sin generación |
| LIVE Gemini/OpenRouter | NO_EJECUTADO por política `training` |
| `ProfileReviewTest` (A05) | 6 pasaron, 37 aserciones |

Comandos principales:

```text
docker exec consultora-dh-app php artisan test tests/Feature/A07
npm test
npm run typecheck
npm run build
docker exec consultora-dh-app ./vendor/bin/pint <archivos-modificados>
```

---

## 12. Riesgos residuales honestos

1. **Groq LIVE no verificado.** El intento devolvió `HTTP 403` de la protección
   perimetral de Groq. No es un fallo de credencial ni de la aplicación; no se
   puede demostrar una generación real desde esta red. El failover sí está
   probado, porque el turno continuó en Cloudflare. Acción manual: ejecutar una
   pregunta sintética desde una red aceptada por Groq y registrar el resultado.
2. **OpenRouter queda fuera de datos privados por defecto.** Es deliberado y
   fail-closed. Para incluirlo, el operador debe verificar la preferencia de
   privacidad de la cuenta y declarar `AI_OPENROUTER_DATA_POLICY=standard`.
3. **Gemini sigue en `training`.** No recibe datos de cliente mientras
   `AI_ALLOW_TRAINING_PROVIDERS=false`.
4. **No se añadieron proveedores nuevos** (Mistral, Cerebras, etc.) porque no
   había credenciales nuevas y el mandato prohíbe inventarlas. Los proveedores
   reales configurados son Groq, Cloudflare, Gemini y OpenRouter.
5. **TypeScript mantiene 46 errores preexistentes** en módulos no tocados
   (`AttachmentPicker`, `useContextualSuggestion`, `DocumentosPage`,
   `OperacionPage`, dos anotaciones de tipo en `assistant-artifacts.spec.ts` y
   tipos de Node en pruebas PWA). No se ocultaron ni se declararon resueltos.
6. **La outbox no cubre el borrado de una cuenta completa** como operación
   administrativa; esta ronda cierra el borrado de conversaciones. Si se elimina
   un usuario, el cascade de la outbox puede descartar trabajo pendiente. Es un
   riesgo separado, no el flujo §09.
7. **La reproducción de un turno completado** no vuelve a ejecutar escrituras,
   pero un fallo ocurrido *después* de crear una propuesta y *antes* de marcar
   el turno como `completed` podría dejar una propuesta huérfana. El turno se
   marca `failed` y el reintento no reproduce escrituras aceptadas; no se
   observó en las pruebas.

---

## 13. Conclusión

El cierre A07-R2 deja el asistente en un estado honesto:

- no llama “disponible” a lo que solo está configurado;
- no deduce privacidad por analogía;
- no envía datos privados a OpenRouter por defecto;
- no pierde la pregunta cuando el proveedor falla;
- no duplica un turno completado ni sus escrituras;
- no deja archivos huérfanos al borrar una conversación;
- y no declara LIVE lo que la red no permitió comprobar.

`FINAL_RESULT=PASS_CON_RESIDUALES_EXTERNOS`.

---

### Nota de publicación

El commit funcional es `30f237befa9f60525f15e547209f377fab58c36e`. Este informe
se publica en el commit inmediatamente posterior; su SHA exacto y el
`ORIGIN_MAIN_TIP` definitivo se informan en el bloque de cierre emitido, porque
un commit no puede contener su propio SHA. La publicación se hace por
fast-forward, sin `--force`.

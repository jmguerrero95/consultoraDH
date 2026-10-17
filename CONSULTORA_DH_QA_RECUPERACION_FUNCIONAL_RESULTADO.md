# CONSULTORA DH — Recuperación funcional integral y cierre de incidencias QA

**Resultado de la ejecución.** Un solo entregable: correcciones **en código**, validadas y publicadas en `origin/main`.

---

## 1. Identidad de la entrega

| Dato | Valor |
| --- | --- |
| `BASE_SHA` | `f36feb21e533f372e9e3cfa40312e78aaef69042` |
| `FINAL_SHA` (código verificado) | `e737e6854124d1941dc674bd6f416e0dd987551f` |
| HEAD de `origin/main` | el commit de **documentación** que añade este informe, hijo de `e737e68` (el SHA no puede ir dentro de su propio commit sin reescribir historia, y no se hace `--force`) |
| Rama | `main` |
| Remoto | `origin` → `https://github.com/jmguerrero95/consultoraDH.git` |
| Sincronización | `git rev-parse HEAD` **==** `git rev-parse origin/main` (verificado tras `git fetch`) |
| Fuerza | Sin `--force`, sin reescritura de historia |
| Alcance del diff de código | **53 archivos**, `+3956 / −1005` |

Commits (en orden):

| SHA | Mensaje |
| --- | --- |
| `28df2b3` | QA Soporte: alta real de conversación, canal sin NaN y pantallas completas |
| `852e5ed` | QA IA: failover ordenado y acotado, estado real y widget global |
| `ad08c1a` | QA fundaciones y administración: alertas, botones, navegación y reportes |
| `e737e68` | Correcciones halladas al generar los XLSX reales |

Archivos sin versionar que **no** se tocaron ni se borraron (trabajo previo del propietario): `CONSULTORA_DH_IMPORT_6_POST_APPLY_VALIDATION_INFORME.md`, `LOCAL-RUN-01-INFORME.md`, `LOCAL-RUN-01-INFORME-CIERRE.md`.

---

## 2. Archivos modificados (53)

**Soporte (ETAPA C)**
`app/Http/Controllers/Api/SupportConversationController.php`, `app/Domain/Support/Actions/AssignConversation.php`, `app/Domain/Support/Events/SupportConversationAssigned.php`, `routes/api.php`, `routes/channels.php`, `resources/js/services/api.ts`, `resources/js/pages/support/Conversation.vue`, `resources/js/pages/support/Inbox.vue`, `resources/js/pages/portal/SupportConversation.vue`, `resources/js/pages/portal/SupportInbox.vue`

**IA (ETAPA D)**
`app/Services/Ai/AiProviderResolver.php`, `app/Services/Ai/OpenAiCompatibleProvider.php`, `app/Services/Ai/AiTurnFailed.php`, `app/Services/Ai/AiAssistantService.php`, `app/Http/Controllers/Api/AiAssistantController.php`, `config/ai.php`, `bootstrap/app.php`, `resources/js/services/ai.ts`, `resources/js/pages/Asistente/Index.vue`, `resources/js/components/assistant/FloatingAssistant.vue` *(nuevo)*, `resources/js/layouts/AdminLayout.vue`

**Fundaciones compartidas y administración (ETAPAS A y B)**
`resources/js/components/ui/AppAlert.vue`, `AppButton.vue`, `AppModal.vue`, `resources/js/components/clients/ClientPicker.vue`, `resources/js/composables/useNavigationProgress.ts` *(nuevo)*, `resources/js/router/index.ts`, `resources/js/App.vue`, `resources/js/app.ts`, `resources/css/app.css`, `public/sw.js`, `resources/js/pages/payments/PaymentListPage.vue`, `resources/js/pages/companies/CompanyDetailPage.vue`, `resources/js/pages/planillas/PlanillaDetailPage.vue`, `resources/js/pages/periods/PeriodListPage.vue`, `resources/js/pages/receivables/ReceivablesPage.vue`, `resources/js/types/api.ts`, `app/Http/Resources/AssignmentResource.php`, `app/Http/Controllers/Api/CompanyController.php`, `app/Http/Controllers/Api/PlanillaController.php`, `app/Models/ContributionSheet.php`, `app/Domain/Planillas/PlanillaExporter.php`, `app/Domain/Planillas/PlanillaXlsxExport.php`, `app/Domain/Receivables/ReceivablesService.php`, `app/Domain/Reports/ReportType.php`, `EntityReport.php`, `PortfolioReport.php`, `ReportFilter.php`, `ReportFactory.php`, `CompanyPortfolioReport.php`, `app/Domain/Reports/Export/XlsxExporter.php`, `app/Domain/Reports/Export/LegacyWorkbookExporter.php`, `tests/Feature/A05/ReportTest.php`

---

## 3. BUG-001 … BUG-018

Estado: **C** = corregido en código + compilación/lint · **E** = además verificado **en ejecución** · **P** = pendiente de validación externa (se indica cuál).

| ID | Hallazgo | Corrección aplicada | Estado |
| --- | --- | --- | --- |
| BUG-001 | `AppButton` sólo conocía `busy`; las llamadas que pasan `:loading` (24 en el código) se descartaban en silencio. Los chunks de ruta diferida cargan sin señal alguna | `loading` como alias de `busy` (`isBusy`), `aria-busy`; barra de progreso global en `App.vue` alimentada por `useNavigationProgress` desde el router, con recuperación de error de chunk (`router.onError`) | C |
| BUG-002 | `AppAlert` sólo declaraba `title`; `:message`/`:description` quedaban como atributos sueltos y la alerta se veía como caja vacía | Se declaran `message` y `description`; `v-if="hasContent"` — nunca se dibuja una alerta vacía (afecta a los 105 usos repartidos en 39 archivos) | C |
| BUG-003 | La planilla en estado terminal seguía admitiendo edición y adjuntos | Bloqueo en pantalla (`estadoTerminal`, `puedeEditarLineas`, `puedeAdjuntar`) **y** en servidor: `PlanillaController::uploadFile` responde `409 sheet_locked` | C |
| BUG-004 | `ClientPicker` pedía `per_page=15`, fuera del rango aceptado → **422** | `per_page=25`, `''`→`null`, 300 ms de espera, descarte por `requestId`, primera página al abrir, nombre del seleccionado | C |
| BUG-005 | Los modales de histórico y reverso usaban `@close`; el componente emite `cancel` | `@cancel` + `cancel-label="Cerrar"` + `hide-confirm`; `AppModal.requestCancel()` emite `cancel` y `close` | C |
| BUG-006 | Estados internos (`not_due`) a la vista | Etiquetas legibles en `ReportType`, `EntityReport`, `PortfolioReport` y `ReportFilter`. Comprobado: `not_due` ya sólo existe como tipo interno en `types/api.ts` y en un comentario | E (grep) |
| BUG-007 | El botón "Nueva conversación" llevaba a una pantalla sin formulario y **no existía endpoint de creación** para el personal | `POST /api/support/conversations` (`can:support.reply`) + modo creación en las dos pantallas (personal y portal). Ruta confirmada en `route:list` | E (ruta) / C (UI) |
| BUG-008 | `/broadcasting/auth` devolvía **500**: el canal `support.conversation.NaN` hacía `SupportConversation::find('NaN')` y Postgres fallaba al convertir a bigint | Guardas `ctype_digit` en `routes/channels.php` para los dos canales; el cliente ya no se suscribe sin id válido | C |
| BUG-009 | Estado/Prioridad vacíos en bandejas | Etiquetas con valor por defecto en `support/Inbox.vue`, `portal/SupportInbox.vue` y las dos conversaciones; clases de badge reales | C |
| BUG-010 | Transcripción en orden descendente y con duplicados | Orden ascendente + deduplicación por id al paginar hacia atrás | C |
| BUG-011 | Autor mal atribuido (`msg.author?.name` no existía) | Autor derivado de `sender_kind`, con nombre y rol de respaldo | C |
| BUG-012 | Descargas falsas (botón que no descargaba) | Anclas `<a>` reales contra el endpoint autenticado de adjuntos | C |
| BUG-013 | Tipo/nombre de adjunto por heurística | Tipo e icono por MIME/extensión; nombre real del archivo | C |
| BUG-014 | El botón de audio volvía a llamar a `startRecording` en grabación y estaba deshabilitado durante la misma | Grabar / parar / cancelar; se libera el micrófono y se detienen los tracks al cerrar | C |
| BUG-015 | Cola/asignado/prioridad no persistían (referencias transitorias) | Se dibuja el estado persistido que devuelve el servidor; asignación acepta `null` para "Sin asignar" (backend `present|nullable`) | C |
| BUG-016 | Failover fijo sin memoria: sin tope de intentos ni enfriamiento | Resolutor con lista ordenada y deduplicada de candidatos, tope `AI_MAX_ATTEMPTS` (2) y enfriamiento `AI_COOLDOWN_SECONDS` (60 s) en caché compartida. Se conservan `AI_PRIMARY_*`/`AI_FALLBACK_*`. Una credencial rechazada **no** se reintenta ni entra en enfriamiento | **E** |
| BUG-017 | El estado decía "configurado" aunque el proveedor llevara una hora fallando | `state` (`none`/`available`/`fallback`/`unavailable`), `available`, `active_model`, `cooldowns`, en caché `AI_STATUS_CACHE_SECONDS` (20 s), sin contactar a ningún proveedor | **E** |
| BUG-018 | Una respuesta vacía se aceptaba **y se persistía** como turno del asistente | Rechazo en el borde (`OpenAiCompatibleProvider::parse`: 200 sin texto y sin `tool_calls` → fallo reintentable) y en el servicio (`AiTurnFailed::emptyAnswer`): nunca se guarda un mensaje vacío; un turno que sólo produjo una propuesta no guarda texto en blanco. Los fallos de IA se rinden como **502/503** con mensaje en español | **E** |

---

## 4. MEJ-001 … MEJ-011

> Nota de trazabilidad: el texto literal de **MEJ-010** y **MEJ-011** no es recuperable del contexto de esta sesión (la compactación conservó MEJ-001…009 y, aparte, los entregables de la etapa de IA). Se mapean por etapa: **MEJ-010 = widget flotante global del asistente** y **MEJ-011 = reforma de UX/integración contextual del asistente (reconexión A07)**. Ambas están implementadas y se describen abajo.

| ID | Mejora | Implementación | Estado |
| --- | --- | --- | --- |
| MEJ-001 | Histórico de clientes por empresa | `AssignmentResource` emite `document_type/number/label`, `started_on_precision`, `ended_on_precision`; `CompanyController::show` envía `clients.history` (misma guarda de permiso, orden por `ended_on` desc); tarjeta "Clientes históricos" con paginación en cliente (10) | C |
| MEJ-002 | Formato real de los XLSX de reportes | `XlsxExporter` reescrito: anchos por contenido, negritas, formato de moneda, filas de totales resaltadas. Además se corrigieron dos defectos reales encontrados al generar el archivo (ver §7) | **E** |
| MEJ-003 | Motivo/fecha/responsable de cancelación de planilla | `ContributionSheet` gana `canceller`/`cancelled_at`; `PlanillaExporter::summary` los publica; tarjeta "Cancelación" en la pantalla | C |
| MEJ-004 | "Por facturar" ambiguo | Pasa a "Pendiente por cobrar" con nota aclaratoria | C |
| MEJ-005 | Orden de cartera y semántica del filtro | Orden determinista (`overdue_balance_cop` desc, `oldest_due_on` asc, `client_id`); etiqueta "Antigüedad del saldo" y nota que distingue "Saldo" de "Vencido"/mora | C |
| MEJ-006 | Leyenda del libro operativo | Revisada: el exportador ya no requería cambio; se deja constancia en el comentario | C |
| MEJ-007 | Formato real de la planilla XLSX | `PlanillaXlsxExport` reescrito: cabecera con estilo, anchos por columna, moneda `#,##0`, totales resaltados, A4 horizontal con ajuste a una página, filas de título de impresión | **E** |
| MEJ-008 | Etiquetas legibles en reportes y filtros | `ReportType`, `ReportFilter::describe`/`valueLabel()`/`FIELD_LABELS`; fuera el vocabulario interno | C (grep) |
| MEJ-009 | Cabecera de la conversación sin datos del cliente | Cabecera con nombre y documento del cliente | C |
| MEJ-010 | Widget flotante global del asistente | `FloatingAssistant.vue` montado en `AdminLayout`, sobre el **mismo** servicio `@/services/ai` y los mismos endpoints que `/asistente` (sin segundo protocolo): conversación, propuestas con confirmación, enlace a pantalla completa, nueva conversación, Escape. No dibuja respuesta vacía ni botón que abra una disculpa | C |
| MEJ-011 | UX/integración contextual del asistente (reconexión A07) | Panel con estado real y modelo que responde, errores con el texto del servidor, `no-op` eliminado; `Asistente/Index.vue` usa `state`/`active_model`; se corrigió la clave del aviso contextual de Cartera, que leía un campo inexistente (`total_overdue_cop`) y **nunca** se disparaba | C |

---

## 5. Navegación, botones, iconos y rendimiento

| Aspecto | Antes | Ahora | Validación |
| --- | --- | --- | --- |
| Clics repetidos en el menú | Un clic en un módulo diferido no cambiaba nada hasta descargar el chunk; el usuario volvía a pulsar | Barra de progreso global inmediata en cada navegación, contador de navegaciones solapadas y vigilante de 15 s | Compilación + código; confirmación visual pendiente de la pasada del propietario |
| Ruta duplicada | `portal.requests` declarada dos veces → aviso de vue-router y un registro descartado | Declarada una sola vez (comentado) | Compilación |
| Botones que no hacían nada | `:loading` descartado en 24 sitios | Alias `loading` + `aria-busy` + spinner | Compilación + typecheck |
| Iconos tardíos (FOUT) | Bootstrap Icons declara `font-display: block`: nada se pintaba hasta llegar la fuente (134 KB) | Precarga de la fuente desde `app.ts` con `?url` resuelto por Vite; el `ServiceWorker` sólo se registra en producción | Compilación y build; confirmación visual pendiente |
| Alerta vacía | Cajas de color sin texto | `AppAlert` no se dibuja sin contenido | Código + typecheck |
| Respuesta vacía de IA | Burbuja en blanco presentada como éxito | Servidor la rechaza; la UI sólo añade la burbuja si hay texto | **Ejecución** |
| Rendimiento | — | No se introdujeron dependencias nuevas ni consultas por render. Se eliminó una ruta duplicada y se limitó la primera página del selector de clientes | Revisión del diff |

---

## 6. Soporte: estado de extremo a extremo

- **Alta real**: `POST /api/support/conversations` existe (confirmado con `route:list`) y las dos pantallas tienen formulario (cliente, asunto, cola, mensaje). Sin endpoint inventado ni escritura simulada.
- **Tiempo real**: el canal ya no recibe un id inválido; las guardas `ctype_digit` eliminan el 500 de `/broadcasting/auth` (causa raíz: `find('NaN')` sobre Postgres).
- **Bandejas**: etiquetas nunca vacías, clases de badge reales, `es-CO`, "—" para cola nula.
- **Conversación (personal y portal)**: orden ascendente deduplicado, paginación hacia atrás, autor por `sender_kind`, adjuntos con tipo real, descarga con ancla autenticada, audio con parar/cancelar y liberación del micrófono, cabecera con cliente (nombre + documento), cola/prioridad/asignado persistidos ("Sin asignar" real).
- **RBAC e aislamiento**: se conservan los permisos (`support.reply`, `ai.use`); el portal sigue viendo sólo sus conversaciones.

## 7. IA: estado y hallazgos de la prueba real

**Diseño**: candidatos ordenados y deduplicados (primario, respaldo), tope de intentos, enfriamiento en caché compartida (Redis) compartido entre workers, y estado real calculado sin contactar al proveedor. Una credencial rechazada (401/403) o una petición malformada (400/422) **no** se reintenta en el respaldo y no entra en enfriamiento; transporte, timeout, 429 y 5xx sí.

**Verificado en ejecución (sin coste de proveedor, transporte simulado):**

```
=== BUG-018: forma de la respuesta ===
contenido vacio (200)    ACEPTADO provider=fallback content="Respuesta real"  calls(prim/sec)=1/1  cooldowns={"primary":60}
solo espacios (200)      ACEPTADO provider=fallback content="Respuesta real"  calls(prim/sec)=1/1  cooldowns={"primary":60}
texto real (200)         ACEPTADO provider=primary  content="Respuesta real"  calls(prim/sec)=1/0  cooldowns=[]
solo tool_call (200)     ACEPTADO provider=primary  content=""                calls(prim/sec)=1/0  cooldowns=[]
=== BUG-016 ===
primario 401 -> sin respaldo   RECHAZADO retryable=false calls(prim/sec)=1/0  cooldowns=[]   (no reintenta, no enfria)
primario 429 -> respaldo       ACEPTADO provider=fallback                        cooldowns={"primary":60}
primario 500 -> respaldo       ACEPTADO provider=fallback                        cooldowns={"primary":60}
max_attempts=1, primario vacio RECHAZADO; cooldowns={"primary":60} (solo primario)
turno 2 (primario en espera)   provider=fallback calls(prim/sec)=1/2 (primario NO llamado)
=== BUG-017 ===
sin enfriamiento   state=available   active_model=model-primary
primario en espera state=fallback    active_model=model-fallback  cooldowns={"primary":30}
ambos en espera    state=unavailable active_model=NULL
nada configurado   state=none        active_model=NULL
```

**Rendición de errores (handler real, petición `/api/*`):**

```
AiProviderException retryable  HTTP 503  {"message":"El asistente de IA no está disponible en este momento. Intente de nuevo en unos segundos.","code":"ai_provider_unavailable"}
AiProviderException auth       HTTP 503  {"message":"El asistente de IA no pudo procesar la solicitud. Revise la configuración del proveedor.","code":"ai_provider_unavailable"}
AiTurnFailed empty             HTTP 502  {"message":"El asistente no produjo una respuesta. La conversación no se modificó; intente reformular la pregunta.","code":"ai_turn_failed"}
```

Ninguna respuesta lleva la URL configurada ni la clave.

### Proveedores reales probados (sin exponer claves)

| Ranura | Modelo configurado | Resultado real |
| --- | --- | --- |
| Primario | `google/gemma-4-31b-it:free` | **No respondió** en la comprobación (fallo reintentable → enfriamiento) |
| Respaldo | `nvidia/nemotron-3.5-lightning:free` | Respondió: `content="OK"` |

Salida real: `OK provider=fallback model=nvidia/nemotron-3.5-lightning:free content="OK"` · `elapsed_ms=48007` · `cooldowns={"primary":13}` · `state=fallback`.

Se gastó **una** petición al proveedor (mínimo permitido). El enfriamiento verificado dejó el primario en espera y el estado `fallback` — exactamente lo que BUG-017 debe informar. La clave del despliegue no se imprimió, no se registró y no se incluye en este informe.

## 8. Comprobación real de los XLSX y defectos que reveló

Se generó el reporte "Por empresa" con datos reales y la planilla real, y se leyeron de vuelta con OpenSpout (escritura sólo en `/tmp`, sin fila en base, sin auditoría y sin artefacto en el disco configurado):

```
reporte   existe=si 5807 bytes magia_zip=PK  hoja 'Por empresa' filas=20
planilla  existe=si 5280 bytes magia_zip=PK  hoja 'Planilla SIMPLE' filas=6
reporte  cols: min 1..5, anchos 40/11/18/20/23
planilla cols: min 1..11, anchos 18/30/18/22/22/22/22/12/20/10/34   mergeCells A1:K1
```

Esta prueba descubrió **dos defectos reales**, corregidos en el commit `e737e68`:

1. **El reporte "Por empresa" no se podía generar nunca.** Sumaba una columna `balance_cop` que `monthly_obligations` **no tiene** — el saldo es derivado (`ObligationTotals`), no almacenado — así que la consulta fallaba antes de producir una fila, **en todos los formatos** (web, CSV, XLSX, PDF). Existía desde `52e725d` (A05), ajeno a los cambios de este mandato. Ahora las cifras salen de `ReceivablesService` (mismo origen que la pantalla de Cartera) mediante un único agregado por empresa, sin una segunda fórmula.
2. **Anchuras de columna inválidas.** OpenSpout v5 publica el índice 1-based directamente en el XML; se le pasaba un índice 0-based, de modo que el primer `<col>` salía `min="0"` y el resto desplazado, y un lector estricto rechaza el archivo. Reporte y planilla usan ahora `$index + 1`, verificado leyendo de vuelta ambos archivos.

---

## 9. Validación ejecutada (mínima y acotada)

| Comprobación | Resultado |
| --- | --- |
| `php -l` de todos los PHP modificados | Sin errores |
| `npm run typecheck` (vue-tsc) | 46 errores, **todos** en archivos que **no** se modificaron (11 en `resources/js`, repartidos en 6 archivos, y 35 en `tests/frontend`). Prueba: `git diff BASE..HEAD -- <esos archivos>` está vacío |
| `npm run build` (Vite, una sola vez) | Correcto |
| `php artisan route:list --path=api/support` | `POST api/support/conversations` presente; `POST .../read` presente |
| `curl` sin sesión a `/api/ai/status` y `POST /api/support/conversations` | `401` y `419` (rutas y guardas activas) |
| Matriz de failover/estado/respuesta vacía (transporte simulado) | Correcta en los 12 escenarios (§7) |
| Renderizado 502/503 de fallos de IA | Correcto (§7) |
| Generación y lectura de los dos XLSX con datos reales | Correcta (§8) |
| `php artisan test tests/Feature/A05/ReportTest.php` | **11 pasan** |
| `php artisan test tests/Feature/A03` | **444 pasan**, 0 fallos (390 s) |
| `php artisan test tests/Feature/A05` | 156 pasan, **1 fallo preexistente** (`ProfileReviewTest > U2`, ver §10) |
| `git status` / revisión del diff | Sin secretos (`sk-`, `api_key`, `password`, `BEGIN … PRIVATE`), sin `console.log`/`debugger`/`TODO` añadidos, sin archivos temporales en el commit |

Los PHP se ejecutaron **siempre** dentro del contenedor. No se reinició ni se reconstruyó infraestructura; no se importó ni se modificó nada del lote #6.

## 10. Dependencias externas y hallazgos fuera de alcance

1. **Modelo primario de IA sin respuesta.** `AI_PRIMARY_MODEL=google/gemma-4-31b-it:free` no respondió en la comprobación (fallo clasificado como reintentable, no como credencial rechazada). El sistema hace failover correctamente, pero conviene que el propietario confirme el nombre del modelo y su cuota en el proveedor.
2. **Si no hay JSON de proveedor en producción**, el respaldo ya responde (verificado): el asistente opera en estado `fallback` sin intervención.
3. **Fallo preexistente de test, fuera de alcance**: `tests/Feature/A05/ProfileReviewTest > U2` espera un `409` al aprobar un cambio que A02 "rechaza", usando un `phone` de 80 caracteres porque asumía `varchar(40)`. La migración `2026_10_13_100000_widen_source_phone_columns.php` amplió `clients.phone` a **`varchar(180)`**, así que el valor ya es válido, la actualización se aplica y la respuesta correcta es `200`. Es una expectativa **obsoleta**, no un defecto del producto; no se modificó por quedar en el área A02 (perfil congelado).
4. **Errores de typecheck preexistentes** en 6 archivos no modificados (`assistant/AttachmentPicker.vue`, `assistant/TaskPanel.vue`, `composables/useContextualSuggestion.ts`, `composables/useTasks.ts`, `pages/documentos/DocumentosPage.vue`, `pages/operacion/OperacionPage.vue`) y en `tests/frontend/*`. No se tocaron.
5. **Datos del lote #6**: dos omisiones sin resolver (acción 15483 CC 1098730519 / NIT 901530284 sin fecha; acción 15484 CC 37551762 / NIT 901688315 literal «NO») siguen esperando decisión del propietario. **No** se reimportó ni se inventaron reglas.
6. **Octubre de 2026**: 217 obligaciones candidatas bloqueadas por falta de fecha de corte/tarifa. Es estado de configuración correcto, no un error; no se generaron obligaciones.

## 11. Cómo probarlo (propietario)

Todo lo anterior está publicado: `git fetch origin && git log --oneline -4` debe mostrar `e737e68`.

**A. Navegación, botones e iconos** — recargue con caché limpia y pulse un módulo aún no visitado (p. ej. *Documentos*): debe aparecer la barra superior al instante y una sola pulsación debe bastar. Los iconos no deben aparecer tarde. Abra un formulario y fuerce un error: la alerta debe mostrar texto, nunca una caja vacía ni una acción sin efecto.

**B. Administración** — Empresa → *Clientes históricos* (con documento y fechas); Planilla en estado terminal → edición y adjuntos bloqueados y tarjeta de cancelación si procede; Periodos → "Pendiente por cobrar"; Cartera → orden por vencimiento, filtro "Antigüedad del saldo" y nota aclaratoria.

**C. Exportaciones** — Reportes → *Por empresa* en XLSX (antes fallaba siempre): debe abrir con anchos, moneda y total resaltado; Planilla → XLSX con formato y orientación horizontal.

**D. Soporte** — *Nueva conversación* desde la bandeja del personal (y desde el portal), envío, adjunto y descarga real, audio con parar/cancelar, asignación y "Sin asignar", recarga (F5) y comprobar que cola/prioridad/asignado se mantienen. Confirmar que no aparece ningún error rojo de WebSocket en consola.

**E. Asistente IA** — el botón flotante debe aparecer abajo a la derecha; pregunte algo y confirme que la respuesta llega (con el modelo indicado), que una propuesta de escritura **no** cambia nada hasta confirmar, y que un fallo del proveedor muestra el mensaje en español (502/503) sin dejar burbuja vacía. `/asistente` es la vista ampliada.

## 12. Límites de esta ejecución

- La verificación de UI (barra de navegación, iconos, modales, audio, widget) es **por código y compilación**, no por recorrido en navegador: el recorrido real corresponde al propietario (§11). Se distingue así de lo verificado **en ejecución** (matriz de IA, renderizado 502/503, rutas, generación de los XLSX, suites A03/A05).
- Sólo se hizo **una** petición real a un proveedor de IA.
- No se crearon suites de test nuevas; la única adición es un caso de regresión dentro de `ReportTest` para el fallo de "Por empresa".

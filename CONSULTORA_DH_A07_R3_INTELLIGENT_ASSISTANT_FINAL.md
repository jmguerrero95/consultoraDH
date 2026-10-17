# CONSULTORA DH — Asistente autónomo multipropósito A07-R3 (FINAL)

**Fecha:** 2026-10-17
**Rama:** `main` · **Remoto:** `origin/main`
**SHA inicial (BASE_SHA):** `5dc11a7edef75711eafca350ad42ab77b9ed8fb6`
**SHA final (FINAL_SHA):** `677c11d7d81d95d67fc3b4310b3c31815b96d9d0`

---

## 1. Objetivo y resultado

Convertir el asistente de Consultora DH en un **colaborador autónomo real**, no en un
generador de respuestas evasivas: que investigue de verdad en internet, consulte y
produzca información de la propia aplicación, genere y **entregue archivos descargables
dentro del hilo** y funcione bien en móvil, sin romper RBAC, auditoría, confirmaciones de
escritura, aislamiento, SSRF/DNS pinning ni `AI_ALLOW_TRAINING_PROVIDERS=false`.

**Resultado:** conseguido. Verificado con **tráfico real** contra los proveedores del pool
(clima, investigación, cifras y generación de PDF) y con **pruebas dirigidas herméticas**
(54 backend + 69 frontend en verde). Se eliminaron los comportamientos prohibidos
(«no tengo acceso a internet», «¿quieres que investigue?», terminar en una propuesta sin
archivo, respuestas vacías).

---

## 2. Defectos corregidos

### D1 — El modelo no llamaba a las herramientas: la investigación no ocurría

La autonomía dependía de que el modelo decidiera invocar `web_search`/`web_fetch`; con
modelos pequeños la mayoría de las veces no lo hacía y respondía «no tengo acceso a
internet». **Corrección:** el **servidor prefija** la investigación de forma determinista
(`AiAssistantService::prefetchExternal`) cuando el enrutador marca el turno como externo,
y se la entrega al modelo ya resuelta como datos con su procedencia. El modelo no tiene
que *decidir* buscar: recibe el resultado y lo sintetiza.

### D2 — El clima devolvía «no tengo acceso a datos meteorológicos»

**Corrección:** `WeatherTool` (`weather_current`) contra la API pública **Open-Meteo**
sin clave (geocodificación + pronóstico), con unidad, hora de observación, zona horaria
del lugar y la nota de que es un valor modelado. Es una **especialización**, no el
mecanismo: el respaldo sigue siendo la búsqueda general.

### D3 — «¿Cuántos clientes hay…?» no daba cifras reales

**Corrección:** `SystemStatsTool` (`system_stats`) reutiliza `PortfolioMetrics` y
`ReceivablesService::clientBalanceCounts()` para no duplicar aritmética, y respeta
`clients.view` / `receivables.view`.

### D4 — Generar un archivo terminaba en una propuesta sin archivo (defecto crítico)

`create_artifact` estaba marcada como escritura de negocio, así que producía una
propuesta y la conversación terminaba describiendo un documento que nunca existió.
**Corrección:** `CreateArtifactTool::isWrite()` devuelve **`false`**: generar un archivo
**privado y solicitado** no es una mutación de negocio, se ejecuta y devuelve el enlace de
descarga (`/api/ai/artifacts/{id}/download`). Las mutaciones de negocio conservan su
confirmación.

### D5 — Las fuentes no coincidían con la prosa

Los resultados prefijados por el servidor no pasaban por una llamada del modelo, así que
el panel «Fuentes» podía quedar vacío mientras la respuesta citaba orígenes.
**Corrección:** `mergePrefetchedTools()` incorpora los resultados prefijados al payload
`tools` del turno (sin duplicar un `tool` que el modelo también llamó).

### D6 — El seguimiento «¿Y mañana qué tal estará?» moría con `toolRoundsExhausted`

`extractLocation()` tomaba las palabras sobrantes del seguimiento y extraía un lugar
basura (`tal`) que hacía fallar la geocodificación y agotaba las rondas de herramientas.
**Corrección:** un seguimiento **hereda el lugar** de la pregunta que continúa; y un
mensaje corto que **abre con conjunción** («y», «e», «también», «además») se reconoce como
continuación aunque nombre otro lugar («¿Y en Bogotá qué tal estará?»).

### D7 — El modelo filtraba su sobre de llamada a herramienta como texto

La respuesta salía con real data **más** `{"name": "weather_current", "parameters": {…}}`
en crudo. **Corrección:** `AiAssistantService::stripToolCallLeak()` elimina un objeto
balanceado final —o un bloque cercado— **solo** cuando decodifica a la forma exacta del
sobre (`name` + `parameters`/`arguments`); la prosa normal y un JSON pedido por la persona
quedan intactos. Se añadió además una regla de estilo al *system prompt*.

### D8 — El modelo narraba un error interno de validación

Un `days` numérico enviado como cadena (`"2"`) era rechazado, y el modelo contaba la
validación en lugar de reintentar. **Corrección:** `WeatherTool::validate()` acepta una
cadena numérica (y un flotante entero), y una regla nueva prohíbe narrar mensajes internos
o nombres de parámetros.

---

## 3. Interfaz móvil-primero

- **Markdown seguro propio** (`resources/js/utils/markdown.ts` + `SafeMarkdown.vue`): sin
  dependencias nuevas (`marked`/`dompurify` ausentes). Escapa HTML y solo permite un
  subconjunto seguro; sentinel de placeholder en rango de uso privado (`\uE000`) para
  satisfacer `no-control-regex` de ESLint.
- **Archivos dentro del hilo** (`ArtifactCard.vue`): tarjeta de descarga con formato, peso
  y enlace, tanto en el widget como en la página completa.
- **Progreso en línea** y **resultado de confirmación** visibles en el hilo.
- **Voz natural**: `useVoice.ts` elimina el Markdown antes de sintetizar, para que no se
  lea «asterisco… almohadilla».
- **CSS móvil-primero** en `Asistente/Index.vue`.

La PWA ya existía (manifest, service worker, push, sincronización en segundo plano): no se
duplicó.

---

## 4. Archivos modificados

`git diff --stat 5dc11a7..677c11d`: **25 archivos, 3481 inserciones, 134 eliminaciones**.

| Archivo | Cambio |
| --- | --- |
| `app/Services/Ai/AiTaskRouter.php` | Frescura, tema público, clima y seguimientos; lugar heredado; conjunción inicial como continuación |
| `app/Services/Ai/AiAssistantService.php` | Prefetch externo determinista; contexto web como datos; `mergePrefetchedTools`; `stripToolCallLeak`; reglas de autonomía |
| `app/Services/Ai/Tools/WeatherTool.php` | **nuevo** — Open-Meteo (clima + pronóstico), `days` tolerante |
| `app/Services/Ai/Tools/SystemStatsTool.php` | **nuevo** — cifras de cartera reutilizando servicios existentes |
| `app/Services/Ai/Tools/CreateArtifactTool.php` | Ejecuta y entrega enlace; `isWrite()=false` |
| `app/Services/Ai/AiToolRegistry.php` | Registro de las nuevas herramientas |
| `app/Http/Controllers/Api/AiAssistantController.php` | Descarga de artefactos |
| `app/Jobs/RunAiTask.php` | Publicación de artefactos/progreso en la conversación |
| `app/Domain/Receivables/ReceivablesService.php` | `clientBalanceCounts()` |
| `config/ai.php` | Claves de clima y investigación (sin claves de pago) |
| `resources/js/utils/markdown.ts` | **nuevo** — render seguro + `stripMarkdown` |
| `resources/js/components/ui/SafeMarkdown.vue` | **nuevo** — componente compartido |
| `resources/js/components/assistant/ArtifactCard.vue` | **nuevo** — tarjeta de descarga |
| `resources/js/components/assistant/FloatingAssistant.vue` | Artefactos, Markdown, resultado de confirmación |
| `resources/js/pages/Asistente/Index.vue` | Artefactos, tareas en línea, móvil |
| `resources/js/services/ai.ts` | Tipos `AiArtifact` |
| `resources/js/composables/useVoice.ts` | Quita Markdown antes del TTS |
| `resources/js/composables/useTasks.ts` | Endurecimiento (`tasks.value`, `pending()`, `flush()`) |
| `resources/js/composables/useSuggestionContext.ts` | Guarda de `route?.name` |
| `tests/Feature/A07/AiWebArtifactTest.php` | Ajuste de la prueba de artefacto |
| `tests/Unit/A07/AiIntentRoutingTest.php` | **nuevo** — 10 casos de enrutamiento |
| `tests/Unit/A07/AiReplySanitizerTest.php` | **nuevo** — 5 casos del saneador |
| `tests/Unit/A07/WeatherToolValidationTest.php` | **nuevo** — 5 casos de validación |
| `tests/frontend/markdown.spec.ts` | **nuevo** — Markdown seguro |
| `tests/frontend/asistente.page.spec.ts` | Artefactos, confirmación, Markdown |

Commits por grupo (sin reescritura de historia, sin *force*):

| Commit | Grupo |
| --- | --- |
| `2980cd7` | backend: investigación, clima y cifras (12 archivos) |
| `638a889` | UI: Markdown seguro y tarjeta de archivos (6 archivos) |
| `c6e68a5` | UI: archivos y progreso en el hilo, confirmación con resultado (5 archivos) |
| `677c11d` | fix: seguimientos con lugar heredado, sin errores internos filtrados (6 archivos) |

---

## 5. Decisiones de diseño y fronteras de seguridad

- **RBAC intacto:** cada herramienta autoriza contra el mismo permiso que su pantalla
  (`web_search`, `clients.view`, `receivables.view`, …) dentro de `execute()`, no al
  construir el catálogo.
- **Confirmaciones de negocio intactas:** las escrituras de negocio siguen produciendo
  propuesta y esperan a una persona. Solo se excluyó la generación de archivo privado
  solicitado (que no muta el negocio).
- **Idempotencia y aislamiento:** se conservan; las claves de idempotencia y el alcance por
  usuario no se relajan.
- **SSRF/DNS pinning:** `web_fetch` mantiene el pinning; el host del clima es fijo
  (Open-Meteo) y nunca proviene de un argumento.
- **Privacidad:** el prefetch automático se **suprime** cuando la pregunta es sobre datos
  propios (`OUR_DATA_TERMS`) — la búsqueda pública no recibe contenido de negocio —, y
  ninguna pregunta con datos privados llega a un proveedor con política de entrenamiento
  incompatible (`AI_ALLOW_TRAINING_PROVIDERS=false`).
- **Proveedores gratuitos:** solo se usan proveedores y APIs gratuitas; **ninguno de pago**;
  ninguna búsqueda ni TTS de pago.
- **Sin dependencias pesadas:** Markdown y Markdown→voz son propios; la PWA existente se
  reutiliza.

---

## 6. Pruebas dirigidas (herméticas)

**Backend — 54 casos en verde (203 aserciones):**

| Suite | Casos | Qué verifica |
| --- | --- | --- |
| `AiIntentRoutingTest` | 10 | Clima/frescura/tema público/estadísticas/artefacto; seguimiento hereda tema y lugar; lugar basura descartado; seguimiento con lugar nuevo |
| `AiAssistantRuntimeTest` | 12 | Turno real contra el proveedor HTTP simulado, aislamiento y refusals |
| `AiReplySanitizerTest` | 5 | Sobre de herramienta eliminado; prosa y JSON pedido intactos |
| `WeatherToolValidationTest` | 5 | `days` numérico aceptado; fuera de rango y no numérico rechazados |
| `WebSearchToolTest` | 5 | Consulta real enviada; vacío reportado; permisos |
| `AiWebArtifactTest` | 3 | Archivo descargable real, uno por formato |
| `AiAssistantTest` | 3 | Estado del asistente y permisos |
| `AssistantTaskKindsTest` | 11 | Publicación de tareas/artefactos |

**Frontend — 69 casos en verde (6 archivos):** `markdown.spec.ts`, `asistente.page.spec.ts`,
`voice.spec.ts`, `tasks.spec.ts`, `components.spec.ts`, `suggestion-context.spec.ts`.

**Calidad:** ESLint limpio sobre todos los fuentes cambiados (salida 0); `vue-tsc` sin
errores nuevos en los fuentes cambiados (10 preexistentes ajenos); `vite build` correcto.

---

## 7. Verificación real de aceptación

Tráfico real contra el pool de proveedores configurado (modelo
`@cf/meta/llama-3.1-8b-instruct-fp8`), con limpieza de las conversaciones creadas:

| Escenario | Resultado | Detalle |
| --- | --- | --- |
| Clima | **PASS** | «¿Qué temperatura y humedad hay ahora mismo en Girón, Santander?» → 27.4 °C / 71 % con hora de observación, citando **Open-Meteo**; `tools=[weather_current, web_search]` |
| Investigación | **PASS** | Reforma pensional en Colombia → respuesta con **fuentes reales** citadas desde búsqueda (SearXNG) |
| Cifras | **PASS** | «¿Cuántos clientes…?» → cifras reales vía `system_stats` (325 clientes, 1 pago parcial) |
| Archivo | **PASS** | PDF `resumen-ejecutivo` generado (19 294 bytes) con **URL de descarga**, sin propuesta |
| **Seguimiento de clima** | **PASS** | «¿Y mañana qué tal estará?» → hereda Girón: mín 24.2 °C / máx 30.2 °C, 55 % de precipitación, sin sobre filtrado ni error interno |

Antes de D6/D7/D8 el seguimiento fallaba con `AiTurnFailed: toolRoundsExhausted` y, en otra
corrida, narraba la validación interna; tras la corrección responde con el pronóstico real
del lugar heredado.

---

## 8. Comportamientos prohibidos — estado

| Comportamiento prohibido | Estado |
| --- | --- |
| «No tengo acceso a internet» | Eliminado: el servidor prefija la búsqueda real |
| «¿Quieres que investigue?» | Eliminado: se investiga en el mismo turno |
| Terminar en una propuesta sin archivo | Eliminado: `create_artifact` ejecuta y entrega enlace |
| Respuestas vacías | Bloqueadas: `AiTurnFailed::emptyAnswer()` |
| Búsqueda/TTS de pago | No se usan |
| Desactivar SSRF | No se hizo |
| Enviar datos privados/documentos a búsqueda pública | No ocurre: `OUR_DATA_TERMS` suprime el prefetch |

---

## 9. Llamadas externas usadas

Consumo deliberadamente acotado. En esta jornada se realizaron **≈8–10 llamadas de
inferencia** contra proveedores gratuitos (aceptación: clima, investigación, cifras y
archivo; más dos verificaciones del seguimiento tras las correcciones). **Ninguna a modelos
de pago. Groq no se re-sondeó.**

---

## 10. Restricciones y pendientes

- El modelo pequeño del pool (Llama 3.1 8B) es **no determinista**: en una corrida previa a
  las correcciones llegó a narrar un detalle interno. D7/D8 y la regla de estilo lo
  mitigan, pero una redacción pobre ocasional es posible; la sustitución por un modelo
  mayor es una mejora de calidad, no un defecto funcional.
- El *worker* de cola consume código nuevo: un despliegue requiere `php artisan queue:restart`.
- No se tocó la importación histórica #6 ni datos administrativos reales; sin migraciones
  nuevas; sin credenciales ni `.env` en los commits.

---

## Bloque de cierre

```
CONSULTORA_DH_A07_R3_INTELLIGENT_ASSISTANT_FINAL
BASE_SHA=5dc11a7edef75711eafca350ad42ab77b9ed8fb6
FINAL_SHA=677c11d7d81d95d67fc3b4310b3c31815b96d9d0
GIT_SYNC=ok
AUTONOMOUS_WEB_RESEARCH=PASS
WEATHER_REAL=PASS
ADMIN_STATS_REAL=PASS
ARTIFACT_DELIVERED_REAL=PASS
FOLLOWUP_LOCATION_INHERITANCE=PASS
TOOL_CALL_ENVELOPE_SCRUBBED=PASS
SAFE_MARKDOWN=PASS
VOICE_NATURAL=PASS
MOBILE_FIRST_UI=PASS
FREE_ONLY=PASS
PRIVACY_BOUNDARY=PASS
RBAC_AND_WRITE_CONFIRMATION=PASS
SSRF_PINNING_PRESERVED=PASS
IDEMPOTENCY_PRESERVED=PASS
BACKEND_TESTS_PASS=54
FRONTEND_TESTS_PASS=69
ESLINT=PASS
VUE_TSC=PASS
VITE_BUILD=PASS
EXTERNAL_CALLS_USED=~8-10
PAID_PROVIDERS_USED=none
GROQ_RESEARCHED=false
BLOCKERS_REMAINING=none
FINAL_RESULT=PASS
```

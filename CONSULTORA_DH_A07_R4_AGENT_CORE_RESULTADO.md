# CONSULTORA DH — A07-R4: Núcleo de agente autónomo dirigido por el LLM

**Fecha:** 2026-10-17
**Base:** `9c7be9a662df52e2ee57240872a5a04e18efa7a8` (A07-R3, ya desplegado)
**Último commit de código:** `6234ef4`
**Rama:** `main`
**Resultado:** `PARTIAL` — implementado y verificado de forma hermética; los escenarios en vivo dependen de proveedores que hoy están degradados (ver §15).

---

## 1. Objetivo

Convertir al LLM en el **decisor operativo** del asistente: comprender la petición,
descubrir las capacidades disponibles, invocarlas, observar los resultados, iterar y
entregar un resultado real (informe, PDF, cifra, investigación con fuentes). Se
corrigen además los hallazgos de la auditoría independiente sobre orquestación,
privacidad entre turnos, exactitud financiera, adjuntos, PWA y voz.

Esto es **implementación**, no otra auditoría. No se reconstruyó A01–A07, no se tocó
la importación histórica #6, no se restauraron bases, no se cambiaron claves, no se
sustituyó infraestructura, no se usaron modelos de pago ni IA local.

---

## 2. Antes y después

| Aspecto | A07-R3 (antes) | A07-R4 (ahora) |
|---|---|---|
| Quién elige las herramientas | `AiTaskRouter`: tablas de palabras clave → subconjunto cerrado | El **modelo** decide; el router solo **sugiere** un punto de partida |
| Capacidades no previstas | No se ofrecían; el modelo solo podía decir que no | **Descubribles** vía `discover_capabilities` y luego invocables |
| Presupuesto del turno | Se creaba **después** del prefetch externo (la búsqueda quedaba fuera) | Se crea **antes**; el prefetch lo consume y lo respeta |
| Contexto documental + internet | El router forzaba `web=false` | Se combinan documento, internet y generación |
| Privacidad entre turnos | Se clasificaba solo el turno actual | Se clasifica el **payload completo** y se recuerda en la conversación |
| Saldo a una fecha (`asOf`) | Fecha aplicada solo a la antigüedad; saldo **actual** con etiqueta pasada | Reconstrucción **real** a la fecha; limitación honesta si no se puede |
| Adjuntos al restaurar | El widget no los reconstruía | Se reconstruyen desde el servidor |
| Widget → página completa | Abría una conversación nueva | Pasa el id explícito y la página lo autoselecciona (con validación de propiedad) |
| Markdown | Sin tablas; listas planas | Tablas GFM reales y listas anidadas |
| Voz | Pronunciaba asteriscos, pipes y URLs | Representación oral separada; español `es-CO` preferido |

---

## 3. Decisiones del LLM y responsabilidades de Laravel

**El LLM decide:** qué hacer, qué herramientas usar y en qué orden, si necesita
descubrir más capacidades, cómo encadenar rondas y qué redactar.

**Laravel no adivina la intención por listas rígidas.** Conserva, y solo conserva,
responsabilidades deterministas:

- validar el esquema de cada herramienta antes de ejecutarla;
- re-verificar permisos/RBAC en el **momento de la ejecución** (no al construir el catálogo);
- reconocer capacidades técnicas y bloquear operaciones peligrosas;
- imponer tamaños/formatos y presupuestos;
- resolver consultas administrativas contra servicios autoritativos;
- proteger la privacidad.

El `AiTaskRouter` sigue existiendo, pero degradado a **pista**: matiza el conjunto
inicial de herramientas y si conviene prebuscar en la web. **No** impide al modelo
descubrir e invocar cualquier otra capacidad. No se añadió un catálogo nuevo de
cientos de palabras; las pistas son para casos no ambiguos y nunca bloquean.

---

## 4. Catálogo de capacidades y ejecución

Nuevo `App\Services\Ai\AiCapabilityCatalog`: describe semánticamente **todo** el
registry existente —qué hace cada herramienta, cuándo usarla, si lee o escribe, si
responde con datos propios, si sale a internet y qué argumentos toma—. **No ejecuta
nada y no amplía permisos**: es un mapa.

- `summaries($query)` devuelve la forma compacta que lee el modelo (una frase por
  capacidad), con filtro opcional por texto; si el filtro no reconoce nada,
  devuelve el catálogo completo para no ocultar herramientas.
- `discover_capabilities` es una meta-herramienta **sintética**, resuelta por el
  servicio (no por el registry). Su única salida es el catálogo.
- `AiAssistantService::initialToolSet()` ofrece en cada turno: el **núcleo** siempre
  disponible (`web_search`, `web_fetch`, `create_artifact`), las pistas del router y
  el descubrimiento.
- Cuando el modelo llama a `discover_capabilities`, el servicio **añade las
  definiciones encontradas al conjunto ofrecido en la ronda siguiente**; ahí el
  modelo puede invocarlas directamente. Nada se ejecuta por descubrir.

**La ejecución sigue siendo cerrada.** Una llamada solo se ejecuta si el nombre
existe en `AiToolRegistry` y la herramienta autoriza al usuario en ese instante. El
modelo **no puede** inventar una llamada que Laravel ejecute sin pasar por el
registro y la autorización: no hay ruta de la salida del modelo a SQL, a una URL, al
shell ni a una ruta de fichero.

Pruebas herméticas (`tests/Feature/A07/AgentCoreTest.php`): el modelo descubre
`system_stats` (que **no** estaba en el conjunto enrutado), la definición aparece en
la ronda siguiente y la llamada se ejecuta; el descubrimiento por sí solo no crea
propuestas ni artefactos.

---

## 5. Investigación general y citas

Se mantiene y refuerza el camino ya validado en R3, ahora sin el candado del router:

- `web_search` (SearXNG), `web_fetch` con **anti-SSRF + DNS pinning** (`WebFetchSsrfTest`),
  `ResearchTask` y tareas en segundo plano con worker y progreso reales.
- Open-Meteo queda como especialización opcional de clima, no como sustituto de la
  investigación.
- Las fuentes externas viajan como **datos**, nunca como instrucciones (frontera de
  inyección de prompt preservada: `DocumentSearchTest`,
  `AttachmentToAssistantTest`).
- No se inventan citas: si no hay fuentes, se dice que no hay.

**Escenario A (en vivo, PASS).** Petición nueva sin regla especial: *"Investiga y
resúmeme las principales medidas de la reforma pensional en Colombia y cita tus
fuentes."* El modelo eligió y ejecutó `web_search`, sintetizó y citó una fuente real
(`tupensionhoy.com`), con fecha de referencia. No hubo ninguna regla escrita que
dijera "reforma pensional → buscar": lo decidió el modelo a partir de la pista de
router y del catálogo.

**Búsqueda nativa del proveedor:** no se activó. No hay confirmación de una búsqueda
nativa gratuita y verificable en los proveedores disponibles, así que se conserva
SearXNG como investigación general. Se documenta como no usado.

---

## 6. Internet + datos del sistema + generación combinados

El defecto §6 (el router forzaba `web=false` cuando había contexto documental) está
cerrado en `AiTaskRouter`: con contexto documental la tarea sigue siendo
`document`, pero `web` pasa a depender de la pista de actualidad y las herramientas
de documento, internet y generación se ofrecen juntas. Prueba hermética:
*"Lee este documento, investiga si su información sigue vigente y prepara un informe
actualizado"* → `task=document`, `web=true`, con `document_read` y `web_search`
presentes.

Los datos privados nunca se envían a una búsqueda pública: el prefetch externo solo
se dispara cuando la pregunta es sobre el mundo, y las herramientas de web están
disponibles pero no se usan automáticamente sobre datos propios.

---

## 7. Privacidad de la conversación (crítico)

**El defecto.** La elegibilidad de proveedor se decidía con el turno actual. Una
conversación que empezó con la cartera de un cliente podía, en una pregunta pública
posterior, clasificarse como turno web y enviarse —historial incluido— a un proveedor
cuyo plan gratuito puede entrenar con lo recibido.

**La corrección.**

- Migración aditiva: `ai_conversations.contains_client_data` (boolean, default
  `false`, `nullable=false`). No toca la importación histórica.
- `AiAssistantService::classifiesAsClientData()` clasifica el **payload completo que
  realmente se va a enviar**: historial, pregunta, resultados de herramientas y
  documentos, más patrones privados por naturaleza (un email, una secuencia de seis o
  más dígitos). Se ignoran los mensajes de sistema (son nuestros y fijos).
- Una vez verdadera la bandera, **todos** los turnos siguientes van solo a
  proveedores elegibles para datos privados. Además, si una herramienta de datos se
  ejecuta durante un turno que empezó público (p. ej. tras un descubrimiento), la
  conversación queda marcada.
- `AI_ALLOW_TRAINING_PROVIDERS=false` se mantiene sin cambios. Los proveedores
  independientes (Groq, Cloudflare, Gemini, OpenCode Zen) siguen con política
  `training` por defecto.

**Evidencia en vivo (indirecta y fuerte).** Al pedir cifras del sistema (turno de
datos), el turno **no** cayó en silencio a un proveedor `training`: falló con el
error del único proveedor estándar (OpenRouter, cuota diaria agotada) en lugar de
usar Cloudflare/Gemini, que sí estaban disponibles para el turno público A. Es
exactamente el comportamiento exigido: sin proveedor autorizado, se reporta la
limitación sin filtrar datos.

**Evidencia hermética.** `AgentCoreTest`: una pregunta general limpia no marca la
conversación; un email en el payload sí la marca; un turno de datos la marca y la
marca sobrevive a un seguimiento público.

---

## 8. Exactitud financiera `asOf` (§4.3)

**El defecto.** `clientBalanceCounts($asOf)` aplicaba la fecha solo a los tramos de
antigüedad y devolvía los saldos de **hoy** con la etiqueta de una fecha pasada.

**La corrección.** `obligationsQuery()` acepta ahora `?Carbon $asOf`:

- **Sin fecha**: comportamiento actual idéntico (todo pago no revertido ni anulado
  cuenta), así que dashboard y cartera no cambian.
- **Con fecha explícita**: reconstrucción real — una obligación cuenta desde su
  `generated_at`, un ajuste desde su `created_at` (una reversión es una fila con
  delta negativa, así que sumar solo lo anterior a la fecha es exacto), y una
  asignación de pago solo si fue creada, recibida y no revertida/anulada a esa fecha.
- `SystemStatsTool` informa la **base** (`historical` o actual) para que el modelo no
  presente una cifra pasada como la de hoy.
- Si no hay historia suficiente, la vía es declarar la limitación concreta, nunca una
  cifra falsa. No se alteró ningún dato del libro.

Prueba hermética (`AgentCoreTest`): se crea una obligación con un pago que existía a
la fecha de corte y otro posterior que la salda. A la fecha el cliente **seguía con
deuda**; hoy **no**. Los conteos difieren y la base se etiqueta correctamente.

---

## 9. Generación de documentos y adjuntos

- Se mantiene el camino ya real: el LLM investiga/estructura y el backend produce
  bytes con `CreateArtifactTool` / `GenerateDocumentTask` / `AiArtifact` /
  Dompdf / PhpWord / PhpSpreadsheet en almacenamiento privado. La estructura del LLM
  se valida y renderiza de forma segura (sin HTML/CSS/JS/rutas arbitrarias, sin
  ejecución de código).
- Generar un archivo privado **no** requiere confirmación redundante; las mutaciones
  de negocio conservan confirmación + idempotencia (`AiAssistantRuntimeTest`).
- El resultado es un archivo descargable real enlazado a la conversación, no un 202
  ni una propuesta.
- Verificación hermética: `AssistantTaskKindsTest` produce un archivo real
  descargable en todos los formatos aprobados y rechaza uno no aprobado antes de
  crear nada; `AiWebArtifactTest` verifica el registro y el permiso de
  `create_artifact`.

---

## 10. Persistencia y navegación widget ↔ página completa

- `GET /api/ai/conversations/{id}` devuelve ahora **adjuntos** además de mensajes y
  artefactos (`AiAssistantController::attachmentsFor`).
- `FloatingAssistant.restoreConversation()` reconstruye los adjuntos desde el
  servidor, con su estado de procesamiento; un hilo al que se le subió una hoja de
  cálculo ya no vuelve vacío tras recargar.
- El enlace "abrir en la página del asistente" lleva el id de la conversación
  activa; la página completa lo lee de la query, lo **valida** (el endpoint ya
  responde 403 para hilos ajenos) y **autoselecciona** el mismo hilo, sin duplicar
  conversaciones.
- La página muestra los adjuntos con estado dentro del hilo, junto a los archivos
  generados y las descargas.

Pruebas: `AttachmentToAssistantTest` (la conversación devuelve sus adjuntos; los de
otra persona no se entregan) y las pruebas de página en frontend.

---

## 11. PWA móvil, Markdown y voz

**Móvil primero.** El panel de tareas salió de la parte superior: en móvil lo primero
es el chat. La lista de conversaciones es un **drawer** que se abre a demanda y se
cierra al seleccionar. El compositor queda **sticky** al pie con `safe-area`, y
`.cdh-ai` oculta el desbordamiento horizontal. En escritorio, la barra lateral es una
columna permanente y el toggle de drawer se oculta.

**Markdown y tablas.** El renderer ahora reconoce tablas GFM (fila de encabezado +
separador) y las emite como `<table>` real, con estilo que se desplaza dentro del
mensaje y no ensancha la transcripción en un teléfono de 360 px. Las listas anidadas
se escriben dentro del `<li>` padre. Se conserva el escape-antes-de-decorar (sin
`v-html` peligroso, sin `javascript:`).

**Voz natural.** Llega entero el texto oral separado (`stripMarkdown`): una tabla se
convierte en frases *"Encabezado: valor."* en vez de pipes, una URL larga se reduce a
su host, y las entidades HTML y símbolos decorativos no se pronuncian. La síntesis
prefiere `es-CO` aunque el navegador enumere otra variante de español primero; el
idioma del enunciado sigue siendo `es-CO` y se mantienen
reproducir/pausar/reanudar/detener. Sin TTS de pago.

Pruebas: `tests/frontend/markdown.spec.ts` (tablas renderizadas y habladas, listas
anidadas, URL/entidades), `tests/frontend/voice.spec.ts` (texto exacto del enunciado
de una tabla y preferencia `es-CO`).

---

## 12. Presupuesto, recuperación y failover

- `AiTurnBudget` se crea **al inicio** del turno y se comparte con el prefetch
  externo y todas las rondas del proveedor (cierra §3.6). El prefetch no búsqueda si
  el turno ya agotó el tiempo.
- Se mantienen límites de intentos, tiempo total, rondas máximas, salud de
  proveedor, cuotas, rate limits y failover (`AiErrorScopeAndFailoverTest`).
- El trabajo largo sigue en `AiTask` con worker y progreso reales; no hay fondo
  fingido.
- La ejecución se recupera tras cerrar la PWA porque el estado vive en el servidor.

---

## 13. Pruebas dirigidas (sin suite global)

Solo pruebas herméticas y dirigidas; sin proveedores reales (claves de `.env`
aisladas, `Http::preventStrayRequests`), sin restaurar bases, sin borrar datos, sin
Playwright general, sin repetir las 225 pruebas de A07.

**Backend (dirigidas):**
- `AgentCoreTest` (nuevo): 7 pruebas — descubrimiento, no-ejecución por descubrir,
  privacidad entre turnos, documento+internet, exactitud as-of.
- `AttachmentToAssistantTest`: 11 (incluye las dos nuevas de adjuntos en `show`).
- `AiAssistantRuntimeTest`: 12 · `DocumentSearchTest`: 10 · `AiAssistantTest`: 3 ·
  `AiWebArtifactTest`: 3 · `WebSearchToolTest`: 5 · `AiIntentRoutingTest`: 10 ·
  `AiErrorScopeAndFailoverTest` + `AiReplySanitizerTest` + `AssistantTaskKindsTest`: 31.
- Total dirigidas ejecutadas ≈ **92**. Resultado: **todas PASS**.

**Frontend (dirigidas):**
- `markdown.spec.ts`: 18 · `voice.spec.ts`: 16 · `asistente.page.spec.ts`: 11 ·
  `attachment-picker`, `components`, `pwa`: incluidas (lote de 54). Todas PASS.
- `eslint` limpio · `vue-tsc --noEmit` limpio · `vite build` OK.

---

## 14. Escenarios reales y llamadas externas

Presupuesto objetivo ~8–12 inferencias externas. Se hicieron **~13** en total,
incluyendo dos sondas de diagnóstico de proveedor (necesarias para no atribuir al
código una caída del entorno).

| Escenario | Resultado | Evidencia |
|---|---|---|
| **A** investigación nueva, sin regla especial | **PASS (en vivo)** | Respuesta real con fuente citada (`tupensionhoy.com`); `web_search` ejecutado por decisión del modelo |
| **B** investigación + PDF, archivo real + descarga | **BLOCKED (entorno)** | La generación real está probada en hermético (`AssistantTaskKindsTest`); en vivo el turno no cerró por fallos de proveedor (§15) |
| **C** datos administrativos, sin valores inventados | **BLOCKED (entorno)** | Ningún proveedor elegible para datos privados (OpenRouter en cuota; los `training` se excluyen correctamente). Exactitud probada en hermético |
| **D** adjunto TXT/CSV vs información externa actual | **BLOCKED (entorno)** | Mismo motivo; la combinación documento+web está probada en hermético |
| **E** PWA + voz (360/390/430), adjuntos, controles, widget→página, Markdown tabla, texto exacto del TTS | **PASS (hermético)** | Pruebas frontend + build; sin navegador real (Playwright general prohibido) |
| **F** privacidad: turno 1 privado, turno 2 público | **PASS (hermético)** | `AgentCoreTest`; en vivo, el turno privado no cayó a un proveedor `training` |

No se usó información personal real en pruebas externas: las preguntas fueron temas
públicos (reforma pensional, precio del café).

---

## 15. Limitaciones y bloqueos (honesto)

El entorno de proveedores está **degradado hoy**, no por este cambio:

- **OpenRouter**: `429 free-models-per-day` (cuota diaria de la cuenta agotada). Todos
  los candidatos `standard` (los únicos elegibles para datos privados) están
  indisponibles.
- **Groq**: `401/403 Access denied` (bloqueo de red/geo) → endpoint en cuarentena.
- **Cloudflare**: intermitente; un `502` con respuesta vacía y algún `400` enfriaron
  el modelo.
- **Gemini**: respondió un turno web y luego devolvió `400 malformed` en el
  seguimiento (comportamiento de su endpoint compatible con OpenAI, preexistente).

Consecuencia: los escenarios que requieren proveedor **estándar** (datos privados: C,
D, F) o que necesitan varias rondas estables (B) no pudieron cerrarse en vivo. El
propio fallo es evidencia de que la puerta de privacidad funciona: no hubo
infiltración silenciosa a un proveedor `training`.

Otras limitaciones honestas:

- **Calidad de redacción**: el modelo pequeño (Llama 3.1 8B en Cloudflare) alucinó
  una vez que `discover_capabilities` "no estaba disponible" aunque sí se le ofreció.
  Es un problema de redacción del modelo, no funcional (la respuesta real y la cita
  salieron igualmente). Sustituir por un modelo mayor es mejora de calidad.
- **Sin corrida de navegador real**: la verificación móvil/voz se hizo con pruebas de
  componente y build, no con un recorrido en navegador (prohibido el Playwright
  general en esta tarea).
- **Investigación iterativa**: el pipeline (buscar→analizar→traer→re-buscar→contrastar)
  existe y está cubierto, pero no se pudo demostrar en vivo una iteración
  multi-fuente completa por la cuota.
- **Búsqueda nativa de proveedor**: no usada (no hay opción gratuita verificada).

---

## 16. Commits y sincronización

```
706ae89  feat(ai): modelo como decisor y catálogo de capacidades (A07-R4 HITO 1)
3ef0587  fix(ai): privacidad entre turnos y saldo histórico real (A07-R4 HITO 2)
c2b1627  feat(ai): adjuntos en el hilo y salto widget → página completa (A07-R4 HITO 3)
6234ef4  feat(ai): tablas y listas anidadas en Markdown, y voz natural (A07-R4 HITO 4)
```

No se incluyen secretos ni `.env`. No se modificaron datos administrativos reales. El
informe de cierre se publica en un commit de documentación posterior al último commit
de código.

---

## Bloque de cierre

```
CONSULTORA_DH_A07_R4_AGENT_CORE_RESULTADO
BASE_SHA=9c7be9a662df52e2ee57240872a5a04e18efa7a8
FINAL_SHA=6234ef4dfdb4f345f9aea3ec7183bfd80a8dfbb1
GIT_SYNC=ok
LLM_DIRECTED_ORCHESTRATION=PASS
CAPABILITY_DISCOVERY=PASS
GENERAL_TOOL_CALLING=PASS
MULTISTEP_EXECUTION=PASS
GENERAL_WEB_RESEARCH=PASS
ITERATIVE_RESEARCH=PARTIAL
WEB_FETCH_AND_CITATIONS=PASS
NATIVE_PROVIDER_SEARCH=NOT_USED
INTERNAL_SYSTEM_TOOLS=PASS
COMBINED_WEB_AND_SYSTEM=PASS
COMBINED_DOCUMENT_AND_WEB=PASS
FINANCIAL_AS_OF_ACCURACY=PASS
CONVERSATION_PRIVACY=PASS
PROMPT_INJECTION_PROTECTION=PASS
RBAC_AND_WRITE_CONFIRMATION=PASS
DOCUMENT_GENERATION=PASS
REAL_PDF_DOWNLOAD=PASS
CONVERSATION_ARTIFACTS=PASS
ATTACHMENTS_PERSISTENCE=PASS
WIDGET_TO_FULL_PAGE=PASS
MOBILE_FIRST_PWA=PARTIAL
MARKDOWN_TABLES=PASS
NATURAL_TTS=PASS
GLOBAL_EXECUTION_BUDGET=PASS
PROVIDER_FAILOVER_PRESERVED=PASS
FREE_ONLY=PASS
TARGETED_TESTS=PASS
REAL_BROWSER_SCENARIOS=NOT_RUN
EXTERNAL_LLM_REQUESTS=~13
BLOCKERS_REMAINING=openrouter_free_daily_quota_exhausted,groq_geo_blocked,cloudflare_gemini_intermittent,live_private_and_pdf_scenarios_blocked
FINAL_RESULT=PARTIAL
```

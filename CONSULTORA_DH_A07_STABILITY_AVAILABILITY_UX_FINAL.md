# CONSULTORA DH — A07 FINAL: Estabilidad, disponibilidad y UX

**Fecha:** 2026-10-19 (America/Bogota)
**Rama:** `main`
**BASE_SHA:** `c193bf04916bf531de67a2901e1f3726bdc848bc`
**FINAL_CODE_SHA:** `d8bf649baa76ad45697b803d80c55b3b1bd4371a` (commit de código + tests + informe)
**ORIGIN_MAIN_TIP:** `git rev-parse origin/main` — referenciado así porque el propio informe se añade por encima del código; `HEAD == origin/main` en el momento de la entrega
**GIT_SYNC:** `PASS` (fast-forward, sin `--force`)
**Ámbito:** alta disponibilidad real (supervisor determinista + failover transparente multiproveedor), disponibilidad/estado por capacidad, fixes de calidad documental, gestión de conversaciones, arreglos de voz y ajustes de UX del asistente.

> Este informe consolida una única sesión integral. Implementa, prueba y documenta; no se detiene en auditoría. No se inventan credenciales, proveedores ni resultados: los puntos no verificables se marcan explícitamente.

---

## 1. Resumen ejecutivo

| Bloque | Estado |
|---|---|
| Supervisor determinista anti-bucle de herramientas | **PASS** (probado en unit + integrado en el turno) |
| Failover transparente multiproveedor (mismo turno, sin re-prompt) | **PASS** (pre-existente, extendido; suite A07 completa) |
| Disponibilidad/estado por capacidad (`/api/ai/status`) | **PASS** (12 tests nuevos + endpoint) |
| Calidad/nombres de documentos generados | **PASS** (7 tests de nombre + calidad documental en suite) |
| Gestión de conversaciones (título, renombrar, eliminar) | **PASS** (16 tests backend + 7 frontend) |
| Voz (pausa diferida, `pausable`, normalización de lectura) | **PASS** (voice 18, speech 8) |
| Borradores persistidos (scoping por usuario/hilo, limpieza en logout) | **PASS** (frontend) |
| Verificación LIVE contra proveedor real | **NO EJECUTADA** — bloqueada externamente (ver §6) |
| Solicitudes de pago | **0** |

---

## 2. Cambios implementados

### 2.1 Supervisor de turno determinista — `app/Services/Ai/AiTurnSupervisor.php`

Cierra el defecto del turno manual que registró `client_search` + `client_summary`, cuatro rondas y una respuesta vacía. Es **código con contadores, no un segundo modelo** (no consume cuota):

- Cuenta rondas consecutivas que no produjeron nada nuevo (repetición, error, o descubrimiento de capacidades ya ofrecidas).
- Tras **dos rondas improductivas seguidas**, la siguiente llamada al proveedor se hace **sin herramientas ofrecidas** (síntesis acotada): el modelo debe responder con la evidencia que ya tiene.
- Una ronda productiva resetea la racha; una propuesta de escritura siempre resetea (el turno termina ahí).

Integrado en `AiAssistantService::processResult`: se instancia un supervisor por turno, se registra cada ronda y se calcula `$nextTools` (`[]` cuando toca sintetizar), dejando intactos mensajes, pasajes recuperados y resultados de herramientas.

### 2.2 Failover y resolución multiproveedor — `AiProviderResolver`

- Se renombró el método privado `blocked()` a `isBlocked()` y se expusieron tres métodos públicos que la reportería de disponibilidad necesita para preguntar **al mismo pool que responde**: `candidatesFor(array $required)`, `blocked(ModelCandidate)` y `blockedFor(ModelCandidate)` (segundos restantes; 0 = bloqueo sin temporizador).
- Se preserva la política ya aprobada: failover secuencial en el mismo turno, sin re-prompt, sin escrituras/artefactos/propuestas duplicados; los rechazos de política son terminales; la privacidad manda (un proveedor de free tier que puede entrenar con el contenido no recibe datos privados).

### 2.3 Disponibilidad por capacidad — `app/Services/Ai/AiAvailability.php` (nuevo)

El estado único (`available`) no podía responder "¿puede leer mi hoja de cálculo?", que exige un proveedor con herramientas **y** autorizado para datos privados. El nuevo servicio reporta, por operación:

`general_chat`, `private_chat`, `document_read`, `document_generation`, `internal_data`, `web_research`, `task_proposals`, `tool_synthesis`.

- Deriva la elegibilidad de capacidades reales (`chat`, `tools`, `client_data`) desde el pool.
- Cuenta **proveedores independientes** (por host/endpoint), no modelos: dos modelos tras una misma credencial son un proveedor, no dos oportunidades.
- Estados honestos: `available` (proven + >1 proveedor independiente), `degraded` (un solo proveedor independiente), `unverified` (configurado, nada ha respondido), `quota_limited`, `unavailable`, `blocked` (p. ej. búsqueda web desactivada), `unconfigured`.
- Nunca expone claves, URLs base, prompts ni registros.

**Endpoint** `GET /api/ai/status` extendido de forma aditiva (no rompe clientes): añade `capabilities` (mapa por operación) e `independent_providers`. El controlador inyecta `AiAvailability`.

**Frontend:** `resources/js/services/ai.ts` gana `AiCapabilityAvailability` y campos nuevos; `Index.vue` y `FloatingAssistant.vue` usan `capabilities` para el texto de disponibilidad y el aviso, eliminando el "Disponibilidad sin comprobar" permanente.

### 2.4 Calidad documental — `app/Services/Ai/Tools/CreateArtifactTool.php`

- Nombre legible y seguro (`humanFilename()`): preserva letras Unicode (á, é, í, ó, ú, ü, ñ), una sola extensión (no `informe.pdf.pdf`), elimina separadores de ruta, caracteres de control, conjunto reservado de Windows y punto/espacio finales; acota la raíz a 150 caracteres.
- La lectura cruda del nombre usa un tope generoso (anti-abuso) y luego se **trunca** a un nombre usable en vez de fallar la llamada.
- `execute()` usa directamente el nombre validado (el path es un UUID: sin riesgo de traversal).
- Se preservan las mejoras previas de PDF/DOCX/XLSX/CSV (tablas reales, títulos sin duplicar, neutralización de fórmulas).

### 2.5 Gestión de conversaciones — `app/Services/Ai/AiConversationManager.php` (nuevo)

- **Título determinista** desde la primera pregunta (sin llamada a proveedor), capitalizado, con redacción de email/identificadores numéricos y tope de 80 caracteres; un título elegido por la persona **nunca** se sobrescribe.
- **Renombrar**: limpia caracteres de control, colapsa espacios, acota longitud; vacío → `422`.
- **Eliminar**: borra el material privado del hilo (mensajes, propuestas, adjuntos por cascada; y **explícitamente** los archivos generados: bytes en disco + filas). **Nunca** borra tareas operativas, obligaciones ni clientes (los `ai_tasks` sobreviven con `ai_conversation_id = null`).
- Controlador: `rename()` y `destroy()` con verificación de propiedad (un id adivinado → `403`). Rutas `PATCH`/`DELETE /api/ai/conversations/{conversation}`. `store()`/`messages()` derivan título y hacen `touch()`; la lista ordena por actividad reciente; `show()` devuelve `title`.

### 2.6 Voz — `useVoice.ts`, `SpeakButton.vue`, `utils/speech.ts` (nuevo)

- **Pausa diferida**: `speechSynthesis` ignora `pause()` sobre una utterance que aún no empezó; ahora la intención (`pauseRequested`) se recuerda y se aplica en `onstart`, con lo que una pulsación inmediata sí detiene la primera palabra.
- **`pausable`**: expuesto en `VoicePlayback`; si el navegador no implementa `pause()`, el control se oculta en vez de aparentar funcionar.
- **Normalización de lectura** (`normalizeForSpeech`): fechas ISO y con barras → "19 de octubre de 2026"; montos marcados (`15.000 COP`, `$ 15.000`) → "quince mil pesos colombianos". Los números sueltos **no** se tocan.

### 2.7 UX acumulada del asistente

- **Borradores** (`utils/drafts.ts`, nuevo): `sessionStorage` con clave por usuario + hilo; sobrevive F5, desaparece al cerrar la pestaña, y se limpia en `auth.clear()` (logout). Integrado en `Index.vue` y `FloatingAssistant.vue`.
- **Micrófono en el widget flotante**: reutiliza `useVoice` (no un segundo motor de dictado); el texto reconocido se añade al compositor, nunca se envía solo.
- **Scroll anclado**: un turno nuevo no arrastra a quien está leyendo más arriba.
- **Renombrar/eliminar inline** en la lista de conversaciones.

---

## 3. Estado real de proveedores (observado, sin llamadas de pago)

`php artisan ai:models` (catálogo cacheado ~3 h):

| prio | proveedor | modelo | tools | estado |
|---|---|---|---|---|
| 2 | groq | openai/gpt-oss-120b | sí | degraded (última latencia 1162 ms) |
| 3 | groq | openai/gpt-oss-20b | sí | unchecked |
| 3 | cloudflare | @cf/meta/llama-3.1-8b-instruct-fp8 | sí | unchecked |
| 4 | gemini | gemini-3.5-flash-lite | sí | unchecked |
| 5 | gemini | gemini-3.1-flash-lite | sí | unchecked |
| 200-204 | openrouter | 5 modelos `:free` | sí | quota_limited |
| 5,6,10,20,90 | openrouter | 5 modelos `:free` | sí | quota_limited |

**Proveedores independientes observados:** groq, cloudflare, gemini (3). OpenRouter = un solo proveedor (cuota de cuenta agotada). **No hay un quinto proveedor** (ni Mistral ni otro) configurado.

Disponibilidad real por operación (calculada en el contenedor de desarrollo, sin red):

```text
general_chat        state=unverified     avail=N elig=15 ready=5  providers=3
private_chat        state=quota_limited  avail=N elig=10 ready=0  providers=0
document_read       state=quota_limited  avail=N elig=10 ready=0  providers=0
document_generation state=quota_limited  avail=N elig=10 ready=0  providers=0
internal_data       state=quota_limited  avail=N elig=10 ready=0  providers=0
web_research        state=unverified     avail=N elig=15 ready=5  providers=3
task_proposals      state=quota_limited  avail=N elig=10 ready=0  providers=0
tool_synthesis      state=unverified     avail=N elig=15 ready=5  providers=3
independent_private_providers=0
```

Lectura honesta: en este momento **ningún proveedor autorizado para datos privados está listo** (todos en espera/cuota), por eso las operaciones privadas se reportan `quota_limited` y `independent_private_providers=0`. Con `general_chat`/`web_research`/`tool_synthesis` hay 3 proveedores independientes configurados, aún `unverified` hasta que una consulta real responda.

---

## 4. Tests ejecutados

### Backend (contenedor `consultora-dh-app`, aislamiento de test DB/Redis)

| Suite | Resultado |
|---|---|
| `tests/Feature/A07` + `tests/Unit/A07` (suite completa) | **477 passed** (1667 assertions) |

Nuevos/actualizados en esta sesión:

| Archivo | Tests | Resultado |
|---|---|---|
| `tests/Feature/A07/AiAvailabilityTest.php` (nuevo) | 12 | ✅ |
| `tests/Feature/A07/AiConversationManagementTest.php` (nuevo) | 16 | ✅ |
| `tests/Feature/A07/ArtifactFilenameTest.php` (nuevo) | 7 | ✅ |
| `tests/Unit/A07/AiTurnSupervisorTest.php` (nuevo) | 6 | ✅ |

### Frontend (Vitest)

| Suite | Resultado |
|---|---|
| `npx vitest run` (suite completa, 37 archivos) | **387 passed** |
| `conversation-management.spec.ts` (nuevo) | 7 ✅ |
| `speech.spec.ts` (nuevo) | 8 ✅ |
| `voice.spec.ts` (ampliado: pausa antes de empezar, `pausable`) | 18 ✅ |
| `asistente.page.spec.ts` + `assistant-artifacts.spec.ts` | 19 ✅ |

**EXTERNAL_LLM_REQUESTS (de pago): 0.** Las suites de A07 son herméticas: `phpunit.xml` vacía credenciales y el `TestCase` rechaza cualquier petición no simulada.

---

## 5. Seguridad y datos preservados

- No se alteró ninguna decisión de seguridad previa: RBAC, privacidad, `AI_ALLOW_TRAINING_PROVIDERS=false`, confirmación de propuestas, `TaskAssigneeGuard`, idempotencia, SSRF/DNS pinning, aislamiento de DB/Redis de test.
- No se ejecutó `git reset --hard`, `git clean -fd`, `git push --force`, `docker compose down -v`, `Redis FLUSHALL` ni `migrate:fresh`. Sin `--force`.
- ~325 clientes históricos y 15 empresas intactos: la eliminación de una conversación **no** toca clientes, tareas ni obligaciones (probado).
- `.env` intacto y sin secretos en el informe.

---

## 6. Bloqueos externos (declarados, no resueltos)

1. **Verificación LIVE no ejecutada.** No se realizó un smoke real contra un proveedor en esta sesión. Motivo: los proveedores privados están `quota_limited` y una prueba viva consumiría cuota real sin autorización explícita para una corrida aislada. Se mantiene la distinción `VERIFIED_HERMETIC_ONLY` ≠ `VERIFIED_LIVE`.
2. **Sin credenciales nuevas.** No hay un quinto proveedor (Mistral/Cerebras/otro) configurado; no se inventan claves ni proveedores.
3. **OpenRouter agotado a nivel de cuenta** (evidencia histórica y actual): sus 10 modelos figuran `quota_limited`; no cuentan como oportunidades independientes.

---

## 7. Bloque de cierre

```text
CONSULTORA_DH_A07_STABILITY_AVAILABILITY_UX_FINAL

BASE_SHA=c193bf04916bf531de67a2901e1f3726bdc848bc
FINAL_CODE_SHA=d8bf649baa76ad45697b803d80c55b3b1bd4371a
ORIGIN_MAIN_TIP=git_rev_parse_origin_main (HEAD == origin/main al entregar)
GIT_SYNC=PASS
BRANCH=main

TURN_SUPERVISOR=IMPLEMENTED+TESTED (deterministic, no extra model)
MULTIPROVIDER_FAILOVER=PRESERVED+EXTENDED (same turn, no re-prompt)
AVAILABILITY_BY_CAPABILITY=IMPLEMENTED+TESTED (8 operations, endpoint additive)
DOCUMENT_FILENAME_QUALITY=IMPLEMENTED+TESTED
CONVERSATION_MANAGEMENT=IMPLEMENTED+TESTED (title/rename/delete, owner-scoped)
VOICE_PAUSE_AND_NORMALIZATION=IMPLEMENTED+TESTED
DRAFTS_AND_WIDGET_MIC=IMPLEMENTED+TESTED

BACKEND_A07_TESTS=477_PASS (1667 assertions)
FRONTEND_TESTS=387_PASS (37 files)
NEW_BACKEND_TESTS=41 (availability 12, conversations 16, filenames 7, supervisor 6)
NEW_FRONTEND_TESTS=15 (conversation-management 7, speech 8)

INDEPENDENT_PROVIDERS_CONFIGURED=3 (groq, cloudflare, gemini)
INDEPENDENT_PRIVATE_PROVIDERS_READY=0
PRIVATE_OPS_STATE=quota_limited
OPENROUTER_MODELS=quota_limited (account-level)

EXTERNAL_LLM_REQUESTS=0
VERIFIED_HERMETIC_ONLY=YES
VERIFIED_LIVE=NO
SECURITY_DECISIONS_PRESERVED=YES
DESTRUCTIVE_GIT_OR_DATA_COMMANDS=NONE

FINAL_RESULT=PARTIAL
```

**FINAL_RESULT = PARTIAL.** Todo el alcance de código de A07 FINAL está implementado, probado en hermético y documentado; la suite A07 completa (477) y la de frontend (387) pasan. Queda como `PARTIAL` únicamente porque **no hay verificación LIVE** contra un proveedor real: los proveedores autorizados para datos privados están hoy en cuota y una corrida viva no fue autorizada. Se declara explícitamente en vez de promover evidencia hermética a LIVE.

---

## 8. Entregables

- **Código de producción nuevo:** `AiAvailability.php`, `AiConversationManager.php`, `AiTurnSupervisor.php`, `resources/js/utils/drafts.ts`, `resources/js/utils/speech.ts`.
- **Código modificado:** `AiAssistantService.php`, `AiProviderResolver.php`, `CreateArtifactTool.php`, `AiAssistantController.php`, `routes/api.php`, `services/ai.ts`, `Asistente/Index.vue`, `FloatingAssistant.vue`, `SpeakButton.vue`, `useVoice.ts`, `stores/auth.ts`.
- **Tests nuevos:** los 4 backend y 2 frontend listados en §4.
- **Informe:** este documento.
- Nota: el árbol de trabajo arrastraba además normalizaciones de formato (espaciado/imports) de sesiones previas, incluidas en el commit para dejar el árbol limpio; los informes locales previos (untracked) se preservan sin tocar.

# CONSULTORA DH — MULTIPROVEEDOR IA POST-R2

Resultado de la extensión del enrutamiento multimodelo de R2 para incorporar
proveedores gratuitos independientes, de forma que la asistente no dependa solo
de la cuota compartida de OpenRouter.

---

## 1. SHA inicial y final

- **SHA inicial (`BASE_SHA`)**: `17e53115a0ae2cd8a7842a92a6128182e5d6325b`
  (HEAD de `main` == `origin/main` al comenzar, tras publicar R2).
- **SHA final (`FINAL_SHA`)**: `071be35781ea3e5a81562a10555e98d26fb5e220`
  (commit funcional: todo el código de esta entrega, más este informe).
- **Push**: a `origin/main`, sin `--force`.

---

## 2. Funcionalidades de R2 reutilizadas

No se duplicó ni reemplazó nada de la arquitectura de R2. La extensión es
aditiva y se apoya en lo ya existente:

- **Pool (`ModelPool`)** y **adaptador (`OpenAiCompatibleProvider`)**: los nuevos
  proveedores se insertan como *candidatos* (`ModelCandidate`) con su propio
  `base_url` y `api_key`, exactamente igual que los candidatos de OpenRouter.
- **Salud adaptativa (`AiModelHealth`)** con clave por (endpoint, modelo): una
  cuota agotada en un proveedor no toca a los demás; la salud y el enfriamiento
  progresivo ya distinguen transporte, 429, 5xx, respuesta vacía, modelo
  inexistente, parámetro no soportado y credencial inválida.
- **Resolución (`AiProviderResolver`)**: orden por salud, presupuesto de turno
  completo compartido entre rondas, failover entre candidatos sin perder
  contexto, diagnóstico seguro por intento.
- **Clasificación de tareas (`AiTaskRouter`)** y **subconjunto de herramientas**
  por turno, con RBAC intacto.
- **Widget flotante y conversaciones**: sin cambios; siguen usando los mismos
  endpoints A07. Se conservan `active_model` (clave JSON sin cambiar), las
  conversaciones, los adjuntos y las herramientas.
- **Diagnóstico de estado**: el estado sigue mostrando el proveedor/modelo que
  respondió realmente; los nuevos proveedores aparecen con etiqueta legible
  (`groq`, `cloudflare`, `gemini`, `opencode_zen`).

---

## 3. Proveedores incorporados (soporte de integración)

Soporte implementado y activable por variables de entorno, con clave
independiente y clave separada por proveedor:

| Proveedor | Endpoint | Variables principales | Estado |
|---|---|---|---|
| GroqCloud | `https://api.groq.com/openai/v1` | `GROQ_API_KEY`, `GROQ_MODELS`, `GROQ_PRIORITY`, `GROQ_DATA_POLICY`, `GROQ_RPM/RPD` | Soporte listo, sin credencial |
| Cloudflare Workers AI | `https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1` | `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_AI_API_TOKEN`, `CLOUDFLARE_AI_MODELS`, … | Soporte listo, sin credencial |
| Google Gemini Developer API | `https://generativelanguage.googleapis.com/v1beta/openai` | `GEMINI_API_KEY`, `GEMINI_MODELS`, `GEMINI_DATA_POLICY`, … | Soporte listo, sin credencial |
| OpenCode Zen (opcional) | `https://opencode.ai/zen/v1` | `OPENCODE_ZEN_API_KEY`, `OPENCODE_ZEN_MODELS`, `OPENCODE_ZEN_ENABLED` | **DESACTIVADO por defecto**; sin credencial |

Previsiones específicas por proveedor:

- **Cloudflare**: el `account_id` va en la URL, no en la credencial; la plantilla
  `{account_id}` se sustituye desde configuración. El mensaje de error de
  Cloudflare (`errors[0].message`) se extrae para el diagnóstico.
- **Groq / Gemini / Cloudflare**: un `429` se considera **de cuenta** (la cuota
  gratuita pertenece a la cuenta, no a un modelo), por lo que no se malgastan
  reintentos cambiando de modelo dentro del mismo proveedor.
- **Interpretación de cuota**: se leen `Retry-After` (segundos o fecha HTTP),
  `x-ratelimit-reset-requests` y `x-ratelimit-reset-tokens` (duración estilo Go:
  `2.4s`, `1m30s`, `200ms`). Se toma el mayor valor presente; un valor ilegible
  se ignora en lugar de adivinarse.
- **OpenCode Zen**: nunca se conduce a través del CLI de OpenCode ni de su
  sesión interactiva; queda desactivado si requiriese facturación o tarjeta.

---

## 4. Proveedores realmente activos

- **OpenRouter** (el de R2): **activo y verificado en esta sesión** (ver §8).
- **Groq, Cloudflare, Gemini, OpenCode Zen**: **no activos**. No existe ninguna
  credencial para ellos en `.env` (se comprobó la presencia de nombres, nunca
  valores). Sin `api_key` y sin `*_MODELS`, un proveedor **no aporta ningún
  candidato**, de modo que el pool no anuncia un proveedor que no puede llamar.
  Por tanto **no se declara ninguno como operativo**.

---

## 5. Proveedores pendientes de credenciales

Todos los anteriores están pendientes de credencial legítima del propietario.
Para activarlos, definir en `.env` (documentado en `.env.example`):

```dotenv
# GroqCloud
GROQ_API_KEY=<clave legítima>
GROQ_MODELS=llama-3.3-70b-versatile,llama-3.1-8b-instant   # solo modelo de plan gratuito

# Cloudflare Workers AI
CLOUDFLARE_ACCOUNT_ID=<id de cuenta>
CLOUDFLARE_AI_API_TOKEN=<token>
CLOUDFLARE_AI_MODELS=@cf/meta/llama-3.1-8b-instruct       # solo plan gratuito (neuronas)

# Google Gemini Developer API
GEMINI_API_KEY=<clave>
GEMINI_MODELS=gemini-2.5-flash,gemini-2.0-flash            # solo elegibles en nivel gratuito

# OpenCode Zen (opcional; dejar vacío si requiere pago)
OPENCODE_ZEN_ENABLED=true
OPENCODE_ZEN_API_KEY=<clave independiente legítima>
OPENCODE_ZEN_MODELS=<modelo gratuito confirmado>
```

> **Nada de esto se ha declarado funcionando.** La lista de modelos de cada
> `*_MODELS` es la única barrera de "solo gratuito" para un endpoint
> independiente (la aplicación no puede leer sus listas de precios). Debe
> verificarse contra el catálogo vigente del proveedor antes de habilitarlo.

---

## 6. Modelos gratuitos comprobados

- **Comprobados en vivo (con credencial existente, OpenRouter):**
  `nvidia/nemotron-3.5-super-vl:free` (respondió a la consulta general y emitió
  la llamada a herramienta). Otros candidatos gratuitos del pool de R2 siguen
  presentes (`nvidia/nemotron-3-super-120b-a12b:free`,
  `nvidia/nemotron-3.5-lightning:free`, etc.).
- **Documentados como ejemplo (NO comprobados, sin credencial):** las listas
  de ejemplo comentadas en `.env.example` para Groq, Cloudflare y Gemini. Se
  marcan explícitamente como "verificar contra el catálogo del proveedor" y
  **no se habilitan por defecto**. No se declaran como comprobados.

---

## 7. Cuotas y limitaciones conocidas

- **OpenRouter**: cuota compartida del nivel gratuito; el pool de R2 ya la
  gestiona con salud adaptativa y failover. Se añade la posibilidad de fijar
  `AI_OPENROUTER_RPM`/`AI_OPENROUTER_RPD` (por defecto `0` = sin tope local).
- **Groq** (RPM/RPD/TPM/TPD): se interpretan las cabeceras del proveedor en el
  `429`. El tope de **peticiones** por minuto/día puede fijarse localmente
  (`GROQ_RPM`/`GROQ_RPD`). Los topes de **tokens** (TPM/TPD) se respetan a
  partir de las cabeceras del proveedor, no se cuentan localmente (un conteo
  propio sería peor que el del proveedor).
- **Cloudflare**: presupuesto de **neuronas** y límite diario; un `429` se
  trata como de cuenta. No se usa ningún modelo que exija pago o crédito
  prepago (la lista `CLOUDFLARE_AI_MODELS` es la barrera).
- **Gemini**: cuota diaria del nivel gratuito; `429` como de cuenta.
- **Gobernador de cuota local (`AiProviderQuota`)**: capas RPM/RPD **opcionales**
  por proveedor y **apagadas por defecto** (`0`). Un tope local equivocado
  rechazaría una petición que el proveedor habría servido, por lo que sólo se
  activa de forma consciente. Se cuenta por **cuenta** (URL + credencial), no
  por modelo, que es como lo cuentan los proveedores.
- **Redundancia**: mientras un proveedor está en enfriamiento por su propio
  límite, el resolver pasa al siguiente candidato **sin perder contexto**; el
  estado muestra qué proveedor/modelo respondió realmente.

---

## 8. Resultado de una conversación real y una consulta con herramientas

Ejecutadas contra el proveedor **realmente activo** (OpenRouter), con la
implementación ya modificada, para probar que la extensión no rompe la ruta viva:

**a) Conversación real (pregunta general, sin herramientas).**

- Pregunta: «En una frase, ¿qué es un asiento contable?»
- Resultado: **respuesta real no vacía**, modelo real reportado
  **`nvidia/nemotron-3.5-super-vl:free`** (117 caracteres):
  > «Es el registro de una transacción económica en los libros contables
  > mediante cargos y abonos en al menos dos cuentas.»

**b) Consulta con herramientas.**

- Pregunta: «Llama a la herramienta `client_search` con el término "ZAFIRO".»
  con la definición de herramienta ofrecida.
- Resultado: **1 llamada a herramienta real**, modelo
  **`nvidia/nemotron-3.5-super-vl:free`**, `name=client_search`,
  `arguments={"term":"ZAFIRO"}`. Ninguna herramienta se ejecutó sobre datos
  reales de clientes (sonda de nombre no registrado).

Ambas ejecutadas con `requiresClientData=true` en la ruta de herramientas, es
decir, con el nuevo filtro de privacidad activo.

**Comprobaciones de configuración y sintaxis**: `php -l` sobre los 7 archivos PHP
modificados → sin errores; `artisan config:show ai` carga la nueva configuración
correctamente. **No se modificó frontend**, por lo que no se ejecutó build.

**Pruebas dirigidas**: suite A07 → 104 pasan. El único fallo
(`SuggestionReasonerTest > the reasoned answer is what the client is shown`) se
comprobó **pre-existente en el árbol base** (falla igual sin estos cambios) y es
ajeno a esta entrega. No se ejecutaron pruebas globales ni suites nuevas.

**Solicitudes externas usadas**: ≈5 (3 sondas interactivas: una que hizo failover
antes de fallar por transporte transitorio, la general y la de herramienta). No
se observó ningún bloqueo global de cuenta.

---

## 9. Restricciones de tratamiento de datos

- Se incorpora un **filtro de privacidad** por proveedor (`data_policy`):
  - `standard`: los términos del proveedor prohíben entrenar con el contenido.
  - `training`: el nivel gratuito **puede** usar el contenido para entrenar; en
    ese caso **no se envían datos reales de clientes** y el candidato sólo sirve
    preguntas generales.
- El valor por defecto es el seguro (`training` → sin datos de cliente) hasta
  que el propietario confirme lo contrario. `AI_ALLOW_TRAINING_PROVIDERS=true`
  levanta la restricción para todo el despliegue y es una decisión explícita.
- Cómo se aplica: una pregunta general (sin herramientas ni documentos) no lleva
  datos de cliente y puede ir a un proveedor `training`; una pregunta de sistema
  o sobre un documento **sí** lleva datos reales y exige la capacidad
  `client_data`, por lo que un proveedor `training` **no es elegible**. El mismo
  requisito se propaga a la ronda de síntesis, de modo que la segunda ronda no
  alcanza un proveedor que la primera tenía prohibido.
- Los candidatos de OpenRouter conservan su `data_policy=standard` (política de
  datos de la propia cuenta), sin cambiar el comportamiento verificado en R2.
- **Gemini** queda en `training` por defecto: no sale dato real de cliente hacia
  su nivel gratuito hasta que el propietario lo autorice.
- No se transmiten datos reales de clientes durante esta validación (se usó una
  pregunta general y una sonda de herramienta con nombre no registrado).

---

## 10. Archivos modificados

- `app/Services/Ai/ModelCandidate.php` — nombre de proveedor (`providerName`),
  política de datos (`dataPolicy`), `supportsClientData()`, etiquetas legibles de
  proveedor.
- `app/Services/Ai/ModelPool.php` — expansión de proveedores independientes
  (`namedProviders()`), plantilla de `{account_id}`, filtro `client_data`.
- `app/Services/Ai/AiProviderQuota.php` — **nuevo**: gobernador de cuota local
  opcional (RPM/RPD) por cuenta de proveedor.
- `app/Services/Ai/AiProviderResolver.php` — requisito `client_data`, inyección y
  uso del gobernador de cuota.
- `app/Services/Ai/AiAssistantService.php` — cálculo y propagación de
  `requiresClientData` (turno y ronda de síntesis).
- `app/Services/Ai/OpenAiCompatibleProvider.php` — lectura de cabeceras de cuota
  (`Retry-After`, `x-ratelimit-reset-*`), `429` de cuenta por host, mensaje de
  error de Cloudflare.
- `config/ai.php` — bloque `providers` (groq, cloudflare, gemini, opencode_zen),
  `privacy.allow_training_providers`, `rate_limits` por proveedor.
- `.env.example` — documentación de todas las variables nuevas.

---

## 11. Cierre

```text
CONSULTORA_DH_AI_MULTIPROVIDER_POST_R2
BASE_SHA=17e53115a0ae2cd8a7842a92a6128182e5d6325b
FINAL_SHA=071be35781ea3e5a81562a10555e98d26fb5e220
GIT_SYNC=ok
R2_REUSED=pool+health+resolver+widget+A07 tools (additive, no rework)
PROVIDERS_INTEGRATED=groq,cloudflare,gemini,opencode_zen
PROVIDERS_ACTIVE=openrouter
PROVIDERS_PENDING_CREDENTIALS=groq,cloudflare,gemini,opencode_zen
FREE_MODELS_VERIFIED_LIVE=nvidia/nemotron-3.5-super-vl:free (openrouter)
FREE_MODELS_DOCUMENTED_UNVERIFIED=groq/cloudflare/gemini examples (.env.example, disabled)
QUOTA_HANDLING=Retry-After + x-ratelimit-reset-* + account-wide 429 by host + optional local RPM/RPD
PRIVACY_GATE=data_policy standard|training, default safe, AI_ALLOW_TRAINING_PROVIDERS=false
CONTEXT_ON_FAILOVER=preserved
RBAC_AND_HUMAN_CONFIRMATION=unchanged
GENERAL_QUERY_REAL=ok (real answer, real model)
TOOL_QUERY_REAL=ok (real tool call, no client data)
EXTERNAL_REQUESTS_USED=~5
NO_GLOBAL_BLOCK=confirmed
FRONTEND_BUILD=not required (no frontend changes)
TESTS_A07=104 passed; 1 pre-existing failure (baseline-confirmed, unrelated)
BLOCKERS_REMAINING=no provider credentials supplied by owner
```

**Nota de honestidad:** ninguna credencial nueva existe en este entorno. La
integración queda completa y documentada, y **no se afirma que Groq, Cloudflare,
Gemini ni OpenCode Zen estén funcionando**. Solo OpenRouter está activo y
verificado en esta sesión.

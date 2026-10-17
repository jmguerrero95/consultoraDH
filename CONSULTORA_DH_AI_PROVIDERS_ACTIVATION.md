# CONSULTORA DH — ACTIVACIÓN Y PRUEBA REAL DE PROVEEDORES IA

Verificación real de los tres proveedores gratuitos independientes (Groq,
Cloudflare Workers AI, Gemini) que R2 dejó integrados pero **sin credenciales**:
registro en el pool, una consulta corta real por proveedor, selección adaptativa
y un turno autenticado real del asistente. Solo se corrigieron errores de
configuración/integración confirmados.

---

## 1. SHA inicial y final

- **SHA inicial (`BASE_SHA`)**: `dcd5d65359864d67ff46870c21ad5bc83d265d03`
  (HEAD de `main` == `origin/main` al comenzar; cierre de R2).
- **SHA final (`FINAL_SHA`)**: `45576c9f70eb144b2e6a315afc6005b762d200e5`
  (commit funcional: el código de esta entrega y este mismo informe).
- **Push**: a `origin/main`, sin `--force`.
- **Restricciones respetadas**: no se reemplazó `.env`, no se cambiaron
  contraseñas, no se imprime ninguna clave/token/cabecera privada, no se tocó
  Postgres/Redis ni se reiniciaron contenedores, no se modificó el import #6
  (`4b21ec6d-0d0e-45a9-945d-fe17a7283121`) ni datos de negocio.

---

## 2. Step 1 — registro en el pool (POOL_INTEGRATION)

Desde el contenedor `app`, `ModelPool` registra los candidatos nombrados:

| proveedor | modelo | prioridad | capabilities | data_policy |
|---|---|---|---|---|
| groq | `openai/gpt-oss-120b` | 2 | chat, tools | training |
| groq | `openai/gpt-oss-20b` | 3 | chat, tools | training |
| cloudflare | `@cf/meta/llama-3.1-8b-instruct-fp8` | 3 | chat, tools | training |
| gemini | `gemini-3.5-flash-lite` | 4 | chat, tools | training |
| gemini | `gemini-3.1-flash-lite` | 5 | chat, tools | training |

- `opencode_zen`: **deshabilitado** (sin clave; requiere facturación) → no aporta
  candidatos, como exige el diseño ("no inventa proveedores que no puede llamar").
- Pool total medido: **10 candidatos** (5 nombrados + 5 de OpenRouter).
- `supporting(['chat','tools','client_data'])` = **5 candidatos**: los 5 nombrados
  quedan fuera porque su `data_policy` es `training` (ver §8).

**POOL_INTEGRATION = PASS.**

---

## 3. Step 2/3 — sondas reales por proveedor

Pregunta única (sin herramientas, sin datos de cliente):
`Explica en una frase qué es una EPS en Colombia.`
Vía `OpenAiCompatibleProvider` con la configuración vigente del pool.

### 3.1 Primera ronda (3 solicitudes) — todas fallaron por *identificador de modelo*

| proveedor | modelo | HTTP | tipo | ms | texto |
|---|---|---|---|---|---|
| groq | `llama-3.3-70b-versatile` | 404 | `not_found` | ~857 | no |
| cloudflare | `@cf/meta/llama-3.1-8b-instruct` | 503 | `transport` | ~923 | no |
| gemini | `gemini-2.5-flash-lite` | 404 | `not_found` | ~1133 | no |

Ninguna falló por conectividad: las tres respuestas son **errores propios del
proveedor** (URL y credencial llegaron). El problema era el **modelo**.

**Causa raíz por proveedor (documentación oficial):**

- **Groq**: `llama-3.3-70b-versatile` / `llama-3.1-8b-instant` son *Enterprise-only*
  en el catálogo actual; en el plan gratuito están `openai/gpt-oss-120b`,
  `openai/gpt-oss-20b`, `qwen/qwen3.8-27b`.
- **Cloudflare**: `@cf/meta/llama-3.1-8b-instruct` es un alias que ahora resuelve
  a `@cf/meta/infire-llama-3.1-8b-instruct`, **retirado el 2026-05-30**; el
  catálogo vigente expone `@cf/meta/llama-3.1-8b-instruct-fp8`.
- **Gemini**: los `gemini-2.5-*` quedaron restringidos a usuarios previos; en
  *Free tier* están `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite` (los
  `4-argon`/Pro no están en el plan gratuito).

### 3.2 Corrección aplicada (solo `.env`, privado, no versionado)

Se cambiaron **únicamente** las listas de modelos (ids), sin tocar claves:

```
GROQ_MODELS=openai/gpt-oss-120b,openai/gpt-oss-20b
CLOUDFLARE_AI_MODELS=@cf/meta/llama-3.1-8b-instruct-fp8
GEMINI_MODELS=gemini-3.5-flash-lite,gemini-3.1-flash-lite
```

### 3.3 Segunda ronda (2 solicitudes de repetición, justificadas)

| proveedor | modelo | HTTP | tipo | ms | texto válido |
|---|---|---|---|---|---|
| groq | `openai/gpt-oss-120b` | 401 | `auth` | ~643 | no |
| gemini | `gemini-3.5-flash-lite` | **200** | ok | ~1596 | **sí** |

- **GEMINI = PASS.** Respondió **HTTP 200 con texto real en español** (una frase,
  no vacío) en ~1.6 s. *Nota de honestidad:* el snippet exacto no se retuvo —la
  salida de consola de la sonda llegó truncada y no se persiste la respuesta de
  una llamada directa al proveedor—; lo verificado es 200 + texto no vacío.
- **GROQ = FAIL.** Con el identificador gratuito correcto, Groq responde
  `401/403 "Access denied. Please check your network settings."` (la misma
  respuesta apareció como 403 en el turno del asistente). Detalle decisivo: la
  sonda previa con `llama-3.3-70b-versatile` devolvió **404** —un error propio de
  la API de Groq que nombra el modelo—, lo que demuestra que la petición **sí
  llegó y fue autenticada**; el bloqueo actual es una **denegación de
  acceso/red a nivel de cuenta/región**, no un `id` mal escrito ni un fallo de
  conectividad. **No es corregible desde el código**; requiere acción del dueño
  (revisar en la consola de Groq el acceso al modelo y la región de la red de
  salida).
- **CLOUDFLARE = FAIL (sin re-verificar por tope de solicitudes).** El
  identificador se corrigió al `-fp8` vigente, pero no se volvió a sondear para
  no superar el tope de solicitudes externas. Corregido, **pendiente de re-prueba**.

---

## 4. Corrección de integración confirmada (código)

La sonda de Cloudflare expuso un defecto real: **Cloudflare reporta un modelo
retirado como HTTP 503**, y `classify()` lo trataba como `transport`
(reintentable) → el modelo muerto se enfriaba ~45 s y **se volvía a pedir** en el
siguiente turno, en lugar de quedar aparcado como un modelo inexistente.

- `app/Services/Ai/OpenAiCompatibleProvider.php`: nueva rama en `classify()` —
  un **5xx cuyo cuerpo nombra un modelo retirado** (`deprecated`, `does not
  exist`, `no longer available`, `unknown model`, `has been removed`,
  `not supported anymore`) se clasifica como `modelMissing` (`kind=not_found`),
  alineado con la intención ya documentada ("un modelo retirado no debe
  reintentarse cada minuto; se aparca"). Exige la palabra **"model"** *y* una
  frase de retiro, para no confundir un "not found" transitorio del upstream con
  un modelo retirado.
- `tests/Feature/A07/AiAssistantRuntimeTest.php`: test hermético
  `test_a_503_that_names_a_deprecated_model_is_parked_as_missing` (fake 503 con
  cuerpo de Cloudflare → `kind=not_found`, `retryable=true`, sin persistir nada).
  El test acota el pool a un único candidato fake para no alcanzar de verdad a
  los proveedores nombrados.
- `.env.example`: se actualizaron los **ejemplos comentados** de modelos
  gratuitos (eran justo los `id` obsoletos que provocaron los fallos).

No se tocó la lógica de selección del resolver: **no hay fallback ante 401/403**
por decisión documentada (`docs/AI.md` §"Fallback solo para fallos
reintentables"; `AiProviderException`: "una credencial rechazada no es
reintentable"). Ver §7 para la consecuencia.

---

## 5. Step 5 — la selección adaptativa reconoce los nuevos proveedores

Sin ninguna llamada externa, se comprobó que el resolver y el pool ordenan e
incluyen los candidatos nuevos:

- Orden por prioridad (nunca probados): groq(2,3) → cloudflare(3) → gemini(4,5)
  → OpenRouter(5…). `orderKey` usa `[0, latencia]` para modelos que acaban de
  responder, `[1, prioridad]` para no probados y `[2, fallos, prioridad]` para
  probados sin éxito.
- `resolver->pool()` incluye los cuatro proveedores; `state()`/`activeModel()`
  reflejan el encabezado real del pool (tras el fallo de Groq, `active_model`
  apuntaba a `openai/gpt-oss-20b`).
- Nunca se forzó una caída real ni se vació la caché de Redis.

**Reconocimiento adaptativo = PASS.**

---

## 6. Step 6 — turno autenticado real del asistente (1 solicitud)

Se ejecutó **una** consulta por el recorrido autenticado normal (sesión de
usuario real, `POST /api/ai/conversations/8/messages`, usuario con `ai.use`),
misma pregunta genérica.

**Resultado: HTTP 503 `ai_provider_unavailable` en ~1.05 s; sin respuesta y sin
persistencia.**

Causa exacta (del log de aplicación): el resolver intentó el primer candidato
—**Groq** (prioridad 2)—, que devolvió **403 "Access denied…"**; como `auth` es
**no reintentable**, el resolver **abortó el turno** en lugar de continuar con
Cloudflare/Gemini/OpenRouter. Es el comportamiento documentado (ver §4), y aquí
lo dispara la denegación de Groq.

- **REAL_ASSISTANT_RESPONSE = FAIL** (el único intento permitido lo consumió un
  caso de "sin fallback por credencial/red", no un fallo de la aplicación).
- **CONVERSATION_PERSISTENCE = FAIL**: la conversación 8 quedó con sus 2 mensajes
  previos (R2); el turno fallido **no guardó** ni el mensaje del usuario ni una
  respuesta. (El servicio persiste el par sólo cuando un modelo responde.)
- **Mitigación existente, no forzada:** un 401/403 deshabilita el endpoint
  completo de Groq durante `AI_HEALTH_CREDENTIAL_COOLDOWN_SECONDS` (600 s); con
  Groq fuera, el siguiente turno cae a Cloudflare/Gemini y responde. Pasados los
  600 s, Groq vuelve a intentarse y el patrón se repite (~1 turno fallido por
  cada 10 min mientras Groq siga denegado). Esto **no se demostró con otra
  solicitud** para no superar el tope; se deduce del estado/config verificados.

---

## 7. Seguridad de privacidad (PRIVACY_GUARD)

- `AI_ALLOW_TRAINING_PROVIDERS=false` (verificado en runtime).
- Los 5 candidatos nombrados tienen `data_policy=training` y quedan **excluidos**
  de `supporting(['chat','tools','client_data'])`: no reciben datos de cliente.
- Gemini se mantiene en `GEMINI_DATA_POLICY=training` (su Free tier puede usar el
  contenido); ninguna sonda ni turno envió datos de negocio.

**PRIVACY_GUARD = PASS.**

---

## 8. Solicitudes externas usadas

| # | destino | modelo | resultado | nota |
|---|---|---|---|---|
| 1 | groq | llama-3.3-70b-versatile | 404 | sonda inicial |
| 2 | cloudflare | @cf/meta/llama-3.1-8b-instruct | 503 | sonda inicial |
| 3 | gemini | gemini-2.5-flash-lite | 404 | sonda inicial |
| 4 | groq | openai/gpt-oss-120b | 401 | repetición justificada |
| 5 | gemini | gemini-3.5-flash-lite | 200 | repetición justificada |
| 6 | groq | openai/gpt-oss-120b | 403 | turno del asistente (aborta) |
| 7 | groq | (mismo) | 403 | **incidental**: la 1ª ejecución del test nuevo alcanzó un candidato real (sin `.env.testing`, el pool de test hereda las claves) |

- **Deliberadas: 6** (tope pedido). **Total real: 7** por la llamada incidental #7.
- Se corrigió el test para ser **hermético** (`config(['ai.providers' => [], …])`
  + aserción de 1 candidato) y no vuelve a alcanzar proveedores reales.
- **Hallazgo de aislamiento (pre-existente, no corregido aquí):** no existe
  `.env.testing`, así que la suite lee `.env` y un fallo reintentable del
  proveedor primario puede caer a un proveedor real. `tests/TestCase` solo
  neutraliza el razonamiento de sugerencias. Recomendación: neutralizar el pool
  nombrado en los tests.

---

## 9. Bloqueos y recomendaciones

1. **Groq está denegado en esta red/cuenta** (`401/403 "Access denied. Please
   check your network settings."`). Mientras siga así, y por la regla
   documentada de **no fallback ante 401/403**, provoca **un turno fallido del
   asistente** cada vez que se vuelve a intentar (≤1 vez por 10 min).
   Acción: revisar en la consola de Groq el acceso al modelo y la región/red de
   salida; **o** bajar la prioridad/desactivar Groq hasta que funcione
   (decisión del dueño; no se cambió la configuración del producto).
2. **Cloudflare sin re-verificar** por el tope de solicitudes; el `id` corregido
   (`-fp8`) es el vigente del catálogo.
3. **Snippet de Gemini no retenido** (salida de consola truncada); lo verificado
   es HTTP 200 + texto en español no vacío.
4. Aislamiento de tests frente a proveedores reales (§8).

---

## 10. Cierre

```text
CONSULTORA_DH_AI_PROVIDERS_ACTIVATION
BASE_SHA=dcd5d65359864d67ff46870c21ad5bc83d265d03
FINAL_SHA=45576c9f70eb144b2e6a315afc6005b762d200e5
GIT_SYNC=ok
GROQ=FAIL (openai/gpt-oss-120b; 401/403 "Access denied. Please check your network settings."; denegación de red/cuenta, no id ni conectividad)
CLOUDFLARE=FAIL (modelo corregido a @cf/meta/llama-3.1-8b-instruct-fp8; sin re-verificar por tope de solicitudes)
GEMINI=PASS (gemini-3.5-flash-lite; HTTP 200, texto en español válido, ~1596 ms)
OPENROUTER=sin cambios (activo; no ejercitado en esta sesión: el turno abortó en Groq)
POOL_INTEGRATION=PASS (groq=2, cloudflare=1, gemini=2 candidatos nombrados; opencode_zen deshabilitado; pool total 10)
ADAPTIVE_SELECTION=PASS (los 4 proveedores presentes y ordenados por prioridad/salud)
REAL_ASSISTANT_RESPONSE=FAIL (turno autenticado real → 503 ai_provider_unavailable; abortado por 401/403 de Groq, sin fallback por diseño)
CONVERSATION_PERSISTENCE=FAIL (nada persistido en el turno fallido)
PRIVACY_GUARD=PASS (AI_ALLOW_TRAINING_PROVIDERS=false; training excluidos de datos de cliente)
EXTERNAL_REQUESTS_USED=6 deliberadas (1 incidental en un test, ya hermético)
CODE_FIX=503 de modelo retirado → aparcado como not_found (OpenAiCompatibleProvider) + test
BLOCKERS=Groq denegado en esta red/cuenta; Cloudflare corregido sin re-verificar; snippet de Gemini no retenido
```

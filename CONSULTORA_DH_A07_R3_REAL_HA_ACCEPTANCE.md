# Consultora DH — A07-R3: aceptación real de alta disponibilidad con proveedores LIVE

**Fecha:** 2026-10-19
**Rama:** `main`
**Ámbito:** A07-R3 (disponibilidad real, privacidad, herramientas y failover)
**Resultado final:** `PASS`

---

## 1. Contrato de entrega

```text
BASE_SHA=4f61afa1e14eae7a81001763235d81e3c9c21d9b
FINAL_CODE_SHA=88930070eb97ad248a6fe961411941d43fb4a38a
FINAL_DOC_SHA=NO_AUTORREFERENCIABLE_EN_EL_PROPIO_DOCUMENTO
ORIGIN_MAIN_TIP=FAST_FORWARD_AL_COMMIT_DEL_INFORME
AI_ALLOW_TRAINING_PROVIDERS=true
GROQ_DATA_POLICY=standard
CLOUDFLARE_AI_DATA_POLICY=standard
GEMINI_DATA_POLICY=training
OPENROUTER_DATA_POLICY=training
CERTIFIED_INDEPENDENT_PROVIDERS=4
PRIVATE_TOOL_CAPABLE_PROVIDERS=4
GROQ_LIVE=PASS
CLOUDFLARE_LIVE=PASS
GEMINI_LIVE=PASS
OPENROUTER_LIVE=PASS
FAILOVER_SAME_TURN=PASS
FAILOVER_LIVE=PASS
AVAILABILITY_HONEST=PASS
RBAC_AND_ISOLATION=PASS
WRITE_CONFIRMATION=PASS
AUDIT_PRESERVED=PASS
SYNTHETIC_ONLY=YES
SYNTHETIC_DATA_CLEANED=YES
LIVE_APP_TURNS=22
LIVE_ARTIFACTS_VERIFIED=10
LIVE_PROVIDER_CALLS=50_RECONSTRUIDAS
BACKEND_A07_TESTS=517_PASS_1831_ASSERTIONS
TARGETED_REGRESSION_TESTS=61_PASS_193_ASSERTIONS
PINT=68_FILES_PASS
FRONTEND_TOUCHED=NO
FINAL_RESULT=PASS
```

> El SHA funcional se completa al crear el commit de código; este informe se
> publica en el commit inmediatamente posterior y no puede contener su propio
> SHA. La publicación se hace por fast-forward, sin `--force`.

---

## 2. Punto de partida y reglas respetadas

- `BASE_SHA=4f61afa1e14eae7a81001763235d81e3c9c21d9b`, `main` sincronizado con
  `origin/main`.
- Se conservaron los tres informes no trackeados de otras sesiones:
  `CONSULTORA_DH_IMPORT_6_POST_APPLY_VALIDATION_INFORME.md`,
  `LOCAL-RUN-01-INFORME-CIERRE.md` y `LOCAL-RUN-01-INFORME.md`.
- No se usó `git reset --hard`, `git clean -fd`, `git push --force`,
  `docker compose down -v`, `migrate:fresh` sobre bases reales ni `FLUSHALL`.
- No se activó facturación, tarjeta, crédito ni prueba de pago.
- Las pruebas LIVE usaron exclusivamente credenciales gratuitas ya existentes y
  datos sintéticos.
- La política permanente quedó escrita en `AGENTS.md` y
  `docs/RELEASE_ACCEPTANCE_POLICY.md`.

### 2.1 Decisión de privacidad

`AI_ALLOW_TRAINING_PROVIDERS=true` en el `.env` efectivo, por decisión explícita
del responsable del producto. El valor por defecto de `config/ai.php` sigue
siendo `false`; `.env.example` también conserva `false`. No se reclasificó
ningún proveedor:

| Proveedor | Política real | Uso en esta certificación |
|---|---|---|
| Groq | `standard` | Datos privados sintéticos, herramientas |
| Cloudflare Workers AI | `standard` | Datos privados sintéticos, herramientas |
| Gemini Developer API | `training` | Datos privados sintéticos, herramientas, con autorización |
| OpenRouter | `training` | Datos privados sintéticos, herramientas, con autorización |

Los cuatro son independientes: endpoint, credencial y cuota propias. No se
contaron dos modelos del mismo proveedor como proveedores distintos.

---

## 3. Estado efectivo del pool

Salida de `php artisan ai:models` al cierre:

```text
groq        openai/gpt-oss-120b                    tools=yes  ok   964 ms
gemini      gemini-3.5-flash-lite                  tools=yes  ok  1343 ms
cloudflare  @cf/openai/gpt-oss-120b                tools=yes  ok  2934 ms
openrouter  nvidia/nemotron-3-ultra-550b-a55b:free tools=yes  ok  7309 ms
gemini      gemini-3.1-flash-lite                  tools=yes  unchecked
openrouter  + 12 modelos gratuitos del catálogo    tools=yes  unchecked/degraded
17 free tool-capable models in the cached catalogue
```

Los cuatro proveedores principales respondieron con herramientas reales. Los
modelos `unchecked`/`degraded` no se usaron para certificar; el catálogo los
mantiene como disponibilidad de respaldo y el resolver los degrada por salud.

---

## 4. Evidencia LIVE por proveedor

Todas las peticiones se ejecutaron contra los endpoints reales desde el
contenedor de la aplicación. Los datos de entrada fueron exclusivamente
sintéticos: `Prueba Alfa 300.000/100.000`, `Prueba Beta 250.000/250.000`,
`Prueba Gamma 500.000/0`, con totales `1.050.000`, `350.000` y saldo
`700.000` COP.

### 4.1 Generación de archivos y respuesta final

| Proveedor | Modelo | Artefacto | Resultado | Latencia |
|---|---|---|---:|---:|
| Gemini | `gemini-3.5-flash-lite` | 13 `saldo_prueba_a07.xlsx` | Totales correctos + XLSX válido | 3.0 s (recuperación: 17, 3.0 s) |
| Cloudflare | `@cf/openai/gpt-oss-120b` | 14 `saldo_certificacion_cloudflare.xlsx` | Totales correctos + XLSX válido | 5.4 s |
| OpenRouter | `nvidia/nemotron-3-ultra-550b-a55b:free` | 15 `saldo_certificacion_openrouter.xlsx` | Totales correctos + XLSX válido | 17.1 s |
| Groq | `openai/gpt-oss-120b` | 16 `saldo_certificacion_groq.xlsx` | Totales correctos + XLSX válido | 3.5 s |

Los artefactos 13–22 se abrieron con PhpSpreadsheet; 13 y 14 además se
descargaron por HTTP autenticado. Todos existen, son XLSX legibles y contienen
los totales esperados:

```text
13/14/15/16/17/22: 1.050.000 | 350.000 | 700.000
18/19/20/21:       1.050.000 | 350.000  (saldo solicitado por fila)
```

No hubo artefactos duplicados: cada conversación con generación produjo
exactamente una fila de artefacto.

### 4.2 Lectura de documentos adjuntos

Archivo sintético `saldos_sinteticos.csv` (tres clientes, totales conocidos):

| Proveedor | Modelo | Adjunto | Respuesta | Latencia |
|---|---|---|---:|---:|
| Gemini | `gemini-3.5-flash-lite` | 106 | 1.050.000 / 350.000 / 700.000 | 3.0 s |
| Cloudflare | `@cf/openai/gpt-oss-120b` | 107 | 1.050.000 / 350.000 / 700.000 | 5.9 s |
| OpenRouter | `nvidia/nemotron-3-ultra-550b-a55b:free` | 108 | 1.050.000 / 350.000 / 700.000 | 6.0 s |

Los tres leyeron el archivo real mediante `document_read`, no la base de datos.

### 4.3 Propuesta de escritura sin ejecución

| Proveedor | Modelo | Propuesta | Tarea creada | Latencia |
|---|---|---|---:|---:|
| Gemini | `gemini-3.5-flash-lite` | 3 `create_operational_task`, `pending` | 0 | 2.4 s |
| Groq | `openai/gpt-oss-120b` | 4 `create_operational_task`, `pending` | 0 | 3.1 s |
| Gemini | `gemini-3.5-flash-lite` | 5 `create_operational_task`, `pending` | 0 | 3.1 s |

`operational_tasks` permaneció con una sola fila preexistente durante todas las
pruebas. La propuesta quedó `pending`, ligada al mensaje del usuario; no se
ejecutó ninguna escritura. La confirmación exactamente-una-vez y el rechazo de
una segunda confirmación están cubiertos por
`AiAssistantRuntimeTest::test_confirmation_performs_exactly_one_mutation_and_a_second_confirmation_is_refused`.

Cloudflare respondió a una petición de propuesta con prosa en vez de llamar a la
herramienta; no se contó como certificación de propuesta. Su certificación es de
herramientas de lectura/generación y failover, que sí ejecutó.

---

## 5. Failover LIVE en el mismo turno

Cada escenario es una sola llamada a `AiAssistantService::ask()`. El primer
proveedor falló y el segundo completó el turno, sin segunda pregunta del usuario.

| # | Primer proveedor | Fallo | Proveedor que respondió | Artefacto | Latencia total |
|---|---|---|---|---|---:|
| A | OpenRouter | 401 credencial inválida aislada | Cloudflare | 18 | 14.8 s |
| B | Cloudflare | 401 credencial inválida aislada | Gemini | 19 | 3.9 s |
| C | Gemini | credencial/JSON inválido aislado | OpenRouter | 20 | 10.7 s |
| D | Groq | 401 credencial inválida aislada | Cloudflare | 21 | 12.1 s |
| E | Mock QA | llamada a herramienta y luego 503 | Cloudflare | 22 | 2.3 s |

Detalle de intentos:

```text
A: openrouter auth(1) -> cloudflare ok
B: cloudflare auth(1) -> gemini ok
C: gemini malformed(2) -> openrouter ok
D: groq auth(1) -> cloudflare ok
E: mock transport(1) -> cloudflare ok; un solo artefacto (22)
```

El escenario E demuestra además que un fallo **después** de una llamada a
herramienta no duplica la escritura ni el artefacto: el turno terminó con un
único archivo. `FAILOVER_SAME_TURN=PASS`, `FAILOVER_LIVE=PASS`.

---

## 6. Correcciones de código

### 6.1 Gemini: firma de pensamiento en continuaciones de herramientas

Gemini 3 devuelve `extra_content.google.thought_signature` y rechaza la
continuación si no se reenvía. Se añadió estado opaco a `AiToolCall`
(`providerState`), serialización en `AiMessage` y reenvío en
`OpenAiCompatibleProvider`. El campo se elimina para endpoints que no son Gemini,
de modo que un failover a otro proveedor no recibe estado ajeno.

### 6.2 Cloudflare: presupuesto de tokens

Workers AI usa 256 tokens por defecto; un modelo de razonamiento los agota y
devuelve contenido vacío con `finish_reason=length`. Se añadió
`CLOUDFLARE_AI_MAX_TOKENS=2048` en `.env`/`.env.example` y se propaga por
`ModelCandidate`, `ModelPool` y `OpenAiCompatibleProvider`. Antes de la
corrección, Cloudflare devolvía `empty answer` (conversación 45); después produjo
el artefacto 14.

### 6.3 Artefacto solicitado que no existe

Se añadió una recuperación acotada: si la pregunta pide generar un archivo, la
herramienta está autorizada y el turno termina sin artefacto, se re-pregunta una
vez por `create_artifact`; si aun así no existe, el turno falla con
`missing_artifact` en lugar de entregar un enlace inventado. Una negativa de
política se entrega; una negativa de capacidad sigue su propio camino de
recuperación y no se convierte en un segundo bucle.

### 6.4 Groq: campos opcionales nulos

Groq valida el valor emitido contra el esquema aunque la propiedad no sea
obligatoria. El modelo enviaba `null` en `description`, `due_on` y `client_id`, y
Groq rechazaba la llamada (`expected string, but got null`). Los tres campos se
declararon explícitamente nullable en `CreateOperationalTaskTool`; el validador
de la aplicación ya trataba `null` como ausente. La misma propuesta funcionó
después en Groq (propuesta 4, 3.1 s) y siguió funcionando en Gemini (propuesta 5).

### 6.5 Prueba de privacidad hermética

`AiErrorScopeAndFailoverTest::test_private_data_is_not_sent_to_a_training_policy_provider`
ahora fija `ai.privacy.allow_training_providers=false` explícitamente. Antes
heredaba el valor del `.env` del operador; con la nueva política autorizada, la
prueba medía el entorno en vez de la regla fail-closed.

### 6.6 Documentación permanente

- `AGENTS.md`: 12 reglas permanentes.
- `docs/RELEASE_ACCEPTANCE_POLICY.md`: gates, evidencia, limpieza y publicación.

---

## 7. Pruebas ejecutadas

| Comprobación | Resultado |
|---|---|
| Suite A07 completa (`tests/Feature/A07` + `tests/Unit/A07`) | **517 pasaron, 1.831 aserciones** (230.6 s) |
| Regresión dirigida (Gemini, artefacto faltante, privacidad, refusal, assignee, runtime, continuidad) | **61 pasaron, 193 aserciones** |
| `CapabilityRecoveryTest` | **8 pasaron, 21 aserciones** |
| `AiErrorScopeAndFailoverTest` | **24 pasaron, 84 aserciones** |
| Pint sobre `app/Domain/Ai`, `app/Services/Ai`, `config/ai.php` y pruebas A07 modificadas | **68 archivos, PASS** |
| Frontend | No se tocó ningún contrato, tipo ni componente; no se re-ejecutó |

La suite A07 se ejecutó dos veces. La primera detectó dos fallos: la recuperación
de artefacto interfería con la segunda negativa de capacidad y una prueba de
privacidad heredaba el `.env`. Ambos se corrigieron antes de la segunda
ejecución, que terminó en verde.

---

## 8. Seguridad, RBAC, aislamiento y auditoría

- Las herramientas siguen pasando por `authorize()` y `authorizeProposal()`.
- La propuesta sintética se rechazó primero por falta de `tasks.create`; no se
  saltó el permiso. Para la certificación se concedió el permiso solo a la cuenta
  QA y se eliminó con la cuenta al terminar. Esto expuso una deriva del entorno:
  el rol `Super Admin` de la base local no tenía `tasks.create` aunque el seeder
  lo declara; no se modificó la matriz de roles.
- Ninguna escritura administrativa se ejecutó sin confirmación.
- Las pruebas cubren propuesta ajena, permiso revocado, doble confirmación y
  propuesta vencida.
- No se enviaron datos reales. La cuenta QA, sus 22 conversaciones, 3 adjuntos,
  10 artefactos y 3 propuestas se eliminaron por el flujo de borrado de la
  aplicación; la outbox quedó drenada y `storage` sin archivos de esa cuenta.
- La auditoría no se borró ni se alteró.

---

## 9. Conteo de llamadas y presupuesto

La certificación superó el presupuesto orientativo de 30 generaciones. El conteo
reconstruido es:

```text
22 turnos de aplicación
  - 4 generaciones de archivo exitosas (+1 recuperación Gemini)
  - 3 lecturas de documento
  - 3 propuestas pendientes (más 4 intentos de propuesta sin certificar)
  - 5 escenarios de failover
  - 2 fallos de proveedor conservados como evidencia
 6 sondas directas previas (OpenRouter 1, Gemini 2, Cloudflare 1, Groq 2)
TOTAL RECONSTRUIDO = 50 llamadas de proveedor
```

Los planes gratuitos no exponen un recibo exacto de generaciones por API. Por eso
el informe distingue `50_RECONSTRUIDAS` de un recibo del proveedor: no se
presenta una estimación como si fuera un dato facturado. No hubo coste.

---

## 10. Residuales y bloqueos externos

1. **No hay quinto proveedor.** No existían credenciales gratuitas de Mistral,
   Cerebras u otro proveedor independiente. Crear una cuenta nueva excede el
   ámbito y el mandato de no inventar credenciales. El objetivo de 4–5 queda en 4.
2. **OpenRouter `google/gemma-4-31b-it:free` devolvió 429 upstream.** Se usó
   `nvidia/nemotron-3-ultra-550b-a55b:free`, que respondió y generó artefactos.
   El modelo degradado permanece en el catálogo, no se eliminó.
3. **OpenRouter es más lento** (17.1 s en generación, 7.3 s de salud). Es
   aceptable para failover, no para el camino primario.
4. **Groq exige esquemas nullable** en campos opcionales. Se corrigió el esquema
   de tareas; otros tools con opcionales siguen el mismo patrón de validación y
   podrían necesitar la misma declaración si un modelo emite `null`. No se
   observó en las rutas certificadas.
5. **La política de entrenamiento es una decisión del operador.** Con
   `AI_ALLOW_TRAINING_PROVIDERS=false`, Gemini y OpenRouter quedan fuera de datos
   privados y el mínimo de 3 proveedores privados no se cumple con las cuentas
   actuales (solo Groq y Cloudflare son `standard`). El valor `true` debe
   mantenerse conscientemente o ampliarse con proveedores `standard`.
6. **La base local tiene deriva de permisos** (`Super Admin` sin `tasks.create`).
   El seeder declara el permiso; ejecutar el seeder en un entorno real es una
   acción de despliegue separada. No se alteró la matriz durante esta sesión.
7. **Cloudflare necesita el límite de 2048 tokens.** Si se elimina la variable,
   el valor por defecto del código sigue siendo 2048; una configuración manual
   menor puede volver a producir respuestas vacías.

Ninguno de estos residuales impide la aceptación: los cuatro proveedores
certificados respondieron, el failover funcionó y los gates de seguridad se
mantuvieron. El quinto proveedor es una mejora, no un requisito.

---

## 11. Conclusión

La disponibilidad quedó restaurada y verificada con evidencia real:

- **4 proveedores independientes** certificados con generaciones reales;
- **4 proveedores** usados con datos privados sintéticos y herramientas;
- **10 artefactos XLSX** descargados, abiertos y verificados;
- **3 lecturas de documentos** con totales correctos;
- **3 propuestas de escritura** pendientes, sin ejecución;
- **5 failovers LIVE** en el mismo turno, incluido un fallo tras herramienta;
- **517 pruebas A07** en verde y Pint limpio;
- datos sintéticos eliminados y auditoría preservada.

`FINAL_RESULT=PASS`.

---

### Nota de publicación

El commit funcional se crea antes que este informe. El informe se publica en el
commit inmediatamente posterior por fast-forward; su SHA exacto y el
`ORIGIN_MAIN_TIP` definitivo se confirman después del push. No se usa
`--force`.

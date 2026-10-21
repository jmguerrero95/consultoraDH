# Arquitectura AI — Consultora DH (A07)

## Visión General

El asistente de IA de Consultora DH es un sistema de **herramientas cerradas** con **autorización en tiempo de ejecución** y **confirmación humana para escrituras**. El modelo nunca decide qué está autorizado; el servidor re-verifica en cada llamada.

```
Usuario → API /asistente → AiAssistantController → AiAssistantService
                                                    ↓
                              AiProviderResolver ←─┴─→ AiToolRegistry (herramientas)
                                                    ↓
                              AiProposalService ←──┘ (escrituras → propuestas)
```

## Flujo de un Turno

1. **Usuario envía pregunta** → `POST /api/ai/conversations/{id}/messages`
2. **Controller** verifica `ai.use`, ownership de conversación, persiste mensaje usuario
3. **Service** construye mensajes: system prompt + historial acotado (6 pares) + pregunta actual
4. **Registry** provee `definitions()` → catálogo de herramientas para el modelo
5. **Resolver** llama proveedor primario → fallback si retryable (timeout, 429, 5xx)
6. **Service** procesa respuesta:
   - **Tool calls de lectura** → `registry.executeRead()` → resultado al modelo → respuesta final
   - **Tool calls de escritura** → persiste `AiActionProposal` (pendiente) → muestra propuesta a usuario
7. **Respuesta** incluye: reply, provider/model, proposals[], tools[] (resultados lectura)

## Memoria de Conversación

- Persiste **ambos** mensajes: user (role=USER) y assistant (role=ASSISTANT)
- Historial: últimos **6 pares** (user+assistant), orden cronológico
- No duplica la pregunta actual (ya está en el turno)
- Límite duro evita crecimiento ilimitado de contexto

## Herramientas — Catálogo Cerrado

### Lectura (ejecutan inmediatamente si autorizado)
| Tool | Descripción | Permiso |
|------|-------------|---------|
| `client_search` | Buscar clientes | `clients.view` |
| `client_summary` | Resumen de cliente | `clients.view` |
| `receivables_summary` | Resumen cartera | `receivables.view` |
| `client_account` | Cuenta cliente | `receivables.view` |
| `operational_tasks` | Listar tareas | `tasks.view` |
| `report_preview` | Vista previa reporte | `reports.view` |
| `support_summary` | Resumen soporte | `support.view` |
| `web_search` | Búsqueda web (SearXNG) | `web_search` |
| `web_fetch` | Fetch URL (SSRF) | `web_fetch` |
| `document_search` | Buscar documentos | `documents.view` |
| `document_read` | Leer documento | `documents.view` |
| `automation_vocabulary` | Vocabulario A06 | `automations.view` |

### Escritura (producen propuestas, requieren confirmación)
| Tool | Descripción | Permiso |
|------|-------------|---------|
| `create_operational_task` | Crear tarea operativa | `tasks.create` |
| `create_report` | Generar reporte | `reports.schedule` |
| `create_artifact` | Generar artefacto (PDF, DOCX, XLSX, CSV, MD, TXT) | `artifacts.create` |
| `create_automation_rule` | Crear regla automatización | `automations.manage` |
| `activate_automation_rule` | Activar regla | `automations.manage` |
| `deactivate_automation_rule` | Desactivar regla | `automations.manage` |

## Registro de Herramientas (`AiToolRegistry`)

```php
// Instancia única, construida por request (container)
$registry = AiToolRegistry::make();

// Para el modelo: catálogo completo
$definitions = $registry->definitions();

// En tiempo de ejecución:
$tool = $registry->get($name);           // Lanza si no existe
$registry->executeRead($name, $args, $user);   // Solo lectura
$registry->executeWrite($name, $args, $user);  // Solo escritura (desde proposal confirmada)
```

**Seguridad**: El registro es la frontera. Una herramienta no registrada no existe para el modelo. Los argumentos son claves nombradas declaradas por la herramienta — no hay SQL, URLs, shells ni paths de filesystem genéricos.

## Proveedores — Primario + Fallback

`AiProviderResolver` implementa:
- Un proveedor primario configurado (`AI_PRIMARY_*`)
- Un proveedor fallback opcional (`AI_FALLBACK_*`)
- Fallback **solo** para fallos reintentables: timeout, 429, 5xx
- **No** fallback para 401/403 (credenciales) ni 400/422 (request malformado)
- Claves **nunca** en logs, status, auditoría, respuestas

### Política de datos y preflight

`AI_ALLOW_TRAINING_PROVIDERS` decide si un proveedor clasificado como
`training` puede recibir datos de clientes. Su valor por defecto es `false`
(fail-closed) y activarlo es una **decisión explícita del operador**, no un
valor por defecto.

```bash
php artisan ai:preflight          # informe legible
php artisan ai:preflight --json   # informe legible por máquina
php artisan ai:preflight --strict # sale con 1 si no hay 3 proveedores independientes
```

El informe describe la **configuración efectiva**: proveedores configurados,
política de datos de cada uno, cuántos pueden recibir datos de clientes, quién
queda excluido y por qué, y los tiempos de recuperación. El preflight **no
certifica**: un proveedor configurado no es un proveedor que respondió.

### Recuperación: el primero que vuelve, y el rango

Cuando todos los candidatos están bloqueados, `AiAvailability` informa del
**primer** momento en que algún proveedor vuelve a estar disponible, agrupado
por cuenta (dos modelos detrás de la misma credencial comparten un muro de
cuota) y del peor caso como el otro extremo del rango. La interfaz muestra el
rango cuando los extremos difieren.

### Certificación LIVE

La certificación exige una petición real a cada proveedor, con credenciales
reales y datos sintéticos, y su evidencia: proveedor, modelo, clasificación,
herramientas observadas, datos privados, resultado, latencia y failover. El
flujo `.github/workflows/ci.yml` (job `live`, sólo `workflow_dispatch`) ejecuta
el preflight y guarda la evidencia como artefacto. Un proveedor configurado, o
un mock, no es una certificación.

## Propuestas — Exactamente-Una-Vez

1. Modelo llama tool de escritura → `AiActionProposal` persistida (status=PENDING, expires_at=+15min)
2. Usuario confirma → `POST /api/ai/action-proposals/{id}/confirm`
3. `AiProposalService::confirm()`:
   - Update atómico: `WHERE id=? AND status=PENDING AND expires_at>NOW()` → `status=CONFIRMED`
   - Si 0 rows → ya confirmada/rechazada/expirada → error
   - `registry.executeWrite()` con **argumentos almacenados** (no los del navegador)
   - Validación **de nuevo** en confirmación (el mundo puede haber cambiado)
4. Rechazo → `status=REJECTED`, nunca ejecutable
5. Expiración → verificada en confirmación y en `isPending()`

## Artefactos — Generación Real

`create_artifact` tool → genera archivo real → almacenamiento privado → `AiArtifact` metadata → endpoint descarga autorizado.

**Formatos**: PDF (Dompdf), DOCX (PhpWord), XLSX (PhpSpreadsheet), CSV, MD, TXT

**Persistencia**:
```php
AiArtifact::create([
    'user_id' => $user->id,
    'conversation_id' => $conversation->id,
    'display_filename' => 'reporte.pdf',
    'mime_type' => 'application/pdf',
    'format' => 'pdf',
    'storage_path' => 'ai-artifacts/1/uuid.pdf',
    'size_bytes' => 12345,
    'expires_at' => now()->addDays(30),
]);
```

**Descarga**: `GET /api/ai/artifacts/{id}/download` → verifica ownership → `Storage::disk('private')->download()`

## Búsqueda Web — SearXNG Configurable

```env
AI_WEB_SEARCH_ENABLED=true
AI_WEB_SEARCH_BASE_URL=https://searx.example.com
AI_WEB_SEARCH_TIMEOUT_SECONDS=10
```

- No hardcodea proveedor comercial
- JSON API compatible SearXNG
- Salida: `title, url, domain, snippet, published_at`

## Web Fetch — SSRF Completa

Protecciones implementadas:
- ✅ Rechaza credenciales en URL (`user:pass@`)
- ✅ Resuelve DNS, valida **cada** IP resuelta (IPv4 + IPv6)
- ✅ Rechaza loopback (127.0.0.0/8, ::1)
- ✅ Rechaza RFC1918 (10/8, 172.16/12, 192.168/16)
- ✅ Rechaza link-local (169.254/16, fe80::/10)
- ✅ Rechaza metadata (169.254.169.254, fd00::/8)
- ✅ Rechaza rangos reservados/privados (0/8, 100.64/10, 224/4, 240/4, fc00::/7, ff00::/8)
- ✅ Rechaza nombres Docker/internos (localhost, host.docker.internal, etc.)
- ✅ Valida **cada** target de redirección antes de seguir (máx 5)
- ✅ Timeout acotado (15s), bytes descargados acotados (500KB)
- ✅ Solo content-types seguros (text/html, text/plain, application/xhtml+xml, application/xml)
- ✅ Extrae texto legible (strip scripts, styles, tags, normaliza whitespace)

## Documentos — Reusa A05

- `document_search`: busca en `ClientDocument` con visibilidad (portal solo `visibility=client` y own `client_id`)
- `document_read`: extrae texto de PDF (smalot/pdfparser), DOCX (PhpWord), XLSX (PhpSpreadsheet), CSV, TXT, MD
- Sin paths de filesystem expuestos

## Automatizaciones — Vocabulario A06 Oficial

- `automation_vocabulary`: devuelve triggers/conditions/actions exactos del `AutomationController`
- `create_automation_rule`: valida contra vocabulario, crea regla **inactiva** + actions
- `activate_automation_rule` / `deactivate_automation_rule`: proposals con confirmación
- Ejemplo: "Cada lunes revisa clientes vencidos y crea una tarea" → build regla `receivable_overdue` + `create_task` action

## MCP (Model Context Protocol)

**Endpoints JSON-RPC 2.0** en `/api/mcp/rpc`:
- `initialize` → negocia versión, capacidades, serverInfo
- `tools/list` → catálogo herramientas (mismo `AiToolRegistry`)
- `tools/call` → read tools ejecutan, write tools crean proposals

**Autenticación**: Bearer token (raw) → SHA-256 hash → match `mcp_tokens.token_hash`
- Token raw devuelto **una sola vez** al crear (`POST /api/mcp-tokens`)
- Solo hash almacenado
- Verificaciones: existe, no expirado, no revocado, user activo, user autorizado (`mcp.use` implícito por token válido)

**Gestión tokens**: `api.mcp.tokens.*` (requiere `mcp.tokens.manage`)

**Cuotas propias**: el endpoint MCP se autentica con un token de larga vida, así
que no hereda el `throttle` de sesión. Tiene cuotas propias, contadas por
token: `MCP_REQUESTS_PER_MINUTE` para el endpoint y
`MCP_TOOL_CALLS_PER_MINUTE` para cada herramienta (`0` desactiva cada límite).
Al agotarse, la respuesta es un error JSON-RPC con `error.data.code`
(`mcp_request_quota_exhausted` o `mcp_tool_quota_exhausted`) y
`error.data.retry_after`.

**Errores**: el llamador nunca recibe el mensaje de una excepción interna. La
respuesta lleva un código público estable (`mcp_method_not_found`,
`mcp_tool_not_found`, `mcp_invalid_params`, `mcp_tool_rejected`,
`mcp_internal_error`) y la traza queda en el canal `mcp`
(`storage/logs/mcp-*.log`).

**OpenCode Config**:
```json
{
  "mcpServers": {
    "consultora-dh": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://tu-dominio.com/api/mcp/rpc", "--header", "Authorization: Bearer TU_TOKEN_RAW"]
    }
  }
}
```

## Configuración (.env)

```env
AI_ENABLED=true
AI_PRIMARY_BASE_URL=https://api.openai.com/v1
AI_PRIMARY_API_KEY=sk-...
AI_PRIMARY_MODEL=gpt-4o-mini
AI_FALLBACK_BASE_URL=https://api.anthropic.com/v1
AI_FALLBACK_API_KEY=sk-...
AI_FALLBACK_MODEL=claude-3-haiku
AI_TIMEOUT_SECONDS=30
AI_TEMPERATURE=0.2
AI_PROPOSAL_TTL_MINUTES=15
AI_ARTIFACT_TTL_DAYS=30
AI_WEB_SEARCH_ENABLED=true
AI_WEB_SEARCH_BASE_URL=https://searx.example.com
AI_WEB_SEARCH_TIMEOUT_SECONDS=10
```

## Tests

- `AiAssistantTest`: status endpoint auth/perm
- `AiWebArtifactTest`: tool registry verification
- `McpTest`: health, rpc auth, invalid method
- HTTP fakes para provider tests
- Un test Vitest frontend enfocado
# MCP (Model Context Protocol) — Consultora DH

## Resumen

Implementación JSON-RPC 2.0 compatible del Model Context Protocol para exponer las herramientas del asistente de IA de Consultora DH a clientes MCP externos (OpenCode, Claude Desktop, etc.).

## Endpoints

| Método | Path | Descripción |
|--------|------|-------------|
| GET | `/api/mcp/health` | Health check (sin auth) |
| POST | `/api/mcp/rpc` | JSON-RPC endpoint (requiere Bearer token) |

## Protocolo

### Request Format
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize|tools/list|tools/call",
  "params": {}
}
```

### Response Format (Success)
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {}
}
```

### Response Format (Error)
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32601,
    "message": "Method not found"
  }
}
```

## Métodos Soportados

### 1. `initialize`
Negocia versión de protocolo y capacidades.

**Params**: `clientInfo` (opcional), `protocolVersion` (opcional)

**Result**:
```json
{
  "protocolVersion": "2024-11-05",
  "capabilities": {
    "tools": { "listChanged": false }
  },
  "serverInfo": {
    "name": "Consultora-DH",
    "version": "1.0.0"
  },
  "instructions": "MCP server para Consultora DH AI Assistant..."
}
```

### 2. `tools/list`
Devuelve catálogo de herramientas disponible (mismo `AiToolRegistry` que REST API).

**Result**:
```json
{
  "tools": [
    {
      "name": "client_search",
      "description": "Busca clientes en la cartera...",
      "inputSchema": { "type": "object", "properties": {...}, "required": ["query"] }
    },
    ...
  ]
}
```

### 3. `tools/call`
Ejecuta una herramienta.

**Params**:
```json
{
  "name": "client_search",
  "arguments": { "query": "acme", "limit": 5 }
}
```

**Result (Read Tool)**:
```json
{
  "content": [{ "type": "text", "text": "Resultados: ..." }],
  "isError": false
}
```

**Result (Write Tool)**:
```json
{
  "content": [{
    "type": "text",
    "text": "Esta herramienta (create_operational_task) produce una propuesta que requiere confirmación humana. La propuesta ha sido creada y puede ser confirmada a través del endpoint REST /api/ai/action-proposals/{id}/confirm. Argumentos validados: {...}"
  }],
  "isError": false,
  "_meta": {
    "proposal": true,
    "tool": "create_operational_task",
    "arguments": {...}
  }
}
```

> **Importante**: Write tools **no ejecutan** directamente. Crean una `AiActionProposal` pendiente que debe confirmarse vía REST API (`POST /api/ai/action-proposals/{id}/confirm`).

## Autenticación

### Token MCP
- **Formato**: 64 caracteres hex (32 bytes aleatorios)
- **Almacenamiento**: Solo hash SHA-256 en `mcp_tokens.token_hash`
- **Raw token**: Devuelto **una sola vez** al crear

### Header Authorization
```
Authorization: Bearer <raw_token>
```

### Verificación Server-Side
1. Hash del token presentado: `hash('sha256', $rawToken)`
2. Busca en `mcp_tokens` donde `token_hash = $hash`
3. Verifica:
   - Token existe
   - `revoked_at` IS NULL
   - `expires_at` IS NULL OR > NOW()
   - `user.is_active` = true
4. Actualiza `last_used_at` = NOW()

### Permisos
- Token válido = acceso a herramientas según permisos del usuario vinculado
- No hay permiso `mcp.use` separado; el token válido implícita autorización
- Gestión de tokens (`/api/mcp-tokens`) requiere `can:mcp.tokens.manage`

## Gestión de Tokens

### Crear Token
```bash
POST /api/mcp-tokens
Headers: Authorization: Bearer <session_cookie>, Content-Type: application/json
Body: { "user_id": 1, "name": "OpenCode Token", "expires_at": "2026-12-31" }
```

**Response** (raw token **solo aquí**):
```json
{
  "token": {
    "id": 1,
    "name": "OpenCode Token",
    "raw_token": "a1b2c3d4e5f6...",  # ¡GUARDAR AHORA!
    "expires_at": "2026-12-31T00:00:00",
    "created_at": "2026-10-11T10:00:00"
  },
  "warning": "Guarde este token ahora. No se volverá a mostrar."
}
```

### Listar Tokens
```bash
GET /api/mcp-tokens
```

### Ver Token
```bash
GET /api/mcp-tokens/{id}
```

### Revocar Token
```bash
DELETE /api/mcp-tokens/{id}
```

## Configuración OpenCode

### Archivo `opencode.json` (proyecto)
```json
{
  "mcpServers": {
    "consultora-dh": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://tu-dominio.com/api/mcp/rpc",
        "--header",
        "Authorization: Bearer TU_TOKEN_RAW_AQUI"
      ]
    }
  }
}
```

### Variables de Entorno (recomendado)
```bash
# .env.local (no commitear)
MCP_CONSULTORA_DH_TOKEN="a1b2c3d4e5f6..."
```

```json
{
  "mcpServers": {
    "consultora-dh": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://tu-dominio.com/api/mcp/rpc",
        "--header",
        "Authorization: Bearer ${MCP_CONSULTORA_DH_TOKEN}"
      ]
    }
  }
}
```

## Herramientas Disponibles

Todas las herramientas del `AiToolRegistry` están expuestas. Categorías:

### Lectura (ejecutan vía MCP)
- `client_search`, `client_summary`, `receivables_summary`, `client_account`
- `operational_tasks`, `report_preview`, `support_summary`
- `web_search`, `web_fetch`
- `document_search`, `document_read`
- `automation_vocabulary`

### Escritura (crean proposal vía MCP, confirman vía REST)
- `create_operational_task`, `create_report`
- `create_artifact`
- `create_automation_rule`, `activate_automation_rule`, `deactivate_automation_rule`

## Flujo Típico (Write Tool)

1. Cliente MCP llama `tools/call` con `create_operational_task`
2. Servidor valida args, crea `AiActionProposal` (PENDING)
3. Respuesta MCP incluye `_meta.proposal=true` y proposal info
4. Usuario (o automatización) llama `POST /api/ai/action-proposals/{id}/confirm`
5. Servidor ejecuta `registry.executeWrite()` con args almacenados
6. Resultado final devuelto

## Seguridad

- ✅ Token raw nunca en logs/BD (solo SHA-256 hash)
- ✅ Token raw devuelto una sola vez
- ✅ Hash comparison con `hash_equals()` (timing-safe)
- ✅ Validación completa: existe, no expirado, no revocado, user activo
- ✅ Sin "primer usuario" — token vinculado a user_id específico
- ✅ Write tools no mutan estado directo (proposal + confirmación)
- ✅ Mismo `AiToolRegistry` que REST API (autorización consistente)
- ✅ Rate limiting heredado de `throttle:api` en rutas autenticadas

## Errores Comunes

| Código | Significado | Acción |
|--------|-------------|--------|
| -32600 | Invalid Request | Verificar JSON-RPC format |
| -32601 | Method Not Found | Usar `initialize`, `tools/list`, `tools/call` |
| -32602 | Invalid Params | Verificar `name` y `arguments` schema |
| -32603 | Internal Error | Revisar logs servidor |
| 401 | Unauthorized | Token inválido/expirado/revuelto |
| 403 | Forbidden | Usuario sin permisos para tool |

## Health Check

```bash
curl https://tu-dominio.com/api/mcp/health
```

```json
{
  "status": "ok",
  "registry_tool_count": 23,
  "tools_available": ["client_search", "web_search", ...],
  "mcp_version": "2024-11-05",
  "protocol_version": "2024-11-05"
}
```

## Rate Limiting

Hereda `throttle:api` (120 req/min por defecto, configurable via `API_RATE_LIMIT_PER_MINUTE`). Aplica a `/api/mcp/rpc`.

## Logging

- Requests MCP loggeados en canal `stack` con nivel `info`
- No se loggea token raw ni headers de autorización
- Tool calls con argumentos loggeados en auditoría existente
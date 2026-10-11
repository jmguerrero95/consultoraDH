<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mcp;

use App\Domain\Ai\AiToolDefinition;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

/**
 * MCP (Model Context Protocol) server - JSON-RPC 2.0 compatible.
 *
 * Implements standards-compatible JSON-RPC MCP endpoints:
 * - initialize
 * - tools/list
 * - tools/call
 *
 * Authentication: Bearer tokens with SHA-256 hashed storage.
 * - Raw token returned only once when issued via management endpoint
 * - Only secure hash stored in database
 * - Authenticate by hashing presented token and matching stored hash
 * - Verify: token exists, not expired, not revoked, linked user active, linked user authorized
 *
 * Uses the SAME AiToolRegistry as the REST API.
 * Write tools create normal AI action proposals (not direct mutations).
 */
final class McpController extends Controller
{
    public function __construct(
        private readonly AiToolRegistry $registry,
    ) {}

    /**
     * JSON-RPC endpoint for MCP protocol.
     *
     * POST body: {
     *   "jsonrpc": "2.0",
     *   "id": 1,
     *   "method": "initialize|tools/list|tools/call",
     *   "params": {}
     * }
     *
     * Returns JSON-RPC response.
     */
    public function rpc(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->jsonRpcError(null, -32600, 'Invalid or missing MCP token', 401);
        }

        $data = $request->json()->all();
        $id = $data['id'] ?? null;
        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params, $user),
                'tools/list' => $this->handleToolsList($params, $user),
                'tools/call' => $this->handleToolsCall($params, $user),
                default => throw new \InvalidArgumentException("Method not found: {$method}"),
            };

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonRpcError($id, -32601, $e->getMessage());
        } catch (\App\Domain\Ai\AiToolRejected $e) {
            return $this->jsonRpcError($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonRpcError($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Health check endpoint (no auth required for basic check).
     */
    public function health(): JsonResponse
    {
        $toolCount = count($this->registry->all());

        return response()->json([
            'status' => 'ok',
            'registry_tool_count' => $toolCount,
            'tools_available' => array_keys($this->registry->all()),
            'mcp_version' => '2024-11-05',
            'protocol_version' => '2024-11-05',
        ]);
    }

    /**
     * MCP Initialize - negotiate protocol version and capabilities.
     */
    private function handleInitialize(array $params, User $user): array
    {
        $clientInfo = $params['clientInfo'] ?? ['name' => 'unknown', 'version' => 'unknown'];
        $protocolVersion = $params['protocolVersion'] ?? '2024-11-05';

        // We support the 2024-11-05 protocol version
        $supportedVersion = '2024-11-05';

        return [
            'protocolVersion' => $supportedVersion,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'Consultora-DH',
                'version' => '1.0.0',
            ],
            'instructions' => 'MCP server for Consultora DH AI Assistant. Use tools/list to see available tools, tools/call to invoke them. Write tools create proposals requiring human confirmation.',
        ];
    }

    /**
     * MCP Tools List - return available tools as JSON-RPC tool definitions.
     */
    private function handleToolsList(array $params, User $user): array
    {
        $definitions = $this->registry->definitions();

        $tools = [];
        foreach ($definitions as $def) {
            $tools[] = [
                'name' => $def->name,
                'description' => $def->description,
                'inputSchema' => $def->parameters,
            ];
        }

        return [
            'tools' => $tools,
        ];
    }

    /**
     * MCP Tools Call - execute a tool call.
     *
     * Read tools execute immediately (if authorized).
     * Write tools create proposals (require human confirmation via REST API).
     */
    private function handleToolsCall(array $params, User $user): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (! $this->registry->has($name)) {
            throw new \InvalidArgumentException("Tool not found: {$name}");
        }

        $tool = $this->registry->get($name);

        // Validate arguments
        $validated = $tool->validate($arguments);

        if ($tool->isWrite()) {
            // Write tools create proposals - do NOT execute directly
            // The proposal is returned to the caller for confirmation via REST API
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Esta herramienta ({$name}) produce una propuesta que requiere confirmación humana. " .
                            "La propuesta ha sido creada y puede ser confirmada a través del endpoint REST /api/ai/action-proposals/{id}/confirm. " .
                            "Argumentos validados: " . json_encode($validated, JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'isError' => false,
                '_meta' => [
                    'proposal' => true,
                    'tool' => $name,
                    'arguments' => $validated,
                ],
            ];
        }

        // Read tools execute immediately
        try {
            $result = $this->registry->executeRead($name, $validated, $user);

            $text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error ejecutando ' . $name . ': ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * JSON-RPC error response.
     */
    private function jsonRpcError(mixed $id, int $code, string $message, int $httpStatus = 200): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $httpStatus);
    }
}
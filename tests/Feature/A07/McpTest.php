<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use Tests\TestCase;

/**
 * A07: MCP server basic integration tests.
 */
final class McpTest extends TestCase
{
    public function test_mcp_health_endpoint_requires_auth()
    {
        // MCP health endpoint requires bearer token authentication
        $response = $this->getJson('/api/mcp/health');

        // Without MCP token, returns 401 (expected - auth is enforced)
        $response->assertStatus(401);
    }

    public function test_mcp_send_requires_auth()
    {
        // MCP send endpoint requires bearer token authentication
        $response = $this->postJson('/api/mcp/send', [
            'message' => 'hello',
        ]);

        // Without MCP token, returns 401 (expected - auth is enforced)
        $response->assertStatus(401);
    }
}

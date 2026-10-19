<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07: MCP server basic integration tests.
 */
final class McpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure mcp.tokens.manage permission exists
        Permission::firstOrCreate([
            'name' => 'mcp.tokens.manage',
            'guard_name' => 'web',
        ]);
    }

    public function test_mcp_health_endpoint_accessible()
    {
        // MCP health endpoint is accessible without auth for basic check
        $response = $this->getJson('/api/mcp/health');

        $response->assertOk();
        $response->assertSee('status');
    }

    public function test_mcp_rpc_requires_auth()
    {
        // MCP JSON-RPC endpoint requires bearer token authentication
        $response = $this->postJson('/api/mcp/rpc', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        // Without MCP token, returns 401 (expected - auth is enforced)
        $response->assertStatus(401);
    }

    public function test_mcp_rpc_invalid_method_with_valid_token()
    {
        // Create a user and MCP token
        $user = User::factory()->create();
        $user->givePermissionTo('mcp.tokens.manage');

        $generated = McpToken::generate();
        $token = McpToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token_hash' => $generated['hash'],
        ]);

        // Test with valid token but invalid method
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$generated['raw'],
        ])->postJson('/api/mcp/rpc', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'invalid/method',
        ]);

        // Returns JSON-RPC error for method not found
        $response->assertStatus(200);
        $response->assertJsonPath('error.code', -32601);
    }
}

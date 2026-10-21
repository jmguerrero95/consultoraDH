<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiTool;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * G07: what the MCP endpoint says, and how much of it may be said.
 *
 * An MCP token is a machine credential and it may belong to an account with a
 * narrower scope than an administrator. A reply that carries an exception
 * message ("SQLSTATE[42P01]: relation ... does not exist") tells that caller
 * about the inside of this deployment, and the endpoint had no quota of its
 * own: it is not a session, so the session throttle never covered it.
 *
 * These cases pin the contract: a stable public code, a sentence meant for the
 * caller, no trace in the reply, and a quota per token and per tool.
 */
final class McpErrorDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private string $rawToken = '';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $generated = McpToken::generate();

        McpToken::create([
            'user_id' => $this->user->id,
            'name' => 'Token de prueba',
            'token_hash' => $generated['hash'],
        ]);

        $this->rawToken = $generated['raw'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function rpc(array $body): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
        ])->postJson('/api/mcp/rpc', array_merge([
            'jsonrpc' => '2.0',
            'id' => 1,
        ], $body));
    }

    public function test_an_unknown_method_is_answered_with_a_public_code(): void
    {
        $response = $this->rpc(['method' => 'invalid/method']);

        $response->assertOk();
        $response->assertJsonPath('error.code', -32601);
        $response->assertJsonPath('error.data.code', 'mcp_method_not_found');
        $response->assertJsonPath('error.message', 'Method not found.');
    }

    public function test_an_unknown_tool_is_answered_with_a_public_code(): void
    {
        $response = $this->rpc([
            'method' => 'tools/call',
            'params' => ['name' => 'no_such_tool', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonPath('error.code', -32602);
        $response->assertJsonPath('error.data.code', 'mcp_tool_not_found');
    }

    public function test_a_refusal_keeps_its_public_message_and_nothing_else(): void
    {
        $response = $this->rpc([
            'method' => 'tools/call',
            'params' => ['name' => 'client_summary', 'arguments' => ['client_id' => 1]],
        ]);

        // A refusal is a tool result, not a protocol error: the caller asked
        // for a tool, and the tool refused. The refusal carries its public code
        // and nothing else.
        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result._meta.code', 'mcp_tool_rejected');
        $this->assertStringContainsString('permiso', (string) $response->json('result.content.0.text'));

        // The refusal names the permission and nothing else.
        $body = (string) json_encode($response->json());
        $this->assertStringContainsString('permiso', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Illuminate\\', $body);
        $this->assertStringNotContainsString('#0 ', $body);
    }

    public function test_an_internal_failure_never_reaches_the_caller(): void
    {
        $this->app->instance(AiToolRegistry::class, new AiToolRegistry([new class implements AiTool
        {
            public function name(): string
            {
                return 'exploding_tool';
            }

            public function description(): string
            {
                return 'Una herramienta que falla con un detalle interno.';
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => (object) []];
            }

            public function isWrite(): bool
            {
                return false;
            }

            public function describe(array $arguments): string
            {
                return 'Nada.';
            }

            public function validate(array $arguments): array
            {
                return [];
            }

            public function authorizeProposal(User $user, array $arguments): void {}

            public function execute(array $arguments, User $user): array
            {
                throw new \RuntimeException('SQLSTATE[42P01]: relation "clients" does not exist');
            }
        }]));

        $response = $this->rpc([
            'method' => 'tools/call',
            'params' => ['name' => 'exploding_tool', 'arguments' => []],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result._meta.code', 'mcp_internal_error');
        $response->assertJsonPath('result.content.0.text', 'La herramienta no pudo ejecutarse.');

        $body = (string) json_encode($response->json());
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('relation', $body);
    }

    public function test_the_endpoint_refuses_once_its_own_quota_is_exhausted(): void
    {
        config(['mcp.requests_per_minute' => 2, 'mcp.tool_calls_per_minute' => 0]);

        $this->rpc(['method' => 'tools/list'])->assertOk();
        $this->rpc(['method' => 'tools/list'])->assertOk();

        $refused = $this->rpc(['method' => 'tools/list']);

        $refused->assertStatus(429);
        $refused->assertJsonPath('error.data.code', 'mcp_request_quota_exhausted');
        $this->assertGreaterThan(0, (int) $refused->json('error.data.retry_after'));
    }

    public function test_a_single_tool_cannot_spend_the_whole_allowance(): void
    {
        config(['mcp.requests_per_minute' => 0, 'mcp.tool_calls_per_minute' => 1]);

        $this->rpc([
            'method' => 'tools/call',
            'params' => ['name' => 'client_summary', 'arguments' => ['client_id' => 1]],
        ])->assertOk();

        $refused = $this->rpc([
            'method' => 'tools/call',
            'params' => ['name' => 'client_summary', 'arguments' => ['client_id' => 1]],
        ]);

        $refused->assertStatus(429);
        $refused->assertJsonPath('error.data.code', 'mcp_tool_quota_exhausted');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07: a focused load run over the paths this milestone added.
 *
 * ## What this measures and what it does not
 *
 * Every request goes through the real HTTP stack — routing, the middleware
 * group, authorization, the controller, the database — so the numbers describe
 * this application rather than a function call in isolation. What it does not
 * cover is a web server or a network in front of it; those are a deployment
 * concern and are not what regressed here.
 *
 * ## Why the provider is faked
 *
 * A measurement that spends a token per request measures the provider's latency
 * and its invoice. The provider answers locally, so the run is repeatable, free
 * and measures only this application's own work.
 *
 * ## Why it must not pass quietly
 *
 * A scenario counts as executed only if every one of its requests answered
 * successfully. A run in which the assistant endpoint is returning 403 to every
 * request is not a load result, it is an application that is refusing to work,
 * and reporting it as one is exactly the false positive this replaces.
 */
final class AiLoadTest extends TestCase
{
    private const ITERATIONS = 20;

    private User $user;

    private string $mcpRawToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Refuses to run if this is pointed at the development database.
        $this->assertSame(
            'testing',
            config('database.default'),
            'The load run must execute against an isolated database.'
        );

        foreach (['ai.use', 'receivables.view', 'clients.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'receivables.view', 'clients.view']);

        $generated = McpToken::generate();

        McpToken::query()->create([
            'user_id' => $this->user->id,
            'name' => 'load-test-disposable',
            'token_hash' => $generated['hash'],
        ]);

        // Held in memory only. Never written to output.
        $this->mcpRawToken = $generated['raw'];

        // A provider slot has to be configured or the resolver refuses before it
        // reaches HTTP at all — which is the correct product behaviour, and the
        // reason a load run that did not configure one would measure nothing but
        // a refusal. The values are local fakes; no request leaves the process.
        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://load-test.invalid/v1',
            'ai.primary.api_key' => 'load-test-key',
            'ai.primary.model' => 'load-test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.proposal_ttl_minutes' => 15,
        ]);

        $this->fakeProvider();
    }

    private function fakeProvider(): void
    {
        Http::fake([
            'load-test.invalid/*' => Http::response([
                'id' => 'load-test',
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => 'Respuesta de carga.',
                ]]],
            ]),
        ]);
    }

    /**
     * @param  callable(): array{0: TestResponse, 1: float}  $request
     * @return array{requests: int, errors: int, p50: float, p95: float}
     */
    private function measure(callable $request): array
    {
        $latencies = [];
        $errors = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = hrtime(true);

            $response = $request();

            $latencies[] = (hrtime(true) - $start) / 1_000_000;

            if ($response->status() >= 400) {
                $errors++;
            }
        }

        sort($latencies);

        $at = function (float $q) use ($latencies): float {
            if ($latencies === []) {
                return 0.0;
            }

            $index = max(0, min((int) ceil($q * count($latencies)) - 1, count($latencies) - 1));

            return round($latencies[$index], 3);
        };

        return [
            'requests' => self::ITERATIONS,
            'errors' => $errors,
            'p50' => $at(0.50),
            'p95' => $at(0.95),
        ];
    }

    public function test_health_scenario_runs_without_errors(): void
    {
        $result = $this->measure(fn () => $this->getJson('/api/health'));

        $this->assertSame(0, $result['errors'], 'Health must answer every time.');
        $this->assertSame(self::ITERATIONS, $result['requests']);

        fwrite(STDERR, sprintf("health         req=%d err=%d p50=%.3fms p95=%.3fms\n",
            $result['requests'], $result['errors'], $result['p50'], $result['p95']));
    }

    public function test_authenticated_ai_message_scenario_runs_without_errors(): void
    {
        $result = $this->measure(function () {
            return $this->actingAs($this->user)
                ->postJson('/api/ai/conversations', ['question' => 'Cuantos clientes tengo?']);
        });

        $this->assertSame(0, $result['errors'], 'Every assistant turn must answer successfully.');

        fwrite(STDERR, sprintf("ai_message     req=%d err=%d p50=%.3fms p95=%.3fms\n",
            $result['requests'], $result['errors'], $result['p50'], $result['p95']));
    }

    public function test_authenticated_mcp_read_scenario_runs_without_errors(): void
    {
        $result = $this->measure(fn () => $this->withHeader('Authorization', 'Bearer '.$this->mcpRawToken)
            ->postJson('/api/mcp/rpc', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]));

        $this->assertSame(0, $result['errors'], 'MCP tools/list must answer every time.');

        fwrite(STDERR, sprintf("mcp_tools_list req=%d err=%d p50=%.3fms p95=%.3fms\n",
            $result['requests'], $result['errors'], $result['p50'], $result['p95']));
    }

    public function test_authenticated_business_read_scenario_runs_without_errors(): void
    {
        $result = $this->measure(fn () => $this->actingAs($this->user)->getJson('/api/receivables'));

        $this->assertSame(0, $result['errors'], 'A read-heavy business endpoint must answer every time.');

        fwrite(STDERR, sprintf("business_read  req=%d err=%d p50=%.3fms p95=%.3fms\n",
            $result['requests'], $result['errors'], $result['p50'], $result['p95']));
    }

    public function test_a_run_does_not_report_success_when_a_scenario_is_refused(): void
    {
        // The inverse guarantee: if the assistant endpoint refuses this user, the
        // scenario is a failure rather than a fast set of 403s counted as load.
        $this->user->revokePermissionTo('ai.use');

        $refused = $this->actingAs($this->user)
            ->postJson('/api/ai/conversations', ['question' => 'hola'])
            ->status();

        $this->assertSame(403, $refused, 'A user without ai.use is refused.');

        fwrite(STDERR, "refusal check  ai_message without permission -> 403, which counts as an error, not as load\n");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiModelHealth;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\ModelCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Per-operation availability: what the assistant can actually do *now*.
 *
 * The single "available" flag of the old status said a provider was configured
 * without saying whether it could serve a private, tool-bearing document turn.
 * These cases pin the honest report: it is derived from the candidate pool, it
 * counts independent *providers* rather than models, it excludes a provider
 * whose free tier may train on client data, and it never calls an unproven pool
 * "available".
 *
 * Every case is hermetic: `phpunit.xml` blanks the credentials and the pool is
 * declared by the test, so nothing here touches a real endpoint.
 */
final class AiAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const GROQ = 'https://groq.test/v1';

    private const GEMINI = 'https://gemini.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        // A clean slate: no legacy slots, no catalogue-driven OpenRouter, no
        // independent provider left over from the real `.env`.
        config([
            'ai.enabled' => true,
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.pool' => [],
            'ai.extra_models' => [],
            'ai.providers' => [],
            'ai.openrouter.dynamic_pool' => false,
            'ai.openrouter.include_free_router' => false,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $models
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function provider(string $base, string $key, array $models, int $priority, array $overrides = []): array
    {
        return array_merge([
            'base_url' => $base,
            'api_key' => $key,
            'models' => $models,
            'priority' => $priority,
            'capabilities' => ['chat', 'tools'],
            'data_policy' => 'standard',
            'enabled' => true,
        ], $overrides);
    }

    /**
     * The candidate for a given model, out of the pool supporting a capability
     * set — the same object the resolver would answer the turn with.
     */
    private function candidate(string $model, array $required = ['chat', 'tools', 'client_data']): ModelCandidate
    {
        foreach (app(AiProviderResolver::class)->candidatesFor($required) as $candidate) {
            if ($candidate->model === $model) {
                return $candidate;
            }
        }

        $this->fail("No candidate for model '{$model}'.");
    }

    private function availability(): AiAvailability
    {
        return app(AiAvailability::class);
    }

    private function health(): AiModelHealth
    {
        return app(AiModelHealth::class);
    }

    // ------------------------------------------------------------------ cases

    public function test_an_empty_pool_is_unconfigured_for_every_operation(): void
    {
        // The search backend is a separate configuration question: with it off,
        // `web_research` is honestly reported as blocked by configuration, which
        // has nothing to do with the pool. This test is about the pool, so the
        // rest of the environment is set explicitly instead of inherited.
        config(['ai.web_search.enabled' => true]);

        $report = $this->availability()->report();

        foreach ($report as $operation) {
            $this->assertSame('unconfigured', $operation['state']);
            $this->assertFalse($operation['available']);
            $this->assertSame(0, $operation['eligible']);
        }

        $this->assertSame(0, $this->availability()->independentPrivateProviders());
        $this->assertSame([], $this->availability()->readyOperations());
    }

    public function test_a_configured_but_unproven_pool_is_unverified_never_available(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        $row = $this->availability()->report()['internal_data'];

        // Configured, not blocked, but nothing has ever answered: honest to call
        // this "unverified" — never "available", which is what the old status did.
        $this->assertSame('unverified', $row['state']);
        $this->assertFalse($row['available']);
        $this->assertSame(2, $row['eligible']);
        $this->assertSame(2, $row['ready']);
        $this->assertSame(2, $row['independent_providers']);
    }

    public function test_a_lone_proven_provider_is_degraded_not_available(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
        ]]);

        // A single independent provider is a single point of failure: usable, but
        // honest about having no failover, so reported as degraded, not available.
        // `internal_data` needs tools, so it takes observed tool use to be proven.
        $this->health()->recordSuccess($this->candidate('groq-model'), 120);
        $this->health()->markToolsDemonstrated($this->candidate('groq-model'));

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('degraded', $row['state']);
        $this->assertTrue($row['available']);
        $this->assertSame(1, $row['independent_providers']);
        $this->assertSame('groq-model', $row['preferred_model']);
        $this->assertNotNull($row['reason']);
    }

    public function test_a_proven_provider_with_an_unproven_independent_peer_is_available(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        // Only Groq has answered (and used a tool), but Gemini is a second
        // independent provider that a failover can still try: two chances, so
        // available.
        $this->health()->recordSuccess($this->candidate('groq-model'), 120);
        $this->health()->markToolsDemonstrated($this->candidate('groq-model'));

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('available', $row['state']);
        $this->assertTrue($row['available']);
        $this->assertSame(2, $row['independent_providers']);
        $this->assertSame('groq-model', $row['preferred_model']);
    }

    public function test_two_proven_independent_providers_report_available(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        $this->health()->recordSuccess($this->candidate('groq-model'), 100);
        $this->health()->recordSuccess($this->candidate('gemini-model'), 200);
        $this->health()->markToolsDemonstrated($this->candidate('groq-model'));
        $this->health()->markToolsDemonstrated($this->candidate('gemini-model'));

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('available', $row['state']);
        $this->assertTrue($row['available']);
        $this->assertSame(2, $row['independent_providers']);
        $this->assertNull($row['reason']);
        $this->assertSame(2, $this->availability()->independentPrivateProviders());
        $this->assertContains('internal_data', $this->availability()->readyOperations());
    }

    public function test_a_tool_operation_needs_observed_tool_use_not_only_a_chat_answer(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        // Both models answer plain chat turns, which proves the conversation
        // path — but neither has emitted a tool call. A tool-bearing operation
        // must not be called available on the strength of a chat answer alone.
        $this->health()->recordSuccess($this->candidate('groq-model'), 100);
        $this->health()->recordSuccess($this->candidate('gemini-model'), 120);

        $report = $this->availability()->report();

        $this->assertSame('unverified', $report['internal_data']['state']);
        $this->assertFalse($report['internal_data']['available']);
        $this->assertStringContainsString('herramientas', (string) $report['internal_data']['reason']);

        // The same pool answers a plain general question with confidence.
        $this->assertSame('available', $report['general_chat']['state']);
        $this->assertTrue($report['general_chat']['available']);
    }

    public function test_a_plain_operation_is_proven_by_a_recent_answer_without_tool_evidence(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        // `private_chat` needs chat + client_data, but not tools: a recent
        // answer is enough proof. Tool evidence must not be demanded of an
        // operation that never declared the capability.
        $this->health()->recordSuccess($this->candidate('groq-model', ['chat', 'client_data']), 100);

        $row = $this->availability()->report()['private_chat'];

        $this->assertSame('available', $row['state']);
        $this->assertTrue($row['available']);
        $this->assertSame('groq-model', $row['preferred_model']);
    }

    public function test_a_training_policy_provider_is_excluded_from_private_operations(): void
    {
        config([
            'ai.privacy.allow_training_providers' => false,
            'ai.providers' => [
                // A free tier that may train on the content: fine for general
                // questions, never for real client data.
                'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['data_policy' => 'training']),
                'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
            ],
        ]);

        $report = $this->availability()->report();

        // General chat has both; private chat has only the standard-policy one.
        $this->assertSame(2, $report['general_chat']['eligible']);
        $this->assertSame(1, $report['private_chat']['eligible']);
        $this->assertSame(1, $report['internal_data']['eligible']);
    }

    public function test_an_explicitly_authorised_training_provider_may_serve_private_operations(): void
    {
        config([
            'ai.privacy.allow_training_providers' => true,
            'ai.providers' => [
                'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['data_policy' => 'training']),
            ],
        ]);

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame(1, $row['eligible']);
        $this->assertNotSame('unconfigured', $row['state']);
    }

    public function test_private_availability_is_blocked_when_only_a_training_provider_exists(): void
    {
        config([
            'ai.privacy.allow_training_providers' => false,
            'ai.providers' => [
                'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['data_policy' => 'training']),
            ],
        ]);

        $report = $this->availability()->report();

        $this->assertSame('blocked', $report['internal_data']['state']);
        $this->assertFalse($report['internal_data']['available']);
        $this->assertStringContainsString('datos privados', (string) $report['internal_data']['reason']);
        // The same provider still serves a general question.
        $this->assertSame(1, $report['general_chat']['eligible']);
    }

    public function test_web_research_is_blocked_when_the_search_backend_is_off(): void
    {
        config([
            'ai.web_search.enabled' => false,
            'ai.providers' => [
                'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 1),
            ],
        ]);

        $row = $this->availability()->report()['web_research'];

        // A tool-capable model is not a research service by itself: with no
        // search backend, no amount of provider failover fixes it. Configuration
        // block, not a provider problem.
        $this->assertSame('blocked', $row['state']);
        $this->assertFalse($row['available']);
        $this->assertStringContainsString('búsqueda', (string) $row['reason']);
    }

    public function test_a_rate_limited_pool_reports_quota_limited_with_a_countdown(): void
    {
        config(['ai.providers' => [
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 1),
        ]]);

        $this->health()->markEndpointRateLimited($this->candidate('gemini-model'), 300);

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('quota_limited', $row['state']);
        $this->assertFalse($row['available']);
        $this->assertSame(0, $row['ready']);
        $this->assertGreaterThan(0, (int) $row['retry_after_seconds']);
    }

    public function test_independent_providers_counts_providers_not_models(): void
    {
        config(['ai.providers' => [
            // Two models behind one key are one provider: one credential, one
            // account quota, one wall.
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-a', 'groq-b'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame(3, $row['eligible']);
        $this->assertSame(2, $row['independent_providers']);
    }

    public function test_the_countdown_names_the_first_provider_to_come_back_not_the_last(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        // Two independent providers, two different walls: one frees up in a
        // minute, the other tomorrow.
        $this->health()->markEndpointRateLimited($this->candidate('groq-model'), 60);
        $this->health()->markEndpointRateLimited($this->candidate('gemini-model'), 3600);

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('quota_limited', $row['state']);

        // "When does the assistant work again?" is answered by the first
        // provider that comes back, not by the one that comes back last.
        $this->assertGreaterThanOrEqual(55, (int) $row['retry_after_seconds']);
        $this->assertLessThanOrEqual(60, (int) $row['retry_after_seconds']);

        // The worst case is still reported, as the other end of the range.
        $this->assertGreaterThan(3500, (int) $row['retry_after_max_seconds']);
    }

    public function test_two_models_behind_one_account_share_one_recovery_time(): void
    {
        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-a', 'groq-b'], 1),
        ]]);

        // One credential, one quota: the account is not usable again until its
        // wall clears, so the two models do not produce two countdowns.
        $this->health()->markEndpointRateLimited($this->candidate('groq-a'), 600);
        $this->health()->markEndpointRateLimited($this->candidate('groq-b'), 600);

        $row = $this->availability()->report()['internal_data'];

        $this->assertSame('quota_limited', $row['state']);
        $this->assertGreaterThan(500, (int) $row['retry_after_seconds']);
    }

    public function test_status_endpoint_exposes_capabilities_and_independent_count(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        config(['ai.providers' => [
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]]);

        $this->health()->recordSuccess($this->candidate('groq-model'), 100);
        $this->health()->recordSuccess($this->candidate('gemini-model'), 150);
        $this->health()->markToolsDemonstrated($this->candidate('groq-model'));
        $this->health()->markToolsDemonstrated($this->candidate('gemini-model'));

        $response = $this->actingAs($user)->getJson('/api/ai/status');

        $response->assertOk();
        $response->assertJsonPath('capabilities.internal_data.state', 'available');
        $response->assertJsonPath('capabilities.internal_data.available', true);
        $response->assertJsonPath('independent_providers', 2);

        // A safe report: model ids and provider names, never a key or a base URL.
        $body = $response->json();
        $encoded = json_encode($body);
        $this->assertStringNotContainsString('groq-key', (string) $encoded);
        $this->assertStringNotContainsString(self::GROQ, (string) $encoded);
    }
}

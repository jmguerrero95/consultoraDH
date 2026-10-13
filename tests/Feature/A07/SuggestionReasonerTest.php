<?php

declare(strict_types=1);

use App\Domain\Assistant\SuggestionEngine;
use App\Models\AiSuggestion;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 3, section 1.5: the model is allowed to help, and everything it says is
 * checked before anybody sees it.
 *
 * ## What is actually being protected
 *
 * Every assertion below is about one of two things: that the model is *not*
 * called when it should not be, or that its answer is discarded when it should
 * be. The happy path is the least interesting test in the file - a correct
 * answer is supposed to pass - and it is here to prove the checks are not simply
 * rejecting everything.
 *
 * The provider is faked throughout. These tests must never spend quota, and
 * more importantly they must be deterministic: a test whose result depends on
 * what a free model happened to say is not a test.
 */
final class SuggestionReasonerTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private const NOW = '2026-06-01 09:00';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'receivables.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'receivables.view']);

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'free-test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.suggestions.reasoning.enabled' => true,
        ]);

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A provider that answers with whatever text it is given.
     */
    private function providerSays(string $content): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response([
                'id' => 'resp_1',
                'model' => 'free-test-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ]),
        ]);
    }

    /** One overdue client per test, shared by the setup and `offer()`. */
    private ?Client $client = null;

    private function overdueClient(): Client
    {
        $client = Client::factory()->create(['first_names' => 'Ana', 'last_names' => 'Torres']);
        $period = a03_generatedPeriod('2026-01');
        $company = Company::factory()->create();
        $employer = a03_employer(
            client: $client,
            company: $company,
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: '2026-01',
        );

        a03_payable(45_000_000, '2026-01', $employer);

        return $client;
    }

    /**
     * An offer on the overdue client's own statement.
     *
     * Scoped to that client, because that is the only question a single
     * client's account can now ask - and an unscoped one is answered with
     * silence rather than with whoever happens to be worst.
     */
    private function offer(): AiSuggestion
    {
        $client = $this->client ??= $this->overdueClient();

        $suggestion = app(SuggestionEngine::class)
            ->currentFor($this->user, 'receivables', 'client_account', null, $client->id);

        return $suggestion;
    }

    /** How many times the provider was actually called. */
    private function providerCalls(): int
    {
        return count(Http::recorded());
    }

    public function test_a_well_formed_answer_is_used_and_recorded(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'El saldo está completamente vencido.',
            'rationale' => 'La evidencia muestra que la totalidad de los $45.000.000 está vencida.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertNotNull($suggestion->reasoned_explanation, 'A good answer is kept.');
        $this->assertStringContainsString('completamente vencido', $suggestion->reasoned_explanation);
        $this->assertSame('free-test-model', $suggestion->reasoned_model);
        $this->assertSame(1, $this->providerCalls());
    }

    public function test_the_reasoned_answer_is_what_the_client_is_shown(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'El saldo está completamente vencido.',
            'rationale' => 'La evidencia muestra que la totalidad de los $45.000.000 está vencida.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $this->offer();

        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account');

        $response->assertOk()
            ->assertJsonPath('suggestion.reasoned', true)
            ->assertJsonPath('suggestion.explanation', fn (string $text) => str_contains($text, 'completamente vencido'));
    }

    // ---------------------------------------------------------------------
    // Nothing invented
    // ---------------------------------------------------------------------

    public function test_an_answer_with_a_number_the_evidence_does_not_contain_is_thrown_away(): void
    {
        $this->client ??= $this->overdueClient();

        // $98.000.000 is not anywhere in the evidence. It is the model doing
        // arithmetic nobody asked for, and a collections operator acting on it
        // would be acting on a fiction.
        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'El cliente debe $98.000.000.',
            'rationale' => 'La evidencia indica una deuda de $98.000.000.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertNull($suggestion->reasoned_explanation, 'An invented figure discards the whole answer.');
        $this->assertSame(1, $this->providerCalls(), 'It is not retried into a better answer.');
    }

    public function test_an_answer_that_invents_an_action_is_thrown_away(): void
    {
        $this->client ??= $this->overdueClient();

        // "Dar de baja el cliente" is not something this feature may propose, and
        // nothing downstream would catch it - accepting a suggestion only opens
        // a conversation, so the invented instruction would be read as advice.
        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'El saldo está completamente vencido.',
            'rationale' => 'La totalidad de los $45.000.000 está vencida.',
            'action' => 'Dar de baja el cliente y eliminar su historial.',
        ], JSON_UNESCAPED_UNICODE));

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertNull($suggestion->reasoned_explanation);
    }

    public function test_an_answer_that_is_not_json_is_thrown_away(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays('Estimado usuario, su cliente tiene una deuda importante.');

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertNull($suggestion->reasoned_explanation, 'Prose where JSON was required is not parsed into a claim.');
    }

    public function test_the_model_is_allowed_to_decide_it_has_nothing_to_add(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'silent',
            'headline' => '',
            'rationale' => '',
            'action' => '',
        ], JSON_UNESCAPED_UNICODE));

        $suggestion = $this->offer();
        $suggestion->refresh();

        // Silence is a correct outcome, not a failure to retry around.
        $this->assertNull($suggestion->reasoned_explanation);
        $this->assertSame(1, $this->providerCalls());
    }

    public function test_a_provider_outage_leaves_the_generated_sentence_untouched(): void
    {
        $this->client ??= $this->overdueClient();

        Http::fake([self::PROVIDER_URL => Http::response(['error' => 'overloaded'], 503)]);

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertNull($suggestion->reasoned_explanation);

        // And the person is still told something true.
        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account');

        $response->assertOk()
            ->assertJsonPath('suggestion.reasoned', false)
            ->assertJsonPath('suggestion.explanation', fn ($t) => str_contains($t, 'Ana Torres'));
    }

    public function test_no_provider_configured_is_not_an_error(): void
    {
        $this->client ??= $this->overdueClient();

        config([
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
        ]);

        $suggestion = $this->offer();

        $this->assertNotNull($suggestion, 'The suggestion still exists without a model.');
    }

    // ---------------------------------------------------------------------
    // Not called when it should not be
    // ---------------------------------------------------------------------

    public function test_rereading_an_offer_never_costs_a_model_call(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'Todo vencido.',
            'rationale' => 'Los $45.000.000 están vencidos.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $this->offer();
        $this->assertSame(1, $this->providerCalls());

        // Ten page refreshes of a card that is already on offer.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user)->getJson('/api/ai/suggestion?module=receivables&operation=client_account');
        }

        $this->assertSame(1, $this->providerCalls(), 'Navigation must not spend quota.');
    }

    public function test_the_same_problem_is_not_reasoned_twice_in_a_day(): void
    {
        $client = $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'Todo vencido.',
            'rationale' => 'Los $45.000.000 están vencidos.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $this->offer();

        // Cooldown has passed, so it is offered again - but the reasoning window
        // has not, and the stored answer is reused.
        Carbon::setTestNow(Carbon::parse('2026-06-01 18:00'));
        app(SuggestionEngine::class)->currentFor($this->user, 'receivables', 'client_account', null, $client->id);

        $this->assertSame(1, $this->providerCalls(), 'Reasoned once, then reused.');
    }

    public function test_reasoning_is_skipped_entirely_when_switched_off(): void
    {
        $this->client ??= $this->overdueClient();

        config(['ai.suggestions.reasoning.enabled' => false]);
        $this->providerSays('{"decision":"answer","headline":"x","rationale":"y","action":"z"}');

        $suggestion = $this->offer();
        $suggestion->refresh();

        $this->assertSame(0, $this->providerCalls());
        $this->assertNull($suggestion->reasoned_explanation);
    }

    public function test_a_weak_signal_is_never_worth_a_model_call(): void
    {
        // Half the balance late is below the detector's own threshold, so there
        // is no suggestion and therefore nothing to reason about.
        $client = Client::factory()->create(['first_names' => 'Ana', 'last_names' => 'Torres']);
        $period = a03_generatedPeriod('2026-01');
        $company = Company::factory()->create();
        $employer = a03_employer(
            client: $client,
            company: $company,
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: '2026-01',
        );

        a03_payable(40_000_000, '2026-01', $employer);
        a03_payable(60_000_000, '2026-05', $employer);

        $this->providerSays('{"decision":"answer","headline":"x","rationale":"y","action":"z"}');

        $this->assertNull(app(SuggestionEngine::class)->currentFor($this->user, 'receivables', 'client_account'));
        $this->assertSame(0, $this->providerCalls());
    }

    public function test_the_daily_reasoning_ceiling_holds(): void
    {
        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'Todo vencido.',
            'rationale' => 'Los $45.000.000 están vencidos.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        // Five separate problems, so five separate opportunities to spend.
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $i => $month) {
            $client = Client::factory()->create(['first_names' => 'Luis', 'last_names' => 'R'.$i]);
            $period = a03_generatedPeriod($month);
            $company = Company::factory()->create();
            $employer = a03_employer(
                client: $client,
                company: $company,
                relationshipStart: $period->period_month->toDateString(),
                effectiveMonth: $month,
            );

            a03_payable(45_000_000, $month, $employer);

            Carbon::setTestNow(Carbon::parse('2026-06-01 0'.min($i + 1, 9).':00'));
            app(SuggestionEngine::class)->currentFor($this->user, 'receivables', 'client_account', null, $client->id);
        }

        // Free tiers are metered by request, and an assistant that spends the
        // budget on page views stops answering for the rest of the day.
        $this->assertLessThanOrEqual(2, $this->providerCalls(), 'Reasoning is bounded per person per day.');
        $this->assertGreaterThan(0, $this->providerCalls(), 'And it still happens when justified.');
    }

    public function test_stale_reasoning_is_dropped_when_the_evidence_moves(): void
    {
        $this->client ??= $this->overdueClient();

        $this->providerSays(json_encode([
            'decision' => 'answer',
            'headline' => 'Todo vencido.',
            'rationale' => 'Los $45.000.000 están vencidos.',
            'action' => 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        ], JSON_UNESCAPED_UNICODE));

        $this->offer();

        $shown = app(SuggestionEngine::class)->present(AiSuggestion::query()->firstOrFail());
        $this->assertTrue($shown['reasoned']);

        // The evidence the reasoning was written from is edited without going
        // through the engine - an import applied elsewhere, say. The sentence
        // still describes the old figures, so it must stop being used.
        $row = AiSuggestion::query()->firstOrFail();
        $evidence = $row->evidence;
        $evidence['outstanding_cop'] = 1;
        $row->update(['evidence' => $evidence]);

        $after = app(SuggestionEngine::class)->present($row->fresh());

        $this->assertFalse($after['reasoned'], 'A sentence about figures that have changed is not shown.');
        $this->assertStringContainsString('45.000.000', $after['explanation']);
    }
}

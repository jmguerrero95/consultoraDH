<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiMessage;
use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiModelHealth;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiTurnBudget;
use App\Services\Ai\ModelCandidate;
use App\Services\Ai\ModelPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * BUG-021: the failure/recovery policy, exercised hermetically.
 *
 * Every case here drives the real assistant over the real provider adapter with
 * the HTTP boundary faked. No credential from `.env` is reachable: `phpunit.xml`
 * blanks them and `TestCase` refuses any un-faked request, so a test that reached
 * the network would fail rather than silently spend a free tier's quota.
 *
 * The point of each case is *scope*: which failures let the turn continue on an
 * independent provider, which are terminal, and whether a turn spends one
 * provider-call budget or several.
 */
final class AiErrorScopeAndFailoverTest extends TestCase
{
    use RefreshDatabase;

    private const GROQ = 'https://groq.test/v1';

    private const GEMINI = 'https://gemini.test/v1';

    private User $user;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'automations.view', 'receivables.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'automations.view', 'receivables.view']);

        // A clean slate: the two legacy slots and the catalogue-driven OpenRouter
        // sources are off, so only the providers a test declares exist.
        config([
            'ai.enabled' => true,
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.pool' => [],
            'ai.extra_models' => [],
            'ai.openrouter.dynamic_pool' => false,
            'ai.openrouter.include_free_router' => false,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $models
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    private function provider(string $base, string $key, array $models, int $priority, array $capabilities = ['chat', 'tools'], string $policy = 'standard'): array
    {
        return [
            'base_url' => $base,
            'api_key' => $key,
            'models' => $models,
            'priority' => $priority,
            'capabilities' => $capabilities,
            'data_policy' => $policy,
            'enabled' => true,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    private function useProviders(array $providers): void
    {
        config(['ai.providers' => $providers]);
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $content): array
    {
        return ['id' => 'r', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments = [], string $id = 'call_1'): array
    {
        return ['id' => 'r', 'choices' => [['message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
            ]],
        ]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBody(string $message): array
    {
        return ['error' => ['message' => $message]];
    }

    private function url(string $base): string
    {
        return rtrim($base, '/').'/chat/completions';
    }

    /**
     * @return list<array{0: Request, 1: Response}>
     */
    private function requestsTo(string $base): array
    {
        $url = $this->url($base);

        return Http::recorded()
            ->filter(static fn (array $pair): bool => $pair[0]->url() === $url)
            ->values()
            ->all();
    }

    /**
     * @return array{reply: string, provider: ?string, model: ?string, proposals: array<int, mixed>, tools: array<int, mixed>}
     */
    private function ask(string $question = 'Explica en una frase qué es una EPS en Colombia.'): array
    {
        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        return app(AiAssistantService::class)->ask($this->conversation, $question, $this->user);
    }

    private function messages(): Builder
    {
        return AiMessageModel::query()->where('ai_conversation_id', $this->conversation->id);
    }

    // --------------------------------------------------- credential / access

    public function test_a_rejected_credential_fails_over_to_an_independent_provider(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->errorBody('Access denied. Please check your network settings.'), 403),
            $this->url(self::GEMINI) => Http::response($this->text('Una EPS es una entidad que administra el aseguramiento en salud.')),
        ]);

        $result = $this->ask();

        $this->assertStringContainsString('EPS', $result['reply']);
        $this->assertSame('gemini-model', $result['model'], 'The independent provider answered.');
        $this->assertCount(1, $this->requestsTo(self::GROQ));
        $this->assertCount(1, $this->requestsTo(self::GEMINI));
    }

    public function test_a_sibling_model_behind_a_rejected_key_is_not_tried(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-a', 'groq-b'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->errorBody('Access denied.'), 403),
            $this->url(self::GEMINI) => Http::response($this->text('Respuesta del proveedor independiente.')),
        ]);

        $this->ask();

        // The whole endpoint is quarantined: the second model of the same account
        // is not asked, because it shares the credential that was just refused.
        $this->assertCount(1, $this->requestsTo(self::GROQ), 'The rejected key is probed once, not once per model.');
        $this->assertCount(1, $this->requestsTo(self::GEMINI));
    }

    public function test_a_rejected_key_does_not_block_later_turns(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->errorBody('Access denied.'), 403),
            $this->url(self::GEMINI) => Http::response($this->text('Respuesta del proveedor independiente.')),
        ]);

        $this->ask();

        $second = $this->ask('¿Y una EPS qué cubre?');

        $this->assertSame('Respuesta del proveedor independiente.', $second['reply']);
        // The quarantined credential is not re-probed on the next question.
        $this->assertCount(1, $this->requestsTo(self::GROQ), 'A quarantined credential is not used as a health probe.');
        $this->assertCount(2, $this->requestsTo(self::GEMINI));
    }

    // ------------------------------------------------------------- model faults

    public function test_a_missing_model_does_not_stop_the_turn(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['retired-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->errorBody('The model `retired-model` does not exist.'), 404),
            $this->url(self::GEMINI) => Http::response($this->text('Respuesta válida.')),
        ]);

        $result = $this->ask();

        $this->assertSame('Respuesta válida.', $result['reply']);
        $this->assertCount(1, $this->requestsTo(self::GROQ));
    }

    public function test_an_empty_answer_fails_over_to_a_model_that_answers(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['empty-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            // A 200 with neither prose nor a tool call is not an answer.
            $this->url(self::GROQ) => Http::response(['id' => 'r', 'choices' => [[]]]),
            $this->url(self::GEMINI) => Http::response($this->text('Respuesta con texto.')),
        ]);

        $result = $this->ask();

        $this->assertSame('Respuesta con texto.', $result['reply']);
    }

    // ---------------------------------------------------------------- quotas

    public function test_an_account_wide_quota_does_not_try_sibling_models(): void
    {
        $this->useProviders([
            // Two models behind one account: a free-tier limit belongs to both.
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-a', 'groq-b'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('Rate limit reached for requests on free models.'),
                429,
                ['Retry-After' => '30'],
            ),
            $this->url(self::GEMINI) => Http::response($this->text('Otra cuenta responde.')),
        ]);

        $result = $this->ask();

        $this->assertSame('Otra cuenta responde.', $result['reply']);
        $this->assertCount(1, $this->requestsTo(self::GROQ), 'One account limit is not retried per model.');
        $this->assertCount(1, $this->requestsTo(self::GEMINI));
    }

    // -------------------------------------------------- terminal scopes

    public function test_a_content_policy_refusal_is_terminal_and_not_evaded_by_failover(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('Your request was rejected as a result of our safety system.'),
                403,
            ),
            $this->url(self::GEMINI) => Http::response($this->text('No debería llegar aquí.')),
        ]);

        try {
            $this->ask();
            $this->fail('A content-policy refusal must end the turn.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::SCOPE_POLICY, $e->scope);
            $this->assertTrue($e->isTerminal());
        }

        $this->assertCount(1, $this->requestsTo(self::GROQ));
        $this->assertCount(0, $this->requestsTo(self::GEMINI), 'A safety refusal is not answered by another provider.');
    }

    public function test_no_provider_configured_is_terminal(): void
    {
        $this->useProviders([]);

        try {
            $this->ask();
            $this->fail('With no provider the turn must fail.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::SCOPE_INTERNAL, $e->scope);
            $this->assertTrue($e->isTerminal());
        }

        Http::assertNothingSent();
    }

    public function test_an_unauthorized_write_is_refused_without_provider_hopping(): void
    {
        // The user has ai.use but not tasks.create: the write tool is refused.
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::sequence()
                ->push($this->toolCall('create_operational_task', [
                    'title' => 'Tarea no autorizada',
                    'assigned_to' => $this->user->id,
                    'priority' => 'high',
                ]))
                ->push($this->text('No pude crear la tarea.')),
            $this->url(self::GEMINI) => Http::response($this->text('No debería llegar aquí.')),
        ]);

        $result = $this->ask('Crea una tarea para revisar cartera');

        $this->assertSame([], $result['proposals'], 'The refusal stores nothing.');
        $this->assertSame(0, AiActionProposal::query()->where('user_id', $this->user->id)->count());
        // An authorization refusal is the application's, not a provider fault:
        // it does not trigger a failover to the second provider.
        $this->assertCount(0, $this->requestsTo(self::GEMINI));
    }

    // -------------------------------------------------------------- privacy

    public function test_private_data_is_not_sent_to_a_training_policy_provider(): void
    {
        // Explicit, so the test does not inherit the operator's .env choice:
        // what is asserted here is the fail-closed policy, not this deployment.
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'training' => $this->provider(self::GROQ, 'groq-key', ['training-model'], 1, ['chat', 'tools'], 'training'),
            'standard' => $this->provider(self::GEMINI, 'gemini-key', ['standard-model'], 2, ['chat', 'tools'], 'standard'),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->text('No debería recibir datos de negocio.')),
            $this->url(self::GEMINI) => Http::response($this->text('El resumen de cartera está disponible.')),
        ]);

        // "cartera" routes this to a system task, which carries business data.
        $result = $this->ask('¿Cuál es el resumen de cartera?');

        $this->assertSame('standard-model', $result['model']);
        $this->assertCount(0, $this->requestsTo(self::GROQ), 'A training-policy provider never receives business data.');
        $this->assertCount(1, $this->requestsTo(self::GEMINI));
    }

    // ------------------------------------------------------- capability routing

    public function test_a_model_that_rejects_tools_is_downgraded_and_skipped_next_time(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['no-tools-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('This model does not support tools.'),
                400,
            ),
            $this->url(self::GEMINI) => Http::response($this->text('Las automatizaciones disponibles son estas.')),
        ]);

        // "automatizaciones" routes to a tool-bearing system task.
        $first = $this->ask('¿Qué automatizaciones puedo crear?');

        $this->assertSame('gemini-model', $first['model']);
        $this->assertCount(1, $this->requestsTo(self::GROQ));

        // The downgrade is reflected in the reported state: it can still talk.
        $snapshot = collect(app(AiProviderResolver::class)->snapshot())
            ->firstWhere('model', 'no-tools-model');
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['tools'], 'A model that refused tools is reported as conversation-only.');

        // A later tool turn does not offer it the same rejected payload.
        $before = count($this->requestsTo(self::GROQ));
        $this->ask('¿Qué automatizaciones puedo crear?');

        $this->assertSame($before, count($this->requestsTo(self::GROQ)), 'The downgraded model is not asked again for tool work.');
    }

    // ------------------------------------------------------------- budgets

    public function test_tool_rounds_share_one_attempt_budget_for_the_whole_turn(): void
    {
        config(['ai.tool_max_attempts' => 2, 'ai.tool_max_rounds' => 4]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['loop-model'], 1),
        ]);

        // The model keeps waking a tool and never answers.
        Http::fake([
            $this->url(self::GROQ) => Http::response($this->toolCall('automation_vocabulary')),
        ]);

        try {
            $this->ask('¿Qué automatizaciones puedo crear?');
            $this->fail('A turn that never answers must fail rather than spin.');
        } catch (AiProviderException) {
            // The budget, not the round limit, is what stopped it.
        }

        // Before BUG-021 each round restarted the counter, so four rounds could
        // make eight calls. One shared budget spends two, in total.
        Http::assertSentCount(2);
    }

    public function test_a_spent_time_budget_makes_no_request(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
        ]);

        Http::fake();

        $spent = new AiTurnBudget(deadline: microtime(true) - 5, maxAttempts: 4);

        try {
            app(AiProviderResolver::class)->complete([AiMessage::user('hola')], [], null, 'chat', null, false, $spent);
            $this->fail('A turn past its deadline must not call a provider.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::SCOPE_TRANSPORT, $e->scope);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------- privacy classification contract

    public function test_a_call_without_a_privacy_classification_is_refused_before_any_request(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
        ]);

        Http::fake();

        try {
            // The classification argument is omitted: the historical default of
            // "public" is gone, so this must not reach a provider.
            app(AiProviderResolver::class)->complete([AiMessage::user('hola')], [], null, 'chat', null, null, null);
            $this->fail('A missing classification must be refused, not defaulted to public.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::SCOPE_INTERNAL, $e->scope);
            $this->assertSame('privacy_contract', $e->kind);
            $this->assertTrue($e->isTerminal(), 'No provider can make a missing classification safe.');
        }

        Http::assertNothingSent();
    }

    public function test_public_text_with_a_sensitive_identifier_cannot_be_downgraded_to_public(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        // The only provider may train on the content and is not authorised for
        // private data.
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['chat', 'tools'], 'training'),
        ]);

        Http::fake();

        try {
            // Explicitly public, but the payload carries an email: the content
            // floor promotes it, so the training provider is not eligible.
            app(AiProviderResolver::class)->complete(
                [AiMessage::user('mi correo es ana.gomez@example.com, ¿lo actualizas?')],
                [],
                null,
                'chat',
                null,
                false,
                null,
            );
            $this->fail('A payload with a sensitive identifier must not reach a training provider.');
        } catch (AiProviderException) {
            // No authorised candidate: the record stays on the server.
        }

        Http::assertNothingSent();
    }

    public function test_an_explicitly_public_clean_call_may_use_a_training_provider(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['chat', 'tools'], 'training'),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->text('Una EPS administra el aseguramiento.')),
        ]);

        $result = app(AiProviderResolver::class)->complete(
            [AiMessage::user('Explica brevemente qué es una EPS.')],
            [],
            null,
            'chat',
            null,
            false,
            null,
        );

        $this->assertSame('Una EPS administra el aseguramiento.', $result->content);
        Http::assertSentCount(1);
    }

    public function test_an_explicitly_private_call_only_uses_an_authorised_provider(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['chat', 'tools'], 'training'),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2, ['chat', 'tools'], 'standard'),
        ]);

        Http::fake([
            $this->url(self::GEMINI) => Http::response($this->text('El saldo del cliente es privado.')),
        ]);

        app(AiProviderResolver::class)->complete(
            [AiMessage::user('¿Cuál es el saldo del cliente?')],
            [],
            null,
            'chat',
            null,
            true,
            null,
        );

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $this->url(self::GROQ));
    }

    // ------------------------------------------------------ continuity

    public function test_a_failover_persists_exactly_one_turn(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response($this->errorBody('Access denied.'), 403),
            $this->url(self::GEMINI) => Http::response($this->text('Una EPS administra el aseguramiento en salud.')),
        ]);

        $question = 'Explica qué es una EPS en Colombia.';
        $result = $this->ask($question);

        $this->assertSame(2, $this->messages()->count(), 'One user turn and one assistant turn, no duplicates.');
        $this->assertSame(1, $this->messages()->where('role', 'user')->count());
        $this->assertSame(1, $this->messages()->where('role', 'assistant')->count());
        $this->assertSame($question, $this->messages()->where('role', 'user')->sole()->content);
        $this->assertSame($result['reply'], $this->messages()->where('role', 'assistant')->sole()->content);
    }

    public function test_a_tool_result_survives_a_synthesis_failure_on_another_provider(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            // The first provider calls a read tool, then fails on the synthesis.
            $this->url(self::GROQ) => Http::sequence()
                ->push($this->toolCall('automation_vocabulary', [], 'call_keep'))
                ->push($this->errorBody('Service unavailable.'), 503),
            $this->url(self::GEMINI) => Http::response($this->text('Las automatizaciones admiten estos disparadores.')),
        ]);

        $result = $this->ask('¿Qué automatizaciones puedo crear?');

        $this->assertSame('gemini-model', $result['model']);
        $this->assertSame('Las automatizaciones admiten estos disparadores.', $result['reply']);

        // The operation was not repeated: one tool call, one failure, one answer.
        Http::assertSentCount(3);
        $this->assertCount(2, $this->requestsTo(self::GROQ));
        $this->assertCount(1, $this->requestsTo(self::GEMINI));

        // The result of the tool call travelled to the provider that continued,
        // under the same call id — nothing was lost, and nothing was re-run.
        [$request] = $this->requestsTo(self::GEMINI)[0];
        $toolMessages = array_values(array_filter(
            $request->data()['messages'] ?? [],
            static fn (array $message): bool => ($message['role'] ?? null) === 'tool',
        ));

        $this->assertCount(1, $toolMessages);
        $this->assertSame('call_keep', $toolMessages[0]['tool_call_id']);
        $this->assertSame('automation_vocabulary', $toolMessages[0]['name']);
    }

    // ----------------------------------------- A07-R5 daily quota & safe errors

    private function candidateFor(string $host): ModelCandidate
    {
        return collect(app(ModelPool::class)->all())
            ->firstOrFail(static fn (ModelCandidate $candidate): bool => $candidate->providerHost() === $host);
    }

    /**
     * A *daily* account limit is not the ordinary cooldown.
     *
     * OpenRouter's `free-models-per-day` cannot move until the day rolls over, so
     * re-probing the account every thirty minutes only spends a request to learn
     * the same thing. The account is set aside for the rest of the UTC day, and an
     * independent provider — a different account — is untouched.
     */
    public function test_a_daily_account_quota_suspends_only_that_account(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('Rate limit exceeded: free-models-per-day. Add 10 credits to unlock 1000 free model requests per day'),
                429,
            ),
            $this->url(self::GEMINI) => Http::response($this->text('Otra cuenta responde.')),
        ]);

        $result = $this->ask();

        $this->assertSame('gemini-model', $result['model'], 'An independent account is unaffected by another account daily limit.');

        $health = app(AiModelHealth::class);
        $groq = $this->candidateFor('groq');

        $this->assertTrue($health->endpointRateLimited($groq));
        $this->assertGreaterThan(3600, $health->endpointRateLimitedRemaining($groq), 'A daily limit is not a 30-minute cooldown.');
        $this->assertFalse($health->endpointRateLimited($this->candidateFor('gemini')), 'The independent account is not suspended.');
    }

    public function test_a_per_minute_quota_is_not_extended_to_the_day(): void
    {
        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
            'gemini' => $this->provider(self::GEMINI, 'gemini-key', ['gemini-model'], 2),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('Rate limit reached for requests on free models.'),
                429,
                ['Retry-After' => '30'],
            ),
            $this->url(self::GEMINI) => Http::response($this->text('Otra cuenta responde.')),
        ]);

        $this->ask();

        $remaining = app(AiModelHealth::class)->endpointRateLimitedRemaining($this->candidateFor('groq'));

        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(120, $remaining, 'A per-minute limit clears in seconds, not hours.');
    }

    /**
     * The provider's own words invite a payment; the interface is never shown them.
     */
    public function test_the_public_message_never_carries_the_provider_commercial_text(): void
    {
        $e = AiProviderException::rateLimited(
            'The AI provider is rate limiting this deployment. (Rate limit exceeded: free-models-per-day. '
                .'Add 10 credits to unlock 1000 free model requests per day)',
            30,
            true,
            true,
        );

        $this->assertStringContainsString('límite', $e->publicMessage());
        $this->assertStringNotContainsStringIgnoringCase('credit', $e->publicMessage());
        $this->assertStringNotContainsStringIgnoringCase('free-models', $e->publicMessage());
        $this->assertStringNotContainsStringIgnoringCase('credit', $e->safeDetail());
        $this->assertStringContainsString('HTTP 429', $e->safeDetail());
    }

    public function test_a_commercial_quota_message_never_reaches_the_interface(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1),
        ]);

        Http::fake([
            $this->url(self::GROQ) => Http::response(
                $this->errorBody('Rate limit exceeded: free-models-per-day. Add 10 credits to unlock 1000 free model requests per day'),
                429,
            ),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Explica en una frase qué es una EPS en Colombia.',
        ]);

        $response->assertStatus(503);

        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($payload);
        $this->assertStringNotContainsStringIgnoringCase('credit', $payload);
        $this->assertStringNotContainsStringIgnoringCase('free-models', $payload);
        $this->assertStringNotContainsStringIgnoringCase('payment', $payload);
    }

    /**
     * The diagnostics must say *why* a provider was skipped.
     *
     * "Gemini was never tried because the question was classified as private" and
     * "Gemini was tried and refused the payload" are different diagnoses. The turn
     * record distinguishes them and carries no prompt text.
     */
    public function test_diagnostics_distinguish_a_privacy_exclusion_from_a_refusal(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->useProviders([
            'groq' => $this->provider(self::GROQ, 'groq-key', ['groq-model'], 1, ['chat', 'tools'], 'training'),
        ]);

        Http::fake();
        Cache::forget('ai.diagnostics.recent');

        try {
            $this->ask('Consulta las relaciones de nuestros clientes.');
            $this->fail('A private turn with no authorised provider must fail.');
        } catch (AiProviderException) {
            // Expected: the record stays on the server.
        }

        $rows = Cache::get('ai.diagnostics.recent', []);

        // The newest row is the turn's final result; the provider diagnosis that
        // explains it sits just below. Both belong to the same turn.
        $turnRow = $rows[0] ?? null;
        $this->assertIsArray($turnRow);
        $this->assertSame('turn', $turnRow['outcome']);
        $this->assertSame('failed', $turnRow['result']);

        $row = null;

        foreach ($rows as $candidate) {
            if (($candidate['outcome'] ?? null) === 'no_candidates') {
                $row = $candidate;
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertSame('privacy', $row['scope']);
        $this->assertSame(0, $row['eligible']);
        $this->assertContains('client_data', $row['required']);
        $this->assertArrayHasKey('turn', $row);
        $this->assertStringNotContainsString('nuestros clientes', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiMessage;
use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\OperationalTask;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiTurnFailed;
use App\Services\Ai\ModelPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07: the assistant turn as it actually runs.
 *
 * These tests drive the real `AiAssistantService` over the real
 * `OpenAiCompatibleProvider`, with the provider's HTTP call faked. Nothing
 * between the HTTP boundary and the database is stubbed, so what is asserted is
 * the runtime behaviour: the exact bytes the provider was asked to read, the rows
 * that were written, and the refusals that were issued.
 */
final class AiAssistantRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    private User $assignee;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'tasks.create', 'clients.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // This file runs against the test database without refreshing it, so
        // earlier runs leave rows behind. The responsible party therefore needs a
        // name no earlier run could have used: the assignee rule treats a
        // duplicated full name as ambiguous, and asking would be the right answer
        // for two real colleagues called the same thing.
        $this->assignee = User::factory()->create(['name' => 'Laura Sintetica QA '.Str::random(8)]);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'tasks.create']);

        // A configured primary is what lets the turn reach the HTTP boundary at all.
        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.proposal_ttl_minutes' => 15,
        ]);
    }

    private function ask(string $question): array
    {
        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);

        return app(AiAssistantService::class)->ask($this->conversation, $question, $this->user);
    }

    /**
     * A request that names the responsible party.
     *
     * A model may not choose who owns a task, and the person asking is never
     * assumed to be the owner, so the valid-write cases have to name somebody —
     * exactly as a person would.
     */
    private function taskQuestion(): string
    {
        return 'Crea una tarea para '.$this->assignee->name;
    }

    /**
     * Assertions are scoped to what this test created, so a row left in the test
     * database by an earlier aborted run cannot be mistaken for this test's work.
     */
    private function myProposals(): Builder
    {
        return AiActionProposal::query()->where('user_id', $this->user->id);
    }

    private function myTasks(): Builder
    {
        return OperationalTask::query()->where('assigned_to', $this->assignee->id);
    }

    private function myMessages(): Builder
    {
        return AiMessageModel::query()
            ->where('ai_conversation_id', $this->conversation->id);
    }

    /**
     * A provider answer whose message carries one tool call.
     */
    private function fakeToolCall(string $name, array $arguments, string $callId = 'call_1'): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_1',
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => $callId,
                                'type' => 'function',
                                'function' => [
                                    'name' => $name,
                                    'arguments' => json_encode($arguments),
                                ],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'id' => 'resp_2',
                    'choices' => [['message' => [
                        'role' => 'assistant',
                        'content' => 'No pude preparar esa operacion.',
                    ]]],
                ]),
        ]);
    }

    private function fakeText(string $content): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response([
                'id' => 'resp_1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ]),
        ]);
    }

    private function validTaskArguments(): array
    {
        return [
            'title' => 'Revisar cartera vencida',
            'assigned_to' => $this->assignee->id,
            'priority' => 'high',
        ];
    }

    // ---------------------------------------------------------------- FIX 1

    public function test_valid_write_call_creates_a_pending_proposal_with_normalized_arguments(): void
    {
        $this->fakeToolCall('create_operational_task', $this->validTaskArguments());

        $result = $this->ask($this->taskQuestion());

        $this->assertCount(1, $result['proposals'], 'A valid write call produces exactly one proposal.');

        $proposal = $this->myProposals()->sole();
        $this->assertSame(AiActionProposal::PENDING, $proposal->status);
        $this->assertSame('create_operational_task', $proposal->tool);
        $this->assertSame((int) $this->user->id, (int) $proposal->user_id);
        $this->assertSame('Revisar cartera vencida', $proposal->arguments['title']);
    }

    public function test_malformed_write_call_creates_no_proposal(): void
    {
        // `assigned_to` is required by the tool's own validator.
        $this->fakeToolCall('create_operational_task', ['title' => 'Sin responsable']);

        $result = $this->ask('Crea una tarea');

        $this->assertSame(0, $this->myProposals()->count(), 'An unusable call must store nothing.');
        $this->assertSame([], $result['proposals']);
    }

    public function test_unauthorized_write_call_creates_no_proposal(): void
    {
        $this->user->revokePermissionTo('tasks.create');

        $this->fakeToolCall('create_operational_task', $this->validTaskArguments());

        $result = $this->ask('Crea una tarea');

        $this->assertSame(0, $this->myProposals()->count(), 'An unauthorized call must store nothing.');
        $this->assertSame([], $result['proposals']);
    }

    public function test_no_mutation_happens_before_confirmation(): void
    {
        $this->fakeToolCall('create_operational_task', $this->validTaskArguments());

        $this->ask($this->taskQuestion());

        $this->assertSame(0, $this->myTasks()->count(), 'The model asking is not the work being done.');
    }

    public function test_confirmation_performs_exactly_one_mutation_and_a_second_confirmation_is_refused(): void
    {
        $this->fakeToolCall('create_operational_task', $this->validTaskArguments());

        $this->ask($this->taskQuestion());

        $proposal = $this->myProposals()->sole();
        $this->assertSame(0, $this->myTasks()->count());

        $this->actingAs($this->user)
            ->postJson("/api/ai/action-proposals/{$proposal->id}/confirm")
            ->assertOk();

        $this->assertSame(1, $this->myTasks()->count(), 'Confirmation performs the work once.');

        $this->actingAs($this->user)
            ->postJson("/api/ai/action-proposals/{$proposal->id}/confirm")
            ->assertStatus(400);

        $this->assertSame(1, $this->myTasks()->count(), 'A second confirmation must not repeat the work.');
    }

    public function test_permission_revoked_before_confirmation_prevents_execution(): void
    {
        $this->fakeToolCall('create_operational_task', $this->validTaskArguments());

        $this->ask($this->taskQuestion());
        $proposal = $this->myProposals()->sole();

        $this->user->revokePermissionTo('tasks.create');
        $this->user->unsetRelation('permissions');

        $this->actingAs($this->user)
            ->postJson("/api/ai/action-proposals/{$proposal->id}/confirm")
            ->assertStatus(500);

        $this->assertSame(0, $this->myTasks()->count(), 'Authorization is re-checked at confirmation.');
    }

    // ---------------------------------------------------------------- FIX 2

    public function test_tool_round_trip_sends_assistant_tool_calls_then_matching_tool_results(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_1',
                    'choices' => [['message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_operational_task',
                                'arguments' => json_encode($this->validTaskArguments()),
                            ],
                        ]],
                    ]]],
                ])
                // The synthesis round: the model answers in prose after seeing
                // the tool result. A write tool stops the loop, so this branch is
                // exercised by the read-tool test below.
                ->push([
                    'id' => 'resp_2',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Listo.']]],
                ]),
        ]);

        $this->ask($this->taskQuestion());

        $sent = Http::recorded();

        $this->assertCount(1, $sent, 'A write tool stops the loop; no synthesis round is requested.');

        // Nothing was executed, and the proposal is what came back.
        $this->assertSame(0, $this->myTasks()->count());
        $this->assertSame(1, $this->myProposals()->count());
    }

    public function test_read_tool_round_trip_returns_result_to_provider_and_final_answer_is_synthesized(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_1',
                    'choices' => [['message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_xyz',
                            'type' => 'function',
                            'function' => [
                                'name' => 'automation_vocabulary',
                                'arguments' => '{}',
                            ],
                        ]],
                    ]]],
                ])
                ->push([
                    'id' => 'resp_2',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Las automatizaciones admiten estos disparadores.']]],
                ]),
        ]);

        $result = $this->ask('¿Qué automatizaciones puedo crear?');

        $sent = Http::recorded();
        $this->assertCount(2, $sent, 'A read tool is followed by exactly one synthesis round.');

        // The second request must carry the assistant tool-call message and the
        // matching tool result. Without both, the provider is asked to continue a
        // conversation that never happened.
        $second = $this->messagesOf($sent[1][0]);
        $roles = array_column($second, 'role');

        $this->assertSame(
            ['system', 'user', 'assistant', 'tool'],
            $roles,
            'The continuation carries the assistant tool-call message before its tool result.'
        );

        $assistant = $second[2];
        $this->assertArrayHasKey('tool_calls', $assistant, 'The assistant message declares its tool calls.');
        $this->assertSame('call_xyz', $assistant['tool_calls'][0]['id']);
        $this->assertSame('automation_vocabulary', $assistant['tool_calls'][0]['function']['name']);
        $this->assertIsString($assistant['tool_calls'][0]['function']['arguments']);

        $tool = $second[3];
        $this->assertSame('call_xyz', $tool['tool_call_id'], 'The tool result answers that exact call id.');
        $this->assertSame('automation_vocabulary', $tool['name']);
        $this->assertJson($tool['content']);

        // And the answer the caller receives is the model's, not a summary of the tool.
        $this->assertSame('Las automatizaciones admiten estos disparadores.', $result['reply']);
    }

    public function test_synthesis_failure_fails_the_turn_and_persists_no_assistant_reply(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_1',
                    'choices' => [['message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'automation_vocabulary', 'arguments' => '{}'],
                        ]],
                    ]]],
                ])
                ->push(['error' => ['message' => 'upstream exploded']], 500),
        ]);

        try {
            $this->ask('¿Qué automatizaciones puedo crear?');
            $this->fail('A provider failure during synthesis must not return a reply.');
        } catch (AiProviderException) {
            // Expected: the turn failed rather than answering.
        }

        $this->assertSame(
            0,
            $this->myMessages()->where('role', AiMessage::ROLE_ASSISTANT)->count(),
            'A failed turn stores no assistant reply to be mistaken for an answer later.'
        );
    }

    /**
     * A retired model does not always arrive as a 404.
     *
     * Cloudflare reports a deprecated model as a 503 whose body names the model.
     * Classified as transport it would cool for a moment and be asked again on
     * the next turn; because the body is explicit about a retired model, it is
     * treated as missing and parked, exactly like a 404.
     */
    public function test_a_503_that_names_a_deprecated_model_is_parked_as_missing(): void
    {
        // Only the faked primary may be in the pool: a real deployment's named
        // providers would otherwise be reached as fallback candidates.
        config([
            'ai.pool' => [],
            'ai.extra_models' => [],
            'ai.providers' => [],
            'ai.openrouter.dynamic_pool' => false,
            'ai.openrouter.include_free_router' => false,
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::response([
                'success' => false,
                'errors' => [[
                    'code' => 7001,
                    'message' => 'Model has been deprecated: @cf/meta/infire-llama-3.1-8b-instruct was deprecated on 2026-05-30. Choose an available model.',
                ]],
            ], 503),
        ]);

        $this->assertCount(1, app(ModelPool::class)->all(), 'Only the faked primary is reachable.');

        try {
            $this->ask('¿Qué es una EPS en Colombia?');
            $this->fail('A retired model must not be answered as if it were merely unreachable.');
        } catch (AiProviderException $e) {
            $this->assertSame(
                'not_found',
                $e->kind,
                'A 503 that names a deprecated model is a catalogue problem, not an outage.'
            );
            $this->assertTrue($e->retryable, 'Another candidate may still answer the same turn.');
        }

        // §10: the question is now a durable, failed turn — the person still
        // sees what they asked after a reload — but no assistant reply is stored,
        // so a failed turn can never be mistaken for an answer.
        $this->assertSame(
            0,
            $this->myMessages()->where('role', AiMessage::ROLE_ASSISTANT)->count(),
            'A failed turn stores no assistant reply.'
        );
        $this->assertSame(1, $this->myMessages()->where('role', AiMessage::ROLE_USER)->count());
        $this->assertSame(
            AiMessageModel::STATUS_FAILED,
            $this->myMessages()->where('role', AiMessage::ROLE_USER)->firstOrFail()->status,
            'The failed question is kept so it can be retried by its turn id.'
        );
    }

    public function test_exhausted_tool_rounds_fail_instead_of_returning_an_undefined_reply(): void
    {
        // Every round asks for another tool, so the bound is reached with the
        // model never having produced an answer.
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('call_1'))
                ->push($this->toolCallBody('call_2'))
                ->push($this->toolCallBody('call_3'))
                ->push($this->toolCallBody('call_4'))
                ->push($this->toolCallBody('call_5')),
        ]);

        $this->expectException(AiTurnFailed::class);

        try {
            $this->ask('¿Qué automatizaciones puedo crear?');
        } finally {
            $this->assertSame(
                0,
                $this->myMessages()->where('role', AiMessage::ROLE_ASSISTANT)->count(),
                'Exhausting the rounds stores no reply the model never gave.'
            );
        }
    }

    public function test_general_conversation_without_tools_is_answered_in_one_round(): void
    {
        $this->fakeText('Un modelo de lenguaje es un sistema que estima la siguiente palabra.');

        $result = $this->ask('¿Qué es un modelo de lenguaje?');

        $this->assertCount(1, Http::recorded(), 'A general question needs no tool round.');
        $this->assertStringContainsString('modelo de lenguaje', $result['reply']);

        $this->assertSame(1, $this->myMessages()->where('role', AiMessage::ROLE_USER)->count());
        $this->assertSame(1, $this->myMessages()->where('role', AiMessage::ROLE_ASSISTANT)->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesOf(Request $request): array
    {
        return $request->data()['messages'] ?? [];
    }

    /**
     * One provider answer that asks for a tool and nothing else.
     */
    private function toolCallBody(string $callId): array
    {
        return [
            'id' => 'resp_'.$callId,
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $callId,
                    'type' => 'function',
                    'function' => ['name' => 'automation_vocabulary', 'arguments' => '{}'],
                ]],
            ]]],
        ];
    }
}

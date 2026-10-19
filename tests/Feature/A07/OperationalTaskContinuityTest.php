<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\OperationalTask;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-RUNTIME-RELIABILITY (T12) — asking who is responsible, then resolving it.
 *
 * The live run asked a natural question back ("¿a quién la asigno?") because
 * `create_operational_task` requires an assignee and none was given. That question
 * is a *valid* answer — the turn needs an input only the person has — so it must
 * not be treated as an error, and the assistant must not invent an assignee, must
 * not assign it to the person asking, and must not make the field optional.
 *
 * What this pins is the follow-up: given the name in the next turn, the assistant
 * keeps the context of the first turn, resolves the name to a real staff account,
 * and creates exactly one *pending* proposal — linked to the conversation and to
 * the message that asked for it — while creating no task. The task is created only
 * when the person confirms.
 */
final class OperationalTaskContinuityTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $requester;

    private User $assignee;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'tasks.create', 'tasks.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->requester = User::factory()->create(['status' => 'active']);
        $this->requester->givePermissionTo(['ai.use', 'tasks.create', 'tasks.view']);

        $this->assignee = User::factory()->create([
            'name' => 'Laura Sintetica QA',
            'status' => 'active',
        ]);

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.proposal_ttl_minutes' => 15,
        ]);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->requester->id,
            'title' => 'T12',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(string $content): array
    {
        return ['id' => 'resp_text', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCallBody(string $name, array $arguments, string $callId): array
    {
        return [
            'id' => 'resp_'.$callId,
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $callId,
                    'type' => 'function',
                    'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                ]],
            ]]],
        ];
    }

    /**
     * @return list<Request>
     */
    private function providerRequests(): array
    {
        $out = [];

        foreach (Http::recorded() as $pair) {
            if ($pair[0]->url() === self::PROVIDER_URL) {
                $out[] = $pair[0];
            }
        }

        return $out;
    }

    public function test_asking_for_the_assignee_is_a_valid_answer_and_the_next_turn_resolves_it(): void
    {
        Http::fake([
            // Turn 1 asks who is responsible and stops: no write is attempted.
            // Turn 2 resolves the name and then proposes exactly one task.
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('¿A quién quieres que la asigne? Necesito el responsable y no debo inventarlo.'))
                ->push($this->toolCallBody('team_directory', ['query' => 'Laura'], 'c1'))
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisar la cartera vencida',
                    'assigned_to' => $this->assignee->id,
                    'priority' => 'high',
                ], 'c2'))
                ->push($this->textBody('Preparé la propuesta; confírmala para crear la tarea.')),
        ]);

        $service = app(AiAssistantService::class);

        // ---- Turn 1: the clarifying question is the answer, and it is not an error.
        $first = $service->ask(
            $this->conversation,
            'Crea una tarea para revisar la cartera vencida la próxima semana',
            $this->requester,
        );

        $this->assertStringContainsString('A quién', $first['reply']);
        $this->assertSame([], $first['proposals']);
        $this->assertSame(0, AiActionProposal::query()->where('ai_conversation_id', $this->conversation->id)->count());
        $this->assertSame(0, OperationalTask::query()->where('assigned_to', $this->assignee->id)->count());

        // ---- Turn 2: the name is resolved and one proposal is created.
        $second = $service->ask($this->conversation, 'Asígnala a Laura Sintetica QA.', $this->requester);

        $this->assertCount(1, $second['proposals'], 'Exactly one proposal, never a task.');

        $proposal = AiActionProposal::query()->where('ai_conversation_id', $this->conversation->id)->sole();
        $this->assertSame(AiActionProposal::PENDING, $proposal->status);
        $this->assertSame('create_operational_task', $proposal->tool);
        $this->assertSame((int) $this->conversation->id, (int) $proposal->ai_conversation_id);
        $this->assertSame((int) $this->assignee->id, (int) $proposal->arguments['assigned_to']);
        $this->assertSame('Revisar la cartera vencida', $proposal->arguments['title']);

        // The proposal belongs to the message that asked for it — the *second*
        // user message — and the assistant kept the first turn's context.
        $secondUserMessage = AiMessageModel::query()
            ->where('ai_conversation_id', $this->conversation->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($secondUserMessage);
        $this->assertSame((int) $secondUserMessage->id, (int) $proposal->message_id);

        // The first turn's exchange is still part of the thread the second turn ran in.
        $this->assertGreaterThanOrEqual(3, AiMessageModel::query()->where('ai_conversation_id', $this->conversation->id)->count());

        // Nothing was created: a proposal is a proposal.
        $this->assertSame(
            0,
            OperationalTask::query()->where('assigned_to', $this->assignee->id)->count(),
            'No task exists before confirmation.'
        );
    }

    /**
     * The live turn proposed the task assigned to the person who asked, without
     * being told who should own it. Nothing in the product may choose a
     * responsible party on its own, so the instruction the model is given must
     * say so: ask, never pick, never the requester, never unassigned.
     */
    public function test_the_instruction_the_model_receives_asks_for_the_assignee_instead_of_choosing(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response($this->textBody('¿A quién la asigno?')),
        ]);

        app(AiAssistantService::class)->ask(
            $this->conversation,
            "Prepara una tarea de seguimiento titulada 'Revisión de cartera de prueba', con prioridad media y vencimiento dentro de siete días. Muéstrame la propuesta antes de registrarla. No hagas ninguna modificación administrativa sin mi confirmación.",
            $this->requester,
        );

        $requests = $this->providerRequests();
        $this->assertNotEmpty($requests);

        $system = '';
        foreach ($requests[0]->data()['messages'] as $message) {
            if (($message['role'] ?? null) === 'system') {
                $system .= (string) ($message['content'] ?? '');
            }
        }

        $this->assertStringContainsString('RESPONSABLE DE UNA TAREA', $system);
        $this->assertStringContainsString('pregunta a quién asignarla', $system);
        $this->assertStringContainsString('no la asignes a la persona que está pidiendo la tarea', $system);
        $this->assertStringContainsString('no la dejes sin responsable', $system);
    }
}

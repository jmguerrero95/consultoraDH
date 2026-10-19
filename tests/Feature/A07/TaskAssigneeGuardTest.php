<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\OperationalTask;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-DETERMINISTIC-SAFETY (T12) — the responsible party is a server rule.
 *
 * The live run's first turn named nobody and still produced a proposal assigned
 * to the person who asked. An instruction to the model is guidance, so these pin
 * the guarantee where it can actually hold: the assistant will not store a
 * proposal for an operational task unless the assignee is justified by what the
 * person wrote — a staff member named in full, an explicit request to take it
 * oneself, or the assignee a previous proposal for the *same task* already
 * carried. Everything else is refused before the row exists and the model is
 * told to ask.
 *
 * The provider is faked; nothing between the HTTP boundary and the database is.
 */
final class TaskAssigneeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $requester;

    private User $laura;

    private User $carlos;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'tasks.create', 'tasks.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->requester = User::factory()->create(['name' => 'Ana Peticionaria QA', 'status' => 'active']);
        $this->requester->givePermissionTo(['ai.use', 'tasks.create', 'tasks.view']);

        $this->laura = User::factory()->create(['name' => 'Laura Sintetica QA', 'status' => 'active']);
        $this->carlos = User::factory()->create(['name' => 'Carlos Ficticio QA', 'status' => 'active']);

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

    private function promptOf(Request $request): string
    {
        $text = '';

        foreach ($request->data()['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $text .= "\n".$content;
            }
        }

        return $text;
    }

    private function ask(string $question): array
    {
        return app(AiAssistantService::class)->ask($this->conversation, $question, $this->requester);
    }

    private function proposals(): Builder
    {
        return AiActionProposal::query()->where('ai_conversation_id', $this->conversation->id);
    }

    // ---- the defect: nobody was named, yet a proposal appeared ----------------

    public function test_a_request_that_names_nobody_creates_no_proposal(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera de prueba',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿A quién quieres que la asigne? Necesito el responsable.')),
        ]);

        $result = $this->ask(
            "Prepara una tarea de seguimiento titulada 'Revisión de cartera de prueba', con prioridad media y "
            .'vencimiento dentro de siete días. Muéstrame la propuesta antes de registrarla. No hagas ninguna '
            .'modificación administrativa sin mi confirmación.'
        );

        $this->assertSame([], $result['proposals'], 'Without a responsible party there is no proposal.');
        $this->assertSame(0, $this->proposals()->count());
        $this->assertSame(0, OperationalTask::query()->count());
        $this->assertStringContainsString('A quién', $result['reply']);

        // The model was told, as data, to ask rather than choose.
        $requests = $this->providerRequests();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Falta el responsable', $this->promptOf($requests[1]));
    }

    public function test_assigning_to_the_person_who_asked_without_being_told_is_refused(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->textBody('¿Quién es el responsable? No lo elijo yo.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisión de cartera' con prioridad alta.");

        $this->assertSame([], $result['proposals'], 'The requester is never assumed to be the owner.');
        $this->assertSame(0, $this->proposals()->count());
    }

    public function test_assigning_to_somebody_the_request_never_named_is_refused(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->carlos->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->textBody('¿A quién la asigno?')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisión de cartera'.");

        $this->assertSame([], $result['proposals'], 'A model may not choose the responsible party.');
        $this->assertSame(0, $this->proposals()->count());
    }

    // ---- the allowed cases ---------------------------------------------------

    public function test_a_staff_member_named_in_full_is_validated_and_used(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisar cartera vencida',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->textBody('Preparé la propuesta; confírmala para crear la tarea.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisar cartera vencida' para Laura Sintetica QA.");

        $this->assertCount(1, $result['proposals']);
        $this->assertSame((int) $this->laura->id, (int) $this->proposals()->sole()->arguments['assigned_to']);

        // Still a proposal: the write needs a person to confirm it.
        $this->assertSame(0, OperationalTask::query()->count());
    }

    public function test_the_named_person_is_the_only_assignee_the_turn_may_use(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisar cartera vencida',
                    'assigned_to' => $this->carlos->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->textBody('La propuesta necesita a la persona nombrada.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisar cartera vencida' para Laura Sintetica QA.");

        $this->assertSame([], $result['proposals'], 'The named person decides the assignee.');
        $this->assertSame(0, $this->proposals()->count());
    }

    public function test_taking_the_task_oneself_is_valid_when_asked_for_explicitly(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('Preparé la propuesta para ti.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisión de cartera' y asígnamela a mí.");

        $this->assertCount(1, $result['proposals'], 'Self-assignment is allowed when it is asked for.');
        $this->assertSame((int) $this->requester->id, (int) $this->proposals()->sole()->arguments['assigned_to']);
    }

    public function test_self_assignment_still_needs_the_permission(): void
    {
        $this->requester->revokePermissionTo('tasks.create');
        $this->requester->unsetRelation('permissions');

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('No tengo permiso para crear tareas.')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' y asígnamela a mí.");

        $this->assertSame(0, $this->proposals()->count(), 'RBAC is checked before the proposal exists.');
    }

    // ---- ambiguity and names that are not names ------------------------------

    public function test_two_people_named_at_once_ask_before_proposing(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('Nombra a una sola persona, por favor: ¿Laura o Carlos?')),
        ]);

        $result = $this->ask(
            "Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA y Carlos Ficticio QA."
        );

        $this->assertSame([], $result['proposals'], 'Ambiguity asks, it does not guess.');
        $this->assertSame(0, $this->proposals()->count());
        $this->assertStringContainsString('Laura o Carlos', $result['reply']);
    }

    public function test_a_name_fragment_does_not_match_anybody(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿Cuál es el nombre completo de la persona responsable?')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura.");

        $this->assertSame([], $result['proposals'], 'A fragment is not a name.');
        $this->assertSame(0, $this->proposals()->count());
    }

    // ---- continuity across the two turns ------------------------------------

    public function test_the_second_turn_keeps_the_title_priority_and_due_date(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('¿A quién la asigno? Necesito el responsable.'))
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera de prueba',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                    'due_on' => '2026-11-01',
                ], 'c1'))
                ->push($this->textBody('Preparé la propuesta; confírmala.')),
        ]);

        $first = $this->ask(
            "Prepara una tarea de seguimiento titulada 'Revisión de cartera de prueba' con prioridad alta y "
            .'vencimiento el 2026-11-01. Muéstrame la propuesta antes de registrarla.'
        );

        $this->assertSame([], $first['proposals']);

        $second = $this->ask('Asígnala a Laura Sintetica QA.');

        $this->assertCount(1, $second['proposals']);

        $arguments = $this->proposals()->sole()->arguments;

        $this->assertSame('Revisión de cartera de prueba', $arguments['title']);
        $this->assertSame('high', $arguments['priority']);
        $this->assertSame('2026-11-01', $arguments['due_on']);
        $this->assertSame((int) $this->laura->id, (int) $arguments['assigned_to']);
    }

    // ---- corrections and independent tasks ----------------------------------

    public function test_a_correction_replaces_the_proposal_for_the_same_task(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                // The correction names nobody: it keeps the assignee it was
                // justified with, and only the priority changes. A write stops
                // the loop, so each turn consumes exactly one response.
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c2')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA con prioridad alta.");
        $second = $this->ask('Corrige la propuesta: ponla en prioridad normal.');

        $this->assertCount(1, $second['proposals']);

        $rows = $this->proposals()->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertSame(AiActionProposal::EXPIRED, $rows[0]->status, 'The replaced proposal is closed.');
        $this->assertSame(AiActionProposal::PENDING, $rows[1]->status);
        $this->assertSame('normal', $rows[1]->arguments['priority']);
        $this->assertSame((int) $this->laura->id, (int) $rows[1]->arguments['assigned_to']);

        // Only the latest proposal is on offer.
        $this->assertSame(1, $this->proposals()->where('status', AiActionProposal::PENDING)->count());
    }

    public function test_a_superseded_proposal_can_no_longer_be_confirmed(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c2')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA con prioridad alta.");
        $this->ask('Corrige la propuesta: ponla en prioridad normal.');

        $rows = $this->proposals()->orderBy('id')->get();
        $replaced = $rows[0];
        $current = $rows[1];

        $this->actingAs($this->requester)
            ->postJson("/api/ai/action-proposals/{$replaced->id}/confirm")
            ->assertStatus(400);

        $this->assertSame(0, OperationalTask::query()->count(), 'A replaced proposal performs no work.');

        $this->actingAs($this->requester)
            ->postJson("/api/ai/action-proposals/{$current->id}/confirm")
            ->assertOk();

        $this->assertSame(1, OperationalTask::query()->count(), 'The current proposal performs the work once.');
    }

    public function test_a_correction_that_changes_the_assignee_must_name_the_new_person(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                // The correction changes the owner without naming anybody: that
                // is a new decision the model may not make, so it is refused and
                // the turn ends with the model asking instead.
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->carlos->id,
                    'priority' => 'high',
                ], 'c2'))
                ->push($this->textBody('¿A quién debo asignarla?')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA con prioridad alta.");

        $result = $this->ask('Cámbiale el responsable.');

        $this->assertSame([], $result['proposals'], 'A new responsible party has to be named.');
        $this->assertSame(1, $this->proposals()->count());
        $this->assertSame(AiActionProposal::PENDING, $this->proposals()->sole()->status);
    }

    public function test_proposals_for_different_tasks_do_not_invalidate_each_other(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Llamar al cliente',
                    'assigned_to' => $this->carlos->id,
                    'priority' => 'normal',
                ], 'c2')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA.");
        $this->ask("Crea una tarea titulada 'Llamar al cliente' para Carlos Ficticio QA.");

        $rows = $this->proposals()->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $this->proposals()->where('status', AiActionProposal::PENDING)->count());
        $this->assertSame('Llamar al cliente', $rows[1]->arguments['title']);
    }

    public function test_a_name_from_an_unrelated_task_is_not_inherited(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                // A different task, with an assignee the new request never named.
                // The refusal continues the turn, so the model then answers.
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Llamar al cliente',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c2'))
                ->push($this->textBody('¿A quién asigno esta nueva tarea?')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA.");

        $result = $this->ask('Ahora prepara otra tarea diferente.');

        $this->assertSame([], $result['proposals'], 'A name from another task does not carry over.');
        $this->assertSame(1, $this->proposals()->count());
        $this->assertSame('Revisión de cartera', $this->proposals()->sole()->arguments['title']);
    }

    public function test_another_conversations_proposal_never_justifies_this_one(): void
    {
        $otherUser = User::factory()->create(['status' => 'active']);

        $otherConversation = AiConversation::query()->create([
            'user_id' => $otherUser->id,
            'title' => 'Otra conversación',
        ]);

        AiActionProposal::query()->create([
            'user_id' => $otherUser->id,
            'ai_conversation_id' => $otherConversation->id,
            'tool' => 'create_operational_task',
            'arguments' => ['title' => 'Revisión de cartera', 'assigned_to' => $this->laura->id],
            'status' => AiActionProposal::PENDING,
            'expires_at' => now()->addMinutes(15),
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿A quién la asigno?')),
        ]);

        $result = $this->ask('Prepara la propuesta de la tarea de revisión.');

        $this->assertSame([], $result['proposals'], 'Another conversation justifies nothing here.');
        $this->assertSame(0, $this->proposals()->count());
    }

    // ---- edge case 1: "for me" is not an assignment --------------------------

    public function test_preparing_a_task_for_oneself_is_not_an_assignment(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Seguimiento de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿A quién asigno la tarea? Necesito el responsable.')),
        ]);

        $result = $this->ask('Prepara para mí una tarea de seguimiento de cartera.');

        $this->assertSame([], $result['proposals'], '"Para mí" says who the work is for, not who owns it.');
        $this->assertSame(0, $this->proposals()->count());
        $this->assertSame(0, OperationalTask::query()->count());
        $this->assertStringContainsString('A quién', $result['reply']);

        // Asked to ask, as data — not left to the model's judgement.
        $requests = $this->providerRequests();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Falta el responsable', $this->promptOf($requests[1]));
    }

    public function test_mentioning_oneself_is_not_an_assignment(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Seguimiento de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿Quién es el responsable de la tarea?')),
        ]);

        $result = $this->ask(
            'Prepara una tarea de seguimiento de cartera y muéstramela a mí antes de registrarla.'
        );

        $this->assertSame([], $result['proposals'], 'Mentioning "a mí" assigns nothing.');
        $this->assertSame(0, $this->proposals()->count());
    }

    public function test_ponme_como_responsable_is_an_assignment_instruction(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Seguimiento de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('Preparé la propuesta.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Seguimiento de cartera' y ponme como responsable.");

        $this->assertCount(1, $result['proposals'], 'An explicit assignment instruction is valid.');
        $this->assertSame((int) $this->requester->id, (int) $this->proposals()->sole()->arguments['assigned_to']);

        // Valid, but still only a proposal.
        $this->assertSame(0, OperationalTask::query()->count());
    }

    public function test_asignala_a_mi_is_an_assignment_instruction(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Seguimiento de cartera',
                    'assigned_to' => $this->requester->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('Preparé la propuesta.')),
        ]);

        $result = $this->ask("Crea una tarea titulada 'Seguimiento de cartera' y asígnala a mí.");

        $this->assertCount(1, $result['proposals']);
        $this->assertSame((int) $this->requester->id, (int) $this->proposals()->sole()->arguments['assigned_to']);
    }

    // ---- edge case 2: only a live proposal, and only for a correction ---------

    public function test_an_expired_proposal_does_not_justify_a_new_proposal(): void
    {
        // Status still reads `pending`, but the window in which it could have
        // been confirmed has closed, so it justifies nothing.
        $stale = AiActionProposal::query()->create([
            'user_id' => $this->requester->id,
            'ai_conversation_id' => $this->conversation->id,
            'tool' => 'create_operational_task',
            'arguments' => ['title' => 'Revisión de cartera', 'assigned_to' => $this->laura->id],
            'status' => AiActionProposal::PENDING,
            'expires_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿A quién asigno la tarea?')),
        ]);

        // A genuine correction of the *same* task, so identity and context both
        // match: the only thing standing in the way is the expiry.
        $result = $this->ask('Corrige la propuesta y ponla en prioridad normal.');

        $this->assertSame([], $result['proposals'], 'An expired proposal justifies nothing.');
        $this->assertSame(1, $this->proposals()->count(), 'No new proposal was stored.');
        $this->assertSame(AiActionProposal::PENDING, $stale->fresh()->status, 'The stale row is left untouched.');
        $this->assertSame(0, OperationalTask::query()->count());
    }

    public function test_a_repeated_title_without_a_correction_inherits_no_assignee(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'high',
                ], 'c1'))
                // Same title, same assignee, but this is another task and the
                // request says nothing about correcting the one on the table.
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c2'))
                ->push($this->textBody('¿A quién asigno esta tarea nueva?')),
        ]);

        $this->ask("Crea una tarea titulada 'Revisión de cartera' para Laura Sintetica QA con prioridad alta.");

        $result = $this->ask("Crea otra tarea titulada 'Revisión de cartera'.");

        $this->assertSame([], $result['proposals'], 'A repeated title inherits no responsible party.');
        $this->assertSame(1, $this->proposals()->count());
        $this->assertSame(AiActionProposal::PENDING, $this->proposals()->sole()->status);
    }

    public function test_an_expired_proposal_is_neither_inherited_nor_replaced(): void
    {
        // The only proposal on the table carries the title the correction names,
        // but its window has closed and its status still reads `pending`. It is
        // not a correction target: the new proposal is refused, and the stale row
        // is neither superseded nor confirmed.
        AiActionProposal::query()->create([
            'user_id' => $this->requester->id,
            'ai_conversation_id' => $this->conversation->id,
            'tool' => 'create_operational_task',
            'arguments' => ['title' => 'Revisión de cartera', 'assigned_to' => $this->laura->id],
            'status' => AiActionProposal::PENDING,
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('create_operational_task', [
                    'title' => 'Revisión de cartera',
                    'assigned_to' => $this->laura->id,
                    'priority' => 'normal',
                ], 'c1'))
                ->push($this->textBody('¿A quién asigno la tarea?')),
        ]);

        $result = $this->ask('Actualiza la propuesta y ponla en prioridad normal.');

        $this->assertSame([], $result['proposals']);
        $this->assertSame(1, $this->proposals()->count());
        $this->assertSame(AiActionProposal::PENDING, $this->proposals()->sole()->status);
    }
}

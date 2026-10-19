<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiActionProposal;
use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTask;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\AiConversationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Naming, renaming and deleting a conversation — without touching business data.
 *
 * The two properties that matter are pinned here:
 *
 *   - a thread is *named* from its first question, deterministically and with the
 *     personal identifiers redacted, and a name a person chose is never
 *     overwritten;
 *   - *deleting* a thread removes the private material that belonged to it — its
 *     messages, its proposals, its attachments and the files it generated — and
 *     can never delete an operational task, an obligation or a client record that
 *     a confirmed proposal created.
 */
final class AiConversationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('ai.use');

        $this->other = User::factory()->create();
        $this->other->givePermissionTo('ai.use');

        Storage::fake('local');
    }

    private function manager(): AiConversationManager
    {
        return app(AiConversationManager::class);
    }

    private function conversationFor(User $user, ?string $title = null): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => $title ?? $this->manager()->placeholderTitle(),
        ]);
    }

    // ------------------------------------------------------------- titling

    public function test_a_question_becomes_a_readable_title(): void
    {
        $title = $this->manager()->titleFromQuestion('Por favor explícame la mora de la cartera');

        $this->assertSame('Explícame la mora de la cartera', $title);
    }

    public function test_a_personal_identifier_is_redacted_from_the_title(): void
    {
        $email = $this->manager()->titleFromQuestion('Escríbeme a juan.perez@example.com sobre la mora');

        $this->assertStringNotContainsString('@', $email);
        $this->assertStringNotContainsString('juan.perez', $email);
        $this->assertStringContainsString('…', $email);

        $digits = $this->manager()->titleFromQuestion('Revisa el NIT 901234567 de la empresa');

        $this->assertStringNotContainsString('901234567', $digits);
        $this->assertStringContainsString('…', $digits);
    }

    public function test_a_title_is_bounded_to_the_limit(): void
    {
        $title = $this->manager()->titleFromQuestion(str_repeat('palabra ', 20));

        $this->assertLessThanOrEqual(AiConversationManager::TITLE_LIMIT, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
    }

    public function test_an_empty_question_gets_a_readable_default(): void
    {
        $this->assertSame('Nueva consulta', $this->manager()->titleFromQuestion('   '));
    }

    public function test_only_the_placeholder_title_is_auto_detected(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->isAutoTitle(null));
        $this->assertTrue($manager->isAutoTitle(''));
        $this->assertTrue($manager->isAutoTitle('Conversación 2026-10-19 12:17'));
        $this->assertTrue($manager->isAutoTitle('Conversación MCP 2026-10-19 12:17'));
        $this->assertFalse($manager->isAutoTitle('Análisis de cartera'));
    }

    public function test_a_derived_title_replaces_the_placeholder_but_never_a_chosen_one(): void
    {
        $manager = $this->manager();

        $placeholder = $this->conversationFor($this->user);
        $manager->applyDerivedTitle($placeholder, 'Análisis de la cartera vencida');

        $this->assertSame('Análisis de la cartera vencida', $placeholder->fresh()->title);

        $chosen = $this->conversationFor($this->user, 'Mi nombre propio');
        $manager->applyDerivedTitle($chosen, 'Análisis de la cartera vencida');

        $this->assertSame('Mi nombre propio', $chosen->fresh()->title, 'A name a person chose is never overwritten.');
    }

    public function test_rename_cleans_and_bounds_the_title(): void
    {
        $conversation = $this->conversationFor($this->user);

        $this->assertSame('Análisis de cartera', $this->manager()->rename($conversation, "  Análisis \t de \n cartera  "));

        $long = $this->manager()->rename($conversation, str_repeat('x', 200));
        $this->assertLessThanOrEqual(AiConversationManager::TITLE_LIMIT, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    public function test_rename_rejects_an_empty_title(): void
    {
        $conversation = $this->conversationFor($this->user);

        $this->expectException(\InvalidArgumentException::class);

        $this->manager()->rename($conversation, "   \x00  ");
    }

    // ------------------------------------------------------------- endpoints

    public function test_a_person_can_rename_their_own_conversation(): void
    {
        $conversation = $this->conversationFor($this->user);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/ai/conversations/{$conversation->id}",
            ['title' => 'Cartera de octubre'],
        );

        $response->assertOk();
        $response->assertJsonPath('title', 'Cartera de octubre');
        $response->assertJsonPath('conversation_id', $conversation->id);
        $this->assertSame('Cartera de octubre', $conversation->fresh()->title);
    }

    public function test_renaming_an_empty_title_is_a_validation_error(): void
    {
        $conversation = $this->conversationFor($this->user);

        $this->actingAs($this->user)
            ->patchJson("/api/ai/conversations/{$conversation->id}", ['title' => '   '])
            ->assertStatus(422);
    }

    public function test_a_person_cannot_rename_another_persons_conversation(): void
    {
        $conversation = $this->conversationFor($this->other, 'Del otro');

        $this->actingAs($this->user)
            ->patchJson("/api/ai/conversations/{$conversation->id}", ['title' => 'Secuestrada'])
            ->assertStatus(403);

        $this->assertSame('Del otro', $conversation->fresh()->title);
    }

    // ------------------------------------------------------------- deletion

    public function test_deleting_a_conversation_removes_its_private_material(): void
    {
        $conversation = $this->conversationFor($this->user);

        $message = AiMessage::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Genera el informe.',
        ]);

        AiActionProposal::query()->create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'tool' => 'create_task',
            'arguments' => ['title' => 'Llamar al cliente'],
            'status' => AiActionProposal::PENDING,
            'expires_at' => now()->addMinutes(15),
        ]);

        $path = 'ai-artifacts/'.$this->user->id.'/informe.pdf';
        Storage::disk('local')->put($path, 'bytes');

        AiArtifact::query()->create([
            'user_id' => $this->user->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'display_filename' => 'informe.pdf',
            'mime_type' => 'application/pdf',
            'format' => 'pdf',
            'storage_path' => $path,
            'size_bytes' => 5,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        // Everything private to the thread is gone, file bytes included.
        $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_messages', ['ai_conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_action_proposals', ['ai_conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_artifacts', ['conversation_id' => $conversation->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_conversation_never_deletes_a_task_or_a_client(): void
    {
        $client = Client::factory()->create();

        $conversation = $this->conversationFor($this->user);
        $conversation->forceFill(['client_id' => $client->id])->save();

        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $conversation->id,
            'kind' => 'document_generate',
            'status' => 'succeeded',
            'input' => ['question' => 'Genera el informe'],
            'dedupe_key' => 'task-1',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$conversation->id}")
            ->assertOk();

        // The operational task survives; only its link to the thread is cleared,
        // because it is a business record that does not belong to the thread.
        $this->assertDatabaseHas('ai_tasks', ['id' => $task->id]);
        $this->assertNull($task->fresh()->ai_conversation_id);

        // The client record is untouched.
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_a_person_cannot_delete_another_persons_conversation(): void
    {
        $conversation = $this->conversationFor($this->other, 'Del otro');

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$conversation->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->id]);
    }

    // ------------------------------------------------------------- listing

    public function test_the_list_is_ordered_by_recent_activity_and_carries_the_title(): void
    {
        $old = $this->conversationFor($this->user, 'Antigua');
        $old->forceFill(['updated_at' => now()->subHours(2)])->save();

        $new = $this->conversationFor($this->user, 'Reciente');

        $otherConversation = $this->conversationFor($this->other, 'De otro usuario');

        $response = $this->actingAs($this->user)->getJson('/api/ai/conversations');

        $response->assertOk();

        $ids = array_column($response->json('conversations'), 'id');
        $this->assertSame([$new->id, $old->id], $ids, 'Most recent activity first.');
        $this->assertNotContains($otherConversation->id, $ids, 'Only the caller\'s conversations.');

        $titles = array_column($response->json('conversations'), 'title');
        $this->assertContains('Reciente', $titles);
    }

    public function test_show_returns_the_conversation_title(): void
    {
        $conversation = $this->conversationFor($this->user, 'Análisis de cartera');

        $this->actingAs($this->user)
            ->getJson("/api/ai/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Análisis de cartera');
    }
}

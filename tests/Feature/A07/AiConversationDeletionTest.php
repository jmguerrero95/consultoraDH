<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiActionProposal;
use App\Models\AiArtifact;
use App\Models\AiArtifactDeletion;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTask;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\MonthlyObligation;
use App\Models\User;
use App\Services\Ai\AiArtifactCleaner;
use App\Services\Ai\AiConversationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * §09 — deleting a conversation is atomic in the database and durable for disk.
 *
 * The properties pinned here are the ones the product requires:
 *
 *   A. the private material of a thread is removed — rows and bytes;
 *   B. a rollback removes nothing, so a failure cannot break a link or delete a
 *      file that a surviving row still points at;
 *   C. a committed delete whose file removal fails keeps the DB gone and leaves
 *      the path in the outbox, which the scheduled command later drains — no
 *      orphan file is ever lost;
 *   D. deleting twice, or draining an empty outbox twice, is idempotent;
 *   E. the artifact scope is the conversation *and* its owner, so another
 *      account's files and rows are never touched;
 *   F. an operational task survives and only its link is cleared;
 *   G. a client and its obligations survive;
 *   H. an administrative document and its file survive;
 *   I. a thread with nothing on disk deletes cleanly;
 *   J. a thread that generated nothing leaves no outbox work.
 */
final class AiConversationDeletionTest extends TestCase
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

    private function conversationFor(User $user): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => 'Conversación '.now()->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Create an artifact with its bytes on the fake disk.
     */
    private function artifact(AiConversation $conversation, string $filename = 'informe.pdf'): AiArtifact
    {
        $path = 'ai-artifacts/'.$conversation->user_id.'/'.$filename;
        Storage::disk('local')->put($path, 'bytes');

        return AiArtifact::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'display_filename' => $filename,
            'mime_type' => 'application/pdf',
            'format' => 'pdf',
            'storage_path' => $path,
            'size_bytes' => 5,
        ]);
    }

    /**
     * Create an uploaded attachment with its bytes on the fake disk.
     */
    private function attachment(AiConversation $conversation, string $filename = 'entrada.pdf'): AiAttachment
    {
        $path = 'ai-attachments/'.$conversation->user_id.'/'.$filename;
        Storage::disk('local')->put($path, 'bytes');

        return AiAttachment::query()->create([
            'user_id' => $conversation->user_id,
            'ai_conversation_id' => $conversation->id,
            'storage_path' => $path,
            'original_name' => $filename,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 5,
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);
    }

    // ----------------------------------------------------------- A. happy path

    public function test_deleting_a_conversation_removes_its_rows_and_its_bytes(): void
    {
        $conversation = $this->conversationFor($this->user);

        AiMessage::query()->create([
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

        $artifact = $this->artifact($conversation);
        $path = $artifact->storage_path;

        $attachment = $this->attachment($conversation);
        $attachmentPath = $attachment->storage_path;

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_messages', ['ai_conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_action_proposals', ['ai_conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_artifacts', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_attachments', ['ai_conversation_id' => $conversation->id]);
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing($attachmentPath);

        // The outbox is drained as part of the delete when the disk cooperates.
        $this->assertDatabaseCount('ai_artifact_deletions', 0);
    }

    // ------------------------------------------------- B. rollback deletes nothing

    public function test_a_rollback_leaves_the_bytes_and_the_rows_intact(): void
    {
        $conversation = $this->conversationFor($this->user);
        $artifact = $this->artifact($conversation);
        $path = $artifact->storage_path;
        $attachment = $this->attachment($conversation);

        // Force the transaction to fail after the artifact rows were read but
        // before it commits, by refusing the conversation delete at the model
        // level. The old implementation removed the bytes *before* the
        // transaction, so this would have left a surviving artifact pointing at a
        // file that no longer existed.
        AiConversation::deleting(static function (): void {
            throw new \RuntimeException('forced rollback');
        });

        try {
            $this->manager()->delete($conversation);
            $this->fail('The forced rollback should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced rollback', $e->getMessage());
        }

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('ai_artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseHas('ai_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists($attachment->storage_path);

        // No file work was queued, because nothing committed.
        $this->assertDatabaseCount('ai_artifact_deletions', 0);
    }

    // ------------------------------------------- C. durable outbox on disk failure

    public function test_a_failed_file_removal_commits_the_delete_and_is_left_for_retry(): void
    {
        $conversation = $this->conversationFor($this->user);
        $artifact = $this->artifact($conversation);
        $path = $artifact->storage_path;

        // A disk whose delete throws, standing in for a locked or offline disk.
        $realDisk = Storage::disk('local');

        $failing = \Mockery::mock();
        $failing->shouldReceive('delete')->andThrow(new \RuntimeException('disk offline'));
        Storage::set('local', $failing);

        try {
            $this->manager()->delete($conversation);
        } finally {
            Storage::set('local', $realDisk);
        }

        // The database half is committed: the thread and its artifact rows are
        // gone even though the bytes were not removed.
        $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_artifacts', ['id' => $artifact->id]);
        Storage::disk('local')->assertExists($path);

        // The path is owed: exactly one outbox row, with an attempt recorded.
        $this->assertDatabaseCount('ai_artifact_deletions', 1);
        $row = AiArtifactDeletion::query()->firstOrFail();
        $this->assertSame($path, $row->storage_path);
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->last_error);

        // Once the disk is healthy again, the scheduled command finishes the job.
        AiArtifactDeletion::query()->update(['available_at' => now()->subMinute()]);

        $this->artisan('ai:cleanup-artifacts')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('ai_artifact_deletions', 0);
    }

    // ------------------------------------------------------------ D. idempotency

    public function test_deleting_twice_and_draining_twice_is_idempotent(): void
    {
        $conversation = $this->conversationFor($this->user);
        $artifact = $this->artifact($conversation);
        $path = $artifact->storage_path;

        $this->manager()->delete($conversation);
        // A second delete on the stale instance must not throw, must not recreate
        // anything and must not enqueue duplicate work.
        $this->manager()->delete($conversation);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('ai_artifact_deletions', 0);

        $this->artisan('ai:cleanup-artifacts')->assertSuccessful();
        $this->artisan('ai:cleanup-artifacts')->assertSuccessful();
        $this->assertDatabaseCount('ai_artifact_deletions', 0);
    }

    // --------------------------------------------------------- E. owner scoping

    public function test_a_delete_never_reaches_another_accounts_files(): void
    {
        $mine = $this->conversationFor($this->user);
        $myArtifact = $this->artifact($mine, 'mio.pdf');

        $theirs = $this->conversationFor($this->other);
        $theirArtifact = $this->artifact($theirs, 'suyo.pdf');

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$mine->id}")
            ->assertOk();

        Storage::disk('local')->assertMissing($myArtifact->storage_path);
        Storage::disk('local')->assertExists($theirArtifact->storage_path);
        $this->assertDatabaseHas('ai_artifacts', ['id' => $theirArtifact->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $theirs->id]);
    }

    public function test_a_person_cannot_delete_another_persons_conversation(): void
    {
        $theirs = $this->conversationFor($this->other);
        $artifact = $this->artifact($theirs);

        $this->actingAs($this->user)
            ->deleteJson("/api/ai/conversations/{$theirs->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ai_conversations', ['id' => $theirs->id]);
        Storage::disk('local')->assertExists($artifact->storage_path);
    }

    // ------------------------------------------------- F. tasks are never deleted

    public function test_an_operational_task_survives_and_only_its_link_is_cleared(): void
    {
        $conversation = $this->conversationFor($this->user);

        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $conversation->id,
            'kind' => 'document_generate',
            'status' => 'succeeded',
            'input' => ['question' => 'Genera el informe'],
            'dedupe_key' => 'task-1',
        ]);

        $this->manager()->delete($conversation);

        $this->assertDatabaseHas('ai_tasks', ['id' => $task->id]);
        $this->assertNull($task->fresh()->ai_conversation_id);
    }

    // ------------------------------------------ G. clients and obligations survive

    public function test_a_client_and_its_obligations_survive(): void
    {
        $client = Client::factory()->create();
        $obligation = MonthlyObligation::factory()->create(['client_id' => $client->id]);

        $conversation = $this->conversationFor($this->user);
        $conversation->forceFill(['client_id' => $client->id])->save();

        $this->manager()->delete($conversation);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('monthly_obligations', ['id' => $obligation->id]);
    }

    // -------------------------------------- H. administrative documents survive

    public function test_an_administrative_document_and_its_file_survive(): void
    {
        $document = ClientDocument::factory()->create();
        Storage::disk('local')->put($document->stored_path, 'administrative');

        $conversation = $this->conversationFor($this->user);
        $artifact = $this->artifact($conversation);

        $this->manager()->delete($conversation);

        // The thread's own artifact bytes go; the administrative document does
        // not, even though both live under the same private disk.
        Storage::disk('local')->assertMissing($artifact->storage_path);
        $this->assertDatabaseHas('client_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->stored_path);
    }

    // ---------------------------------------------------- I/J. clean no-ops

    public function test_a_thread_with_nothing_on_disk_deletes_cleanly(): void
    {
        $conversation = $this->conversationFor($this->user);

        AiMessage::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hola',
        ]);

        $this->manager()->delete($conversation);

        $this->assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseCount('ai_artifact_deletions', 0);
    }

    public function test_the_cleaner_queue_is_deduplicated_and_the_drain_is_idempotent(): void
    {
        $cleaner = app(AiArtifactCleaner::class);

        $this->assertSame(0, $cleaner->pendingCount());

        $conversation = $this->conversationFor($this->user);
        $artifact = $this->artifact($conversation);
        $path = $artifact->storage_path;

        // Simulate the committed half of a delete: rows gone, bytes still there.
        AiArtifact::query()->whereKey($artifact->id)->delete();

        $cleaner->queue([['user_id' => $this->user->id, 'storage_path' => $path]]);
        // A second enqueue of the same path is ignored by the unique index.
        $cleaner->queue([['user_id' => $this->user->id, 'storage_path' => $path]]);

        $this->assertSame(1, $cleaner->pendingCount());

        $this->assertSame(1, $cleaner->process());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $cleaner->pendingCount());

        // A further drain clears nothing and does not error.
        $this->assertSame(0, $cleaner->process());
    }
}

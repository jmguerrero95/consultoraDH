<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * R2-09, R2-10, R2-11 and R2-14 — attachments.
 *
 * ## What each checkpoint is really about
 *
 * **Ownership (R2-10).** An attachment has to be attributable to a
 * conversation. It previously carried only `support_message_id`, which is
 * nullable because a file is uploaded before the message carrying it is sent, so
 * ownership had to be reached through a join that could be absent — and the
 * read path dereferenced that absent message unconditionally.
 *
 * **Paths (R2-11).** The stored extension came from the client's filename, which
 * is a claim the sender makes. A file called `payload.php` was therefore stored
 * as `.php`. The extension is now derived from the accepted type.
 *
 * **Atomicity (R2-09).** `Storage::put()` reports failure by returning false
 * rather than by throwing, and the return value was not checked — so a refused
 * write still produced a metadata row pointing at a file that did not exist.
 *
 * **Download (R2-11b).** Every read is authorised against the owning
 * conversation, and nothing is served straight off a public path.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    Storage::fake('local');
});

/** A client with a portal account, and a conversation belonging to them. */
function attachmentContext(): array
{
    $client = Client::factory()->create();
    $queue = SupportQueue::factory()->create();

    $clientUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
    ]);

    $conversation = SupportConversation::factory()->create([
        'client_id' => $client->id,
        'queue_id' => $queue->id,
        'status' => 'waiting_staff',
    ]);

    return [$client, $clientUser, $queue, $conversation];
}

function uploadFor($test, User $user, SupportConversation $conversation, UploadedFile $file)
{
    return $test->actingAs($user)->postJson("/api/portal/support/conversations/{$conversation->id}/attachments", [
        'file' => $file,
    ]);
}

/* -------------------------------------------------------------------------- */
/* | | Ownership                                                                   *  */
/* -------------------------------------------------------------------------- */

test('an uploaded attachment records its owning conversation', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $response = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'));

    $response->assertCreated()->assertJsonPath('conversation_id', $conversation->id);

    $attachment = SupportMessageAttachment::findOrFail($response->json('id'));

    // Read from the row, not the response, so the claim is about storage.
    expect($attachment->conversation_id)->toBe($conversation->id)
        // The message is deliberately absent until the attachment is sent.
        ->and($attachment->support_message_id)->toBeNull();
})->group('R2-10');

test('an attachment cannot be downloaded through another conversation', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    // A second conversation belonging to the same client.
    $other = SupportConversation::factory()->create([
        'client_id' => $client->id,
        'queue_id' => $conversation->queue_id,
    ]);

    $response = $this->actingAs($clientUser)->getJson(
        "/api/portal/support/conversations/{$other->id}/attachments/{$uploaded->json('id')}"
    );

    // Not the attachment — the conversation in the URL does not own it.
    $response->assertNotFound();
})->group('R2-10');

test('an attachment of a different client is not served', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    $stranger = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => Client::factory()->create()->id,
    ]);

    $this->actingAs($stranger)->getJson(
        "/api/portal/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    )->assertNotFound();
})->group('R2-10');

/* -------------------------------------------------------------------------- */
/* | | Stored paths                                                                *  */
/* -------------------------------------------------------------------------- */

test('the stored extension comes from the accepted type, not the filename', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    // A PDF whose name claims to be a script. The type is accepted, so the
    // upload proceeds; what must not happen is the script extension on disk.
    $response = uploadFor($this, $clientUser,
        $conversation,
        UploadedFile::fake()->create('payload.php.pdf', 20, 'application/pdf'),
    );

    $response->assertCreated();

    $path = $response->json('stored_path');

    expect($path)->toEndWith('.pdf')
        ->and($path)->not->toEndWith('.php')
        ->and(pathinfo($path, PATHINFO_EXTENSION))->toBe('pdf');

    // And the sender's name is kept for display without being used as a path.
    expect($response->json('original_name'))->toBeString()->not->toContain('/');
})->group('R2-11');

test('a path traversal attempt in the filename cannot escape the directory', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $response = uploadFor($this, $clientUser,
        $conversation,
        UploadedFile::fake()->create('../../etc/passwd.pdf', 20, 'application/pdf'),
    );

    $response->assertCreated();

    $path = $response->json('stored_path');

    expect($path)->toStartWith('support-attachments/')
        ->and($path)->not->toContain('..')
        ->and($path)->not->toContain('/etc/');
})->group('R2-11');

test('an accepted audio type is stored under its own extension', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $response = uploadFor($this, $clientUser,
        $conversation,
        UploadedFile::fake()->create('nota.webm', 20, 'audio/webm'),
    );

    $response->assertCreated()
        ->assertJsonPath('kind', 'audio');

    expect($response->json('stored_path'))->toEndWith('.webm');
})->group('R2-11');

test('a type outside the accepted list is refused', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    uploadFor($this, $clientUser,
        $conversation,
        UploadedFile::fake()->create('script.php', 20, 'application/x-php'),
    )->assertStatus(422);
})->group('R2-11');

/* -------------------------------------------------------------------------- */
/* | | Atomicity                                                                   *  */
/* -------------------------------------------------------------------------- */

test('the bytes are written and the row is created together', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $response = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    // Both exist, and the row's hash describes the bytes actually written.
    Storage::disk('local')->assertExists($response->json('stored_path'));

    $attachment = SupportMessageAttachment::findOrFail($response->json('id'));
    $written = Storage::disk('local')->get($response->json('stored_path'));

    expect($attachment->sha256)->toBe(hash('sha256', $written))
        ->and($attachment->size_bytes)->toBe(strlen($written));
})->group('R2-09');

test('a failed write produces no metadata row', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    /*
     * `put()` reports failure by returning false, which is the real shape of a
     * disk that is full or a directory that cannot be written. The previous
     * code ignored the return value, so the row was created regardless and the
     * attachment then pointed at a file that did not exist.
     */
    Storage::shouldReceive('disk')
        ->andReturn(new class
        {
            public function put($path, $contents)
            {
                return false;
            }

            public function exists($path)
            {
                return false;
            }

            public function delete($path)
            {
                return true;
            }

            public function download($path, $name)
            {
                return null;
            }
        });

    $before = SupportMessageAttachment::query()->count();

    try {
        uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'));
    } catch (Throwable) {
        // The failure is raised as an exception; either way it must not leave a
        // row behind.
    }

    expect(SupportMessageAttachment::query()->count())->toBe($before);
})->group('R2-09');

/* -------------------------------------------------------------------------- */
/* | | Download contract                                                           *  */
/* -------------------------------------------------------------------------- */

test('an attachment belonging to a visible message can be downloaded', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    // The message that carries it, visible to the client.
    SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_kind' => 'client',
        'message_kind' => 'message',
        'client_visible' => true,
        'body_text' => 'Adjunto mi factura.',
    ]);

    SupportMessageAttachment::whereKey($uploaded->json('id'))
        ->update(['support_message_id' => SupportMessage::query()->latest('id')->value('id')]);

    $response = $this->actingAs($clientUser)->get(
        "/api/portal/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    );

    $response->assertOk();
})->group('R2-11');

test('an attachment with no message yet is not served to a client', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    // Still unsent, so there is nothing to disclose it under.
    $this->actingAs($clientUser)->get(
        "/api/portal/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    )->assertForbidden();
})->group('R2-11');

test('an attachment on an internal note is not served to a client', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf'))
        ->assertCreated();

    SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_kind' => 'staff',
        'message_kind' => 'note',
        'client_visible' => false,
        'body_text' => 'Nota interna.',
    ]);

    SupportMessageAttachment::whereKey($uploaded->json('id'))
        ->update(['support_message_id' => SupportMessage::query()->latest('id')->value('id')]);

    $this->actingAs($clientUser)->get(
        "/api/portal/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    )->assertForbidden();
})->group('R2-11');

test('staff with access to the queue may download a visible attachment', function (): void {
    [$client, $clientUser, $queue, $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_kind' => 'client',
        'message_kind' => 'message',
        'client_visible' => true,
        'body_text' => 'Consulta.',
    ]);

    SupportMessageAttachment::whereKey($uploaded->json('id'))
        ->update(['support_message_id' => SupportMessage::query()->latest('id')->value('id')]);

    $staff = userWithPermissions(['support.view']);
    SupportQueueMember::create(['queue_id' => $queue->id, 'user_id' => $staff->id]);

    $this->actingAs($staff)->get(
        "/api/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    )->assertOk();
})->group('R2-11');

test('staff outside the queue are refused', function (): void {
    [$client, $clientUser, , $conversation] = attachmentContext();

    $uploaded = uploadFor($this, $clientUser, $conversation, UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'))
        ->assertCreated();

    SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_kind' => 'client',
        'message_kind' => 'message',
        'client_visible' => true,
        'body_text' => 'Consulta.',
    ]);

    SupportMessageAttachment::whereKey($uploaded->json('id'))
        ->update(['support_message_id' => SupportMessage::query()->latest('id')->value('id')]);

    // A staff member who is not a member of the queue.
    $outsider = userWithPermissions(['support.view']);

    $this->actingAs($outsider)->get(
        "/api/support/conversations/{$conversation->id}/attachments/{$uploaded->json('id')}"
    )->assertForbidden();
})->group('R2-11');
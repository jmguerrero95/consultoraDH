<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * FIX-LOCAL-01 / B.3 — the staff attachment endpoint.
 *
 * `POST /api/support/conversations/{id}/attachments` was called by the conversation screen and
 * answered **404**: the route existed only under `/api/portal/…`, which is the client's side.
 * Both the file picker and the audio recorder post here, so neither worked for staff.
 *
 * This goes through the real request pipeline — route, `can:support.reply`, the per-conversation
 * check, validation and storage — because the properties being asserted are about that pipeline.
 * A hand-built `Request` cannot test an upload: Symfony re-wraps the file during multipart
 * parsing and loses the "already uploaded" flag, so every such attempt fails validation for
 * reasons that have nothing to do with the endpoint.
 *
 * Runs on the suite's own database, never the development one.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();

    Storage::fake('local');

    $this->user = userWithRole('Support');
    $this->conversation = SupportConversation::query()->create([
        'client_id' => Client::factory()->create()->id,
        'queue_id' => SupportQueue::factory()->create()->id,
        // `authorizeConversationAccess()` admits somebody who is on the queue **or** is the
        // assignee. Assigning is the simpler of the two, and it is the state an operator is
        // actually in when they upload something.
        'assigned_to_user_id' => $this->user->id,
        'subject' => 'Prueba de adjunto',
        'status' => 'waiting_staff',
        'priority' => 'normal',
    ]);
});

it('stores an upload from staff on the private disk', function (): void {
    $response = $this->actingAs($this->user)->post(
        '/api/support/conversations/'.$this->conversation->id.'/attachments',
        ['file' => UploadedFile::fake()->create('nota.pdf', 12, 'application/pdf')],
    );

    $response->assertCreated();

    $attachment = SupportMessageAttachment::query()->firstOrFail();

    $this->assertSame($this->conversation->id, $attachment->conversation_id);
    $this->assertSame('file', $attachment->kind->value);
    $this->assertSame('staff', $attachment->source_channel);
    $this->assertSame($this->user->id, $attachment->uploaded_by_user_id);

    // Private, and under a generated name rather than the operator's.
    Storage::disk('local')->assertExists($attachment->stored_path);
    $this->assertStringNotContainsString('nota.pdf', $attachment->stored_path);
    $this->assertNull($attachment->support_message_id, 'linked when the message is sent');
});

it('records a recording as audio rather than a file', function (): void {
    $response = $this->actingAs($this->user)->post(
        '/api/support/conversations/'.$this->conversation->id.'/attachments',
        ['file' => UploadedFile::fake()->create('nota-de-voz.webm', 8, 'audio/webm')],
    );

    $response->assertCreated();

    $this->assertSame('audio', SupportMessageAttachment::query()->firstOrFail()->kind->value);
});

it('refuses a type outside the allowed list', function (): void {
    $this->actingAs($this->user)
        ->post('/api/support/conversations/'.$this->conversation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('ejecutable.exe', 4, 'application/octet-stream'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    $this->assertSame(0, SupportMessageAttachment::query()->count());
});

it('refuses a caller with no authority over the conversation', function (): void {
    $other = User::factory()->create();
    $other->assignRole(\Spatie\Permission\Models\Role::query()->firstOrCreate(['name' => 'Read Only']));

    $this->actingAs($other)
        ->post('/api/support/conversations/'.$this->conversation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('nota.pdf', 12, 'application/pdf'),
        ])
        ->assertStatus(403);
});

it('refuses an unauthenticated caller', function (): void {
    $this->post('/api/support/conversations/'.$this->conversation->id.'/attachments', [
        'file' => UploadedFile::fake()->create('nota.pdf', 12, 'application/pdf'),
    ])->assertStatus(401);
});

it('refuses a conversation that does not exist', function (): void {
    $this->actingAs($this->user)
        ->post('/api/support/conversations/999999/attachments', [
            'file' => UploadedFile::fake()->create('nota.pdf', 12, 'application/pdf'),
        ])
        ->assertStatus(404);
});

it('refuses to download an attachment that no message carries yet', function (): void {
    // The existing rule: an upload is not readable until it is sent. `downloadAttachment()`
    // returns 404 when `message` is null, and an attachment with no message has no place to be
    // read in the transcript. Recorded here so the behaviour is asserted rather than assumed.
    $attachment = SupportMessageAttachment::query()->create([
        'conversation_id' => $this->conversation->id,
        'support_message_id' => null,
        'kind' => 'file',
        'original_name' => 'nota.pdf',
        'stored_path' => 'support-attachments/prueba.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'sha256' => str_repeat('a', 64),
        'uploaded_by_user_id' => $this->user->id,
        'source_channel' => 'staff',
    ]);

    Storage::disk('local')->put($attachment->stored_path, 'contenido');

    $this->actingAs($this->user)
        ->get('/api/support/conversations/'.$this->conversation->id.'/attachments/'.$attachment->id)
        ->assertStatus(404);
});

it('lets the assignee download an attachment once a message carries it', function (): void {
    $message = SupportMessage::query()->create([
        'conversation_id' => $this->conversation->id,
        'sender_kind' => 'staff',
        'message_kind' => 'message',
        'channel' => 'staff',
        'body_text' => 'Adjunto',
        'author_user_id' => $this->user->id,
        'client_visible' => true,
    ]);

    $attachment = SupportMessageAttachment::query()->create([
        'conversation_id' => $this->conversation->id,
        'support_message_id' => $message->id,
        'kind' => 'file',
        'original_name' => 'nota.pdf',
        'stored_path' => 'support-attachments/enviado.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 8,
        'sha256' => str_repeat('c', 64),
        'uploaded_by_user_id' => $this->user->id,
        'source_channel' => 'staff',
    ]);

    Storage::disk('local')->put($attachment->stored_path, 'contenido');

    $this->actingAs($this->user)
        ->get('/api/support/conversations/'.$this->conversation->id.'/attachments/'.$attachment->id)
        ->assertOk();
});

it('refuses a download from somebody outside the conversation', function (): void {
    $attachment = SupportMessageAttachment::query()->create([
        'conversation_id' => $this->conversation->id,
        'support_message_id' => null,
        'kind' => 'file',
        'original_name' => 'nota.pdf',
        'stored_path' => 'support-attachments/ajeno.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'sha256' => str_repeat('b', 64),
        'uploaded_by_user_id' => $this->user->id,
        'source_channel' => 'staff',
    ]);

    Storage::disk('local')->put($attachment->stored_path, 'contenido');

    $other = User::factory()->create();
    $other->assignRole(\Spatie\Permission\Models\Role::query()->firstOrCreate(['name' => 'Support']));

    // Same role and a valid session, but never a member of this queue and not the assignee —
    // which is exactly the shape `authorizeConversationAccess()` exists to refuse.
    SupportQueueMember::query()->where('user_id', $other->id)->delete();

    $this->actingAs($other)
        ->get('/api/support/conversations/'.$this->conversation->id.'/attachments/'.$attachment->id)
        ->assertStatus(403);
});
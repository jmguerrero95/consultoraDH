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
use Tests\TestCase;

/**
 * G01 — an attachment can only be linked to a message in its own conversation,
 * by the person who uploaded it.
 *
 * The five association paths (client reply, staff reply, internal note, and both
 * conversation-creation endpoints) used to update by id with `whereNull`, so a
 * guessed id from another conversation could be pulled into a message. These
 * tests attack each path and assert that nothing is linked and nothing leaks.
 */
uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses()->group('A06');

beforeEach(function (): void {
    seedRoles();
    Storage::fake('local');

    $this->queue = SupportQueue::factory()->create([
        'name' => 'General',
        'slug' => 'general',
        'is_default' => true,
    ]);

    $this->staff = User::factory()->create(['account_type' => 'staff']);
    $this->staff->assignRole('Support');
    SupportQueueMember::create(['queue_id' => $this->queue->id, 'user_id' => $this->staff->id]);

    $this->clientA = Client::factory()->create();
    $this->userA = User::factory()->create(['account_type' => 'client', 'client_id' => $this->clientA->id]);

    $this->clientB = Client::factory()->create();
    $this->userB = User::factory()->create(['account_type' => 'client', 'client_id' => $this->clientB->id]);

    $this->conversationA = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
        'status' => 'waiting_staff',
    ]);

    $this->conversationB = SupportConversation::factory()->create([
        'client_id' => $this->clientB->id,
        'queue_id' => $this->queue->id,
        'status' => 'waiting_staff',
    ]);
});

function uploadAs($test, User $user, SupportConversation $conversation, string $channel = 'portal'): SupportMessageAttachment
{
    $base = $channel === 'staff' ? '/api/support' : '/api/portal/support';

    $response = $test->actingAs($user)->postJson("{$base}/conversations/{$conversation->id}/attachments", [
        'file' => UploadedFile::fake()->create('documento.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    return SupportMessageAttachment::findOrFail($response->json('id'));
}

test('a client cannot link an attachment from another client conversation', function (): void {
    $foreign = uploadAs($this, $this->userA, $this->conversationA);

    $this->actingAs($this->userB)
        ->postJson("/api/portal/support/conversations/{$this->conversationB->id}/messages", [
            'body_text' => 'Mensaje con adjunto ajeno',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'attachment_not_linkable');

    expect(SupportMessage::query()->where('conversation_id', $this->conversationB->id)->count())->toBe(0)
        ->and($foreign->fresh()->support_message_id)->toBeNull();
});

test('a client cannot link an attachment from another conversation of their own', function (): void {
    $other = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
        'status' => 'waiting_staff',
    ]);

    $foreign = uploadAs($this, $this->userA, $other);

    $this->actingAs($this->userA)
        ->postJson("/api/portal/support/conversations/{$this->conversationA->id}/messages", [
            'body_text' => 'Mensaje con adjunto de otra conversación',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertStatus(422);

    expect($foreign->fresh()->support_message_id)->toBeNull()
        ->and(SupportMessage::query()->where('conversation_id', $this->conversationA->id)->count())->toBe(0);
});

test('staff cannot link an attachment from another conversation in the same queue', function (): void {
    $foreign = uploadAs($this, $this->staff, $this->conversationB, 'staff');

    $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conversationA->id}/messages", [
            'body_text' => 'Respuesta con adjunto ajeno',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertStatus(422);

    expect($foreign->fresh()->support_message_id)->toBeNull();
});

test('staff cannot link an attachment uploaded by somebody else', function (): void {
    $foreign = uploadAs($this, $this->userA, $this->conversationA);

    $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conversationA->id}/notes", [
            'body_text' => 'Nota con adjunto de un cliente',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertStatus(422);

    expect($foreign->fresh()->support_message_id)->toBeNull();
});

test('creating a conversation cannot adopt an attachment from an existing one', function (): void {
    $foreign = uploadAs($this, $this->userA, $this->conversationA);
    $before = SupportConversation::query()->count();

    $this->actingAs($this->userA)
        ->postJson('/api/portal/support/conversations', [
            'subject' => 'Nueva conversación',
            'body_text' => 'Cuerpo',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertStatus(422);

    expect(SupportConversation::query()->count())->toBe($before)
        ->and($foreign->fresh()->support_message_id)->toBeNull();
});

test('a client can link their own attachment to their own message', function (): void {
    $own = uploadAs($this, $this->userA, $this->conversationA);

    $response = $this->actingAs($this->userA)
        ->postJson("/api/portal/support/conversations/{$this->conversationA->id}/messages", [
            'body_text' => 'Mensaje con mi adjunto',
            'attachment_ids' => [$own->id],
        ])
        ->assertCreated();

    expect($own->fresh()->support_message_id)->toBe($response->json('id'))
        ->and($response->json('attachments.0.id'))->toBe($own->id);
});

test('staff can link their own upload to an internal note', function (): void {
    $own = uploadAs($this, $this->staff, $this->conversationA, 'staff');

    $response = $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conversationA->id}/notes", [
            'body_text' => 'Nota con mi adjunto',
            'attachment_ids' => [$own->id],
        ])
        ->assertCreated();

    expect($own->fresh()->support_message_id)->toBe($response->json('id'));
});

test('an already sent attachment cannot be attached a second time', function (): void {
    $own = uploadAs($this, $this->userA, $this->conversationA);

    $this->actingAs($this->userA)
        ->postJson("/api/portal/support/conversations/{$this->conversationA->id}/messages", [
            'body_text' => 'Primer mensaje',
            'attachment_ids' => [$own->id],
        ])
        ->assertCreated();

    $this->actingAs($this->userA)
        ->postJson("/api/portal/support/conversations/{$this->conversationA->id}/messages", [
            'body_text' => 'Segundo mensaje',
            'attachment_ids' => [$own->id],
        ])
        ->assertStatus(422);

    expect(SupportMessage::query()->where('conversation_id', $this->conversationA->id)->count())->toBe(1);
});

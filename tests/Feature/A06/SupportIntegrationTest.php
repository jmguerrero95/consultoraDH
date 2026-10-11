<?php

declare(strict_types=1);

namespace Tests\Feature\A06;

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(\Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    seedRoles();

    $this->staff = User::factory()->create(['account_type' => 'staff']);
    $this->staff->assignRole('Support');

    $this->clientA = Client::factory()->create();
    $this->userA = User::factory()->create(['account_type' => 'client', 'client_id' => $this->clientA->id]);

    $this->clientB = Client::factory()->create();
    $this->userB = User::factory()->create(['account_type' => 'client', 'client_id' => $this->clientB->id]);

    $this->queue = SupportQueue::factory()->create(['name' => 'General', 'slug' => 'general']);
    \App\Models\SupportQueueMember::create([
        'queue_id' => $this->queue->id,
        'user_id' => $this->staff->id,
    ]);
});

test('client A and B cannot access each other conversations', function (): void {
    $convA = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
        'subject' => 'Client A Issue',
    ]);

    $convB = SupportConversation::factory()->create([
        'client_id' => $this->clientB->id,
        'queue_id' => $this->queue->id,
        'subject' => 'Client B Issue',
    ]);

    // Client A can see their own conversation
    $responseA = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$convA->id}");
    $responseA->assertOk();
    $responseA->assertJsonPath('subject', 'Client A Issue');

    // Client A cannot see Client B's conversation
    $responseA2 = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$convB->id}");
    $responseA2->assertNotFound();

    // Client B can see their own conversation
    $responseB = $this->actingAs($this->userB)
        ->getJson("/api/portal/support/conversations/{$convB->id}");
    $responseB->assertOk();

    // Client B cannot see Client A's conversation
    $responseB2 = $this->actingAs($this->userB)
        ->getJson("/api/portal/support/conversations/{$convA->id}");
    $responseB2->assertNotFound();
});

test('staff can see all conversations in their queue', function (): void {
    $convA = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    $convB = SupportConversation::factory()->create([
        'client_id' => $this->clientB->id,
        'queue_id' => $this->queue->id,
    ]);

    $response = $this->actingAs($this->staff)
        ->getJson('/api/support/inbox');

    $response->assertOk();
    $subjects = collect($response->json('conversations.data'))->pluck('subject');
    expect($subjects)->toContain($convA->subject);
    expect($subjects)->toContain($convB->subject);
});

test('staff outside queue cannot see conversation', function (): void {
    $otherQueue = SupportQueue::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $otherQueue->id,
    ]);

    // Staff not in the queue cannot access
    $response = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conv->id}");
    $response->assertForbidden();
});

test('internal note is invisible to portal client', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    // Staff adds internal note
    $note = SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Internal note for staff only',
        'client_visible' => false,
    ]);

    // Client cannot see the internal note
    $response = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$conv->id}?per_page=50");

    $response->assertOk();
    $messages = $response->json('messages');
    $noteTexts = collect($messages)->pluck('body_text');
    expect($noteTexts)->not->toContain('Internal note for staff only');
});

test('staff can see internal notes', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    $note = SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Internal note for staff only',
        'client_visible' => false,
    ]);

    $response = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conv->id}?per_page=50");

    $response->assertOk();
    $messages = $response->json('messages');
    $noteTexts = collect($messages)->pluck('body_text');
    expect($noteTexts)->toContain('Internal note for staff only');
});

test('monotonic read cursor works', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    $msg1 = SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Message 1',
        'client_visible' => true,
    ]);

    $msg2 = SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->userA->id,
        'body_text' => 'Message 2',
        'client_visible' => true,
    ]);

    $msg3 = SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Message 3',
        'client_visible' => true,
    ]);

    // Request first page
    $response1 = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$conv->id}?per_page=2");
    $response1->assertOk();
    $messages1 = $response1->json('messages');
    expect($messages1)->toHaveCount(2);

    // Use cursor to get next page
    $cursor = $response1->header('X-Next-Cursor');
    $response2 = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$conv->id}?per_page=2&before_id={$cursor}");
    $response2->assertOk();
    $messages2 = $response2->json('messages');
    expect($messages2)->toHaveCount(1);

    // Verify no overlap
    $ids1 = collect($messages1)->pluck('id');
    $ids2 = collect($messages2)->pluck('id');
    expect($ids1->intersect($ids2)->isEmpty())->toBeTrue();
});

test('visible message channel for client', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Staff reply',
        'client_visible' => true,
        'channel' => \App\Domain\Support\SupportMessageChannel::Portal,
        'sender_kind' => \App\Domain\Support\SupportMessageSenderKind::Staff,
        'message_kind' => \App\Domain\Support\SupportMessageKind::Message,
    ]);

    $response = $this->actingAs($this->userA)
        ->getJson("/api/portal/support/conversations/{$conv->id}");
    $response->assertOk();
    $messages = $response->json('messages');
    expect($messages)->toHaveCount(1);
    expect($messages[0]['body_text'])->toBe('Staff reply');
});

test('internal note channel for staff', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    SupportMessage::create([
        'conversation_id' => $conv->id,
        'author_id' => $this->staff->id,
        'body_text' => 'Internal note',
        'client_visible' => false,
        'channel' => \App\Domain\Support\SupportMessageChannel::Staff,
        'sender_kind' => \App\Domain\Support\SupportMessageSenderKind::Staff,
        'message_kind' => \App\Domain\Support\SupportMessageKind::Note,
    ]);

    $response = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conv->id}");
    $response->assertOk();
    $messages = $response->json('messages');
    expect($messages)->toHaveCount(1);
    expect($messages[0]['body_text'])->toBe('Internal note');
});

test('reconnect resync endpoint contract', function (): void {
    $conv = SupportConversation::factory()->create([
        'client_id' => $this->clientA->id,
        'queue_id' => $this->queue->id,
    ]);

    // Client marks as read
    $response = $this->actingAs($this->userA)
        ->postJson("/api/portal/support/conversations/{$conv->id}/read", [
            'up_to_message_id' => $conv->last_message_id,
        ]);
    $response->assertOk();
    expect($response->json('unread_count'))->toBe(0);
});
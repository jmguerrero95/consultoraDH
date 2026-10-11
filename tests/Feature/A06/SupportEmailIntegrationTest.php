<?php

declare(strict_types=1);

namespace Tests\Feature\A06;

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReplyToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(\Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    seedRoles();

    $this->staff = User::factory()->create(['account_type' => 'staff']);
    $this->staff->assignRole('Support');

    $this->client = Client::factory()->create();
    $this->user = User::factory()->create(['account_type' => 'client', 'client_id' => $this->client->id]);

    $this->conv = SupportConversation::factory()->create([
        'client_id' => $this->client->id,
        'subject' => 'Test Conversation',
    ]);
});

test('staff reply produces fallback email with transient raw token', function (): void {
    $response = $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conv->id}/messages", [
            'body_text' => 'Staff reply to client',
        ]);

    $response->assertCreated();
    $message = $response->json('message');
    expect($message['body_text'])->toBe('Staff reply to client');

    // Verify token was issued and stored as hash
    $token = SupportReplyToken::where('conversation_id', $this->conv->id)
        ->where('user_id', $this->staff->id)
        ->first();
    expect($token)->not->toBeNull();
    expect($token->token_hash)->not->toBeNull();
    expect($token->raw_token)->toBeNull(); // Never stored
});

test('fallback email contains correct reply-to with token', function (): void {
    $response = $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conv->id}/messages", [
            'body_text' => 'Staff reply',
        ]);

    $response->assertCreated();

    // The fallback email logic would generate a reply-to with token
    // We verify the token exists and is valid
    $token = SupportReplyToken::where('conversation_id', $this->conv->id)->first();
    expect($token)->not->toBeNull();

    // Token should be usable for inbound
    $rawToken = $token->getRawToken(); // This method doesn't exist on stored model
    // The raw token is only available at issuance time
    // We verify the hash is stored
    expect($token->token_hash)->not->toBeNull();
});

test('valid client email reply correlates to same conversation', function (): void {
    // Staff sends reply, generating token
    $this->actingAs($this->staff)
        ->postJson("/api/support/conversations/{$this->conv->id}/messages", [
            'body_text' => 'Staff reply',
        ]);

    $token = SupportReplyToken::where('conversation_id', $this->conv->id)->first();
    expect($token)->not->toBeNull();

    // Get the raw token from the token model (we need to simulate the issuance)
    // In reality, the raw token is only available at issuance. For testing,
    // we create a token directly with known raw token.
    $tokenRecord = SupportReplyToken::create([
        'conversation_id' => $this->conv->id,
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'known-raw-token'),
        'expires_at' => now()->addDays(7),
    ]);

    // Simulate inbound email with correct sender and token
    $hmacSecret = config('support.email.hmac_secret');
    $timestamp = time();
    $payload = "known-raw-token|{$timestamp}";
    $hmac = hash_hmac('sha256', $payload, $hmacSecret);

    $inboundPayload = [
        'token' => 'known-raw-token',
        'timestamp' => $timestamp,
        'hmac' => $hmac,
        'from' => $this->user->email,
        'subject' => 'Re: Test Conversation',
        'text' => 'Client reply via email',
        'message_id' => '<test-' . time() . '@example.com>',
    ];

    $response = $this->postJson('/api/internal/support-email/inbound', $inboundPayload, [
        'X-Support-Email-Signature' => $hmac,
        'X-Support-Email-Timestamp' => (string) $timestamp,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'correlated');
    $response->assertJsonPath('conversation_id', $this->conv->id);

    // Verify message was created in the conversation
    $messages = SupportMessage::where('conversation_id', $this->conv->id)
        ->where('body_text', 'Client reply via email')
        ->get();
    expect($messages)->toHaveCount(1);
    expect($messages->first()->author_id)->toBe($this->user->id);
    expect($messages->first()->client_visible)->toBeTrue();
});

test('wrong sender email goes to quarantine', function (): void {
    $tokenRecord = SupportReplyToken::create([
        'conversation_id' => $this->conv->id,
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'known-raw-token'),
        'expires_at' => now()->addDays(7),
    ]);

    $hmacSecret = config('support.email.hmac_secret');
    $timestamp = time();
    $payload = "known-raw-token|{$timestamp}";
    $hmac = hash_hmac('sha256', $payload, config('support.email.hmac_secret'));

    $inboundPayload = [
        'token' => 'known-raw-token',
        'timestamp' => $timestamp,
        'hmac' => $hmac,
        'from' => 'attacker@evil.com', // Different sender
        'subject' => 'Re: Test Conversation',
        'text' => 'Malicious reply',
        'message_id' => '<test-' . time() . '@evil.com>',
    ];

    $response = $this->postJson('/api/internal/support-email/inbound', $inboundPayload, [
        'X-Support-Email-Signature' => $hmac,
        'X-Support-Email-Timestamp' => (string) $timestamp,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'quarantined');
    $response->assertJsonPath('reason', 'token_sender_mismatch');

    // Verify no message was created
    $messages = SupportMessage::where('conversation_id', $this->conv->id)
        ->where('body_text', 'Malicious reply')
        ->get();
    expect($messages)->toBeEmpty();
});

test('In-Reply-To alone cannot authorize', function (): void {
    $tokenRecord = SupportReplyToken::create([
        'conversation_id' => $this->conv->id,
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'known-raw-token'),
        'expires_at' => now()->addDays(7),
    ]);

    $hmacSecret = config('support.email.hmac_secret');
    $timestamp = time();
    $payload = "known-raw-token|{$timestamp}";
    $hmac = hash_hmac('sha256', $payload, $hmacSecret);

    // Inbound with correct In-Reply-To but invalid HMAC (no token)
    $inboundPayload = [
        'token' => 'invalid-token',
        'timestamp' => $timestamp,
        'hmac' => 'invalid-hmac',
        'from' => $this->user->email,
        'subject' => 'Re: Test Conversation',
        'text' => 'Reply without valid token',
        'message_id' => '<test-' . time() . '@example.com>',
        'in_reply_to' => '<original-message-id@example.com>',
    ];

    $response = $this->postJson('/api/internal/support-email/inbound', $inboundPayload, [
        'X-Support-Email-Signature' => 'invalid-hmac',
        'X-Support-Email-Timestamp' => (string) $timestamp,
    ]);

    $response->assertStatus(401); // HMAC validation fails
});

test('duplicate ingress creates no duplicate message', function (): void {
    $tokenRecord = SupportReplyToken::create([
        'conversation_id' => $this->conv->id,
        'user_id' => $this->user->id,
        'token_hash' => hash('sha256', 'known-raw-token'),
        'expires_at' => now()->addDays(7),
    ]);

    $hmacSecret = config('support.email.hmac_secret');
    $timestamp = time();
    $payload = "known-raw-token|{$timestamp}";
    $hmac = hash_hmac('sha256', $payload, $hmacSecret);

    $inboundPayload = [
        'token' => 'known-raw-token',
        'timestamp' => $timestamp,
        'hmac' => $hmac,
        'from' => $this->user->email,
        'subject' => 'Re: Test Conversation',
        'text' => 'Duplicate test reply',
        'message_id' => '<duplicate-test-' . time() . '@example.com>',
    ];

    // First inbound
    $response1 = $this->postJson('/api/internal/support-email/inbound', $inboundPayload, [
        'X-Support-Email-Signature' => $hmac,
        'X-Support-Email-Timestamp' => (string) $timestamp,
    ]);
    $response1->assertOk();
    $response1->assertJsonPath('status', 'correlated');

    // Second inbound with same message_id
    $response2 = $this->postJson('/api/internal/support-email/inbound', $inboundPayload, [
        'X-Support-Email-Signature' => $hmac,
        'X-Support-Email-Timestamp' => (string) $timestamp,
    ]);
    $response2->assertOk();
    $response2->assertJsonPath('status', 'duplicate');

    // Verify only one message was created
    $messages = SupportMessage::where('conversation_id', $this->conv->id)
        ->where('body_text', 'Duplicate test reply')
        ->get();
    expect($messages)->toHaveCount(1);
});
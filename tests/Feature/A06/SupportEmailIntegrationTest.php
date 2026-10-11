<?php

declare(strict_types=1);

use App\Domain\Support\SupportConversationStatus;
use App\Domain\Support\SupportInboundEmailStatus;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportInboundEmail;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\SupportReplyToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * End-to-end ingestion of a real inbound email, over HTTP, through the ingress
 * endpoint the mail provider actually calls.
 *
 * ## Scope
 *
 * `InboundEmailSecurityTest` owns the HMAC gate: missing headers, bad signature,
 * stale and future stamps, nonce replay, an unconfigured secret and oversized
 * bodies. `SupportReplyTokenTest` owns correlation: a valid token reaching its
 * conversation, a wrong sender being quarantined, a revoked token correlating
 * nothing, and a token replayed against a different conversation.
 *
 * Neither of those covers what is left once the message is *accepted*, so this
 * file covers only the two gaps:
 *
 *   1. **Idempotency.** A provider that delivers the same mail twice must not
 *      produce two messages in the client's thread. The gate is the ingress
 *      fingerprint, so a repeat of the identical bytes is refused before
 *      correlation is even attempted.
 *   2. **A terminal conversation.** A token stays redeemable after the thread
 *      is closed, so correlation can still succeed at the address level while
 *      the conversation refuses the write. That combination has to land in
 *      quarantine rather than resurrect a closed thread.
 *
 * ## On the shape of these tests
 *
 * The endpoint reads a raw RFC822 body (`SupportEmailIngressController` hands
 * `$request->getContent()` to the parser) and the signature covers those exact
 * bytes, so a JSON payload would never reach the correlation at all. Every
 * request below therefore signs the same literal message it posts.
 *
 * The reply token is obtained from `SupportReplyToken::issue()`, which is the
 * only thing that ever holds the raw value. Reading it back off a loaded row is
 * impossible by construction — the model stores only the SHA-256 — which is why
 * no test here tries.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();

    config([
        'support.inbound_secret' => 'secret-for-ingress-idempotency-test',
        'support.inbound_domain' => 'soporte.example.test',
    ]);
});

/**
 * A client with a portal address, plus a conversation for a token to bind to.
 *
 * @return array{0: Client, 1: SupportConversation}
 */
function ingressContext(string $status = 'waiting_staff'): array
{
    $client = Client::factory()->create();
    $queue = SupportQueue::factory()->create();

    User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
        'email' => 'cliente@example.test',
    ]);

    $conversation = SupportConversation::factory()->create([
        'client_id' => $client->id,
        'queue_id' => $queue->id,
        'subject' => 'Consulta sobre mi factura',
        'status' => $status,
    ]);

    return [$client, $conversation];
}

/**
 * Sign and post one literal RFC822 message.
 *
 * The nonce is a parameter rather than generated internally because the replay
 * of a *body* has to reuse the same bytes while still presenting a fresh nonce:
 * re-signing with the same nonce would be refused by the middleware and the test
 * would prove the nonce gate instead of the idempotency gate.
 */
function postRawEmail($test, array $fields, ?string $messageId = null): TestResponse
{
    $messageId ??= '<'.Str::random(20).'@mail.example.test>';

    $raw = implode("\r\n", [
        'Message-ID: '.$messageId,
        'From: '.$fields['from'],
        'To: '.$fields['to'],
        'Subject: '.$fields['subject'],
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $fields['body'],
        '',
    ]);

    $timestamp = (string) time();
    $nonce = 'nonce-'.Str::random(12);
    $signature = hash_hmac(
        'sha256',
        $timestamp."\n".$nonce."\n".hash('sha256', $raw),
        (string) config('support.inbound_secret'),
    );

    return $test->call(
        'POST',
        '/api/internal/support-email/inbound',
        [],
        [],
        [],
        [
            'HTTP_X_CDH_TIMESTAMP' => $timestamp,
            'HTTP_X_CDH_NONCE' => $nonce,
            'HTTP_X_CDH_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'message/rfc822',
        ],
        $raw,
    );
}

/* -------------------------------------------------------------------------- */
/* | | R4 — idempotency of a redelivered message                                  | */
/* -------------------------------------------------------------------------- */

test('the same mail delivered twice is stored once', function (): void {
    [$client, $conversation] = ingressContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    $fields = [
        'from' => 'cliente@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Consulta sobre mi factura',
        'body' => 'Adjunto el comprobante de pago.',
    ];

    // A fixed Message-ID, and therefore identical bytes: this is the provider
    // retrying the same delivery, not the client writing twice.
    $messageId = '<redelivered-mail@mail.example.test>';

    $first = postRawEmail($this, $fields, $messageId);
    $first->assertOk();
    expect($first->json('status'))->toBe('linked');
    expect($first->json('conversation_id'))->toBe($conversation->id);

    // Second delivery of the very same bytes, under a fresh nonce so the request
    // passes the middleware and reaches the idempotency gate.
    $second = postRawEmail($this, $fields, $messageId);
    $second->assertOk();
    expect($second->json('status'))->toBe('duplicate');

    // The retry resolves to the delivery already recorded, rather than to a
    // second one.
    expect($second->json('inbound_email_id'))->toBe($first->json('inbound_email_id'));

    // The client's thread holds one message, not two.
    expect(SupportMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('body_text', 'Adjunto el comprobante de pago.')
        ->count())->toBe(1);

    // And the ingress log holds one row for the pair of deliveries.
    expect(SupportInboundEmail::query()->count())->toBe(1);
})->group('R4');

/* -------------------------------------------------------------------------- */
/* | | R4 — a closed conversation refuses the write                                | */
/* -------------------------------------------------------------------------- */

test('a valid reply into a closed conversation is quarantined, not reopened', function (): void {
    [$client, $conversation] = ingressContext('closed');

    // The token is still redeemable: closing a thread does not revoke a reply
    // address. So correlation genuinely succeeds here, and the refusal has to
    // come from the conversation rather than from the address check.
    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);
    expect(SupportReplyToken::redeem($issued['raw_token']))->not->toBeNull();

    $response = postRawEmail($this, [
        'from' => 'cliente@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Consulta sobre mi factura',
        'body' => 'Write to a thread that is already closed.',
    ]);

    $response->assertOk();

    expect($response->json('status'))->toBe('quarantined');
    expect($response->json('reason'))->toBe('conversation_terminal');
    expect($response->json('conversation_id'))->toBeNull();

    // Nothing was written into the closed thread.
    expect(SupportMessage::query()
        ->where('conversation_id', $conversation->id)
        ->exists())->toBeFalse();

    // And it stays closed — a late delivery must not reopen it.
    expect($conversation->fresh()->status)->toBe(SupportConversationStatus::Closed);

    $quarantined = SupportInboundEmail::query()->latest('id')->first();
    expect($quarantined->status)->toBe(SupportInboundEmailStatus::Quarantined);
    expect($quarantined->reason)->toBe('conversation_terminal');
})->group('R4');

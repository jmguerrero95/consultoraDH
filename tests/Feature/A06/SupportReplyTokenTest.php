<?php

declare(strict_types=1);

use App\Domain\Support\SupportInboundEmailStatus;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportInboundEmail;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\SupportReplyToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * R2-05 and R2-06 — the reply token and the correlation it authorises.
 *
 * ## The two defects these exist to pin down
 *
 * **Issuance.** Only the hash of a reply token is stored, so the raw value is
 * unrecoverable by design. The previous mailer nonetheless tried to reuse an
 * outstanding token and read `$token->raw_token` off it — an attribute that
 * never existed and never could — and on the other branch generated a token,
 * dropped the raw value into a local array, saved only the hash, then read the
 * same non-existent attribute back off the row it had just created. Both
 * branches were fatal or unreachable.
 *
 * **Direction.** The correlation branch was inverted: it returned
 * `token_sender_mismatch` when the sender was *valid*, and fell through to
 * return the conversation when the sender was *wrong*. So the one case that must
 * never happen — a stranger's mail posted into a client's private thread — was
 * the case the code actively did.
 *
 * Both are asserted here as behaviour rather than as code shape: the direction
 * is proved by checking both outcomes, and issuance by proving the raw token is
 * gone from the database the moment the send completes.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
});

function supportContext(): array
{
    $client = Client::factory()->create();
    $queue = SupportQueue::factory()->create();

    $conversation = SupportConversation::factory()->create([
        'client_id' => $client->id,
        'queue_id' => $queue->id,
        'subject' => 'Consulta sobre mi factura',
        'status' => 'waiting_staff',
    ]);

    return [$client, $queue, $conversation];
}

/* -------------------------------------------------------------------------- */
/* | | R2-05 — the token itself                                                    *  */
/* -------------------------------------------------------------------------- */

test('issuance returns a raw token and stores only its hash', function (): void {
    [$client, , $conversation] = supportContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);
    $raw = $issued['raw_token'];

    expect($raw)->toBeString()->toHaveLength(64)
        ->and($raw)->toMatch('/^[a-f0-9]{64}$/');

    // What is on the row is the digest and never the token itself.
    expect($issued['token']->token_hash)->toBe(hash('sha256', $raw))
        ->and($issued['token']->token_hash)->not->toBe($raw);

    // And the raw value is absent from the stored row's own attributes, which is
    // what "not stored" has to mean if it is ever serialised.
    expect($issued['token']->getAttributes())->not->toHaveKey('raw_token');
})->group('R2-05');

test('the raw token cannot be recovered from the database', function (): void {
    [$client, , $conversation] = supportContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);
    $raw = $issued['raw_token'];

    // Reload from the database, as a later request would.
    $reloaded = SupportReplyToken::query()->findOrFail($issued['token']->id);

    expect($reloaded->getAttributes())->not->toHaveKey('raw_token')
        ->and($reloaded->getAttributes())->not->toContain($raw)
        // The hash is hidden from serialisation, so a dump cannot leak the half
        // of the pair that verifies one.
        ->and($reloaded->toArray())->not->toHaveKey('token_hash');
})->group('R2-05');

test('a token is redeemed by its raw value and only while valid', function (): void {
    [$client, , $conversation] = supportContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    expect(SupportReplyToken::redeem($issued['raw_token']))->not->toBeNull();

    // A wrong token of the right shape resolves to null rather than erroring.
    expect(SupportReplyToken::redeem(str_repeat('a', 64)))->toBeNull();

    // Revoked.
    $issued['token']->revoke();
    expect(SupportReplyToken::redeem($issued['raw_token']))->toBeNull();

    // Expired.
    $second = SupportReplyToken::issue($conversation, 'client', client: $client);
    $second['token']->forceFill(['expires_at' => now()->subMinute()])->save();
    expect(SupportReplyToken::redeem($second['raw_token']))->toBeNull();
})->group('R2-05');

test('issuing supersedes the previous token so only one is ever live', function (): void {
    [$client, , $conversation] = supportContext();

    $first = SupportReplyToken::issue($conversation, 'client', client: $client);
    $second = SupportReplyToken::issue($conversation, 'client', client: $client);

    // The old address stops working the moment a new one is issued.
    expect(SupportReplyToken::redeem($first['raw_token']))->toBeNull()
        ->and(SupportReplyToken::redeem($second['raw_token']))->not->toBeNull();

    // And exactly one row is unrevoked.
    expect(SupportReplyToken::query()
        ->where('conversation_id', $conversation->id)
        ->where('participant_kind', 'client')
        ->whereNull('revoked_at')
        ->count())->toBe(1);
})->group('R2-05');

test('isValid refuses a revoked or expired token', function (): void {
    [$client, , $conversation] = supportContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);
    $token = $issued['token'];

    expect($token->isValid())->toBeTrue();

    $token->revoke();
    expect($token->fresh()->isValid())->toBeFalse();

    $other = SupportReplyToken::issue($conversation, 'client', client: $client);
    $other['token']->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($other['token']->fresh()->isValid())->toBeFalse();
})->group('R2-05');

test('the token is scoped to the conversation it was issued for', function (): void {
    [$client, , $conversation] = supportContext();
    [$otherClient, , $otherConversation] = supportContext();

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    expect($issued['token']->belongsToConversation($conversation->id))->toBeTrue()
        ->and($issued['token']->belongsToConversation($otherConversation->id))->toBeFalse();
})->group('R2-05');

/* -------------------------------------------------------------------------- */
/* | | R2-05 — the fallback mail that issues it                                    *  */
/* -------------------------------------------------------------------------- */

test('the fallback email issues a token and puts it in the reply-to address', function (): void {
    config(['support.inbound_domain' => 'soporte.example.test', 'mail.default' => 'array']);

    [$client, , $conversation] = supportContext();

    $staff = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $message = SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'author_user_id' => $staff->id,
        'sender_kind' => 'staff',
        'message_kind' => 'message',
        'body_text' => 'Le adjunto el estado de su caso.',
    ]);

    $mailable = new \App\Mail\SupportFallbackEmail($message, $conversation);
    // Laravel normalises reply-to into an Address value object.
    $address = $mailable->envelope()->replyTo[0]->address;

    // The address carries a token that the database can resolve.
    expect($address)->toStartWith('reply+')->toEndWith('@soporte.example.test');

    $raw = Str::after(Str::before($address, '@'), 'reply+');
    $token = SupportReplyToken::redeem($raw);

    expect($token)->not->toBeNull()
        ->and($token->conversation_id)->toBe($conversation->id)
        ->and($token->participant_kind)->toBe('client')
        ->and($token->client_id)->toBe($client->id);
})->group('R2-05');

test('one send issues exactly one token', function (): void {
    config(['support.inbound_domain' => 'soporte.example.test']);

    [, , $conversation] = supportContext();

    $staff = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $message = SupportMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'author_user_id' => $staff->id,
        'sender_kind' => 'staff',
        'message_kind' => 'message',
        'body_text' => 'Respuesta.',
    ]);

    $mailable = new \App\Mail\SupportFallbackEmail($message, $conversation);

    // The mailer asks for the envelope more than once while it builds and
    // retries a message. Each call must not issue, and supersede, a token.
    $first = $mailable->envelope()->replyTo[0]->address;
    $second = $mailable->envelope()->replyTo[0]->address;

    expect($second)->toBe($first)
        ->and(SupportReplyToken::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->count())->toBe(1);
})->group('R2-05');

/* -------------------------------------------------------------------------- */
/* R2-06 — the direction of the correlation                                    */
/* -------------------------------------------------------------------------- */

/**
 * Drives the real ingress path with a signed request.
 *
 * The correlation decision is made in `SupportEmailIngressService`, so asserting
 * it means going in through the HTTP endpoint the way a mail provider does —
 * otherwise the test would only restate the service's own logic.
 */
/**
 * Post a real RFC822 message through the ingress endpoint, signed.
 *
 * The endpoint takes a raw mail body and parses it with Symfony's MIME parser,
 * so a JSON payload would never exercise the correlation at all — it would fail
 * at `parse_failed` and every assertion below would be measuring the wrong
 * thing. The body is therefore assembled as an actual message, and the signature
 * is computed over those exact bytes.
 */
function ingestWithReplyToken($test, array $fields): Illuminate\Testing\TestResponse
{
    $raw = implode("\r\n", [
        'Message-ID: <'.Str::random(20).'@mail.example.test>',
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

test('a valid token with the right sender reaches the exact conversation', function (): void {
    config(['support.inbound_secret' => 'secret-for-correlation-test', 'support.inbound_domain' => 'soporte.example.test']);

    [$client, , $conversation] = supportContext();

    // The portal address the client actually writes from.
    $portalUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
        'email' => 'cliente@example.test',
    ]);

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    $response = ingestWithReplyToken($this, [
        'from' => 'cliente@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Consulta sobre mi factura',
        'body' => 'Gracias, sigo esperando la respuesta.',
    ]);

    $response->assertOk();

    // The reply landed in the conversation the token was issued for.
    expect($response->json('conversation_id'))->toBe($conversation->id);
    expect(SupportMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('sender_kind', 'client')
        ->exists())->toBeTrue();
})->group('R2-06');

test('a valid token with the wrong sender is quarantined, never correlated', function (): void {
    config(['support.inbound_secret' => 'secret-for-correlation-test', 'support.inbound_domain' => 'soporte.example.test']);

    [$client, , $conversation] = supportContext();

    $portalUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
        'email' => 'cliente@example.test',
    ]);

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    $response = ingestWithReplyToken($this, [
        // Somebody else quoting a reply address they were not entitled to.
        'from' => 'extraño@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Consulta sobre mi factura',
        'body' => 'Informacion que no le corresponde ver.',
    ], $issued['raw_token']);

    $response->assertOk();

    // Quarantined, and the reason recorded is the mismatch.
    expect($response->json('status'))->toBe('quarantined');
    expect($response->json('reason'))->toBe('token_sender_mismatch');

    // And nothing was written into the client's thread.
    expect($response->json('conversation_id'))->toBeNull();
    expect(SupportMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('sender_kind', 'client')
        ->exists())->toBeFalse();

    $quarantined = SupportInboundEmail::query()->latest('id')->first();
    expect($quarantined->status)->toBe(SupportInboundEmailStatus::Quarantined);
})->group('R2-06');

test('a revoked token does not correlate and does not accuse the sender', function (): void {
    config(['support.inbound_secret' => 'secret-for-correlation-test', 'support.inbound_domain' => 'soporte.example.test']);

    [$client, , $conversation] = supportContext();

    $portalUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
        'email' => 'cliente@example.test',
    ]);

    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);
    $issued['token']->revoke();

    $response = ingestWithReplyToken($this, [
        'from' => 'cliente@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Consulta',
        'body' => 'Mensaje con un token ya revocado.',
    ]);

    $response->assertOk();

    // Treated as no correlation at all: the sender did nothing wrong, so it is
    // not reported as a mismatch.
    expect($response->json('reason'))->toBe('no_correlation');
    expect(SupportMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('sender_kind', 'client')
        ->exists())->toBeFalse();
})->group('R2-06');

test('a token cannot be replayed against a different conversation', function (): void {
    config(['support.inbound_secret' => 'secret-for-correlation-test', 'support.inbound_domain' => 'soporte.example.test']);

    [$client, , $conversation] = supportContext();
    [$otherClient, , $otherConversation] = supportContext();

    $portalUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $client->id,
        'email' => 'cliente@example.test',
    ]);

    $otherPortalUser = User::factory()->create([
        'account_type' => 'client',
        'status' => 'active',
        'client_id' => $otherClient->id,
        'email' => 'otro@example.test',
    ]);

    // A token issued for the first conversation, replayed against the second.
    $issued = SupportReplyToken::issue($conversation, 'client', client: $client);

    $response = ingestWithReplyToken($this, [
        'from' => 'otro@example.test',
        'to' => sprintf('reply+%s@soporte.example.test', $issued['raw_token']),
        'subject' => 'RE: Otra conversación',
        'body' => 'Intento de replay.',
    ]);

    $response->assertOk();

    // The sender is the second client's, but the token belongs to the first, so
    // the sender check fails against the token's own conversation.
    expect($response->json('reason'))->toBe('token_sender_mismatch');
    expect($response->json('conversation_id'))->toBeNull();
})->group('R2-06');
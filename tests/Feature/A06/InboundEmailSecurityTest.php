<?php

declare(strict_types=1);

use App\Models\SupportInboundEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * R2-03 / R2-04 — the inbound email security boundary and the HMAC gate.
 *
 * Every test here goes through the real HTTP kernel and the real middleware.
 * There is no environment bypass: `VerifySupportEmailHmac` authenticates in the
 * test environment exactly as it does in production, so a test that passes is
 * evidence about the shipped behaviour rather than about a stub.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

const INBOUND_SECRET = 'inbound-secret-for-tests-only';
const INBOUND_URI = '/api/internal/support-email/inbound';

/** The signed representation the middleware expects: timestamp, nonce, body digest. */
function inboundSignature(string $timestamp, string $nonce, string $body, ?string $secret = null): string
{
    return hash_hmac(
        'sha256',
        $timestamp."\n".$nonce."\n".hash('sha256', $body),
        // Read from configuration by default, exactly as the middleware does, so a
        // test that changes the configured secret is signing with the value the
        // middleware will actually compare against.
        $secret ?? (string) config('support.inbound_secret'),
    );
}

/** A correctly signed request. Each test overrides one element of it. */
function inboundRequest(array $overrides = []): array
{
    // A real RFC822 body, because the ingress endpoint parses MIME rather than
    // JSON. A JSON payload would be refused at `parse_failed`, and the test
    // would then be measuring the parser rather than the HMAC gate.
    $body = $overrides['body'] ?? implode("\r\n", [
        'Message-ID: <h1@mail.example.test>',
        'From: Ana <ana@example.test>',
        'To: soporte@example.test',
        'Subject: Consulta',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Consulta de prueba.',
        '',
    ]);
    $timestamp = $overrides['timestamp'] ?? (string) time();
    $nonce = $overrides['nonce'] ?? 'nonce-'.bin2hex(random_bytes(8));

    $headers = array_merge([
        'X-CDH-Timestamp' => $timestamp,
        'X-CDH-Nonce' => $nonce,
    ], $overrides['headers'] ?? []);

    // Signing the body actually sent, so a test only diverges where it means to.
    if (! array_key_exists('signature', $overrides)) {
        $headers['X-CDH-Signature'] = inboundSignature(
            $timestamp,
            $nonce,
            $body,
            $overrides['secret'] ?? null,
        );
    } elseif ($overrides['signature'] !== null) {
        $headers['X-CDH-Signature'] = $overrides['signature'];
    }

    return ['body' => $body, 'headers' => $headers, 'nonce' => $nonce];
}

beforeEach(function (): void {
    Config::set('support.inbound_secret', INBOUND_SECRET);
    Config::set('support.inbound_domain', 'soporte.example.test');
    Config::set('support.inbound_max_bytes', 10_485_760);
    Config::set('support.inbound_timestamp_skew_seconds', 300);
    Config::set('support.inbound_nonce_ttl_seconds', 300);

    // The limiter shares its store with the nonce cache; clearing it stops one
    // test's requests from consuming another's budget.
    Cache::clear();
});

/**
 * H1 — a correctly signed request from an unknown sender is accepted without a
 * login.
 *
 * Reaching the controller at all proves the endpoint is not behind the session
 * middleware: with `auth` in front it would be a redirect or a 401. The
 * assertion is on the concrete outcome the controller produces, not merely on
 * the absence of an auth status — otherwise a 500 from a broken middleware
 * would satisfy it and the test would prove nothing.
 */
test('H1 accepts a correctly signed request with no session', function (): void {
    $request = inboundRequest();

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars($request['headers']),
        $request['body'],
    );

    expect($response->status())->toBe(200, 'a signed request reaches the controller and is ingested')
        ->and($response->json())->toHaveKey('status');
});

/** H2 — a signature that was never computed by the holder of the secret. */
test('H2 rejects an invalid signature', function (): void {
    $request = inboundRequest(['signature' => str_repeat('0', 64)]);

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars($request['headers']),
        $request['body'],
    );

    expect($response->status())->toBe(401);
});

/**
 * H3 — the body is altered after signing.
 *
 * This is the property that separates a real signature from a decoration: the
 * digest of the body is inside the signed string, so any change to the payload
 * invalidates it even though the headers are untouched.
 */
test('H3 rejects a body modified after signing', function (): void {
    $original = json_encode(['email' => 'ana@example.test', 'subject' => 'Consulta']);
    $tampered = json_encode(['email' => 'attacker@example.test', 'subject' => 'Consulta']);

    $timestamp = (string) time();
    $nonce = 'nonce-tampered';

    // Signed over the original body...
    $signature = inboundSignature($timestamp, $nonce, $original);

    // ...but the original body is what actually travels.
    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => $signature,
        ]),
        $tampered,
    );

    expect($response->status())->toBe(401);

    // And the assertion is only meaningful if the two bodies really differ.
    expect($tampered)->not->toBe($original);
});

/** H4 — no timestamp: nothing to bound the replay window against. */
test('H4 rejects a request with no timestamp', function (): void {
    $response = $this->postJson(INBOUND_URI, ['anything' => 'at all']);

    expect($response->status())->toBe(401);
});

/** H5 — a stale timestamp, far outside the accepted window. */
test('H5 rejects a stale timestamp', function (): void {
    $timestamp = (string) (time() - 3600);
    $nonce = 'nonce-stale';
    $body = json_encode(['email' => 'ana@example.test']);

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            // Correctly signed, so only the window can refuse it.
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($response->status())->toBe(401);
});

/**
 * H6 — a timestamp from the future.
 *
 * The window is measured as an absolute drift, not `now - timestamp > skew`.
 * The asymmetric form would accept a stamp arbitrarily far in the future and
 * therefore a signature that stays valid for as long as the sender cares to
 * claim; a provider's clock is a minute out, not an hour.
 */
test('H6 rejects a timestamp beyond the future end of the skew window', function (): void {
    $timestamp = (string) (time() + 3600);
    $nonce = 'nonce-future';
    $body = json_encode(['email' => 'ana@example.test']);

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($response->status())->toBe(401);
});

/** H7 — no nonce: nothing to detect a replay with. */
test('H7 rejects a request with no nonce', function (): void {
    $timestamp = (string) time();
    $nonce = 'nonce-unused';
    $body = json_encode(['email' => 'ana@example.test']);

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($response->status())->toBe(401);
});

/**
 * H8 — a genuine replay.
 *
 * The first request is valid and spends its nonce; the second carries the same
 * valid signature and must be refused. Without the nonce this pair is a
 * perfectly good signature delivered twice.
 */
test('H8 refuses a replayed nonce carrying an otherwise valid signature', function (): void {
    $timestamp = (string) time();
    $nonce = 'nonce-replayed';
    $body = json_encode(['email' => 'ana@example.test', 'subject' => 'Consulta']);
    $signature = inboundSignature($timestamp, $nonce, $body);

    $headers = $this->transformHeadersToServerVars([
        'X-CDH-Timestamp' => $timestamp,
        'X-CDH-Nonce' => $nonce,
        'X-CDH-Signature' => $signature,
    ]);

    $first = $this->call('POST', INBOUND_URI, [], [], [], $headers, $body);
    expect($first->status())->not->toBe(401);

    $second = $this->call('POST', INBOUND_URI, [], [], [], $headers, $body);
    expect($second->status())->toBe(401);
});

/**
 * H9 — an invalid signature must NOT burn the nonce.
 *
 * This is the ordering defect. A caller that knows nothing of the secret can
 * pick any nonce, spend it with a deliberately bad signature, and make the
 * legitimate delivery that follows with that same nonce fail as a replay. So
 * the nonce may only be claimed once the signature has been verified, and this
 * test states that as the contract: request A fails on its signature, request B
 * carries the *correct* signature for the same nonce and is accepted.
 */
test('H9 an invalid signature does not consume the nonce', function (): void {
    $timestamp = (string) time();
    $nonce = 'nonce-not-burned';
    $body = json_encode(['email' => 'ana@example.test', 'subject' => 'Consulta']);

    // A — bad signature, chosen nonce.
    $attack = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => str_repeat('a', 64),
        ]),
        $body,
    );

    expect($attack->status())->toBe(401);

    // B — the real sender, same nonce, correct signature. Refusing this would
    // mean the failed attempt above had spent the nonce.
    $legitimate = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($legitimate->status())->not->toBe(401, 'a failed signature must not spend the nonce');
});

/**
 * H10 — an oversized body is refused on size, before it is decoded.
 *
 * The size check is first so an unauthenticated caller cannot make us parse an
 * arbitrary amount of MIME. The assertion is on the status, which is what the
 * caller sees; the ordering is additionally guaranteed by the middleware's own
 * structure, where the limit is the first statement that touches the request.
 */
test('H10 rejects an oversized body on size before any parsing', function (): void {
    Config::set('support.inbound_max_bytes', 256);

    $body = json_encode(['padding' => str_repeat('x', 2048)]);
    $timestamp = (string) time();
    $nonce = 'nonce-oversized';

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($response->status())->toBe(413);
});

/**
 * The exemption is one named path, and nothing broader.
 *
 * Asserted against the middleware's own exclusion list rather than through an
 * HTTP status, for a reason worth stating: the framework short-circuits CSRF
 * verification whenever `runningUnitTests()` is true, so a request sent from a
 * test can never demonstrate enforcement — it would pass with or without the
 * exemption. What is genuinely assertable, and what actually matters, is the
 * shape of the exemption list.
 *
 * The failure this guards against is a pattern such as `api/*`, which would
 * silently strip CSRF protection from every endpoint in the API while each
 * individual route still looked correct. So the list must name the ingress path
 * and contain nothing else.
 */
test('the CSRF exemption is exactly the ingress path and nothing broader', function (): void {
    $paths = app(PreventRequestForgery::class)->getExcludedPaths();

    expect($paths)->toContain('api/internal/support-email/inbound');

    foreach (['api', 'api/*', 'internal/*', '*', 'support-email/*', 'api/internal/*', 'api/internal/support-email/*'] as $tooBroad) {
        expect($paths)->not->toContain($tooBroad);
    }

    expect($paths)->toHaveCount(1);
});

/**
 * Every other API endpoint keeps CSRF protection.
 *
 * Verified by construction rather than by request: the exemption list above has
 * exactly one entry, and that entry is the webhook, so no endpoint that the
 * browser session reaches is excluded. Stated as its own test so the claim is
 * not only implied by the previous one.
 */
test('the exempted webhook is the only API path without CSRF protection', function (): void {
    $paths = app(PreventRequestForgery::class)->getExcludedPaths();

    $apiExemptions = array_values(array_filter(
        $paths,
        static fn (string $path): bool => str_starts_with($path, 'api/'),
    ));

    expect($apiExemptions)->toBe(['api/internal/support-email/inbound']);
});

/** An unconfigured secret is a 503, never a permissive pass-through. */
test('refuses every request when the shared secret is not configured', function (): void {
    Config::set('support.inbound_secret', null);

    $request = inboundRequest();

    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars($request['headers']),
        $request['body'],
    );

    expect($response->status())->toBe(503);
});

/** A secret is read from configuration, not from the environment at the call site. */
test('reads the shared secret from configuration', function (): void {
    Config::set('support.inbound_secret', 'a-different-secret');

    $timestamp = (string) time();
    $nonce = 'nonce-other-secret';
    $body = json_encode(['email' => 'ana@example.test']);

    // Signed with the configured value, so a middleware reading config('support.*')
    // accepts it. A middleware reading env() directly would not, which is the
    // difference this test exists to catch.
    $response = $this->call(
        'POST',
        INBOUND_URI,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'X-CDH-Timestamp' => $timestamp,
            'X-CDH-Nonce' => $nonce,
            'X-CDH-Signature' => inboundSignature($timestamp, $nonce, $body),
        ]),
        $body,
    );

    expect($response->status())->not->toBe(401);
});

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an inbound support email by its HMAC signature.
 *
 * ## The order of the checks is the security property
 *
 * The nonce is the last gate, not the first. An unauthenticated caller who
 * knows nothing of the secret can choose any nonce it likes; claiming nonces
 * before the signature has been verified therefore lets anyone burn a nonce
 * for its whole TTL and lock a legitimate sender out of the window. So the
 * order is:
 *
 *   1. body size          — refuse oversized bodies before decoding anything
 *   2. required headers   — fail cheaply and without touching shared state
 *   3. timestamp window   — both an old and a future stamp are refused
 *   4. read the raw body
 *   5. recompute the expected signature
 *   6. compare in constant time
 *   7. claim the nonce    — only now, atomically, once the caller is known
 *   8. handle the request
 *
 * The body size check precedes the signature check because it bounds the work
 * an unauthenticated caller can make us do; there is no point verifying a
 * signature over a 200 MB payload.
 */
class VerifySupportEmailHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Body size, before any decoding.
        //
        // Read from the server bag rather than a header helper: `Content-Length`
        // is not a header this middleware may trust, and a caller can simply omit
        // it. So the declared length is only ever used to refuse early, and the
        // actual body is measured below as well — a caller who understates the
        // length gets refused by the measured value instead.
        $maxBytes = (int) config('support.inbound_max_bytes', 10_485_760);
        $declaredLength = (int) $request->server->get('CONTENT_LENGTH', 0);

        if ($declaredLength > $maxBytes) {
            Log::warning('Support inbound email: request too large', [
                'declared_length' => $declaredLength,
                'max_bytes' => $maxBytes,
            ]);

            return $this->reject('Cuerpo de la solicitud demasiado grande', 413);
        }

        // 2. Required headers.
        $timestamp = $request->header('X-CDH-Timestamp');
        $signature = $request->header('X-CDH-Signature');
        $nonce = $request->header('X-CDH-Nonce');

        if (! $timestamp || ! $signature) {
            Log::warning('Support inbound email: missing signature headers', [
                'has_timestamp' => (bool) $timestamp,
                'has_signature' => (bool) $signature,
            ]);

            return $this->reject('Firmar la solicitud es obligatoria', 401);
        }

        if (! $nonce) {
            Log::warning('Support inbound email: missing nonce');

            return $this->reject('Nonce requerido', 401);
        }

        $secret = config('support.inbound_secret');
        if (! is_string($secret) || $secret === '') {
            // An endpoint that cannot verify a signature must not accept the
            // request unverified.
            Log::error('Support inbound email: support.inbound_secret is not configured');

            return $this->reject('Servicio no configurado', 503);
        }

        // 3. Timestamp window, refusing a stamp from the future as well as a
        //    stale one.
        $skew = (int) config('support.inbound_timestamp_skew_seconds', 300);
        $timestampInt = (int) $timestamp;
        $drift = abs(time() - $timestampInt);

        if ($drift > $skew) {
            Log::warning('Support inbound email: timestamp outside the accepted window', [
                'drift_seconds' => $drift,
                'skew_seconds' => $skew,
            ]);

            return $this->reject('Marca de tiempo fuera de ventana permitida', 401);
        }

        // 4. The exact bytes that were signed.
        $rawBody = $request->getContent();

        // The declared length can be omitted or understated, so the body itself
        // is measured before it is hashed or decoded. This is the check that
        // actually bounds the work; the one above only lets an honest oversized
        // request be refused without its body being read.
        $actualLength = strlen($rawBody);
        if ($actualLength > $maxBytes) {
            Log::warning('Support inbound email: body too large', [
                'actual_length' => $actualLength,
                'max_bytes' => $maxBytes,
            ]);

            return $this->reject('Cuerpo de la solicitud demasiado grande', 413);
        }

        // 5. Recompute. The body is folded to its own digest so the signed
        //    string stays fixed-length regardless of payload size.
        $signedPayload = $timestamp."\n".$nonce."\n".hash('sha256', $rawBody);
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        // 6. Constant-time comparison.
        if (! hash_equals($expected, (string) $signature)) {
            Log::warning('Support inbound email: invalid signature');

            // The nonce is deliberately left untouched: consuming it here would
            // let an unauthenticated caller deny a future legitimate delivery.
            return $this->reject('Firma inválida', 401);
        }

        // 7. The signature is valid, so the caller is who it claims to be and
        //    the nonce may now be spent. `add()` is the atomic test-and-set:
        //    it returns false when the key already exists.
        $nonceTtl = (int) config('support.inbound_nonce_ttl_seconds', 300);
        if (! Cache::add('support:inbound:nonce:'.$nonce, 1, $nonceTtl)) {
            Log::warning('Support inbound email: nonce replay', ['nonce' => $nonce]);

            return $this->reject('Nonce ya utilizado', 401);
        }

        // 8. Authenticated.
        return $next($request);
    }

    private function reject(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }
}

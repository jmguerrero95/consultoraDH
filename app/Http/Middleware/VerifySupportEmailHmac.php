<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifySupportEmailHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip HMAC check in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        $secret = config('support.inbound_secret');

        if (! $secret) {
            Log::error('Support inbound email: SUPPORT_INBOUND_SECRET not configured');
            return response()->json([
                'message' => 'Servicio no configurado',
            ], 503);
        }

        // Check content length
        $maxBytes = config('support.inbound_max_bytes', 10_485_760); // 10MB default
        if ($request->getContentLength() > $maxBytes) {
            Log::warning('Support inbound email: request too large', [
                'content_length' => $request->getContentLength(),
                'max_bytes' => $maxBytes,
            ]);
            return response()->json([
                'message' => 'Cuerpo de la solicitud demasiado grande',
            ], 413);
        }

        $timestamp = $request->header('X-CDH-Timestamp');
        $signature = $request->header('X-CDH-Signature');

        if (! $timestamp || ! $signature) {
            Log::warning('Support inbound email: missing HMAC headers', [
                'has_timestamp' => (bool) $timestamp,
                'has_signature' => (bool) $signature,
            ]);
            return response()->json([
                'message' => 'Firmar la solicitud es obligatorio',
            ], 401);
        }

        // Verify timestamp is within acceptable window (5 minutes)
        $now = time();
        $timestampInt = (int) $timestamp;
        if (abs($now - $timestampInt) > 300) { // 5 minutes
            Log::warning('Support inbound email: timestamp out of window', [
                'timestamp' => $timestamp,
                'now' => $now,
                'diff' => abs($now - $timestampInt),
            ]);
            return response()->json([
                'message' => 'Marca de tiempo fuera de ventana permitida',
            ], 401);
        }

        // Check nonce replay protection
        $nonce = $request->header('X-CDH-Nonce');
        if (! $nonce) {
            Log::warning('Support inbound email: missing nonce');
            return response()->json([
                'message' => 'Nonce requerido',
            ], 401);
        }

        $nonceKey = "support:inbound:nonce:{$nonce}";
        if (! \Illuminate\Support\Facades\Cache::add($nonceKey, '1', 300)) { // 5 minutes TTL
            Log::warning('Support inbound email: nonce replay detected', [
                'nonce' => $nonce,
            ]);
            return response()->json([
                'message' => 'Nonce ya utilizado',
            ], 401);
        }

        // Get raw body
        $rawBody = $request->getContent();

        // Compute expected signature
        $payload = "{$timestamp}\n{$nonce}\n" . hash('sha256', $rawBody);
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Constant-time comparison
        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning('Support inbound email: invalid HMAC signature');
            return response()->json([
                'message' => 'Firma inválida',
            ], 401);
        }

        // All checks passed
        return $next($request);
    }
}
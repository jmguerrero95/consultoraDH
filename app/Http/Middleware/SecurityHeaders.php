<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers.
 *
 * nginx sets the same headers for static responses; this middleware covers the
 * dynamic HTML and JSON responses so that a request served without the proxy
 * is still protected.
 *
 * HSTS and the Content Security Policy are intentionally *not* set here:
 *  - HSTS must only be enabled once the deployment is served over HTTPS.
 *  - A CSP has to allow the Vite development server during development, which
 *    is the opposite of a useful production policy.
 * Both are handled in config/security.php and documented in docs/SECURITY.md.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // `microphone=(self)` rather than `microphone=()`: the support conversation screen
            // records voice notes with `MediaRecorder`, and a blanket refusal made Edge refuse
            // at `getUserMedia` with "Permissions policy violation: microphone is not allowed in
            // this document" — before any prompt, so the operator could not grant it either.
            //
            // `(self)` allows this origin only. Every other feature stays denied exactly as
            // before, including `camera=()`: nothing here records video, and granting it would
            // be a wider capability than the one feature that is broken.
            'Permissions-Policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), '
                .'encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), '
                .'microphone=(self), payment=(), picture-in-picture=(), publickey-credentials-get=(), '
                .'screen-wake-lock=(), usb=(), xr-spatial-tracking=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (config('security.content_security_policy.enabled') && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                (string) config('security.content_security_policy.value'),
            );
        }

        return $response;
    }
}

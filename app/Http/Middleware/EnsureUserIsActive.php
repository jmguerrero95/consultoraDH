<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Events\SessionRejectedInactive;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that is authenticated but no longer active.
 *
 * Rejecting an inactive account at sign in is not enough on its own. A session
 * opened while the account was active stays valid afterwards: suspending an
 * administrator has no effect until the cookie expires, which is precisely the
 * window an operator suspending someone expects to be immediate.
 *
 * So the status is checked on every authenticated request, on the server. The
 * value the interface holds in `user.status` is presentation only and is never
 * consulted for a decision.
 *
 * On rejection the session is logged out, invalidated and given a fresh CSRF
 * token, and the response is the ordinary unauthenticated one: the caller is
 * told it has to sign in again and nothing about why.
 *
 * Only one audit row is written per transition. Invalidating the session is what
 * bounds it, because the next request has no user to reject, so there is no user
 * to record.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Nothing to check. Whether a session is required at all is the
        // `auth` middleware's decision, which runs before this one.
        if ($user === null) {
            return $next($request);
        }

        if ($user->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        event(new SessionRejectedInactive($user));

        return response()->json([
            'message' => 'Debe iniciar sesión para continuar.',
            'code' => 'unauthenticated',
        ], 401);
    }
}

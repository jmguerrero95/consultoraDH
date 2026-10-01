<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Events\LogoutPerformed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the current session.
 *
 * The session is invalidated and then regenerated rather than merely flushed,
 * so the identifier that travelled with the request is destroyed and the
 * response receives a new, empty session together with a fresh CSRF token.
 */
final class LogoutUser
{
    public function execute(Request $request): void
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        if ($user !== null) {
            event(new LogoutPerformed($user));
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}

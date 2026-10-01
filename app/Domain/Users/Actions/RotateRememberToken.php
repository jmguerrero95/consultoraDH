<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Invalidates the "remember me" cookies of an account.
 *
 * Consultora DH supports persistent sign in, so a `remember_token` exists and a
 * copy of it can still be sitting in a browser after a password change. If the
 * token survives, whoever holds that cookie stays signed in, which is exactly
 * what a password change is meant to stop.
 *
 * Rotating the token to a cryptographically random value makes every previously
 * issued remember cookie useless, without needing to know where those cookies
 * are. This is the mechanism Laravel itself provides, so no custom cookie
 * cryptography is involved.
 *
 * The token is regenerated on a best-effort basis: if the write fails the
 * password change still succeeded, and swallowing the error here would only
 * hide it from the log. It is reported instead.
 */
final class RotateRememberToken
{
    public function execute(User $user): void
    {
        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();
    }
}

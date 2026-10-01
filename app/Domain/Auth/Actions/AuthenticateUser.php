<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Events\AuthenticationFailed;
use App\Domain\Auth\Events\AuthenticationRejectedInactive;
use App\Domain\Auth\Events\AuthenticationSucceeded;
use App\Domain\Users\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Signs a user in with a session cookie.
 *
 * Design notes:
 *  - The stored address is always lower case, so lookups are exact and two
 *    accounts can never differ only by casing.
 *  - A wrong password and an inactive account produce the *same* exception,
 *    the same message and the same key, so the endpoint cannot be used to
 *    discover which addresses are registered or which are active.
 *  - When the address is unknown a decoy hash is verified anyway, so the
 *    response time does not reveal whether the account exists.
 *  - The session identifier is regenerated on success, which defeats session
 *    fixation.
 */
final class AuthenticateUser
{
    /**
     * A valid bcrypt hash (cost 12, matching the default configuration) of a
     * random value that no user can ever present. Verifying against it costs
     * the same as verifying a real password.
     */
    private const TIMING_DECOY_HASH = '$2y$12$3YAMJD3Y3KwXo8plOarzyuMDAvVSGZZCLF/444Qv1CB62s6wa9pTe';

    /**
     * @throws ValidationException on any rejected attempt.
     */
    public function execute(Request $request, string $email, string $password, bool $remember = false): User
    {
        $email = mb_strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->burnTime();

            $this->reject($request, $email, null);
        }

        if (! Hash::check($password, $user->password)) {
            $this->reject($request, $email, $user);
        }

        if ($user->status !== UserStatus::Active) {
            // Audited as a distinct event, reported to the user exactly like a
            // wrong password.
            event(new AuthenticationRejectedInactive($user));

            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        Auth::login($user, $remember);

        // A fresh session identifier after authentication.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        // Consultora DH's own domain event. The framework's
        // `Illuminate\Auth\Events\Login` is dispatched by `Auth::login()` on
        // the `web` guard and must NOT be dispatched again here, or every
        // listener would run twice for a single sign in.
        event(new AuthenticationSucceeded($user));

        return $user;
    }

    /**
     * Records the attempt and throws the shared, non-enumerable error.
     */
    private function reject(Request $request, string $email, ?User $user): never
    {
        event(new AuthenticationFailed($email, $user));

        throw ValidationException::withMessages([
            'email' => [trans('auth.failed')],
        ]);
    }

    private function burnTime(): void
    {
        Hash::check('timing-decoy', self::TIMING_DECOY_HASH);
    }
}

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
 *  - The attempt is described in the audit trail, not in the application log.
 *    A sign in writes one query and one hash comparison, and says nothing else:
 *    the address, the address' owner and the connection's database name are
 *    not written to a log file, and the query log is never switched on, because
 *    a diagnostic switch left enabled for the life of the connection keeps
 *    every later statement — with its bindings — in memory.
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
     * Persistent-session cookie lifetime, in minutes, when the user kept
     * "Mantener sesión iniciada" selected.
     *
     * User-level Remember Me, not `session.lifetime` (120 min, idle timeout
     * for the Redis session). The recaller is a native Laravel
     * `remember_token` cookie (30 days), independent of Redis. When it
     * expires the user signs in again — the Redis session is never extended
     * for every account to years, and no credentials are stored client-side.
     */
    public const REMEMBER_MINUTES = 43200;

    /**
     * @throws ValidationException on any rejected attempt.
     */
    public function execute(Request $request, string $email, string $password, bool $remember = false): User
    {
        $email = mb_strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // The same work is done for an unknown address, so the time the
            // endpoint takes does not disclose whether the account exists.
            $this->burnTime();

            $this->reject($request, $email, null);
        }

        // One comparison per attempt. Verifying the hash twice would double
        // the cost of every rejection for no reason.
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

        // Native Laravel Remember Me. `Auth::login(..., $remember)`:
        //  - sets/rotates `users.remember_token`,
        //  - queues an HttpOnly cookie `remember_web_<hash>` (see §3),
        //  - and when the Redis session dies, `SessionGuard::user()` falls
        //    back to the recaller and re-authenticates, regenerating the
        //    session. Logout, password change and deactivation rotate or
        //    invalidate the token (LogoutUser, RotateRememberToken,
        //    AuthenticateSession "password changed" check), so the recaller
        //    cannot outlive them.
        // No credentials are ever written to localStorage — the cookie is
        // HttpOnly and opaque.
        //
        // The duration MUST be set BEFORE `login()` because `SessionGuard`
        // snapshots `$rememberDuration` at login time. Setting it after
        // would not affect the cookie that Laravel generates.
        if ($remember) {
            $guard = Auth::guard('web');

            if (method_exists($guard, 'setRememberDuration')) {
                $guard->setRememberDuration(self::REMEMBER_MINUTES);
            }
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

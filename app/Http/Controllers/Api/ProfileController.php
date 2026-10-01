<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Profile\Events\EmailAddressChanged;
use App\Domain\Profile\Events\PasswordChanged;
use App\Domain\Profile\Events\ProfileUpdated;
use App\Domain\Users\Actions\RotateRememberToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateEmailRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self service profile management.
 *
 * Every change requires proof of identity and is written to the audit trail.
 */
final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $changed = [];

        if ($user->name !== $request->validated('name')) {
            $user->name = $request->validated('name');
            $changed[] = 'name';
        }

        $user->save();

        event(new ProfileUpdated($user, $changed));

        return response()->json([
            'user' => new UserResource($user->refresh()),
        ]);
    }

    /**
     * Change the sign in address. The current password is mandatory.
     *
     * Moving to a different address clears `email_verified_at`. Verification is
     * not enforced in A01, so this changes nothing today, but leaving the old
     * timestamp in place would mean that the day verification is switched on the
     * account would present as verified at an address nobody ever proved. The
     * column is set here rather than in a model observer so the rule sits with
     * the operation that makes it true.
     */
    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $previous = $user->email;

        // The model attribute lower cases and trims on assignment, so the
        // comparison is made on the normalised value: re-saving the same address
        // with different casing is not a change of address.
        $next = mb_strtolower(trim((string) $request->validated('email')));

        $user->email = $next;

        if ($next !== $previous) {
            $user->email_verified_at = null;
        }

        $user->save();

        event(new EmailAddressChanged($user, $previous, $user->email));

        return response()->json([
            'message' => 'El correo electrónico fue actualizado correctamente.',
            'user' => new UserResource($user->refresh()),
        ]);
    }

    /**
     * Change the password. The current password is mandatory.
     *
     * The current session is regenerated. Other sessions of the same account
     * are terminated by the `auth.session` middleware, which compares the
     * password hash stored in the session against the current one.
     */
    public function __construct(
        private readonly RotateRememberToken $rotateRememberToken,
    ) {}

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
        ])->save();

        // Invalidate any persistent "remember me" cookie issued with the old
        // password, then keep the current browser session alive with a fresh
        // identifier.
        $this->rotateRememberToken->execute($user);

        $request->session()->regenerate();

        event(new PasswordChanged($user));

        return response()->json([
            'message' => 'La contraseña fue actualizada correctamente.',
        ]);
    }
}

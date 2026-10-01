<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Auth\Actions\AuthenticateUser;
use App\Domain\Auth\Actions\LogoutUser;
use App\Domain\Auth\Events\PasswordResetCompleted;
use App\Domain\Auth\Events\PasswordResetRequested;
use App\Domain\Users\Actions\RotateRememberToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Session authentication for the single page application.
 *
 * Every response here is JSON and every message is written for a Spanish
 * speaking administrator. Technical detail belongs in the log, never in the
 * response body.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticateUser $authenticateUser,
        private readonly LogoutUser $logoutUser,
        private readonly RotateRememberToken $rotateRememberToken,
    ) {}

    /**
     * Confirm the current session and return the signed in user.
     *
     * Used by the interface on start up to decide between the public and the
     * authenticated area without a full page reload.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticateUser->execute(
            $request,
            $request->validated('email'),
            $request->validated('password'),
            $request->boolean('remember'),
        );

        // The session was regenerated, so the CSRF token that travelled with
        // the request is stale. Hand back the fresh one.
        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->logoutUser->execute($request);

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Start the password recovery flow.
     *
     * The response is identical whether or not the address is registered, so
     * the endpoint cannot be used to enumerate accounts.
     */
    public function sendPasswordResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $user = User::query()->where('email', $email)->first();

        $status = Password::sendResetLink(['email' => $email]);

        event(new PasswordResetRequested($email, $user));

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => ['Ya se solicitó un restablecimiento recientemente. Inténtelo de nuevo en unos minutos.'],
            ])->status(429);
        }

        return response()->json([
            'message' => 'Si el correo electrónico está registrado, le enviaremos un enlace para restablecer su contraseña.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                ])->save();

                // A reset exists precisely because the old credentials are not
                // trustworthy any more, so any persistent "remember me" cookie
                // must stop working too.
                $this->rotateRememberToken->execute($user);

                // Audited by the domain listener.
                event(new PasswordResetCompleted($user));

                // Framework hook for anything that listens for a completed
                // reset. The broker does not dispatch it itself.
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Never reveal whether the token simply expired or was wrong.
            throw ValidationException::withMessages([
                'email' => ['El enlace de restablecimiento no es válido o ha expirado. Solicite uno nuevo.'],
            ]);
        }

        return response()->json([
            'message' => 'Su contraseña fue restablecida. Ya puede iniciar sesión.',
        ]);
    }
}

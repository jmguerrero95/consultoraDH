<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Support\Security\TrustedHostsNotConfigured;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',

        // The JSON API is registered with the `web` middleware group on
        // purpose. The interface is a same-origin single page application that
        // authenticates with a session cookie, so it needs the session and CSRF
        // protection that the stateless `api` group does not provide.
        then: function (): void {
            Route::middleware('web')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },

        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Baseline security headers on every dynamic response.
        $middleware->append(SecurityHeaders::class);

        // Terminates sessions whose stored password hash no longer matches,
        // which is what invalidates the other sessions of an account after a
        // password change.
        $middleware->alias([
            'auth.session' => AuthenticateSession::class,

            // Ends the session of an account that is authenticated but no longer
            // active. It runs after `auth`, because it has a user to inspect.
            'user.active' => EnsureUserIsActive::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Trusted hosts
        |----------------------------------------------------------------------
        |
        | The password recovery flow builds absolute URLs from the request. A
        | forged `Host` header would therefore make those links point at a host
        | the attacker controls, which is how a reset link gets stolen.
        |
        | Outside `local` and `testing`, `TRUSTED_HOSTS` (comma separated
        | literal host names) is the whole list. With nothing configured the
        | middleware raises TrustedHostsNotConfigured and the application serves
        | nothing: it never falls back to the development hosts.
        |
        | See config/security.php and app/Support/Security.
        |
        */

        // Only this one, which reads the accepted patterns from
        // config('security.trusted_hosts') itself, so the framework's
        // `trustHosts()` helper is deliberately not used and the chain does not
        // end up with two TrustHosts middleware.
        $middleware->prepend(TrustHosts::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Anything under /api is a JSON client, whatever it asks for.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        |----------------------------------------------------------------------
        | Consistent, Spanish language API errors
        |----------------------------------------------------------------------
        |
        | The interface renders these messages directly, so they must always be
        | presentable. Technical detail belongs in the log, which the framework
        | already writes for unhandled exceptions.
        |
        */

        $exceptions->render(function (TrustedHostsNotConfigured $e, Request $request) {
            // Reported by the handler as an unhandled exception, so the reason
            // reaches the log; the client only learns that nothing is configured.
            return response()->json([
                'message' => 'La aplicación no puede atender solicitudes en este momento.',
                'code' => 'trusted_hosts_not_configured',
            ], 503);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => 'Debe iniciar sesión para continuar.',
                'code' => 'unauthenticated',
            ], 401);
        });

        // The framework converts authorisation, not found, CSRF and rate limit
        // failures into HTTP exceptions *before* render callbacks run, so they
        // are handled here in one place, keyed by status code. Per exception
        // class would silently miss them.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            [$message, $code] = match ($status) {
                401 => ['Debe iniciar sesión para continuar.', 'unauthenticated'],
                403 => ['No tiene permisos para acceder a esta sección.', 'forbidden'],
                404 => ['El recurso solicitado no existe.', 'not_found'],
                419 => [
                    'La sesión expiró o el formulario no es válido. Recargue la página e inténtelo de nuevo.',
                    'session_expired',
                ],
                429 => [
                    $retryAfter !== null
                        ? "Demasiados intentos. Por favor espere {$retryAfter} segundos antes de volver a intentarlo."
                        : 'Demasiados intentos. Por favor espere unos minutos antes de volver a intentarlo.',
                    'too_many_requests',
                ],
                default => [null, null],
            };

            if ($message === null) {
                return null;
            }

            return response()->json(
                array_filter([
                    'message' => $message,
                    'code' => $code,
                    'retry_after' => $retryAfter !== null ? (int) $retryAfter : null,
                ], static fn ($value): bool => $value !== null),
                $status,
                $e->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // A validation failure is not a server fault, and the framework already
            // renders it as 422 with the failed rules. Returning null hands it back.
            //
            // This matters because of what follows: a ValidationException is not an
            // HttpExceptionInterface, so it would be classified as a 500 and, with
            // APP_DEBUG off, replaced by the generic error body. With APP_DEBUG on the
            // `config('app.debug')` branch below returns null anyway, which is why the
            // difference was invisible until the end to end stack, which runs with
            // debug off, met it. A domain action that refuses bad input with a
            // ValidationException answered 422 in development and 500 there.
            if ($e instanceof ValidationException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status < 500) {
                return null;
            }

            // In local development the framework's detailed response is more
            // useful than a generic message. In production this branch is the
            // only one that can run, and it never contains a stack trace.
            if (config('app.debug')) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'Se ha producido un error inesperado. Inténtelo de nuevo más tarde.',
                'code' => 'server_error',
            ], 500);
        });
    })->create();

<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\DocumentNotApplicable;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\Exceptions\ImportApplyFailed;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;
use App\Domain\Imports\Exceptions\UnusableImportAction;
use App\Domain\Imports\WorkbookRejected;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Planillas\SheetNotApplicable;
use App\Domain\Reports\Exceptions\UnknownReportFormat;
use App\Domain\Reports\Exceptions\UnknownReportType;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireAnyAbility;
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
    ->withSchedule(function (Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('operations:dispatch-reminders')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('reports:run-schedules')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    })
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

            // `can:` means every ability listed. `anyAbility:` means at least one, for
            // the routes that guard two independently permitted domains, such as
            // /settings/billing and the generation preview.
            'anyAbility' => RequireAnyAbility::class,
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

        /*
        |----------------------------------------------------------------------
        | A04's domain refusals
        |----------------------------------------------------------------------
        |
        | §15 asks for "422 estándar" on an invalid request and "409 con código de dominio" on
        | an invalid state. Both now come from typed exceptions rather than from each controller
        | remembering to build a response, so they are rendered in one place here — and a refusal
        | raised from a job, a service or the container cannot escape as a 500.
        |
        | The messages are written for a person and carry no payload content: an invalid
        | resolution names the field and the shape it expected, never the value somebody tried
        | to submit, because the value may be a document number.
        |
        */

        $exceptions->render(function (ImportNotApplicable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->userMessage(),
                'code' => $e->reason,
                'previous_import_id' => $e->previousImportId,
            ], 409);
        });

        $exceptions->render(function (InvalidIssueResolution $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
        });

        $exceptions->render(function (UnusableImportAction $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // §12.3: nothing was written. 409 rather than 500 because this is a decision about
            // the plan, not a fault in the server — and because the batch is intact and
            // recoverable, which is the sentence the operator needs.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
                'action' => $e->actionDescription,
            ], 409);
        });

        $exceptions->render(function (ImportApplyFailed $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'apply_failed',
                'stranded_actions' => $e->stranded,
            ], 409);
        });

        // A workbook the guard or the parser refused. §4.1 and §4.2 make it a 422: the
        // operator's file is the thing that is wrong, and the sentence is already written for
        // them. `WorkbookRejected` never carries the server path.
        $exceptions->render(function (WorkbookRejected $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->userMessage(),
                'code' => $e->reason,
            ], 422);
        });

        $exceptions->render(function (SheetNotApplicable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 409);
        });

        $exceptions->render(function (OperationNotApplicable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 409);
        });

        // §51: the report catalogue is closed, so an unknown type is a 422 naming the
        // field rather than a query against whatever table the value happened to match.
        $exceptions->render(function (UnknownReportType|UnknownReportFormat $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
        });

        $exceptions->render(function (DocumentNotApplicable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
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

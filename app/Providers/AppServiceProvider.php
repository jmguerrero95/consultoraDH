<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\Listeners\RecordAuditEvent;
use App\Domain\Support\Events\SupportSlaEventEmitted;
use App\Domain\Support\Listeners\EscalateSupportSla;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Sign-in budgets, read from configuration in `boot()`.
     *
     * Declared with the production figures so the class is correct even if `boot()` has not run,
     * and so a test that resolves the limiter without booting sees the real limit rather than
     * `0` — which `Limit` would read as "no requests ever allowed".
     *
     * @see self::boot() for why the end to end suite raises them
     */
    private int $loginAttemptLimit = 20;

    private int $loginAccountLimit = 30;

    private function loginAttemptLimit(): int
    {
        return $this->loginAttemptLimit;
    }

    private function loginAccountLimit(): int
    {
        return $this->loginAccountLimit;
    }

    public function boot(): void
    {
        // Resources are always returned under an explicit key (`user`), so the
        // automatic `data` envelope is disabled: the API contract then has one
        // shape instead of two.
        JsonResource::withoutWrapping();

        $this->registerRateLimiters();
        $this->registerAuditListener();
    }

    /**
     * Rate limits are part of the security baseline, not an afterthought.
     */
    private function registerRateLimiters(): void
    {
        /*
        |----------------------------------------------------------------------
        | Sign in - two independent limits
        |----------------------------------------------------------------------
        |
        | A single key is not enough in either direction:
        |
        |   * Keying only on the address lets an attacker spray one account from
        |     many machines, and lets a single machine lock a colleague out.
        |
        |   * Keying only on the account lets a botnet walk through many accounts
        |     from a shared egress address.
        |
        | So both are counted separately, and a request has to stay under both.
        |
        | The per account figure is 30 in 15 minutes, and it is deliberately three
        | times the old 10. At 10, three password retries and a colleague sharing the
        | office address could exhaust an administrator's budget for a quarter of an
        | hour: the limit became a weapon against the legitimate user, for no gain,
        | because an attacker who wants to lock a known address out has to beat the
        | per address limit too, which needs several machines. At 30 an online
        | guessing attack still gets 30 passwords per quarter hour, which is not a
        | viable way through a policy of 12 mixed case characters with numbers, and
        | ordinary human error no longer spends the budget.
        |
        | The per address figure stays at 20 in 5 minutes: that is the one an
        | attacker cannot raise without moving machines, so it is the one that does
        | the real work against online guessing.
        */
        RateLimiter::for('login-attempt', function (Request $request): Limit {
            return Limit::perMinutes(5, $this->loginAttemptLimit())      // per client address
                ->by('login-ip|'.$request->ip())
                ->response(fn () => $this->tooManyAttempts());
        });

        RateLimiter::for('login-account', function (Request $request): Limit {
            return Limit::perMinutes(15, $this->loginAccountLimit())     // per account, across machines
                ->by('login-account|'.$this->normalisedEmail($request))
                ->response(fn () => $this->tooManyAttempts());
        });

        /*
        |----------------------------------------------------------------------
        | Password recovery - separate from sign in
        |----------------------------------------------------------------------
        |
        | These limits are the point of this group: a recovery request is not a
        | credential guess, and must never be able to consume the sign in budget.
        | Sharing one bucket meant that asking for reset mails in a loop could stop
        | an administrator signing in at all, without a single wrong password.
        |
        | Recovery is also allowed to be stricter than sign in, because every accepted
        | request costs an outbound mail and, in the reset case, a token. Ten per
        | address per quarter hour is far more than a person needs to recover an
        | account and far less than a mail amplifier would need.
        |
        | These sit on top of the broker's own throttle, which allows one reset link
        | per account per minute, so the tightest limit on repeated mails to one
        | address is still the broker's.
        */
        RateLimiter::for('recovery-attempt', function (Request $request): Limit {
            return Limit::perMinutes(15, 10)     // per client address
                ->by('recovery-ip|'.$request->ip())
                ->response(fn () => $this->tooManyAttempts());
        });

        RateLimiter::for('recovery-account', function (Request $request): Limit {
            return Limit::perMinutes(15, 5)      // per account
                ->by('recovery-account|'.$this->normalisedEmail($request))
                ->response(fn () => $this->tooManyAttempts());
        });

        /*
        |----------------------------------------------------------------------
        | Password reset - separate again
        |----------------------------------------------------------------------
        |
        | Consuming a token is a guess against a secret, so it gets its own budget
        | rather than sharing either of the two above. Without it, an attacker who
        | exhausted the recovery limit would simply move on to the reset endpoint, and
        | two adjacent endpoints sharing one bucket is the same weakness as one
        | endpoint sharing two.
        */
        RateLimiter::for('reset-attempt', function (Request $request): Limit {
            return Limit::perMinutes(15, 10)     // per client address
                ->by('reset-ip|'.$request->ip())
                ->response(fn () => $this->tooManyAttempts());
        });

        RateLimiter::for('reset-account', function (Request $request): Limit {
            return Limit::perMinutes(15, 5)      // per account
                ->by('reset-account|'.$this->normalisedEmail($request))
                ->response(fn () => $this->tooManyAttempts());
        });

        /*
        |----------------------------------------------------------------------
        | Sign in - the end to end suite raises these for itself
        |----------------------------------------------------------------------
        |
        | Configurable for the same reason `api` is, and for the same reason the
        | production figures above are the defaults: the suite is not a client, it
        | is forty-four tests that each open their own session.
        |
        | Every one of them arrives from a single address — the `nginx-e2e`
        | service — so the per-address limit is a budget for the whole run rather
        | than for one user. At twenty attempts per five minutes the suite spent
        | more of its time on the throttle than on the journeys: the login page
        | answered 429, the interface showed "Se ha producido un error inesperado",
        | and six A04 tests plus one A03 test failed on their `beforeEach` sign in
        | while every assertion they made after it was fine.
        |
        | What was being measured was therefore not the module. Raising the budget
        | for the suite measures the module, and the production numbers stay in
        | force everywhere else — the guard itself is covered by its own tests.
        */
        $this->loginAttemptLimit = (int) env('LOGIN_RATE_LIMIT_PER_ATTEMPT', 20);
        $this->loginAccountLimit = (int) env('LOGIN_RATE_LIMIT_PER_ACCOUNT', 30);

        // General API budget, applied to every authenticated endpoint, so a
        // script cannot hammer the application with a valid session.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            // Configurable because the end to end suite drives a few hundred requests
            // in a couple of minutes, which the production budget correctly refuses.
            // The suite raises it for itself; nothing else does, and the default is
            // the production figure.
            (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
        )->by('api|'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        /*
         * A budget of its own for the inbound mail webhook.
         *
         * It cannot join the `api` limiter: that one is keyed on the
         * authenticated account, and this endpoint deliberately has none, so
         * every provider would share the single key of the anonymous address
         * and a busy mailbox would exhaust the same pool the browser session
         * draws from. Keyed on the provider's own address instead, so one
         * sender cannot refuse delivery to another.
         *
         * Generous, because a burst of genuine replies is normal after an
         * outage, while a flood with bad signatures is refused here before the
         * HMAC comparison runs for each one.
         */
        RateLimiter::for('support-inbound', fn (Request $request): Limit => Limit::perMinute(
            (int) env('SUPPORT_INBOUND_RATE_LIMIT_PER_MINUTE', 120),
        )->by('support-inbound|'.$request->ip()));
    }

    /**
     * Single listener for every audited domain event, so a new audited action
     * only requires a new event class and nothing else.
     */
    private function registerAuditListener(): void
    {
        Event::listen(AuditableEvent::class, RecordAuditEvent::class);
        Event::listen(\App\Domain\Support\Events\SupportSlaEventEmitted::class, \App\Domain\Support\Listeners\EscalateSupportSla::class);
    }

    /**
     * The address as it is looked up, so the limit is keyed on the account and
     * not on how the caller happened to type it.
     */
    private function normalisedEmail(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? mb_substr(mb_strtolower(trim($email)), 0, 255) : '';
    }

    /**
     * One consistent answer for every throttled authentication endpoint, in the
     * shape the interface already knows how to read.
     */
    private function tooManyAttempts(): JsonResponse
    {
        return response()->json([
            'message' => 'Demasiados intentos. Por favor espere unos minutos antes de volver a intentarlo.',
            'code' => 'too_many_requests',
            'retry_after' => 60,
        ], 429);
    }
}

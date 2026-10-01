<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\Listeners\RecordAuditEvent;
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
            return Limit::perMinutes(5, 20)      // per client address
                ->by('login-ip|'.$request->ip())
                ->response(fn () => $this->tooManyAttempts());
        });

        RateLimiter::for('login-account', function (Request $request): Limit {
            return Limit::perMinutes(15, 30)     // per account, across machines
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

        // General API budget, applied to every authenticated endpoint, so a
        // script cannot hammer the application with a valid session.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('api|'.($request->user()?->getAuthIdentifier() ?: $request->ip())));
    }

    /**
     * Single listener for every audited domain event, so a new audited action
     * only requires a new event class and nothing else.
     */
    private function registerAuditListener(): void
    {
        Event::listen(AuditableEvent::class, RecordAuditEvent::class);
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

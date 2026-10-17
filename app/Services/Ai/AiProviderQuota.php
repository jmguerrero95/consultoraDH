<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A local, per-provider request budget.
 *
 * ## Why a local cap at all
 *
 * The provider's own 429 is the authoritative limit and this never replaces it.
 * But on a free tier the difference between "just under the quota" and "over
 * it" is whether the assistant answers at all — and a 429 also cools the
 * endpoint down for a while. Staying under a documented RPM/RPD deliberately is
 * better than discovering the wall through a refusal.
 *
 * ## Off unless asked for
 *
 * Every cap defaults to zero, which means "no local limit". A wrong local cap
 * would refuse a request the provider would have served — worse than the 429 it
 * tries to avoid — so the operator sets these knowingly.
 *
 * ## Per account, not per model
 *
 * The counter is keyed by the endpoint (URL + credential), because the quota
 * belongs to the account: two models behind one key share one budget, which is
 * how the providers themselves count it.
 */
final class AiProviderQuota
{
    private const MINUTE_SECONDS = 60;

    /**
     * Whether another request to this candidate's provider is within budget.
     */
    public function allow(ModelCandidate $candidate): bool
    {
        [$rpm, $rpd, $key] = $this->budget($candidate);

        if ($rpm <= 0 && $rpd <= 0) {
            return true;
        }

        if ($rpm > 0 && RateLimiter::tooManyAttempts($this->minuteKey($key), $rpm)) {
            return false;
        }

        if ($rpd > 0 && (int) Cache::get($this->dayKey($key), 0) >= $rpd) {
            return false;
        }

        return true;
    }

    /**
     * Count one request against the provider's budget.
     */
    public function record(ModelCandidate $candidate): void
    {
        [$rpm, $rpd, $key] = $this->budget($candidate);

        if ($rpm > 0) {
            RateLimiter::hit($this->minuteKey($key), self::MINUTE_SECONDS);
        }

        if ($rpd > 0) {
            $dayKey = $this->dayKey($key);

            if (! Cache::has($dayKey)) {
                // Expires at the end of the day, so the daily count resets itself
                // rather than needing a scheduled job.
                Cache::put($dayKey, 0, now()->endOfDay());
            }

            Cache::increment($dayKey);
        }
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function budget(ModelCandidate $candidate): array
    {
        $limits = (array) config('ai.rate_limits.'.$candidate->providerHost(), []);

        return [
            max(0, (int) ($limits['rpm'] ?? 0)),
            max(0, (int) ($limits['rpd'] ?? 0)),
            $candidate->providerHost().':'.$candidate->endpointHash(),
        ];
    }

    private function minuteKey(string $key): string
    {
        return 'ai:quota:rpm:'.$key;
    }

    private function dayKey(string $key): string
    {
        return 'ai:quota:rpd:'.now()->toDateString().':'.$key;
    }
}

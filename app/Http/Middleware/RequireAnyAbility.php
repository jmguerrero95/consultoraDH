<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the caller to hold **at least one** of the listed abilities.
 *
 * ## Why this exists
 *
 * Laravel's `can:` middleware means *all* of the abilities you list:
 *
 *     ->middleware('can:cutoffs.view,rates.view')   // needs both
 *
 * That is right for a screen that genuinely needs both, and wrong for the cases A03-R1
 * found:
 *
 *   * `/settings/billing` contains two independent domains, cutoffs and rates, and each
 *     is separately permitted. Guarding it with `cutoffs.view` locked a rates-only role
 *     out of a page it is allowed to use.
 *   * the generation preview is the confirmation step for an action that requires
 *     `obligations.generate`, and it also reveals obligation amounts, which is
 *     `obligations.view` information. Guarding it with either alone produced the
 *     nonsensical state where a role could press a button whose confirmation step was
 *     forbidden to it.
 *
 * `can:` stays for the all-of case. This is the any-of case, named for what it does, so
 * the two are not confused when somebody reads the route file.
 *
 * ## Not authorisation
 *
 * This is the same kind of gate `can:` is: it stops somebody being shown a screen they
 * cannot use, and it answers `403` with the standard body. Every controller that answers
 * financial data also checks its own ability, because a middleware alone would be a
 * single point of failure for the whole permission model.
 */
final class RequireAnyAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        foreach ($abilities as $ability) {
            if ($user->can($ability)) {
                return $next($request);
            }
        }

        abort(403);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Bounds an untrusted `per_page` value before it reaches the query.
 *
 * `$request->integer('per_page', 25)` only casts: it does not bound. A
 * request with `per_page=1000000` used to ask Eloquent for a million rows,
 * with every eager-loaded relation, and a negative or zero value asked for a
 * page that cannot exist. The clamp is deliberately silent rather than a 422:
 * a stale bookmark or a UI typo should page normally, and a rejected request
 * would only make the UI show an error for a value the server can interpret.
 */
trait NormalizesPagination
{
    protected function perPage(Request $request, int $default = 25, int $maximum = 100): int
    {
        return max(1, min($maximum, (int) $request->integer('per_page', $default)));
    }
}

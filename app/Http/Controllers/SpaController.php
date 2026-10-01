<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the single page application shell.
 *
 * The document itself contains no user data. Everything the interface needs is
 * fetched from the JSON API with the session cookie, so the HTML can be cached
 * safely and an expired session is handled by the interface instead of by a
 * server side redirect loop.
 */
final class SpaController extends Controller
{
    public function __invoke(): View
    {
        return view('app');
    }
}

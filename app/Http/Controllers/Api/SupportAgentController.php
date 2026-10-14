<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The list of people a conversation can be assigned to.
 *
 * ## Why this exists rather than a general `GET /api/users`
 *
 * The support screens call `api.get('/users', …)` and read `(response.data).data`. There is no
 * such route: the request fell through to the SPA catch-all, which answered **200 with
 * `index.html`**, and the browser failed with `Unexpected token '<' … is not valid JSON`.
 *
 * A catch-all answering 200 is the part worth fixing. The frontend was right about what it
 * wanted and wrong about where to ask for it, so the fix is an endpoint that exists — not a
 * route added to match a guess.
 *
 * ## Why the payload is three fields
 *
 * This list exists to fill an assignment dropdown. It returns only what that needs — `id`,
 * `name`, `email` — and only for **active staff**.
 *
 * A general user directory would be a different and much larger thing: every internal user
 * would be enumerable by anyone holding `support.assign`, which is a privilege about assigning
 * conversations, not about the staff roll. Client accounts are excluded outright, so no portal
 * user is ever exposed through a support screen.
 */
final class SupportAgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agents = User::query()
            // `account_type = staff` is the same filter the two screens asked for, and the one
            // that keeps client portal accounts out of a support dropdown.
            ->where('account_type', 'staff')
            // `status = active`, through the model's own scope: the column is `status`, not
            // `is_active`. Deactivated staff must not appear as assignees — they cannot sign in
            // to take the work.
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values();

        // `{ data: [...] }`, which is the envelope both screens already read.
        return response()->json(['data' => $agents]);
    }
}
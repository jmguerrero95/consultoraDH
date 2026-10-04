<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The query string for one month's obligations.
 *
 * ## Why a FormRequest and not a hand-rolled validator
 *
 * R2 stopped this endpoint silently replacing an unreadable `as_of` with today's date, but
 * it did it with a private helper in the controller: `referenceDate()` called `validator()`
 * directly and then `abort(422, ...)`.
 *
 * That returns the right status code and the right sentence, and it is still not the contract
 * this project uses everywhere else. `abort(422, $message)` produces a bare `"message"` and no
 * field map, so a client cannot tell *which* input was rejected, and the standard `errors`
 * envelope that `ListReceivablesRequest` and `ListClientAccountRequest` both produce is missing
 * entirely.
 *
 * Two endpoints validating the same field in two different shapes is the situation R1 §26
 * warned about for permissions and R2 §7 for reference dates: one of them is right and the
 * other is nearly right, and which one a caller meets depends on which screen they opened.
 * This request removes the difference by behaving like the other two.
 *
 * ## Why it does NOT extend `ListQueryRequest`
 *
 * That base class fixes the page sizes at 25, 50 or 100. This endpoint has always accepted any
 * value from 1 to 100 — `PeriodController::perPage()` bounds it that way — and the browser suite
 * and `QueryCountTest` both ask for `per_page=10`. Extending the base would have adopted a
 * different endpoint's pagination contract as a side effect of a change about a date, and
 * would have answered 422 to requests that work today.
 *
 * So the reference-date rules below are copied from `ListClientAccountRequest` deliberately,
 * with a comment pointing at it, rather than inherited. Two copies of four rules is cheaper
 * than silently changing what this endpoint accepts; if the shared minimum ever moves, this
 * file's comment is where the second copy has to be updated.
 *
 * ## The rules are the same rules
 *
 * `date_format:Y-m-d`, optional, and blank means absent. Not loosened and not tightened:
 * `ListClientAccountRequest` is the reference behaviour, and this must not become the
 * permissive one of the pair. It bounds nothing — a month viewed as of 1990 is a legitimate
 * question — and refuses only what cannot be read.
 */
final class ListPeriodObligationsRequest extends FormRequest
{
    /**
     * `obligations.view`, enforced **here** so it runs before validation.
     *
     * R1 §36 made this endpoint depend on both `obligations.view` and `periods.view`, and the
     * route middleware still enforces `periods.view`. This half was checked in the controller
     * body, which runs *after* this request's validation — so the order was wrong, and the
     * symptom was that authorization depended on the shape of an unauthorized caller's input:
     *
     *     GET /periods/1/obligations                     -> 403
     *     GET /periods/1/obligations?as_of=garbage        -> 422 errors.as_of
     *
     * A boundary that answers 422 to one caller and 403 to another, differing only in whether
     * the caller who may not read this data guessed the query format correctly, is not a
     * boundary. It is also the more revealing order: the 422 confirmed that the endpoint
     * exists and what it validates before deciding whether this caller may see anything.
     *
     * Laravel resolves the FormRequest — `authorize()` first, then `rules()` — before the
     * controller is entered at all, so putting the check here makes the order deterministic.
     * The controller keeps its own assertion as well, which is redundant on purpose.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('obligations.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // As `PeriodController::perPage()` bounds them, not as `ListQueryRequest` does.
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // Identical to `ListReceivablesRequest` and `ListClientAccountRequest`.
            'as_of' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'El número de registros por página debe ser un número.',
            'per_page.min' => 'El número de registros por página debe ser al menos 1.',
            'per_page.max' => 'El número de registros por página no puede superar 100.',
            'as_of.date_format' => 'La fecha de referencia debe ser una fecha como 2026-04-10.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'per_page' => 'número de registros por página',
            'as_of' => 'fecha de referencia',
        ];
    }

    /**
     * The reference date for lateness, or `null` for today.
     *
     * `validated()` and then `Carbon::parse()`. Not `$this->date()`, which answers `null` for
     * anything it cannot parse and is the reason this endpoint used to report a month the
     * operator did not ask about; and not the controller's own `abort()`, which now has no
     * caller here.
     *
     * A **blank** value is absent rather than unreadable, and stays absent: clearing a date
     * input sends an empty parameter, and that has to mean "today" rather than a permanent 422
     * the operator cannot clear.
     */
    public function asOf(): ?Carbon
    {
        $value = $this->validated('as_of');

        return is_string($value) && trim($value) !== ''
            ? Carbon::parse($value)->startOfDay()
            : null;
    }
}

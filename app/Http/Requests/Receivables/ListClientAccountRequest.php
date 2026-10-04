<?php

declare(strict_types=1);

namespace App\Http\Requests\Receivables;

use App\Http\Requests\ListQueryRequest;
use Illuminate\Support\Carbon;

/**
 * The query string for one client's statement.
 *
 * ## Why this exists
 *
 * The account endpoint was the last receivables surface taking a bare `Request`, and it read
 * its reference date with `$request->date('as_of')`. That method answers `null` for anything
 * it cannot parse, so `?as_of=2026-13-45` and `?as_of=ayer` were not rejected — they were
 * dropped, and the statement came back "as of today".
 *
 * That is worse than a 422. The operator asked a dated question, got an answer, and the
 * answer is about a different day: a balance that is not overdue today can be shown as though
 * it were not overdue last month, or the reverse. Nothing on the screen says the date was
 * ignored, because from the response's point of view it was never asked for.
 *
 * The list endpoint has always refused it (`date_format:Y-m-d`, §7), so the two screens that
 * accept a reference date disagreed about whether a bad one was an error.
 *
 * ## The date itself
 *
 * A `date_format:Y-m-d` rule and nothing cleverer. `Carbon::parse()` accepts a dozen spellings
 * of the same day, and `2026-1-5` and `2026-01-05` are both "today" for it; the interface
 * sends one format, and a value that is not that format is a mistake worth reporting rather
 * than a spelling to guess at.
 *
 * Note what this does **not** do: it does not bound the date. A statement as of 1990 or as of
 * next year is a legitimate question — "what did this look like before the migration?" — and
 * the figures are computed from the same rows either way. What is refused is a date that
 * cannot be read, because a date that cannot be read cannot be honoured.
 */
final class ListClientAccountRequest extends ListQueryRequest
{
    public function authorize(): bool
    {
        // The permission is checked in the controller, where the message names the screen. A
        // request that authorized itself would have to say the same thing twice.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'as_of' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'as_of.date_format' => 'La fecha de referencia debe ser una fecha como 2026-04-10.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'as_of' => 'fecha de referencia',
        ];
    }

    /**
     * The reference date as a date object, or `null` for today.
     *
     * `validated()` and then `Carbon::parse()`, not `$this->date()`: `Request::date()` is the
     * thing that silently answered `null` for an unreadable date, and using it here would
     * reintroduce the very defect the rules above now refuse.
     *
     * A **blank** value is absent rather than unreadable, and stays absent: clearing a date
     * input sends an empty parameter, so `''` has to mean "today" — otherwise a field the
     * operator cannot refill would keep returning 422.
     */
    public function asOf(): ?Carbon
    {
        $value = $this->validated('as_of');

        return is_string($value) && trim($value) !== ''
            ? Carbon::parse($value)->startOfDay()
            : null;
    }
}

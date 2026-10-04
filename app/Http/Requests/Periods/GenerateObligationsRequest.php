<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generate the obligations for a month.
 *
 * ## There is no `missing_only` flag any more
 *
 * There used to be one, defaulting to true, and the interface exposed it as a checkbox.
 * The flag promised a mode that regenerated everything, and no such mode existed: an
 * obligation is a snapshot, and `GeneratePeriodObligations` has always skipped any pair
 * that already has one. The checkbox therefore could not change what would be written,
 * which makes it worse than a useless control — an operator could reasonably believe they
 * had chosen to rewrite a month and only discover otherwise from the preview totals.
 *
 * Generation is now unconditionally missing-only and idempotent, and the request accepts
 * nothing. A client still sending `missing_only` is not refused: the field is simply
 * ignored, because breaking a working integration over a flag that never did anything
 * would be a worse failure than ignoring it.
 */
final class GenerateObligationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `obligations.generate`, not `obligations.adjust`.
        //
        // Generation writes what a client owes into the portfolio, possibly hundreds of
        // rows at once. Adjusting corrects one figure on a row that already exists.
        // They are different authorities, and a role trusted with the second should not
        // get the first by implication: the Collections role can settle money and correct
        // a single figure, and must not be able to manufacture the debts it is then
        // settling.
        return $this->user()?->can('obligations.generate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generate the obligations for a month.
 *
 * `missing_only` defaults to true and is what the button says: "generate the missing
 * obligations". A month that already has some is never regenerated, so running this
 * twice creates nothing the second time and existing figures are never rewritten.
 */
final class GenerateObligationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `obligations.generate`, not `obligations.adjust`.
        //
        // Generation writes what a client owes into the portfolio, possibly hundreds
        // of rows at once. Adjusting corrects one figure on a row that already
        // exists. They are different authorities, and a role trusted with the second
        // should not get the first by implication: the Collections role can settle
        // money and correct a single figure, and must not be able to manufacture the
        // debts it is then settling.
        return $this->user()?->can('obligations.generate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'missing_only' => ['sometimes', 'boolean'],
        ];
    }

    public function missingOnly(): bool
    {
        return $this->boolean('missing_only', true);
    }
}

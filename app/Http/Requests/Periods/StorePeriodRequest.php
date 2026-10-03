<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use App\Domain\Periods\MonthlyPeriod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a month.
 *
 * The month arrives as a `YYYY-MM` key rather than a date, because that is how a
 * person names a period and because accepting a full date would mean deciding which
 * day of the month was meant. `MonthlyPeriod::fromKey` refuses anything that is not
 * a real month, so `2026-13` and `2026-1` are errors rather than being repaired.
 *
 * The server never fills the month in from today's date. The interface suggests the
 * current month; what is created is what the caller asked for.
 */
final class StorePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('periods.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_month.required' => 'Debe indicar el periodo a abrir.',
            'period_month.regex' => 'El periodo debe escribirse como AAAA-MM, por ejemplo 2026-10.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['period_month' => 'periodo'];
    }

    public function month(): MonthlyPeriod
    {
        return MonthlyPeriod::fromKey((string) $this->validated('period_month'));
    }
}

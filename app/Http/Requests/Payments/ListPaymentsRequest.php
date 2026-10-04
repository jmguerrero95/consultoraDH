<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\ReconciliationState;
use App\Support\Validation\LooseBoolean;
use App\Support\Validation\SafeSearch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payments list filters, validated.
 *
 * §29 and §30.
 *
 * Two defects motivated this. The screen sends `requires_reconciliation=false` for an
 * unchecked box, and the query applied its restriction whenever the key was present — the
 * same shape of bug as the cartera's `overdue=false`, and the review asks explicitly for a
 * regression test so it is not duplicated. And the search needle was built as `'%'.$input.'%'`
 * with nothing escaped, so a typed `%` or `_` became a wildcard and the list answered a
 * question nobody asked.
 *
 * The search also did not fold the needle while folding the columns, which is why the review
 * names this case: `Ana` did not match `Ana María`, because `lower(first_names)` was compared
 * with a needle that still had its capital.
 */
final class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200', new SafeSearch],
            'method' => ['nullable', 'string', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'state' => ['nullable', 'string', Rule::in(array_column(ReconciliationState::cases(), 'value'))],
            'date_from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'string', 'date_format:Y-m-d'],
            'requires_reconciliation' => ['nullable', new LooseBoolean],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.in' => 'Ese medio de pago no existe.',
            'state.in' => 'Ese estado de conciliación no existe.',
            'date_from.date_format' => 'La fecha inicial debe ser una fecha como 2026-01-15.',
            'date_to.date_format' => 'La fecha final debe ser una fecha como 2026-01-15.',
            'per_page.max' => 'Se pueden pedir como máximo 100 registros por página.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => 'búsqueda',
            'method' => 'medio de pago',
            'state' => 'estado',
            'date_from' => 'fecha inicial',
            'date_to' => 'fecha final',
            'requires_reconciliation' => 'sólo por conciliar',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (is_string($from) && is_string($to) && $from !== '' && $to !== '' && $from > $to) {
                $validator->errors()->add(
                    'date_from',
                    'La fecha inicial no puede ser posterior a la fecha final.',
                );
            }
        });
    }

    /**
     * The validated filters, with the boolean reduced to "true or not asked for".
     *
     * §30. Only a true narrows the list to payments that still have unapplied money; absent
     * and false both mean "show everything".
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $filters = [];

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $filters[$key] = $value;
        }

        unset($filters['requires_reconciliation']);

        if (LooseBoolean::toBool($this->input('requires_reconciliation')) === true) {
            $filters['requires_reconciliation'] = true;
        }

        return $filters;
    }

    public function page(): int
    {
        return max(1, (int) $this->validated('page', 1));
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) $this->validated('per_page', 25)));
    }
}

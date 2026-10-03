<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Configure a monthly amount for one client and one company.
 *
 * Whole pesos, positive. There is no formula and no percentage: the amount is
 * configured, because no rule was supplied that could derive one.
 */
final class StoreRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rates.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'effective_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}-01$/'],
            'amount_cop' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_cop.required' => 'Debe indicar el valor mensual en pesos.',
            'amount_cop.integer' => 'El valor debe ser un número entero de pesos, sin decimales.',
            'amount_cop.min' => 'El valor debe ser mayor que cero.',
            'effective_month.regex' => 'El mes de vigencia debe ser el primer día del mes, como 2026-01-01.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount_cop' => 'valor mensual',
            'effective_month' => 'mes de vigencia',
            'client_id' => 'cliente',
            'company_id' => 'empresa',
        ];
    }
}

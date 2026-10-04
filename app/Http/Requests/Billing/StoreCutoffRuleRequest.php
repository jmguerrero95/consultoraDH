<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\CutoffScope;
use App\Support\Validation\FirstDayOfMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a cutoff rule.
 *
 * The identifiers are required by the scope and the request says so: a `client` rule
 * without a company is not a valid thing, and a `general` rule with one would look
 * like it applies to somebody. The database enforces the same three shapes, so this
 * is the message rather than the guarantee.
 */
final class StoreCutoffRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cutoffs.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(array_column(CutoffScope::cases(), 'value'))],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // Rejects 2026-13-01 as well as a mid-month date: a regex checked the
            // shape and let the impossible month through to Carbon.
            'effective_month' => ['required', 'string', new FirstDayOfMonth],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:31'],
            'month_offset' => ['required', 'integer', 'min:0', 'max:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.required' => 'Debe indicar el alcance de la regla.',
            'effective_month.required' => 'Debe indicar el mes desde el que aplica la regla.',
            'effective_month.regex' => 'El mes de vigencia debe ser el primer día del mes, como 2026-01-01.',
            'effective_month.string' => 'El mes de vigencia debe escribirse como texto, como 2026-01-01.',
            'cutoff_day.required' => 'Debe indicar el día de corte.',
            'cutoff_day.min' => 'El día de corte debe estar entre 1 y 31.',
            'cutoff_day.max' => 'El día de corte debe estar entre 1 y 31. En meses cortos se usa el último día.',
            'month_offset.required' => 'Debe indicar si el corte cae en el mismo mes o en el siguiente.',
            'month_offset.in' => 'El desplazamiento debe ser 0 (mismo mes) o 1 (mes siguiente).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'effective_month' => 'mes de vigencia',
            'cutoff_day' => 'día de corte',
            'month_offset' => 'mes de desplazamiento',
            'company_id' => 'empresa',
            'client_id' => 'cliente',
        ];
    }

    /**
     * Refuse the impossible combinations with a message that names the problem.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scope = CutoffScope::tryFrom((string) $this->input('scope'));

            if ($scope === null) {
                return;
            }

            $company = $this->input('company_id');
            $client = $this->input('client_id');

            if ($scope->requiresCompany() && $company === null) {
                $validator->errors()->add(
                    'company_id',
                    'Una regla de alcance «'.$scope->value.'» necesita la empresa a la que aplica.',
                );
            }

            if (! $scope->requiresCompany() && $company !== null) {
                $validator->errors()->add(
                    'company_id',
                    'Una regla general no puede llevar empresa: aplíquela con alcance «company».',
                );
            }

            if ($scope->requiresClient() && $client === null) {
                $validator->errors()->add(
                    'client_id',
                    'Una excepción por cliente necesita el cliente al que aplica.',
                );
            }

            if (! $scope->requiresClient() && $client !== null) {
                $validator->errors()->add(
                    'client_id',
                    'Una regla que no es de alcance «client» no puede llevar cliente.',
                );
            }
        });
    }
}

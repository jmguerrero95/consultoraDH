<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a correction against an obligation.
 *
 * `reversal` is not an accepted type here: a reversal is what the system writes when
 * the *undo* action runs, not something an operator chooses while correcting a
 * figure. Accepting it would put a row in the ledger whose meaning is decided by
 * whether somebody filled in one more field.
 */
final class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('obligations.adjust') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            // Signed, and never zero. Zero is validated here as well as in the domain
            // because the message belongs next to the field.
            'delta_cop' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Debe indicar el tipo de ajuste.',
            'delta_cop.required' => 'Debe indicar el importe del ajuste.',
            'delta_cop.integer' => 'El importe debe ser un número entero de pesos.',
            'delta_cop.not_in' => 'El importe del ajuste no puede ser cero.',
            'reason.required' => 'Debe indicar el motivo del ajuste.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
        ];
    }
}

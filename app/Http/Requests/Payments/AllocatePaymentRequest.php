<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply part of a payment to one obligation.
 *
 * One obligation per request. Allocating a payment across several obligations in a
 * single call would need its own ordering, its own partial-failure behaviour and its
 * own rollback story; three separate calls, each transactional, are easier to reason
 * about and to undo. The explicit oldest-first action exists for the bulk case.
 */
final class AllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.allocate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'obligation_id' => ['required', 'integer', 'exists:monthly_obligations,id'],
            'amount_cop' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'obligation_id.required' => 'Debe indicar la obligación a la que se aplica el pago.',
            'obligation_id.exists' => 'La obligación indicada no existe.',
            'amount_cop.required' => 'Debe indicar el importe a aplicar.',
            'amount_cop.integer' => 'El importe debe ser un número entero de pesos.',
            'amount_cop.min' => 'El importe debe ser mayor que cero.',
        ];
    }
}

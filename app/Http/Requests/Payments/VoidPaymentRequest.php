<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invalidate a payment.
 *
 * A reason and a confirmation. Voids take money out of the balances while keeping
 * every row, so an unexplained one is a hole in the reconciliation; the reason is
 * what closes it.
 */
final class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.void') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Debe indicar el motivo de la anulación.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'confirm.accepted' => 'Debe confirmar la anulación del pago.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}

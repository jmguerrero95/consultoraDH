<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register money received.
 *
 * The amount is validated as an integer here and again in the domain. The duplication
 * is deliberate: this produces a field-level message for somebody filling in a form,
 * and the domain check is what the importer, the assistant and a console command will
 * meet. Neither depends on the other.
 *
 * `confirm_duplicate` is the answer to the duplicate warning, not a bypass of it. The
 * server always reports a possible duplicate; this only records that a human looked
 * at it and said yes.
 */
final class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'amount_cop' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Debe indicar el cliente al que corresponde el pago.',
            'client_id.exists' => 'El cliente indicado no existe.',
            'amount_cop.required' => 'Debe indicar el importe del pago.',
            'amount_cop.integer' => 'El importe debe ser un número entero de pesos, sin decimales.',
            'amount_cop.min' => 'El importe debe ser mayor que cero.',
            'received_on.required' => 'Debe indicar la fecha en que se recibió el pago.',
            'received_on.date_format' => 'La fecha de recepción debe escribirse como AAAA-MM-DD.',
            'method.required' => 'Debe indicar el método de pago.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'client_id' => 'cliente',
            'amount_cop' => 'importe',
            'received_on' => 'fecha de recepción',
            'method' => 'método de pago',
            'reference' => 'referencia',
        ];
    }
}

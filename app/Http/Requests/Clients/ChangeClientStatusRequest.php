<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Activating or deactivating a client.
 *
 * The `when` field is the interesting one. Deactivating a client that still has
 * open relationships has more than one reasonable answer, and the application
 * cannot choose between them: it would either refuse a legitimate change or, far
 * worse, close somebody's employment without anybody deciding it. So the caller
 * states which of the two is intended and the server enforces the consequence.
 */
final class ChangeClientStatusRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('clients.change_status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'when' => ['required', 'string', Rule::in(['block', 'close'])],
            'effective_date' => ['required_if:when,close', 'nullable', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Debe indicar el estado destino.',
            'status.in' => 'El estado debe ser "active" o "inactive".',
            'when.required' => 'Debe indicar cómo tratar las relaciones abiertas.',
            'when.in' => 'Indique "block" para no tocarlas o "close" para cerrarlas.',
            'effective_date.required_if' => 'Debe indicar la fecha de cierre de las relaciones abiertas.',
            'effective_date.date' => 'La fecha de cierre no tiene un formato válido.',
            'effective_date.before_or_equal' => 'La fecha de cierre no puede ser futura.',
            'reason.max' => 'El motivo no puede superar los 255 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'effective_date' => 'fecha de cierre',
        ];
    }

    /**
     * Whether the open relationships should be closed as part of the change.
     */
    public function shouldCloseRelationships(): bool
    {
        return $this->validated('when') === 'close';
    }

    public function effectiveDate(): ?\DateTimeImmutable
    {
        $date = $this->validated('effective_date');

        return is_string($date) && $date !== ''
            ? new \DateTimeImmutable($date)
            : null;
    }
}

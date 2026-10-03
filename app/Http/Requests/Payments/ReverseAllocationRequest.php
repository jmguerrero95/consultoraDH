<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseAllocationRequest extends FormRequest
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
            'reason.required' => 'Debe indicar el motivo de la reversión.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres.',
            'confirm.accepted' => 'Debe confirmar la reversión de la aplicación.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}

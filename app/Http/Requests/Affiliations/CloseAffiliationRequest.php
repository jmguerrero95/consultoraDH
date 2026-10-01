<?php

declare(strict_types=1);

namespace App\Http\Requests\Affiliations;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;

final class CloseAffiliationRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('affiliations.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ended_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ended_on.required' => 'Debe indicar la fecha de cierre de la afiliación.',
            'ended_on.date' => 'La fecha de cierre no tiene un formato válido.',
            'reason.max' => 'El motivo no puede superar los 255 caracteres.',
        ];
    }
}

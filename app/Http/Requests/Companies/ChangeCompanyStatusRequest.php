<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeCompanyStatusRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('companies.change_status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
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
            'reason.max' => 'El motivo no puede superar los 255 caracteres.',
        ];
    }
}

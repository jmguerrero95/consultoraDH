<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Changing the sign in address requires the current password, so that a
     * stolen session alone cannot be used to take over the account.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->getAuthIdentifier()),
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.unique' => 'Ya existe una cuenta registrada con ese correo electrónico.',
            'current_password.required' => 'Debe confirmar su contraseña actual.',
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => mb_strtolower(trim($this->input('email'))),
            ]);
        }
    }
}

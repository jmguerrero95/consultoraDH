<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            // The shared policy, plus the reuse check that only makes sense
            // here: the current password is known, so silently accepting it
            // again would look like a successful change without being one.
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                ...PasswordPolicy::rulesDifferentFrom('current_password'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            'current_password.required' => 'Debe confirmar su contraseña actual.',
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
            'password.max' => 'La contraseña no puede superar los 255 caracteres.',
        ], PasswordPolicy::messages());
    }
}

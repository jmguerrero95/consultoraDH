<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            // The same policy as every other entry point. This used to be
            // `Password::min(12)` alone, which meant a reset could produce a
            // weaker account than choosing a password at sign up.
            'password' => ['required', 'string', 'max:255', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(
            [
                'password.confirmed' => 'La confirmación de contraseña no coincide.',
                'password.max' => 'La contraseña no puede superar los 255 caracteres.',
            ],
            PasswordPolicy::messages(),
        );
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

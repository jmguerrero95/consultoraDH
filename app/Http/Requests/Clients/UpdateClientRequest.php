<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('clients.update') ?? false;
    }

    /**
     * The identity and the status are not editable here.
     *
     * The document is what makes the record unique, so changing it is a different
     * decision with its own checks. The status has its own endpoints because
     * deactivating a client has rules about open relationships that a field edit
     * has no way to express.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_names' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'last_names' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_names.required' => 'Los nombres son obligatorios.',
            'first_names.min' => 'Los nombres deben tener al menos 2 caracteres.',
            'first_names.max' => 'Los nombres no pueden superar los 120 caracteres.',
            'last_names.required' => 'Los apellidos son obligatorios.',
            'last_names.min' => 'Los apellidos deben tener al menos 2 caracteres.',
            'last_names.max' => 'Los apellidos no pueden superar los 120 caracteres.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.max' => 'El correo electrónico no puede superar los 255 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 40 caracteres.',
            'address.max' => 'La dirección no puede superar los 255 caracteres.',
            'city.max' => 'La ciudad no puede superar los 120 caracteres.',
            'department.max' => 'El departamento no puede superar los 120 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_names' => 'nombres',
            'last_names' => 'apellidos',
        ];
    }

    /**
     * The unique mailbox rule, applied to the record being edited so a client can
     * keep its own address.
     */
    public function rulesFor(Client $client): array
    {
        $rules = $this->rules();

        if (array_key_exists('email', $rules)) {
            $rules['email'][] = Rule::unique('clients', 'email')->ignore($client->id);
        }

        return $rules;
    }
}

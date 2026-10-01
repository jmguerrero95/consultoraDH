<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Domain\Clients\DocumentNumber;
use App\Domain\Clients\DocumentType;
use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreClientRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('clients.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::enum(DocumentType::class)],
            'document_number' => ['required', 'string', 'max:32'],
            'first_names' => ['required', 'string', 'min:2', 'max:120'],
            'last_names' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Debe indicar el tipo de documento.',
            'document_type.enum' => 'El tipo de documento no es válido.',
            'document_number.required' => 'Debe indicar el número de documento.',
            'document_number.max' => 'El número de documento no puede superar los 32 caracteres.',
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
            'document_type' => 'tipo de documento',
            'document_number' => 'número de documento',
            'first_names' => 'nombres',
            'last_names' => 'apellidos',
        ];
    }

    /**
     * The uniqueness of a document is a database fact, not a guess.
     *
     * This check exists only to give the person filling the form a message at the
     * moment they can act on it. The unique index is what actually enforces it,
     * because an `exists` query followed by an insert is a race that two people
     * saving at the same moment would both pass.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = DocumentType::tryFrom((string) $this->input('document_type'));
            $number = DocumentNumber::normalise($this->input('document_number'), $type ?? DocumentType::Other);

            if ($type === null || ! DocumentNumber::looksValid($number)) {
                return;
            }

            $exists = Client::query()
                ->where('document_type', $type->value)
                ->where('document_number', $number)
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'document_number',
                    'Ya existe un cliente registrado con ese tipo y número de documento.',
                );
            }
        });
    }
}

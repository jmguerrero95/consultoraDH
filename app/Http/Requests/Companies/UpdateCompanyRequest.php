<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Domain\Companies\TaxIdSyntax;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyRequest extends FormRequest
{
    use AuthorizesRequests;

    /**
     * The attributes for the action, with an explicitly empty digit restored.
     *
     * `validated()` drops a key whose value is null, which erases the difference
     * between "the caller said the digit is empty" and "the caller said nothing
     * about the digit". Those are different requests: one clears a stored value and
     * the other must not, and reading both as the second is how an edit of the
     * telephone number used to erase a company's verification digit.
     *
     * Only the digit is restored. Every other nullable field follows the ordinary
     * convention, where absent and empty mean the same thing because sending them
     * empty is how a form clears them.
     *
     * @return array<string, mixed>
     */
    public function companyAttributes(): array
    {
        $attributes = $this->validated();

        if (array_key_exists('verification_digit', $this->all())
            && ! array_key_exists('verification_digit', $attributes)) {
            $attributes['verification_digit'] = null;
        }

        return $attributes;
    }

    public function authorize(): bool
    {
        return $this->user()?->can('companies.update') ?? false;
    }

    /**
     * The status is not editable here; it has its own endpoint with its own rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            // Same rule as the create request, so the two cannot drift. `sometimes`
            // rather than `nullable`, because a field the request does not mention
            // must not become a request to clear the stored digit.
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:32', new TaxIdSyntax],
            'verification_digit' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]$/'],
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
            'legal_name.required' => 'La razón social es obligatoria.',
            'legal_name.min' => 'La razón social debe tener al menos 2 caracteres.',
            'legal_name.max' => 'La razón social no puede superar los 180 caracteres.',
            'trade_name.max' => 'El nombre comercial no puede superar los 180 caracteres.',
            'tax_id.max' => 'El NIT no puede superar los 32 caracteres.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'email.max' => 'El correo electrónico no puede superar los 255 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 40 caracteres.',
            'address.max' => 'La dirección no puede superar los 255 caracteres.',
            'city.max' => 'La ciudad no puede superar los 120 caracteres.',
            'department.max' => 'El departamento no puede superar los 120 caracteres.',
        ];
    }
}

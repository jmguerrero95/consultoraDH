<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Domain\Companies\TaxId;
use App\Domain\Companies\TaxIdSyntax;
use App\Models\Company;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreCompanyRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('companies.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'min:2', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            // Accepts `900.123.456-3` as well as a bare number, and the digit can
            // also arrive in its own field. What is stored is decided by the domain
            // action, not here.
            //
            // The syntax rule rather than a bare length: the column holds digits
            // only and the database enforces that, so a length check let `ABC123`
            // through to be answered with a server error.
            'tax_id' => ['nullable', 'string', 'max:32', new TaxIdSyntax],
            'verification_digit' => ['nullable', 'string', 'regex:/^[0-9]$/'],
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
            'legal_name.required' => 'La razón social es obligatoria.',
            'legal_name.min' => 'La razón social debe tener al menos 2 caracteres.',
            'legal_name.max' => 'La razón social no puede superar los 180 caracteres.',
            'trade_name.max' => 'El nombre comercial no puede superar los 180 caracteres.',
            'tax_id.max' => 'El NIT no puede superar los 32 caracteres.',
            'verification_digit.regex' => 'El dígito de verificación debe ser un solo número.',
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
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'tax_id' => 'NIT',
            'verification_digit' => 'dígito de verificación',
        ];
    }

    /**
     * Duplicates are reported here so the form can react immediately; the partial
     * unique index is what makes it true when two people save at once.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Uniqueness is decided on the base number: `900123456-3` and
            // `900123456-7` are one company with two contradictory digits, not two
            // companies.
            $taxId = TaxId::normalise($this->input('tax_id'));

            if ($taxId === '') {
                return;
            }

            $exists = Company::query()
                ->whereRaw('lower(btrim(tax_id)) = ?', [mb_strtolower($taxId)])
                ->exists();

            if ($exists) {
                $validator->errors()->add('tax_id', 'Ya existe una empresa registrada con ese NIT.');
            }
        });
    }
}

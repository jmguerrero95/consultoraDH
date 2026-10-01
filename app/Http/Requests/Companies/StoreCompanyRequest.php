<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Domain\Companies\TaxId;
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
            'tax_id' => ['nullable', 'string', 'max:32'],
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

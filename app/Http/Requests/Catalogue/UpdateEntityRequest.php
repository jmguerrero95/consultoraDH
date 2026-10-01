<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEntityRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('social_security_entities.manage') ?? false;
    }

    /**
     * The type is not editable.
     *
     * Moving an EPS entry to be an ARL would silently reinterpret every
     * affiliation that points at it, including years of history. A new entry is
     * the honest way to record a different kind of organisation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la entidad es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 180 caracteres.',
            'code.max' => 'El código no puede superar los 40 caracteres.',
            'tax_id.max' => 'El NIT no puede superar los 32 caracteres.',
        ];
    }
}

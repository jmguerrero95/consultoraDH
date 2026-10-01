<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreEntityRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('social_security_entities.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(SocialSecurityEntityType::class)],
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'code' => ['nullable', 'string', 'max:40'],
            'tax_id' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Debe indicar el tipo de entidad.',
            'type.enum' => 'El tipo de entidad no es válido.',
            'name.required' => 'El nombre de la entidad es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 180 caracteres.',
            'code.max' => 'El código no puede superar los 40 caracteres.',
            'tax_id.max' => 'El NIT no puede superar los 32 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
        ];
    }

    /**
     * "Nueva EPS" and "NUEVA EPS" are one entity.
     *
     * Reported here for an immediate message; the unique index on the generated
     * `normalized_name` is what makes it true under concurrency.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->input('type');
            $name = $this->input('name');

            if (! is_string($type) || ! is_string($name)) {
                return;
            }

            $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));

            $exists = SocialSecurityEntity::query()
                ->where('type', $type)
                ->where('normalized_name', $normalised)
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'name',
                    'Ya existe una entidad de ese tipo con ese nombre.',
                );
            }
        });
    }
}

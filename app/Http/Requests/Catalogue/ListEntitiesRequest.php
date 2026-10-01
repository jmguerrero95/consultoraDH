<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Http\Requests\ListQueryRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

final class ListEntitiesRequest extends ListQueryRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('social_security_entities.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'type' => ['sometimes', 'nullable', 'string', Rule::enum(SocialSecurityEntityType::class)],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'type.enum' => 'El tipo de entidad no es válido.',
        ]);
    }

    public function typeFilter(): ?SocialSecurityEntityType
    {
        $type = $this->validated('type');

        return is_string($type) && $type !== ''
            ? SocialSecurityEntityType::tryFrom($type)
            : null;
    }
}

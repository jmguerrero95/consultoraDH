<?php

declare(strict_types=1);

namespace App\Http\Requests\Affiliations;

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating or changing an affiliation.
 *
 * Two checks here that the database cannot do on its own, because both need to
 * look at the entity rather than only at this row:
 *
 *  - the declared type must match the entity's type. An ARL affiliation may not
 *    point at an EPS entity.
 *  - a risk level is only accepted on an ARL. Rejecting it here, on the field,
 *    is clearer than letting the constraint reject the whole insert.
 */
final class StoreAffiliationRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('affiliations.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'social_security_entity_id' => ['required', 'integer', Rule::exists('social_security_entities', 'id')],
            'type' => ['required', 'string', Rule::enum(SocialSecurityEntityType::class)],
            'started_on' => ['nullable', 'date'],
            // Same as the relationship request: an affiliation is created open and
            // closed by its own operation, so a closing date sent here is refused
            // instead of quietly discarded.
            'ended_on' => ['prohibited'],
            'arl_risk_class' => ['nullable', 'integer', Rule::in(ArlRiskClass::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_company_assignment_id' => ['nullable', 'integer', Rule::exists('client_company_assignments', 'id')],
            // Present only when replacing an existing affiliation of the same type.
            'replace_current' => ['sometimes', 'boolean'],
            'effective_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'social_security_entity_id.required' => 'Debe indicar la entidad.',
            'social_security_entity_id.exists' => 'La entidad indicada no existe.',
            'type.required' => 'Debe indicar el tipo de afiliación.',
            'type.enum' => 'El tipo de afiliación no es válido.',
            'started_on.date' => 'La fecha de inicio no tiene un formato válido.',
            'ended_on.date' => 'La fecha de cierre no tiene un formato válido.',
            'ended_on.after_or_equal' => 'La fecha de cierre no puede ser anterior a la de inicio.',
            'ended_on.prohibited' => 'Una afiliación se crea abierta; para cerrarla use la operación de cierre.',
            'arl_risk_class.in' => 'El nivel de riesgo debe ser 1, 2, 3, 4 o 5.',
            'arl_risk_class.integer' => 'El nivel de riesgo debe ser un número.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
            'effective_date.date' => 'La fecha efectiva no tiene un formato válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'social_security_entity_id' => 'entidad',
            'started_on' => 'fecha de inicio',
            'ended_on' => 'fecha de cierre',
            'arl_risk_class' => 'nivel de riesgo',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = SocialSecurityEntityType::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            $entity = SocialSecurityEntity::query()
                ->find($this->input('social_security_entity_id'));

            if ($entity === null) {
                return;
            }

            if ($entity->type !== $type) {
                $validator->errors()->add(
                    'social_security_entity_id',
                    sprintf(
                        'La entidad seleccionada es de tipo %s y no de %s.',
                        $entity->type->shortLabel(),
                        $type->shortLabel(),
                    ),
                );

                return;
            }

            if ($this->input('arl_risk_class') !== null && ! $type->carriesRiskClass()) {
                $validator->errors()->add(
                    'arl_risk_class',
                    sprintf('El nivel de riesgo sólo aplica a afiliaciones de ARL, no a %s.', $type->shortLabel()),
                );
            }
        });
    }

    public function riskClass(): ?ArlRiskClass
    {
        $value = $this->input('arl_risk_class');

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? ArlRiskClass::tryFrom((int) $value) : null;
    }

    public function type(): SocialSecurityEntityType
    {
        return SocialSecurityEntityType::from((string) $this->input('type'));
    }

    public function shouldReplaceCurrent(): bool
    {
        return filter_var($this->input('replace_current', false), FILTER_VALIDATE_BOOLEAN);
    }
}

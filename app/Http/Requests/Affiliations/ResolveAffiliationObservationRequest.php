<?php

declare(strict_types=1);

namespace App\Http\Requests\Affiliations;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Identifying the entity behind an affiliation the source asserted but did not name.
 *
 * ## Why this is not `StoreAffiliationRequest`
 *
 * Because it is a different operation with a different risk. Registering an affiliation adds a
 * fact somebody typed. This **answers a question an import left open**, and it does so against a
 * row that records what the file said. Two consequences:
 *
 *  - the permission is the same (`affiliations.manage`) — a role that may write an affiliation may
 *    answer this — but the audit trail is A02's own, because the write goes through
 *    `ManageAffiliations` and not through a second path that bypasses its invariants;
 *  - `started_on` is **prohibited**. The observation already carries the date the file stated, and
 *    accepting a new one here would let somebody answer "which fund?" and quietly change "since
 *    when" at the same time. The two questions stay separate.
 *
 * `ended_on` is prohibited for the same reason as on the affiliation form: a record is created
 * open and closed by its own operation.
 */
final class ResolveAffiliationObservationRequest extends FormRequest
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
            // §9.1's rule again, from the other side: the entity a reviewer names must be of the
            // type the file asserted. The database could not check it either, because
            // `client_affiliations` repeats the type precisely so it can.
            'type' => ['required', 'string', Rule::in(['EPS', 'AFP', 'ARL', 'CCF'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'started_on' => ['prohibited'],
            'ended_on' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'social_security_entity_id.required' => 'Debe indicar a qué entidad corresponde la afiliación.',
            'social_security_entity_id.exists' => 'La entidad indicada no existe.',
            'type.required' => 'Debe indicar el tipo de afiliación.',
            'type.in' => 'El tipo de afiliación no es válido.',
            'started_on.prohibited' => 'La fecha de inicio ya la declara el archivo; no se puede cambiar al identificar la entidad.',
            'ended_on.prohibited' => 'Una afiliación se crea abierta; para cerrarla use la operación de cierre.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'social_security_entity_id' => 'entidad',
            'type' => 'tipo de afiliación',
            'notes' => 'notas',
        ];
    }
}

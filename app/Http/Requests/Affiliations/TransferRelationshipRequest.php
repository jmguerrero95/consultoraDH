<?php

declare(strict_types=1);

namespace App\Http\Requests\Affiliations;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a client from the company of an open relationship to another.
 *
 * The `assignment_id` in the route identifies the relationship being closed. It
 * is not accepted from the body, because a body field that decides which
 * historical row gets rewritten is exactly the kind of field that should live in
 * the path where it is visible.
 */
final class TransferRelationshipRequest extends FormRequest
{
    use AuthorizesRequests;

    public function authorize(): bool
    {
        return $this->user()?->can('relationships.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'effective_on' => ['required', 'date'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_company_id.required' => 'Debe indicar la empresa de destino.',
            'to_company_id.exists' => 'La empresa de destino no existe.',
            'effective_on.required' => 'Debe indicar la fecha efectiva del cambio.',
            'effective_on.date' => 'La fecha efectiva no tiene un formato válido.',
            'job_title.max' => 'El cargo no puede superar los 120 caracteres.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'to_company_id' => 'empresa de destino',
            'effective_on' => 'fecha efectiva',
            'job_title' => 'cargo',
        ];
    }
}

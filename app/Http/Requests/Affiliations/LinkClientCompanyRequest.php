<?php

declare(strict_types=1);

namespace App\Http\Requests\Affiliations;

use App\Domain\Affiliations\ManageClientCompanies;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Linking a client to a company.
 *
 * The `resolution` field is the requirement that stops a second open relationship
 * appearing by accident. The source data has people listed at more than one
 * company at the same time and the application cannot tell a real overlap from a
 * mistake, so the caller has to say what it means, and the default (`only_if_none`)
 * refuses rather than guessing.
 *
 * A justification is required for `parallel` and ignored for the others, which is
 * checked in `withValidator` so the message lands on the field that caused it.
 */
final class LinkClientCompanyRequest extends FormRequest
{
    use AuthorizesRequests;

    /** @var list<string> */
    private const RESOLUTIONS = [
        ManageClientCompanies::RESOLUTION_ONLY_IF_NONE,
        ManageClientCompanies::RESOLUTION_TRANSFER,
        ManageClientCompanies::RESOLUTION_PARALLEL,
        ManageClientCompanies::RESOLUTION_CLOSE_OTHERS,
    ];

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
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'started_on' => ['required', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resolution' => ['required', 'string', Rule::in(self::RESOLUTIONS)],
            'parallel_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => 'Debe indicar la empresa.',
            'company_id.exists' => 'La empresa indicada no existe.',
            'started_on.required' => 'Debe indicar la fecha de inicio de la relación.',
            'started_on.date' => 'La fecha de inicio no tiene un formato válido.',
            'ended_on.date' => 'La fecha de cierre no tiene un formato válido.',
            'ended_on.after_or_equal' => 'La fecha de cierre no puede ser anterior a la de inicio.',
            'job_title.max' => 'El cargo no puede superar los 120 caracteres.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
            'resolution.required' => 'Debe indicar cómo tratar las relaciones abiertas del cliente.',
            'resolution.in' => 'La resolución indicada no es válida.',
            'parallel_reason.max' => 'La justificación no puede superar los 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'started_on' => 'fecha de inicio',
            'ended_on' => 'fecha de cierre',
            'job_title' => 'cargo',
            'parallel_reason' => 'justificación',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->resolution() === ManageClientCompanies::RESOLUTION_PARALLEL) {
                $reason = $this->input('parallel_reason');

                if (! is_string($reason) || trim($reason) === '') {
                    $validator->errors()->add(
                        'parallel_reason',
                        'Mantener una relación en paralelo exige indicar por qué.',
                    );
                }
            }
        });
    }

    public function resolution(): string
    {
        $resolution = $this->input('resolution');

        return is_string($resolution) && $resolution !== ''
            ? $resolution
            : ManageClientCompanies::RESOLUTION_ONLY_IF_NONE;
    }

    public function parallelReason(): ?string
    {
        $reason = $this->input('parallel_reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopen a month.
 *
 * A reason is required and is not optional here: reopening withdraws a settled
 * statement, and the first question asked about a corrected month is why. The
 * Operations role does not hold this permission by default, which is deliberate.
 */
final class ReopenPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('periods.reopen') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Debe indicar el motivo de la reapertura.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres: explica por qué se corrige el periodo.',
            'confirm.accepted' => 'Debe confirmar la reapertura del periodo.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}

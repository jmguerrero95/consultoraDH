<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close a month.
 *
 * Confirmation, not data. The interface asks for it with a dialog that names the
 * month and says what closing means, because "close this period" and "delete
 * everything in it" are two things a person might reasonably read into one button.
 */
final class ClosePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('periods.close') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Debe confirmar el cierre del periodo.',
        ];
    }
}

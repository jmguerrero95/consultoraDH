<?php

declare(strict_types=1);

namespace App\Http\Requests\Periods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask what generation would do.
 *
 * ## Why this is not `GenerateObligationsRequest`
 *
 * That request authorizes `obligations.generate`, which is right for the write and wrong
 * here. The preview used it anyway, so a role that could generate a month could press a
 * button whose confirmation step was forbidden to it — the nonsensical state of being able
 * to act but not to see what one is about to do. And a role that could only *read*
 * obligations could open the plan and not the confirmation, which is the mirror image of the
 * same defect.
 *
 * The route middleware accepts `obligations.generate` **or** `obligations.view`; this
 * request states the same rule, so the gate is not split between two places that could
 * disagree.
 *
 * ## It takes nothing
 *
 * The preview writes nothing and audits nothing, and it accepts no parameters: there is no
 * `missing_only`, because generation is unconditionally missing-only and a second plan would
 * be a promise the action does not keep.
 */
final class PreviewObligationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('obligations.generate') ?? false)
            || ($this->user()?->can('obligations.view') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

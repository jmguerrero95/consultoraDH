<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Support\Validation\MoneyWholePesos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record an adjustment.
 *
 * ## The type is a closed list, and `reversal` is not in it
 *
 * `reversal` is what the system writes when somebody undoes something. Accepting it as
 * input would allow a reversal with no target, which `obligation_adjustments`' own
 * `reversal_shape_check` refuses at the database — so the request would accept a value it
 * can never store, and the failure would arrive as a constraint error rather than a field
 * error.
 *
 * `Rule::enum` is used rather than a hand-written `in:` list, so the accepted values and
 * the enum cannot drift apart, and an unknown type is a 422 naming this field instead of a
 * `ValueError` from `AdjustmentType::from()`.
 *
 * ## The direction is not decided here
 *
 * The sign belongs to the type, and the domain enforces it in
 * `AdjustmentType::assertDirection()` — a discount must reduce, a surcharge must increase,
 * a correction may do either. The interface is told the rule through the vocabulary
 * endpoint rather than being asked to reimplement it, and `MoneyWholePesos` refuses
 * centavos rather than accepting `235000.50` and storing `235000`.
 */
final class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('obligations.adjust') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Explicit allowed values, derived from the enum's own `selectable()`.
            //
            // §47 permits `Rule::enum` or an explicit list; the explicit list is used here
            // because the permitted set is `selectable()`, which is the enum **minus** the
            // system-only reversal case. `Rule::enum(...)->only()` narrows a full enum
            // check, and expressing "four of the five cases" that way was ambiguous enough
            // to reject values that are plainly in the list.
            //
            // The list is computed from the enum rather than written out, so a fourth
            // selectable type cannot exist in the domain and be missing from validation.
            'type' => [
                'required',
                'string',
                Rule::in(array_column(AdjustmentType::selectable(), 'value')),
            ],
            'delta_cop' => ['required', 'integer', 'not_in:0', new MoneyWholePesos],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Debe indicar el tipo de ajuste.',
            'type.in' => 'Ese tipo de ajuste no existe. Use corrección, descuento, recargo o crédito.',
            'delta_cop.required' => 'Debe indicar el valor del ajuste.',
            'delta_cop.not_in' => 'El valor del ajuste no puede ser cero.',
            'reason.required' => 'Debe indicar el motivo del ajuste.',
            'reason.min' => 'El motivo debe tener al menos 10 caracteres: es la explicación que queda en la historia.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo de ajuste',
            'delta_cop' => 'valor del ajuste',
            'reason' => 'motivo',
        ];
    }

    /**
     * Refuse a direction the type does not describe, before the domain sees it.
     *
     * The domain enforces the same rule and is the authority; repeating it here means the
     * operator is told which field to fix rather than receiving an exception from an action.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = AdjustmentType::tryFrom((string) $this->input('type'));

            if ($type === null) {
                return;
            }

            $delta = $this->input('delta_cop');

            if (! is_int($delta) && ! (is_string($delta) && preg_match('/^-?\d+$/', trim($delta)) === 1)) {
                return;
            }

            try {
                $type->assertDirection((int) $delta);
            } catch (AdjustmentRejected $e) {
                $validator->errors()->add('delta_cop', $e->getMessage());
            }
        });
    }

    public function type_(): AdjustmentType
    {
        return AdjustmentType::from((string) $this->validated('type'));
    }
}

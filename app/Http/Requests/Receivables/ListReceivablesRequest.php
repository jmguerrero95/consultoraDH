<?php

declare(strict_types=1);

namespace App\Http\Requests\Receivables;

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\SettlementState;
use App\Domain\Billing\TrafficLight;
use App\Support\Validation\FirstDayOfMonth;
use App\Support\Validation\LooseBoolean;
use App\Support\Validation\SafeSearch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The cartera filters, validated.
 *
 * ## Why a FormRequest for a read
 *
 * The review's §28 is right that `SettlementState::from()`, `AgingBucket::from()` and
 * `Carbon::parse()` were being called on whatever arrived in the query string. An unknown
 * traffic light raised a `ValueError` and became a 500; `period_from=2026-13` threw from
 * Carbon; and an unknown aging bucket did the same. A filter the operator mistyped produced
 * a server error rather than a message naming the field.
 *
 * The controller used `array_filter` over `$request->input(...)`, which also meant `false`
 * and `0` survived as "present", and that is how `overdue=false` came to mean
 * "overdue only" — see `ReceivablesService`.
 *
 * Everything here is **allowlist**: an unknown enum value is a 422 naming the field, never a
 * fallback. The one exception is spelled out in the docblock of `overdue()`, because a
 * boolean genuinely has a "not asked for" state.
 *
 * Cross-field checks live here too, because `period_from > period_to` and
 * `minimum_balance > maximum_balance` are two mistakes in one request and one message is
 * what an operator needs.
 */
final class ListReceivablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('receivables.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200', new SafeSearch],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'period_from' => ['nullable', 'string', new FirstDayOfMonth],
            'period_to' => ['nullable', 'string', new FirstDayOfMonth],
            'settlement_state' => ['nullable', 'string', Rule::in($this->cases(SettlementState::class))],
            'aging_bucket' => ['nullable', 'string', Rule::in($this->cases(AgingBucket::class))],
            // An unknown light is refused. It used to fall through to the `default` arm of a
            // `match`, which is `red` — so a typo made a client look worse than they are,
            // and a filter that cannot be expressed silently became the most severe one.
            'traffic_light' => ['nullable', 'string', Rule::in($this->cases(TrafficLight::class))],
            'minimum_balance' => ['nullable', 'integer', 'min:0'],
            'maximum_balance' => ['nullable', 'integer', 'min:0'],
            'as_of' => ['nullable', 'string', 'date_format:Y-m-d'],
            // `LooseBoolean`, not `boolean`: an unchecked checkbox sends the **string**
            // `false`, which the strict rule refuses. See the rule for why that matters.
            'overdue' => ['nullable', new LooseBoolean],
            'outstanding_only' => ['nullable', new LooseBoolean],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_from.date_format' => 'El periodo inicial debe ser una fecha como 2026-01-01.',
            'period_to.date_format' => 'El periodo final debe ser una fecha como 2026-01-01.',
            'as_of.date_format' => 'La fecha de referencia debe ser una fecha como 2026-04-10.',
            'settlement_state.in' => 'Ese estado de liquidación no existe.',
            'aging_bucket.in' => 'Esa antiguedad no existe.',
            'traffic_light.in' => 'Ese semáforo no existe.',
            'per_page.max' => 'Se pueden pedir como máximo 100 registros por página.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => 'búsqueda',
            'client_id' => 'cliente',
            'company_id' => 'empresa',
            'period_from' => 'periodo inicial',
            'period_to' => 'periodo final',
            'settlement_state' => 'estado de liquidación',
            'aging_bucket' => 'antigüedad',
            'traffic_light' => 'semáforo',
            'minimum_balance' => 'saldo mínimo',
            'maximum_balance' => 'saldo máximo',
            'as_of' => 'fecha de referencia',
            'overdue' => 'sólo vencidos',
            'outstanding_only' => 'sólo con saldo',
        ];
    }

    /**
     * Two mistakes in one request deserve one message.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('period_from');
            $to = $this->input('period_to');

            if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
                $fromMonth = substr(trim($from), 0, 7);
                $toMonth = substr(trim($to), 0, 7);

                if ($fromMonth > $toMonth) {
                    $validator->errors()->add(
                        'period_from',
                        'El periodo inicial no puede ser posterior al periodo final.',
                    );
                }
            }

            $minimum = $this->input('minimum_balance');
            $maximum = $this->input('maximum_balance');

            if (is_numeric($minimum) && is_numeric($maximum) && (int) $minimum > (int) $maximum) {
                $validator->errors()->add(
                    'minimum_balance',
                    'El saldo mínimo no puede ser mayor que el saldo máximo.',
                );
            }
        });
    }

    /**
     * The validated filters, with `overdue` reduced to "true or not asked for".
     *
     * The distinction is the whole of §21. `overdue=false` means **no restriction**, not
     * "only overdue": an unchecked checkbox on the screen sends the literal string `false`,
     * and the earlier code applied the filter whenever the key was present, so the default
     * cartera screen — the one with the box unchecked — showed only debtors already past
     * their due date and hid everybody who was not yet late.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $filters = [];

        foreach ($validated as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $filters[$key] = $value;
        }

        // Only a **true** narrows the list. Absent and false are the same question: show
        // everything.
        unset($filters['overdue']);

        if (LooseBoolean::toBool($this->input('overdue')) === true) {
            $filters['overdue'] = true;
        }

        // Cartera is a list of debtors. The default keeps it that way; `outstanding_only=false`
        // is an explicit request to include settled clients, and the caller that asks for it
        // also gets a `total` that counts the same population (§27).
        // Absent means the debtor-list default; an explicit false means "include settled
        // clients too". `toBool()` returning null means "not a boolean", which is the
        // absent case.
        $filters['outstanding_only'] = LooseBoolean::toBool($this->input('outstanding_only')) ?? true;

        return $filters;
    }

    public function page(): int
    {
        return max(1, (int) $this->validated('page', 1));
    }

    public function perPage(): int
    {
        return max(1, min(100, (int) $this->validated('per_page', 25)));
    }

    /**
     * @param  class-string  $enum
     * @return list<string>
     */
    private function cases(string $enum): array
    {
        return array_column($enum::cases(), 'value');
    }
}

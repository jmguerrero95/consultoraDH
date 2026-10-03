<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffRuleInUse;
use App\Domain\Billing\CutoffScope;
use App\Domain\Billing\Events\CutoffRuleSaved;
use App\Domain\Billing\Events\RateSaved;
use App\Domain\Billing\RateInUse;
use App\Domain\Billing\RateResolver;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Receivables\ObligationPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreAdjustmentRequest;
use App\Http\Requests\Billing\StoreCutoffRuleRequest;
use App\Http\Requests\Billing\StoreRateRequest;
use App\Models\Client;
use App\Models\ClientCompanyRate;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The configuration the billing depends on, and the corrections to obligations.
 *
 * Cutoff rules and rates are configuration, so they have their own permissions:
 * knowing that a client is paid 235000 is reading the configuration, and deciding
 * that it should be 250000 is changing it.
 *
 * Adjustments are here because a correction is the one way to change what a client
 * owes without touching what the system generated.
 */
final class BillingConfigurationController extends Controller
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly ObligationPresenter $presenter,
    ) {}

    // --- cutoff rules ---------------------------------------------------

    public function cutoffRules(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('cutoffs.view'), 403, 'No tiene permisos para ver las fechas de corte.');

        $rules = CutoffRule::query()
            ->with(['company', 'client'])
            ->when($request->input('scope'), fn ($query, $scope) => $query->where('scope', $scope))
            ->when($request->input('company_id'), fn ($query, $id) => $query->where('company_id', $id))
            ->when($request->input('client_id'), fn ($query, $id) => $query->where('client_id', $id))
            ->orderBy('effective_month')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'items' => $rules->getCollection()->map(fn (CutoffRule $rule): array => $this->describeRule($rule))->all(),
            'pagination' => [
                'total' => $rules->total(),
                'per_page' => $rules->perPage(),
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
            ],
            'scopes' => array_map(
                fn (CutoffScope $scope): array => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                    'requires_company' => $scope->requiresCompany(),
                    'requires_client' => $scope->requiresClient(),
                ],
                CutoffScope::cases(),
            ),
            'offsets' => array_map(
                fn (CutoffMonthOffset $offset): array => ['value' => $offset->value, 'label' => $offset->label()],
                CutoffMonthOffset::cases(),
            ),
        ]);
    }

    public function storeCutoffRule(StoreCutoffRuleRequest $request): JsonResponse
    {
        $scope = CutoffScope::from((string) $request->validated('scope'));

        try {
            $rule = CutoffRule::query()->create([
                'scope' => $scope->value,
                'company_id' => $scope->requiresCompany() ? $request->validated('company_id') : null,
                'client_id' => $scope->requiresClient() ? $request->validated('client_id') : null,
                'effective_month' => $request->validated('effective_month'),
                'cutoff_day' => (int) $request->validated('cutoff_day'),
                'month_offset' => (int) $request->validated('month_offset'),
                'notes' => $request->validated('notes'),
                'created_by' => $request->user()->id,
            ]);
        } catch (QueryException $e) {
            // Two rules for the same scope and month would make the resolver's answer
            // depend on insertion order, which is not something anybody should have to
            // know to predict a due date.
            foreach ([
                SchemaConstraint::CUTOFF_RULE_GENERAL_MONTH,
                SchemaConstraint::CUTOFF_RULE_COMPANY_MONTH,
                SchemaConstraint::CUTOFF_RULE_CLIENT_MONTH,
            ] as $constraint) {
                if (UniqueViolation::isFor($e, $constraint)) {
                    return response()->json([
                        'message' => 'Ya existe una regla de este alcance para ese mes de vigencia. '
                            .'Cree una regla con un mes posterior en lugar de modificar la anterior.',
                        'code' => 'duplicate_cutoff_rule',
                    ], 409);
                }
            }

            throw $e;
        }

        event(new CutoffRuleSaved($rule, $request->user(), AuditAction::CutoffRuleCreated));

        return response()->json([
            'message' => 'Se creó la fecha de corte.',
            'rule' => $this->describeRule($rule->refresh()),
        ], 201);
    }

    public function updateCutoffRule(
        Request $request,
        CutoffRule $rule
    ): JsonResponse {
        abort_unless($request->user()?->can('cutoffs.manage'), 403, 'No tiene permisos para modificar las fechas de corte.');

        $data = $request->validate([
            'effective_month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}-01$/'],
            'cutoff_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'month_offset' => ['sometimes', 'integer', 'min:0', 'max:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $changed = [];

        foreach ($data as $field => $value) {
            if ((string) $rule->{$field} !== (string) $value) {
                $changed[] = $field;
            }
        }

        // Moving a rule to a different effective month is not an edit. It changes
        // which months the rule answers for, which is what a new rule is for, and the
        // months in between would quietly lose their cutoff. A later effective month
        // is the only move that makes sense, so anything else is refused outright.
        if (in_array('effective_month', $changed, true)) {
            $moved = MonthValue::fromKey(substr((string) $data['effective_month'], 0, 7));

            if ($moved->key() < $rule->month()->key()) {
                return response()->json([
                    'message' => 'Una fecha de corte no se puede mover a un mes de vigencia anterior. '
                        .'Cree una fecha de corte nueva; las decisiones se corrigen hacia adelante.',
                    'code' => 'effective_month_must_not_go_backwards',
                ], 422);
            }
        }

        try {
            CutoffRuleInUse::assertEditable($rule, $changed);
        } catch (CutoffRuleInUse $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'cutoff_rule_in_use',
                'affected_periods' => CutoffRuleInUse::monthsAffectedBy($rule),
            ], 409);
        }

        $rule->fill($data)->save();

        event(new CutoffRuleSaved($rule->refresh(), $request->user(), AuditAction::CutoffRuleUpdated, $changed));

        return response()->json([
            'message' => 'Se actualizó la fecha de corte.',
            'rule' => $this->describeRule($rule->refresh()),
        ]);
    }

    // --- rates ----------------------------------------------------------

    public function rates(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('rates.view'), 403, 'No tiene permisos para ver los valores.');

        $query = ClientCompanyRate::query()->with(['client', 'company']);

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', (int) $clientId);
        }

        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', (int) $companyId);
        }

        $rates = $query->orderByDesc('client_id')
            ->orderByDesc('company_id')
            ->orderByDesc('effective_month')
            ->paginate(50);

        return response()->json([
            'items' => $rates->getCollection()->map(fn (ClientCompanyRate $rate): array => $this->describeRate($rate))->all(),
            'pagination' => [
                'total' => $rates->total(),
                'per_page' => $rates->perPage(),
                'current_page' => $rates->currentPage(),
                'last_page' => $rates->lastPage(),
            ],
        ]);
    }

    public function storeRate(StoreRateRequest $request): JsonResponse
    {
        try {
            $rate = ClientCompanyRate::query()->create([
                'client_id' => $request->validated('client_id'),
                'company_id' => $request->validated('company_id'),
                'effective_month' => $request->validated('effective_month'),
                'amount_cop' => (int) $request->validated('amount_cop'),
                'notes' => $request->validated('notes'),
                'created_by' => $request->user()->id,
            ]);
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::RATE_CLIENT_COMPANY_MONTH)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ya existe un valor configurado para ese cliente, esa empresa y ese mes. '
                    .'Para cambiarlo, cree un valor con un mes posterior.',
                'code' => 'duplicate_rate',
            ], 409);
        }

        event(new RateSaved($rate, $request->user(), AuditAction::RateCreated));

        return response()->json([
            'message' => 'Se configuró el valor mensual.',
            'rate' => $this->describeRate($rate->refresh()),
        ], 201);
    }

    public function updateRate(Request $request, ClientCompanyRate $rate): JsonResponse
    {
        abort_unless($request->user()?->can('rates.manage'), 403, 'No tiene permisos para modificar los valores.');

        $data = $request->validate([
            'amount_cop' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // A rate an obligation quotes is evidence of what was billed. Changing it
        // would change what the system said it had generated, which is the silent
        // recalculation this module exists to prevent. The way to change a value is a
        // new row with a later effective month.
        if (isset($data['amount_cop']) && $data['amount_cop'] !== $rate->amount_cop) {
            try {
                RateInUse::assertEditable($rate, $request->user());
            } catch (RateInUse $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'rate_in_use',
                ], 409);
            }
        }

        $changed = [];

        foreach ($data as $field => $value) {
            if ((string) $rate->{$field} !== (string) $value) {
                $changed[] = $field;
            }
        }

        $rate->fill($data)->save();

        event(new RateSaved($rate->refresh(), $request->user(), AuditAction::RateUpdated, $changed));

        return response()->json([
            'message' => 'Se actualizó el valor mensual.',
            'rate' => $this->describeRate($rate->refresh()),
        ]);
    }

    /**
     * The rate history for one client and company, with the current resolved value.
     */
    public function rateHistory(Request $request, Client $client): JsonResponse
    {
        abort_unless($request->user()?->can('rates.view'), 403, 'No tiene permisos para ver los valores.');

        $companyId = (int) $request->integer('company_id');
        $history = $this->rates->historyFor($client->id, $companyId);

        $current = $this->rates->resolve($client->id, $companyId, MonthValue::fromFirstDay(now()));

        return response()->json([
            'client_id' => $client->id,
            'company_id' => $companyId,
            'current' => $current === null ? null : $this->describeRate($current),
            'history' => $history->map(fn (ClientCompanyRate $rate): array => $this->describeRate($rate))->all(),
        ]);
    }

    // --- adjustments ----------------------------------------------------

    public function storeAdjustment(
        StoreAdjustmentRequest $request,
        MonthlyObligation $obligation
    ): JsonResponse {
        try {
            $adjustment = app(AdjustObligation::class)->execute(
                $obligation,
                AdjustmentType::from((string) $request->validated('type')),
                (int) $request->validated('delta_cop'),
                (string) $request->validated('reason'),
                $request->user(),
            );
        } catch (AdjustmentRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'adjustment_rejected',
                'reason_code' => $e->reasonCode,
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Se registró el ajuste de %s pesos.',
                number_format($adjustment->delta_cop, 0, ',', '.'),
            ),
            'adjustment' => $this->describeAdjustment($adjustment),
            'obligation' => $this->presenter->describe($obligation->refresh()),
        ], 201);
    }

    public function reverseAdjustment(
        Request $request,
        ObligationAdjustment $adjustment
    ): JsonResponse {
        abort_unless($request->user()?->can('obligations.adjust'), 403, 'No tiene permisos para revertir ajustes.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirm' => ['required', 'accepted'],
        ]);

        try {
            $reversal = app(AdjustObligation::class)->reverse(
                $adjustment,
                (string) $data['reason'],
                $request->user(),
            );
        } catch (AdjustmentRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'adjustment_rejected',
                'reason_code' => $e->reasonCode,
            ], 422);
        }

        return response()->json([
            'message' => 'Se revirtió el ajuste. La obligación conserva el registro original.',
            'adjustment' => $this->describeAdjustment($reversal),
            'obligation' => $this->presenter->describe($adjustment->obligation->refresh()),
        ], 201);
    }

    public function adjustments(Request $request, MonthlyObligation $obligation): JsonResponse
    {
        abort_unless($request->user()?->can('obligations.view'), 403, 'No tiene permisos para ver los ajustes.');

        $adjustments = ObligationAdjustment::query()
            ->where('obligation_id', $obligation->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'items' => $adjustments->map(fn (ObligationAdjustment $a): array => $this->describeAdjustment($a))->all(),
            'adjustments_total_cop' => (int) $adjustments->sum('delta_cop'),
        ]);
    }

    // --- describe -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function describeRule(CutoffRule $rule): array
    {
        $example = MonthValue::fromFirstDay(now());

        return [
            'id' => $rule->id,
            'scope' => $rule->scope->value,
            'scope_label' => $rule->scope->label(),
            'company_id' => $rule->company_id,
            'company_name' => $rule->company?->displayName(),
            'client_id' => $rule->client_id,
            'client_name' => $rule->client?->fullName(),
            'effective_month' => $rule->month()->key(),
            'effective_month_label' => $rule->month()->label(),
            // Whether a generated month already quotes this rule. The server refuses
            // to change the day of one that has; saying so here is what lets the
            // screen disable the field and explain, instead of letting the operator
            // fill it in and then refusing.
            'in_use' => MonthlyObligation::query()->where('cutoff_rule_id', $rule->id)->exists(),
            'cutoff_day' => $rule->cutoff_day,
            'month_offset' => $rule->month_offset->value,
            'month_offset_label' => $rule->month_offset->label(),
            // A worked example, computed by the same code that will produce the real
            // due date, so the screen cannot show a date the system would not use.
            'example_due_on' => $rule->resolveFor($example)->format('Y-m-d'),
            'example_period' => $example->key(),
            'notes' => $rule->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRate(ClientCompanyRate $rate): array
    {
        return [
            'id' => $rate->id,
            'client_id' => $rate->client_id,
            'client_name' => $rate->client?->fullName(),
            'company_id' => $rate->company_id,
            'company_name' => $rate->company?->displayName(),
            'effective_month' => $rate->month()->key(),
            'effective_month_label' => $rate->month()->label(),
            'in_use' => MonthlyObligation::query()->where('rate_id', $rate->id)->exists(),
            'amount_cop' => $rate->amount_cop,
            'notes' => $rate->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeAdjustment(ObligationAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'obligation_id' => $adjustment->obligation_id,
            'type' => $adjustment->type->value,
            'type_label' => $adjustment->type->label(),
            'delta_cop' => $adjustment->delta_cop,
            'reason' => $adjustment->reason,
            'reverses_adjustment_id' => $adjustment->reverses_adjustment_id,
            // Whether this adjustment is still in force. Without it the screen cannot
            // tell a live correction from a cancelled one, and would offer to reverse
            // it a second time.
            'reversed_at' => $adjustment->reversed_at?->toIso8601String(),
            'reversed_by' => $adjustment->reversed_by,
            'reversal_reason' => $adjustment->reversal_reason,
            'created_at' => $adjustment->created_at?->toIso8601String(),
        ];
    }
}

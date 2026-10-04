<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\Actions\ManageBillingConfiguration;
use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffRuleInUse;
use App\Domain\Billing\CutoffScope;
use App\Domain\Billing\RateInUse;
use App\Domain\Billing\RateRejected;
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
use App\Support\Validation\FirstDayOfMonth;
use App\Support\Validation\SafeSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $this->batchCutoffInUse($rules->getCollection()->all());

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
        try {
            $rule = app(ManageBillingConfiguration::class)->createCutoffRule(
                $request->safe()->only([
                    'scope', 'company_id', 'client_id', 'effective_month',
                    'cutoff_day', 'month_offset', 'notes',
                ]),
                $request->user(),
            );
        } catch (RateRejected $e) {
            // The action owns the rules and the conflict translation; this only chooses the
            // status code. A refusal the operator can fix is a 422, one that means "that
            // decision already exists" is a 409.
            return $this->configurationFailure($e);
        }

        return response()->json([
            'message' => 'Se creó la fecha de corte.',
            'rule' => $this->describeRule($rule->refresh()),
        ], 201);
    }

    public function updateCutoffRule(
        Request $request,
        CutoffRule $rule,
    ): JsonResponse {
        $data = $request->validate([
            'effective_month' => ['sometimes', 'string', new FirstDayOfMonth],
            'cutoff_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'month_offset' => ['sometimes', 'integer', 'min:0', 'max:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = app(ManageBillingConfiguration::class)->updateCutoffRule(
                $rule,
                $data,
                $request->user(),
            );
        } catch (CutoffRuleInUse $e) {
            // Still reachable and still specific: the in-use check names the periods a rule
            // has already produced dates for, which is more useful than a generic conflict.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'cutoff_rule_in_use',
                'affected_periods' => CutoffRuleInUse::monthsAffectedBy($rule),
            ], 409);
        } catch (RateRejected $e) {
            return $this->configurationFailure($e);
        }

        return response()->json([
            'message' => 'Se actualizó la fecha de corte.',
            'rule' => $this->describeRule($updated->refresh()),
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

        // §32, same as the cutoff list: the interface sent `search` and nothing read it.
        if ($term = $this->searchTerm($request)) {
            $this->applySearch($query, $term, [
                'clients.first_names', 'clients.last_names', 'clients.document_number',
                'companies.legal_name',
            ]);
        }

        $rates = $query->orderByDesc('client_id')
            ->orderByDesc('company_id')
            ->orderByDesc('effective_month')
            // §33: bounded and navigable, rather than a silent first-fifty.
            ->paginate($this->perPage($request));

        $this->batchRateInUse($rates->getCollection()->all());

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
            $rate = app(ManageBillingConfiguration::class)->createRate(
                $request->safe()->only(['client_id', 'company_id', 'effective_month', 'amount_cop', 'notes']),
                $request->user(),
            );
        } catch (RateRejected $e) {
            return $this->configurationFailure($e);
        }

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

        try {
            $updated = app(ManageBillingConfiguration::class)->updateRate(
                $rate,
                $data,
                $request->user(),
            );
        } catch (RateInUse $e) {
            // The amount a generated obligation quotes is evidence of what was billed.
            // Changing it would change what the system said it generated, which is the
            // silent recalculation this module exists to prevent. The way to change a value
            // is a new row with a later effective month.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'rate_in_use',
            ], 409);
        } catch (RateRejected $e) {
            return $this->configurationFailure($e);
        }

        return response()->json([
            'message' => 'Se actualizó el valor mensual.',
            'rate' => $this->describeRate($updated->refresh()),
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

    /**
     * The adjustment types an operator may choose, with the direction each one accepts.
     *
     * §20. The interface kept its own list of three types and omitted `credit`, so the type
     * the API accepted was not the type the screen offered. Publishing the vocabulary from
     * the same enum the domain enforces makes the two impossible to disagree about.
     *
     * The direction is published because the screen has to stop forcing a sign. It used to
     * negate a discount by hand and force everything else positive, which is how a negative
     * correction became impossible to enter and a surcharge typed as `-50000` became
     * `+50000`. With the direction published, the screen can offer the right control
     * without reimplementing the rule.
     */
    public function adjustmentVocabulary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('obligations.view'), 403, 'No tiene permisos para ver los ajustes.');

        return response()->json([
            'types' => array_map(static fn (AdjustmentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'direction' => match (true) {
                    $type->mustReduce() => 'decrease',
                    $type->mustIncrease() => 'increase',
                    default => 'either',
                },
            ], AdjustmentType::selectable()),
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
                $request->type_(),
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
            'adjustment' => $this->describeAdjustment($adjustment->load('reversedBy')),
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
            'adjustment' => $this->describeAdjustment($reversal->load('reversedBy')),
            'obligation' => $this->presenter->describe($adjustment->obligation->refresh()),
        ], 201);
    }

    public function adjustments(Request $request, MonthlyObligation $obligation): JsonResponse
    {
        abort_unless($request->user()?->can('obligations.view'), 403, 'No tiene permisos para ver los ajustes.');

        $adjustments = ObligationAdjustment::query()
            ->where('obligation_id', $obligation->id)
            ->orderBy('id')
            // One relation for the whole list. Describing each row's reversal state on its own
            // would be a query per adjustment, and the history of an obligation is exactly the
            // list that grows.
            ->with('reversedBy')
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
    /**
     * Turn a configuration refusal into a status code.
     *
     * The action decides what is refused and why; this decides only how to say it over
     * HTTP.
     *
     * `409` only for the two cases where a **decision already exists** for the same scope
     * and month, because retrying the identical request will always fail and the fix is a
     * different request: a new decision with a later effective month. `422` for everything
     * else, including a request to move a rule's effective month backwards — that is a
     * malformed request rather than a collision, and A03 has always answered it as one.
     */
    private function configurationFailure(RateRejected $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->reason(),
        ], in_array($e->reason(), [
            'rate_already_configured',
            'cutoff_rule_already_configured',
        ], true) ? 409 : 422);
    }

    /**
     * The search needle, or null when the box is empty.
     *
     * Trimmed and nulled rather than passed through as `''`, because `''` is a pattern that
     * matches everything and would make the query slower than sending no needle at all.
     */
    private function searchTerm(Request $request): ?string
    {
        $search = $request->input('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    /**
     * Restrict a configuration query to a set of related columns.
     *
     * `LIKE` with an escaped, case-folded needle and an explicit `ESCAPE`: an unescaped `%`
     * typed by an operator becomes "anything", so searching `100%` returns every row rather
     * than the one that contains a percent sign. The needle is folded and the columns are
     * folded in SQL, which is what makes typing `Ana` match `Ana María`.
     *
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, string $term, array $columns): void
    {
        $needle = SafeSearch::likeNeedle($term);
        $escape = SafeSearch::likeEscape();

        $query->where(function (Builder $inner) use ($columns, $needle, $escape): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw("lower({$column}) LIKE ?{$escape}", [$needle]);
            }
        });
    }

    /**
     * A bounded page size.
     *
     * Bounded so a request cannot ask for the whole configuration history, which is exactly
     * what §33 warns against: the alternative to navigating pages is not a bigger page, it is
     * fetching thousands of historical rows to avoid a pager.
     */
    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 50)));
    }

    /**
     * The ids on this page whose configuration a generated obligation already quotes.
     *
     * §34. `in_use` was an `exists()` per row, so a fifty-row page cost fifty extra
     * queries — and the flag is what tells the interface whether a day or an amount may be
     * edited, so it has to be correct on every row rather than guessed.
     *
     * Two grouped queries for the page. `null` means "not batched", which is the case for a
     * single row fetched on its own (a create or update response), where one `exists()` is
     * cheaper than materialising a set.
     */
    private ?Collection $inUseCutoffIds = null;

    private ?Collection $inUseRateIds = null;

    /**
     * @param  list<CutoffRule>  $rules
     */
    private function batchCutoffInUse(array $rules): void
    {
        $ids = array_map(static fn (CutoffRule $rule): int => $rule->id, $rules);

        $this->inUseCutoffIds = $ids === []
            ? collect()
            : MonthlyObligation::query()
                ->whereIn('cutoff_rule_id', $ids)
                ->distinct()
                ->pluck('cutoff_rule_id')
                ->map(static fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  list<ClientCompanyRate>  $rates
     */
    private function batchRateInUse(array $rates): void
    {
        $ids = array_map(static fn (ClientCompanyRate $rate): int => $rate->id, $rates);

        $this->inUseRateIds = $ids === []
            ? collect()
            : MonthlyObligation::query()
                ->whereIn('rate_id', $ids)
                ->distinct()
                ->pluck('rate_id')
                ->map(static fn (mixed $id): int => (int) $id);
    }

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
            'in_use' => $this->inUseCutoffIds === null
                ? MonthlyObligation::query()->where('cutoff_rule_id', $rule->id)->exists()
                : $this->inUseCutoffIds->contains($rule->id),
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
            'in_use' => $this->inUseRateIds === null
                ? MonthlyObligation::query()->where('rate_id', $rate->id)->exists()
                : $this->inUseRateIds->contains($rate->id),
            'amount_cop' => $rate->amount_cop,
            'notes' => $rate->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One adjustment, with its reversal state derived from the ledger rather than from
     * marker columns that do not exist.
     *
     * The published shape carried `reversed_at`, `reversed_by` and `reversal_reason`. There
     * is no such column on this table: a reversal is a **row** with
     * `reverses_adjustment_id` pointing back, which is the design the migration comment
     * describes and the right one. So those three keys were always null, and an original
     * that had been undone looked identical to one that had not — the history screen showed
     * it as vigente and offered a second "Revertir" that the unique index then refused.
     *
     * What is published now is the truth of the ledger:
     *
     *     is_reversal               this row undoes another
     *     is_reversed               some row undoes this one
     *     reversed_by_adjustment_id that row's id
     *     reversal_created_at       when it was written
     *     reversal_reason           why, which lives on the reversal row
     *     can_reverse               whether a second reversal is even possible
     *
     * `can_reverse` is published rather than left for the interface to derive from two
     * booleans, because deriving it wrongly is exactly the bug being closed.
     *
     * @return array<string, mixed>
     */
    private function describeAdjustment(ObligationAdjustment $adjustment): array
    {
        $reversal = $adjustment->reversal();

        return [
            'id' => $adjustment->id,
            'obligation_id' => $adjustment->obligation_id,
            'type' => $adjustment->type->value,
            'type_label' => $adjustment->type->label(),
            'delta_cop' => $adjustment->delta_cop,
            'reason' => $adjustment->reason,
            'reverses_adjustment_id' => $adjustment->reverses_adjustment_id,
            'is_reversal' => $adjustment->isReversal(),
            'is_reversed' => $reversal !== null,
            'reversed_by_adjustment_id' => $reversal?->id,
            'reversal_created_at' => $reversal?->created_at?->toIso8601String(),
            'reversal_reason' => $reversal?->reason,
            // A reversal row is not itself reversible, and an undone original is not
            // reversible twice. Both facts are on the row, published under one honest name.
            'can_reverse' => $adjustment->canBeReversed(),
            'is_active' => $adjustment->isActive(),
            'created_at' => $adjustment->created_at?->toIso8601String(),
        ];
    }
}

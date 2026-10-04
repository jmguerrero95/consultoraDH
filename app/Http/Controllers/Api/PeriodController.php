<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Billing\Actions\GenerationBlocked;
use App\Domain\Billing\GenerationResult;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Periods\Actions\ClosePeriod;
use App\Domain\Periods\Actions\CreatePeriod;
use App\Domain\Periods\Actions\ReopenPeriod;
use App\Domain\Periods\ClosePeriodBlocked;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodAlreadyExists;
use App\Domain\Periods\PeriodAlreadyOpen;
use App\Domain\Periods\PeriodIsClosed;
use App\Domain\Periods\ReopenReasonRequired;
use App\Domain\Receivables\ObligationPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Periods\ClosePeriodRequest;
use App\Http\Requests\Periods\GenerateObligationsRequest;
use App\Http\Requests\Periods\ListPeriodObligationsRequest;
use App\Http\Requests\Periods\PreviewObligationsRequest;
use App\Http\Requests\Periods\ReopenPeriodRequest;
use App\Http\Requests\Periods\StorePeriodRequest;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\ObligationAdjustment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly periods, and the obligations generated into them.
 *
 * Thin by design. Every decision about what a period may do, what a generation
 * produces and what it refuses lives in the domain; this translates HTTP into those
 * calls and their refusals into responses.
 *
 * Status is never changed by a generic PATCH. Closing and reopening are actions with
 * their own permissions, their own confirmation and their own audit entries, and a
 * route that let anybody set `status` would make all three of those optional.
 */
final class PeriodController extends Controller
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
        private readonly ObligationCandidateBuilder $candidates,
    ) {}

    /**
     * List periods, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeFinancial($request, 'periods.view');

        $periods = MonthlyPeriod::query()
            ->withCount('obligations')
            ->withSum(['obligations as total_base_cop'], 'base_amount_cop')
            ->orderByDesc('period_month')
            ->paginate($this->perPage($request));

        // The two derived sums the model cannot count for us, for the whole page at
        // once. Summarising each row on its own would run four queries per period,
        // which on a year of months is 196 queries to draw a list, and the cost grows
        // with the history rather than with the page size.
        $withMoney = $this->maySeeMoney($request->user());

        // Only computed when it will be published: a caller without the permission gets no
        // monetary keys, so running two grouped queries per page for figures nobody may see
        // would be paying for a secret.
        $totals = $withMoney
            ? $this->periodTotals(
                $periods->getCollection()->map(fn (MonthlyPeriod $period): int => $period->id)->all(),
            )
            : [];

        // The current period travels with the list because the screen shows it in its
        // header, above the table. Asking for it separately would be a second request
        // for one value, and the two could disagree if a month were opened between
        // them.
        $current = $this->periods->current();

        return response()->json($this->paginated(
            $periods,
            fn (MonthlyPeriod $period): array => $this->summarise(
                $period,
                totals: $totals[$period->id] ?? null,
                withMoney: $withMoney,
            ),
        ) + [
            'current' => $current === null ? null : $this->summarise($current, detailed: true, withMoney: $withMoney),
        ]);
    }

    /**
     * Adjustments and payments per period, in two grouped queries.
     *
     * @param  list<int>  $periodIds
     * @return array<int, array{adjustments: int, paid: int}>
     */
    private function periodTotals(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        $totals = [];

        foreach ($periodIds as $periodId) {
            $totals[$periodId] = ['adjustments' => 0, 'paid' => 0];
        }

        ObligationAdjustment::query()
            ->selectRaw('monthly_obligations.period_id, sum(obligation_adjustments.delta_cop) as total')
            ->join('monthly_obligations', 'monthly_obligations.id', '=', 'obligation_adjustments.obligation_id')
            ->whereIn('monthly_obligations.period_id', $periodIds)
            ->groupBy('monthly_obligations.period_id')
            ->get()
            ->each(function (object $row) use (&$totals): void {
                $totals[(int) $row->period_id]['adjustments'] = (int) $row->total;
            });

        PaymentAllocation::query()
            ->selectRaw('monthly_obligations.period_id, sum(payment_allocations.amount_cop) as total')
            ->join('monthly_obligations', 'monthly_obligations.id', '=', 'payment_allocations.obligation_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('monthly_obligations.period_id', $periodIds)
            ->whereNull('payment_allocations.reversed_at')
            ->whereNull('payments.voided_at')
            ->groupBy('monthly_obligations.period_id')
            ->get()
            ->each(function (object $row) use (&$totals): void {
                $totals[(int) $row->period_id]['paid'] = (int) $row->total;
            });

        return $totals;
    }

    /**
     * The period the system considers current.
     *
     * The resolution order is documented in `MonthlyPeriodResolver` and is not "the
     * newest row": a period matching this calendar month wins even while an earlier
     * month is still open.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorizeFinancial($request, 'periods.view');

        $period = $this->periods->current();
        $open = $this->periods->openPeriods();

        return response()->json([
            // §5. `withMoney` was left at its default of `true` here and in `show()`, so a
            // role that may see the calendar could also see what the months are worth —
            // `total_base_cop`, `total_paid_cop` and the balance — from the one period the
            // screen asks about, while `index()` correctly withheld them. The gate is the
            // same rule in all four places; it was simply not passed to two of them.
            'current' => $period === null
                ? null
                : $this->summarise($period, withMoney: $this->maySeeMoney($request->user())),
            'has_current' => $period !== null,
            // Sent alongside because "which month is current" is ambiguous for a
            // caller when several are open, and the interface shows the list.
            'open_periods' => $open->map(fn (MonthlyPeriod $p): array => [
                'id' => $p->id,
                'key' => $p->key(),
                'label' => $p->label(),
            ])->all(),
        ]);
    }

    public function show(Request $request, MonthlyPeriod $period): JsonResponse
    {
        $this->authorizeFinancial($request, 'periods.view');

        return response()->json([
            // §5, same omission as in `current()`.
            'period' => $this->summarise(
                $period,
                detailed: true,
                withMoney: $this->maySeeMoney($request->user()),
            ),
        ]);
    }

    public function store(StorePeriodRequest $request): JsonResponse
    {
        try {
            $period = app(CreatePeriod::class)->execute($request->month(), $request->user());
        } catch (PeriodAlreadyExists $e) {
            return $this->failure($e->getMessage(), 'period_already_exists', 409);
        }

        return response()->json([
            'message' => sprintf('El periodo %s quedó abierto.', $period->label()),
            'period' => $this->summarise($period, withMoney: $this->maySeeMoney($request->user())),
        ], 201);
    }

    public function close(ClosePeriodRequest $request, MonthlyPeriod $period): JsonResponse
    {
        try {
            $closed = app(ClosePeriod::class)->execute($period, $request->user());
        } catch (ClosePeriodBlocked $e) {
            // 409 rather than 422: nothing is wrong with the request, the period is
            // not ready, and the blockers say what is missing.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'period_close_blocked',
                'blockers' => $e->blockers,
            ], 409);
        }

        return response()->json([
            'message' => sprintf(
                'El periodo %s quedó cerrado. Las obligaciones ya no se modifican; '
                .'los pagos y los ajustes siguen permitidos.',
                $closed->label(),
            ),
            // §5 (R3). `withMoney` was left at its default of `true`, as it had been in
            // `current()` and `show()` before R2. So holding `periods.close` — which is a
            // structural authority, and deliberately separate from `obligations.view` —
            // was enough to read `total_base_cop`, `total_paid_cop` and the balance of the
            // month being closed.
            //
            // That is the leak §37 exists to prevent, reached through the one endpoint an
            // operator uses at the end of every month. The gate is now the same rule in all
            // seven places a period is published, and it is still `maySeeMoney()`: no second
            // policy, and no change to what `periods.close` itself authorises.
            'period' => $this->summarise(
                $closed,
                detailed: true,
                withMoney: $this->maySeeMoney($request->user()),
            ),
        ]);
    }

    public function reopen(ReopenPeriodRequest $request, MonthlyPeriod $period): JsonResponse
    {
        try {
            $reopened = app(ReopenPeriod::class)->execute($period, $request->user(), $request->reason());
        } catch (ReopenReasonRequired|PeriodAlreadyOpen $e) {
            return $this->failure($e->getMessage(), 'period_reopen_rejected', 409);
        }

        return response()->json([
            'message' => sprintf(
                'El periodo %s quedó abierto. No se regeneró nada: revise la previsualización '
                .'y genere las obligaciones que falten.',
                $reopened->label(),
            ),
            'period' => $this->summarise(
                $reopened,
                detailed: true,
                // §5 (R3). Same omission as in `close()`, and the same gate. Reopening is
                // `periods.reopen`, also a structural authority; reading what the month is
                // worth is `obligations.view`.
                withMoney: $this->maySeeMoney($request->user()),
            ),
        ]);
    }

    /**
     * What generation would produce, without writing anything.
     *
     * Read-only by construction: the candidate builder computes and the request
     * carries no parameters that could change anything. There is no audit entry for
     * this endpoint, because nothing business-visible happened.
     */
    public function previewObligations(PreviewObligationsRequest $request, MonthlyPeriod $period): JsonResponse
    {
        // The preview carries no flag any more: generation is unconditionally
        // missing-only, so there is no second plan to choose between. The existing
        // obligations are shown for context — a preview that hid them would promise a
        // smaller set of writes than the button then performs, which is the one thing a
        // preview must never do — and `will_be_created` on each row says which of them
        // would actually be written.
        return response()->json([
            'period' => $this->summarise($period, withMoney: $this->maySeeMoney($request->user())),
            'preview' => $this->candidates->preview($period),
        ]);
    }

    public function generateObligations(GenerateObligationsRequest $request, MonthlyPeriod $period): JsonResponse
    {
        try {
            $result = app(GeneratePeriodObligations::class)->execute($period, $request->user());
        } catch (GenerationBlocked $e) {
            // Nothing was written. The blockers come back so the operator can fix the
            // configuration and try again, rather than discovering it one client at a
            // time.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'generation_blocked',
                'blocker_codes' => $e->distinctCodes(),
                'blockers' => $e->blockers,
            ], 409);
        } catch (PeriodIsClosed $e) {
            return $this->failure($e->getMessage(), 'period_closed', 409);
        }

        return response()->json([
            // Proper plural, because this is the sentence an operator reads to learn
            // whether the month is done. "1 obligación(es)" is how a system admits it
            // has not decided what it is saying.
            'message' => $this->generationMessage($result),
            'result' => $result->toArray(),
            'period' => $this->summarise(
                $period->refresh(),
                detailed: true,
                withMoney: $this->maySeeMoney($request->user()),
            ),
        ]);
    }

    public function obligations(ListPeriodObligationsRequest $request, MonthlyPeriod $period): JsonResponse
    {
        $this->authorizeFinancial($request, 'obligations.view');

        $obligations = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->with(['client', 'company'])
            ->orderBy('client_id')
            ->orderBy('company_id')
            ->paginate($this->perPage($request));

        // §7, and §4 of R3. `$request->date('as_of')` answered `null` for anything it could
        // not parse, so an unreadable reference date was dropped rather than refused and the
        // month was reported "as of today" — a different answer to the question that was
        // asked. R2 replaced that with a private `abort(422, $message)`, which had the right
        // status and no `errors` envelope; the request class does it properly now, the same
        // way the receivables list and the client account already did.
        $asOf = $request->asOf() ?? now();

        // Presented in one pass, so the adjustments and the payments of a whole page
        // come from two grouped queries rather than two for every row.
        $described = $this->obligationsService()
            ->describeMany($obligations->getCollection(), $asOf)
            ->keyBy('id');

        return response()->json($this->paginated(
            $obligations,
            fn (MonthlyObligation $obligation): array => $described[$obligation->id],
        ));
    }

    /**
     * The sentence an operator reads after generation.
     *
     * Separate from the response so the plural is written once, and written with one
     * placeholder per branch: a branch with two placeholders and one argument prints
     * the count where the month belongs.
     */
    private function generationMessage(GenerationResult $result): string
    {
        if ($result->created === 0) {
            return sprintf('No había obligaciones nuevas que generar para %s.', $result->periodKey);
        }

        if ($result->created === 1) {
            return sprintf('Se generó 1 obligación para %s.', $result->periodKey);
        }

        return sprintf('Se generaron %d obligaciones para %s.', $result->created, $result->periodKey);
    }

    // --- helpers --------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{adjustments: int, paid: int}|null  $totals  precomputed for a list
     */
    /**
     * A period as the caller is allowed to see it.
     *
     * ## The monetary keys are obligations, not period metadata
     *
     * §37. `periods.view` publishes who may see that a month exists and what state it is in.
     * It does not publish what the month is worth: `obligation_count`,
     * `total_base_cop`, `total_effective_cop`, `total_paid_cop` and `total_balance_cop` are
     * all derived from obligations, and A03 separates read authorities precisely so that
     * knowing what a client owes is not implied by being able to see a calendar.
     *
     * So the five keys are **omitted** when the caller lacks `obligations.view`, rather than
     * sent as zero. A zero would be a lie in the other direction: it would say the month is
     * worth nothing, which is a financial statement this permission does not entitle anybody
     * to. Absent means "not published", and the interface renders no column.
     *
     * The aggregates themselves are only computed when they will be published, so a caller
     * without the permission does not pay for two subqueries per row either.
     *
     * @param  array{adjustments: int, paid: int}|null  $totals  precomputed for a list
     * @return array<string, mixed>
     */
    /**
     * One period, as the screen shows it.
     *
     * `withMoney` defaults to **false**.
     *
     * It used to default to `true`, which made this helper fail *open*: a call site that
     * forgot the argument published `total_base_cop`, `total_effective_cop`, `total_paid_cop`,
     * `total_balance_cop` and `obligation_count`. Three separate rounds have now found a leak
     * of exactly that shape — R2 on `current()` and `show()`, R3 on `close()` and `reopen()` —
     * and every one of them was a forgotten argument rather than a wrong gate.
     *
     * The argument is therefore not something a caller may omit. The omission has to mean
     * "publish nothing", so that a mistake costs a missing key on a screen that then renders
     * without a figure, rather than financial data reaching a role that may not read it.
     *
     * `maySeeMoney()` is the only thing that should decide, and it is passed explicitly at
     * every call site. `PeriodMoneyBoundaryTest` additionally reads this file's source and
     * fails on any `summarise()` call that omits it; that check is kept because it catches the
     * caller that passes the argument but passes it wrongly, but it is a backstop, not the
     * invariant. The invariant is the default.
     */
    private function summarise(MonthlyPeriod $period, bool $detailed = false, ?array $totals = null, bool $withMoney = false): array
    {
        $summary = [
            'id' => $period->id,
            'key' => $period->key(),
            'label' => $period->label(),
            'period_month' => $period->period_month->format('Y-m-d'),
            'starts_on' => $period->month()->startsOn()->format('Y-m-d'),
            'ends_on_exclusive' => $period->month()->endsOnExclusive()->format('Y-m-d'),
            'status' => $period->status->value,
            'status_label' => $period->status->label(),
            'opened_at' => $period->opened_at?->toIso8601String(),
            'closed_at' => $period->closed_at?->toIso8601String(),
            'reopened_at' => $period->reopened_at?->toIso8601String(),
            'last_reopen_reason' => $period->last_reopen_reason,
            // Generation metadata is about the period itself, not about what was billed, so
            // it stays with `periods.view`.
            'generation_performed_at' => $period->generation_performed_at?->toIso8601String(),
        ];

        if ($withMoney) {
            $base = (int) ($period->total_base_cop ?? $period->obligations()->sum('base_amount_cop'));
            $adjustments = $totals['adjustments'] ?? (int) ObligationAdjustment::query()
                ->whereIn('obligation_id', $period->obligations()->select('id'))
                ->sum('delta_cop');
            $paid = $totals['paid'] ?? (int) PaymentAllocation::query()
                ->whereIn('obligation_id', $period->obligations()->select('id'))
                ->whereNull('reversed_at')
                ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
                ->sum('amount_cop');

            $effective = $base + $adjustments;

            $summary += [
                'obligation_count' => (int) ($period->obligations_count ?? $period->obligations()->count()),
                'total_base_cop' => $base,
                'total_effective_cop' => $effective,
                'total_paid_cop' => $paid,
                'total_balance_cop' => $effective - $paid,
            ];
        }

        if ($detailed) {
            $summary += [
                'accepts_structural_change' => $period->isOpen(),
                // Spelled out because "closed" reads like "untouchable" and it is not:
                // money still moves against a closed month.
                'accepts_financial_activity' => $period->status->acceptsFinancialActivity(),
            ];
        }

        return $summary;
    }

    /**
     * Whether this caller may see a period's monetary figures.
     *
     * A method rather than an inline `can()`, because four endpoints answer "how much is
     * this month worth" and the rule has to be the same rule in all four.
     */
    private function maySeeMoney(?User $user): bool
    {
        // Either obligations authority qualifies.
        //
        // `obligations.generate` counts because somebody who may write a month's obligations
        // has to be able to see what they wrote and what they are about to write — the
        // generation preview exists to show them amounts before they commit to them, so
        // withholding the figures would make the authority unusable.
        //
        // `periods.view` alone does not. That is the whole point of §37: a role that may see
        // the calendar is not thereby entitled to what the months are worth.
        return ($user?->can('obligations.view') ?? false) || ($user?->can('obligations.generate') ?? false);
    }

    private function obligationsService(): ObligationPresenter
    {
        return new ObligationPresenter;
    }

    private function authorizeFinancial(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'No tiene permisos para acceder a esta sección.');
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 25);

        return max(1, min(100, $perPage));
    }

    /**
     * @template TValue
     *
     * @param  LengthAwarePaginator<MonthlyPeriod, TValue>  $paginator
     * @param  callable(MonthlyPeriod): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function paginated(object $paginator, callable $map): array
    {
        return [
            'items' => $paginator->getCollection()->map($map)->values()->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}

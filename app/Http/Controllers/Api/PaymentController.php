<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Payments\AllocationPlanner;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentRejected;
use App\Domain\Payments\ReconciliationState;
use App\Domain\Receivables\ObligationPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\AllocatePaymentRequest;
use App\Http\Requests\Payments\ListPaymentsRequest;
use App\Http\Requests\Payments\ReverseAllocationRequest;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\VoidPaymentRequest;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Validation\SafeSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payments, and the allocations that say what they pay for.
 *
 * Thin. Every rule about money here belongs to `ManagePayments`, and every refusal
 * it raises is translated into a 422 with a `reason_code` the interface can branch
 * on. A refusal is not an error condition: paying an amount larger than the remaining
 * balance is a mistake somebody will make, and the answer should say which mistake.
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly ManagePayments $payments,
        private readonly ObligationPresenter $presenter,
    ) {}

    public function index(ListPaymentsRequest $request): JsonResponse
    {
        // Authorization lives on the FormRequest, and the filters arrive validated and
        // allowlisted: an unknown state or an impossible date is a 422 naming the field.

        // The allocated total is selected as an alias so the state filter can be
        // expressed against it. The reconciliation state is derived from the payment
        // amount and that sum; it is not a column, so filtering it means comparing
        // the two numbers rather than naming a state that does not exist in the
        // table.
        // Excludes voided payments for the same reason the model does: the applied
        // total of a voided payment is zero, so the filter and the row agree.
        $allocatedSql = '(select coalesce(sum(pa.amount_cop), 0) from payment_allocations pa '
            .'inner join payments held on held.id = pa.payment_id '
            .'where pa.payment_id = payments.id and pa.reversed_at is null '
            .'and held.voided_at is null)';

        $query = Payment::query()
            ->with('client')
            // The reconciliation state is derived from the allocations, so the list
            // needs them. Loaded once for the page rather than per payment: fifteen
            // rows on a page would otherwise cost fifteen extra queries, and the list
            // is where an operator looks when something looks wrong.
            ->with('allocations')
            ->select('payments.*')
            ->selectRaw("{$allocatedSql} as allocated_total");

        $filters = $request->filters();

        if (isset($filters['search'])) {
            // Folded on both sides and escaped: `Ana` matches `Ana María`, and a typed `%`
            // is a percent sign rather than "anything".
            $needle = SafeSearch::likeNeedle($filters['search']);

            $query->whereHas('client', function ($clientQuery) use ($needle): void {
                $clientQuery->whereRaw(SafeSearch::match('first_names'), [$needle])
                    ->orWhereRaw(SafeSearch::match('last_names'), [$needle])
                    ->orWhereRaw(SafeSearch::match('document_number'), [$needle])
                    // And the whole name, which neither name column contains: a payment
                    // looked up by the name on it is looked up by the full name.
                    ->orWhereRaw(SafeSearch::fullNameMatch('first_names', 'last_names'), [$needle]);
            });
        }

        if (isset($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        if (isset($filters['state'])) {
            // `tryFrom`, not `from`: an unknown state can no longer reach this far, and a
            // throw here would be a 500 for a mistyped filter.
            match (ReconciliationState::tryFrom((string) $filters['state'])) {
                ReconciliationState::Voided => $query->whereNotNull('voided_at'),
                ReconciliationState::Unallocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} = 0"),
                ReconciliationState::PartiallyAllocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} > 0 and {$allocatedSql} < payments.amount_cop"),
                ReconciliationState::FullyAllocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} >= payments.amount_cop"),
            };
        }

        if (isset($filters['date_from'])) {
            $query->where('received_on', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (isset($filters['date_to'])) {
            $query->where('received_on', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        // "Requiring reconciliation" is: not voided, and with money not yet applied.
        //
        // Only a **true** narrows. The interface sends the literal string `false` for an
        // unchecked box, and this used to apply the restriction whenever the key was present
        // — so the default payments screen listed only payments that still had unapplied
        // money, and a fully reconciled client looked like they had paid nothing.
        if (($filters['requires_reconciliation'] ?? false) === true) {
            $query->whereNull('voided_at')
                ->whereRaw("{$allocatedSql} < payments.amount_cop");
        }

        $payments = $query->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'items' => $payments->getCollection()
                ->map(fn (Payment $payment): array => $this->describe($payment))
                ->all(),
            'pagination' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'from' => $payments->firstItem(),
                'to' => $payments->lastItem(),
            ],
            'methods' => array_map(
                fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases(),
            ),
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()?->can('payments.view'), 403, 'No tiene permisos para ver los pagos.');

        return response()->json([
            'payment' => $this->describe($this->loadForDetail($payment), detailed: true),
        ]);
    }

    /**
     * A payment with its allocations and their obligations ready to present.
     *
     * §44. Every path that renders the detail — show, allocate, reverse and void — used to
     * reach each allocation's obligation through a lazy load, so a payment with thirty
     * allocations issued thirty queries for the obligation, plus its period, client and
     * company, before the presenter batched anything. Loading the whole shape once here is
     * what makes the detail cost independent of how many allocations there are.
     */
    private function loadForDetail(Payment $payment): Payment
    {
        return $payment->loadMissing([
            'client',
            'allocations.obligation.period',
            'allocations.obligation.client',
            'allocations.obligation.company',
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $client = Client::query()->findOrFail($request->validated('client_id'));
        $amount = (int) $request->validated('amount_cop');
        $receivedOn = Carbon::parse((string) $request->validated('received_on'))->startOfDay();
        $reference = $request->validated('reference');

        // The duplicate warning is reported every time, and never blocks. Institutions
        // reuse references, the field is often empty, and refusing real money on a
        // heuristic would be worse than a duplicate a person confirms.
        $warning = $this->payments->duplicateWarning($client, $amount, $receivedOn, $reference);

        if ($warning['possible_duplicate'] && ! $request->boolean('confirm_duplicate')) {
            return response()->json([
                'message' => 'Ya existe un pago con el mismo cliente, importe, fecha y referencia. '
                    .'Revíselo y confirme si se trata de un pago distinto.',
                'code' => 'possible_duplicate',
                'possible_duplicates' => $warning['matches'],
            ], 409);
        }

        try {
            $payment = $this->payments->register($client, $request->validated(), $request->user());
        } catch (PaymentRejected $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'message' => sprintf('Se registró un pago de %s pesos.', number_format($payment->amount_cop, 0, ',', '.')),
            'payment' => $this->describe($this->loadForDetail($payment), detailed: true),
            // Sent back so an operator can decide about applying it, without having
            // to remember the amount they just typed.
            'unallocated_amount_cop' => $payment->amount_cop,
        ], 201);
    }

    public function allocate(AllocatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $obligation = MonthlyObligation::query()->findOrFail($request->validated('obligation_id'));

        try {
            $allocation = $this->payments->allocate(
                $payment,
                $obligation,
                (int) $request->validated('amount_cop'),
                $request->user(),
            );
        } catch (PaymentRejected $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'message' => 'Se aplicó el pago a la obligación.',
            'allocation' => [
                'id' => $allocation->id,
                'amount_cop' => $allocation->amount_cop,
                'obligation_id' => $allocation->obligation_id,
            ],
            'payment' => $this->describe($this->loadForDetail($payment->refresh()), detailed: true),
            'obligation' => $this->presenter->describe($obligation->refresh()),
        ], 201);
    }

    /**
     * What applying oldest-first would do, without doing it.
     *
     * The action is never automatic, and an operator about to reconcile a backlog
     * should be able to see the plan before committing to it.
     */
    public function previewAutoAllocation(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()?->can('payments.allocate'), 403, 'No tiene permisos para aplicar pagos.');

        if ($payment->isVoided()) {
            return $this->rejected(PaymentRejected::paymentIsVoided());
        }

        $plan = $this->planFor($payment);

        return response()->json(['plan' => $plan]);
    }

    /**
     * Apply what is left, oldest obligation first.
     *
     * Explicit, always. A payment that arrives when nothing is owed stays unapplied
     * until somebody says otherwise.
     */
    public function autoAllocate(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()?->can('payments.allocate'), 403, 'No tiene permisos para aplicar pagos.');

        try {
            $outcome = $this->payments->applyOldestFirst($payment, $request->user());
        } catch (PaymentRejected $e) {
            return $this->rejected($e);
        }

        return response()->json([
            // A proper plural, for the same reason as the generation message: this is
            // the sentence that tells the operator how far the payment reached.
            'message' => $outcome->appliedCount === 0
                ? 'No había obligaciones pendientes a las que aplicar este pago.'
                : ($outcome->appliedCount === 1
                    ? 'Se aplicó el pago a la obligación.'
                    : sprintf('Se aplicó el pago a %d obligaciones.', $outcome->appliedCount)),
            'result' => $outcome->toArray(),
            'payment' => $this->describe($this->loadForDetail($payment->refresh()), detailed: true),
        ]);
    }

    public function reverseAllocation(
        ReverseAllocationRequest $request,
        PaymentAllocation $allocation
    ): JsonResponse {
        try {
            $reversed = $this->payments->reverseAllocation($allocation, $request->reason(), $request->user());
        } catch (PaymentRejected $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'message' => 'Se revirtió la aplicación. El pago vuelve a estar disponible.',
            'allocation' => [
                'id' => $reversed->id,
                'reversed_at' => $reversed->reversed_at?->toIso8601String(),
                'reversal_reason' => $reversed->reversal_reason,
            ],
            // §8. This was the one detail response that did not go through
            // `loadForDetail()`, while `show`, `store`, `allocate` and `void` all did. So
            // `client` and every allocation's obligation graph — period, client, company —
            // arrived unloaded and were fetched one at a time by `describe()`'s own
            // fallbacks: a payment with thirty allocations cost a hundred queries to
            // reverse one of them.
            //
            // The same shape was returned the same way five times and four of the five
            // loaded it. That is what made it easy to miss.
            'payment' => $this->describe($this->loadForDetail($allocation->payment->refresh()), detailed: true),
        ]);
    }

    public function void(VoidPaymentRequest $request, Payment $payment): JsonResponse
    {
        try {
            $voided = $this->payments->void($payment, $request->reason(), $request->user());
        } catch (PaymentRejected $e) {
            return $this->rejected($e);
        }

        return response()->json([
            'message' => 'Se anuló el pago. Los saldos se actualizaron y las aplicaciones se conservan.',
            'payment' => $this->describe($this->loadForDetail($voided), detailed: true),
        ]);
    }

    // --- helpers --------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function describe(Payment $payment, bool $detailed = false): array
    {
        // The loaded relation when there is one. On the list the allocations were
        // eager loaded for the whole page, and asking for them again here would undo
        // that: one query per payment, on the screen where the volume is.
        $allocations = $payment->relationLoaded('allocations')
            ? $payment->allocations->sortBy('id')->values()
            : PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->orderBy('id')
                ->get();

        $allocated = $payment->allocatedAmount($allocations);
        $unallocated = $payment->unallocatedAmount($allocations);
        $state = $payment->reconciliationState($allocations);

        $base = [
            'id' => $payment->id,
            'client_id' => $payment->client_id,
            'client_name' => $payment->client?->fullName(),
            'amount_cop' => $payment->amount_cop,
            'received_on' => $payment->received_on?->format('Y-m-d'),
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'allocated_amount_cop' => $allocated,
            'unallocated_amount_cop' => $unallocated,
            'reconciliation_state' => $state->value,
            'reconciliation_state_label' => $state->label(),
            'requires_reconciliation' => $payment->requiresReconciliation($allocations),
            'is_voided' => $payment->isVoided(),
            'voided_at' => $payment->voided_at?->toIso8601String(),
            'void_reason' => $payment->void_reason,
        ];

        if (! $detailed) {
            return $base;
        }

        // §44. Every allocation's obligation was presented one at a time, and each
        // presentation reads the period, the client, the company, the adjustments and the
        // allocations — so a payment with thirty allocations cost a hundred queries to draw
        // a history list.
        //
        // `describeMany()` presents the whole set against two grouped queries, so the cost
        // is bounded by the page rather than growing per row.
        $described = $allocations->isEmpty()
            ? collect()
            : $this->presenter->describeMany(
                $allocations->map(fn (PaymentAllocation $a): MonthlyObligation => $a->obligation),
            )->keyBy('id');

        return $base + [
            'allocations' => $allocations->map(fn (PaymentAllocation $allocation): array => [
                'id' => $allocation->id,
                'payment_id' => $allocation->payment_id,
                'obligation_id' => $allocation->obligation_id,
                'amount_cop' => $allocation->amount_cop,
                // Both states, because the history has to show a reversed row as reversed and
                // still keep it visible. §17: nothing is hidden.
                'is_reversed' => $allocation->isReversed(),
                'is_active' => ! $allocation->isReversed(),
                'reversed_at' => $allocation->reversed_at?->toIso8601String(),
                'reversed_by' => $allocation->reversed_by,
                'reversal_reason' => $allocation->reversal_reason,
                'created_at' => $allocation->created_at?->toIso8601String(),
                'obligation' => $described->get($allocation->obligation_id),
                // Flat copies of what the row shows in a table, so the interface does not
                // have to reach three levels into the nested obligation for a column.
                'obligation_label' => $described->get($allocation->obligation_id)['period_label'] ?? null,
                'company_name' => $described->get($allocation->obligation_id)['company_name'] ?? null,
                'due_on' => $described->get($allocation->obligation_id)['due_on'] ?? null,
            ])->all(),
        ];
    }

    /**
     * The payment vocabulary the list screen needs: payment methods.
     *
     * §42. The payments page used to populate its method select from
     * `/api/receivables/vocabulary`, so a role holding `payments.view` and
     * `payments.create` but **not** `receivables.view` — a perfectly ordinary collections
     * account — got an empty dropdown and could not record how the money arrived. The
     * payments domain publishes its own vocabulary; the receivables screen keeps its own for
     * its own filters, and neither screen depends on the other's permission.
     */
    public function vocabulary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('payments.view'), 403, 'No tiene permisos para ver los pagos.');

        return response()->json([
            'methods' => array_map(
                fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases(),
            ),
            'reconciliation_states' => array_map(
                fn (ReconciliationState $state): array => ['value' => $state->value, 'label' => $state->label()],
                ReconciliationState::cases(),
            ),
        ]);
    }

    /**
     * The debts this payment could be applied to, for a client.
     *
     * §42. Manual allocation used to load `clientAccount` through `receivables.view`, so
     * `payments.allocate` silently depended on an unrelated read permission and a collections
     * role without it could not use the button it was granted.
     *
     * This is the payment domain's own answer, guarded by `payments.allocate`, and it
     * publishes **only what allocating needs**: the obligation id, its month, the company and
     * the balance still owed. No statement, no aging, no portfolio figure — none of it is
     * needed to choose a debt, and none of it is what this permission is about.
     */
    public function allocatable(Request $request, Client $client): JsonResponse
    {
        abort_unless($request->user()?->can('payments.allocate'), 403, 'No tiene permisos para aplicar pagos.');

        $obligations = MonthlyObligation::query()
            ->where('client_id', $client->id)
            ->with(['period', 'company'])
            ->orderBy('period_id')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        $described = $this->presenter->describeMany($obligations)->keyBy('id');

        $rows = [];

        foreach ($obligations as $obligation) {
            $totals = $described->get($obligation->id);

            if (($totals['balance_cop'] ?? 0) <= 0) {
                continue;
            }

            $rows[] = [
                'obligation_id' => $obligation->id,
                'period_id' => $obligation->period_id,
                'period_key' => $totals['period_key'] ?? null,
                'period_label' => $totals['period_label'] ?? null,
                'company_id' => $obligation->company_id,
                'company_name' => $obligation->company?->displayName(),
                'due_on' => $totals['due_on'] ?? null,
                'balance_cop' => $totals['balance_cop'] ?? 0,
            ];
        }

        return response()->json([
            'client_id' => $client->id,
            'items' => $rows,
        ]);
    }

    /**
     * The oldest-first plan, computed without writing.
     *
     * ## The same planner the execution uses
     *
     * This used to be a second implementation of the same arithmetic: it sorted with its own
     * composite key and capped each contribution with its own loop. Two implementations of
     * one rule is two chances to disagree, and they did — the execution caught a unique
     * violation and moved to the next debt, so the plan shown here could promise to finish
     * the oldest month while the action paid a newer one.
     *
     * It now builds the same `AllocationPlanner` plan the action builds, from the same
     * balances, so "what you were shown" and "what runs" are the same list of steps. The
     * execution recomputes it under its locks, which is what makes the second copy
     * authoritative; this copy is the promise.
     *
     * One deliberate difference remains: the preview does not lock, so it is allowed to be
     * stale. The interface says as much, and the button it opens re-reads before writing.
     *
     * @return array<string, mixed>
     */
    private function planFor(Payment $payment): array
    {
        $available = $payment->amount_cop - $payment->allocatedAmount();

        $candidates = MonthlyObligation::query()
            ->where('client_id', $payment->client_id)
            ->with('period')
            ->get();

        if ($candidates->isEmpty() || $available <= 0) {
            return [
                'payment_id' => $payment->id,
                'available_cop' => $available,
                'allocations' => [],
                'would_apply_count' => 0,
                'would_apply_cop' => 0,
                'would_remain_unallocated_cop' => $available,
            ];
        }

        // Two grouped queries for the whole set, rather than two per candidate.
        $described = $this->presenter->describeMany($candidates)->keyBy('id');

        $remainingByObligation = [];

        foreach ($candidates as $obligation) {
            $remainingByObligation[$obligation->id] = (int) $described[$obligation->id]['balance_cop'];
        }

        $plan = (new AllocationPlanner)->plan($candidates->all(), $remainingByObligation, $available);

        $planned = [];

        foreach ($plan as $step) {
            $obligation = $step['obligation'];
            $totals = $described[$obligation->id];

            $planned[] = [
                'obligation_id' => $obligation->id,
                'period_key' => $totals['period_key'],
                'period_label' => $totals['period_label'] ?? null,
                'due_on' => $obligation->due_on?->format('Y-m-d'),
                'balance_cop' => $step['remaining_cop'] + $step['amount_cop'],
                'would_apply_cop' => $step['amount_cop'],
            ];
        }

        return [
            'payment_id' => $payment->id,
            'available_cop' => $available,
            // The whole plan is published, not only its totals: an operator deciding whether
            // to apply a payment has to see which months it would touch.
            'allocations' => $planned,
            'would_apply_count' => count($planned),
            'would_apply_cop' => array_sum(array_column($planned, 'would_apply_cop')),
            'would_remain_unallocated_cop' => $available - array_sum(array_column($planned, 'would_apply_cop')),
        ];
    }

    private function rejected(PaymentRejected $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'payment_rejected',
            'reason_code' => $e->reasonCode,
        ], 422);
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 25)));
    }
}

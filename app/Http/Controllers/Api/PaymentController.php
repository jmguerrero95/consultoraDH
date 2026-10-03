<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentRejected;
use App\Domain\Payments\ReconciliationState;
use App\Domain\Receivables\ObligationPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\AllocatePaymentRequest;
use App\Http\Requests\Payments\ReverseAllocationRequest;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\VoidPaymentRequest;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
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

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('payments.view'), 403, 'No tiene permisos para ver los pagos.');

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

        if (($search = trim((string) $request->input('search', ''))) !== '') {
            $needle = '%'.$search.'%';

            $query->whereHas('client', function ($clientQuery) use ($needle): void {
                $clientQuery->whereRaw('lower(first_names) LIKE ?', [$needle])
                    ->orWhereRaw('lower(last_names) LIKE ?', [$needle])
                    ->orWhereRaw('document_number LIKE ?', [$needle]);
            });
        }

        if (($method = $request->input('method')) !== null && $method !== '') {
            $query->where('method', $method);
        }

        if (($state = $request->input('state')) !== null && $state !== '') {
            match (ReconciliationState::from($state)) {
                ReconciliationState::Voided => $query->whereNotNull('voided_at'),
                ReconciliationState::Unallocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} = 0"),
                ReconciliationState::PartiallyAllocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} > 0 and {$allocatedSql} < payments.amount_cop"),
                ReconciliationState::FullyAllocated => $query->whereNull('voided_at')
                    ->whereRaw("{$allocatedSql} >= payments.amount_cop"),
            };
        }

        if (($from = $request->input('date_from')) !== null && $from !== '') {
            $query->where('received_on', '>=', Carbon::parse($from)->startOfDay());
        }

        if (($to = $request->input('date_to')) !== null && $to !== '') {
            $query->where('received_on', '<=', Carbon::parse($to)->endOfDay());
        }

        // "Requiring reconciliation" is: not voided, and with money not yet applied.
        if ($request->boolean('requires_reconciliation')) {
            $query->whereNull('voided_at')
                ->whereRaw("{$allocatedSql} < payments.amount_cop");
        }

        $payments = $query->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

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
            'payment' => $this->describe($payment, detailed: true),
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
            'payment' => $this->describe($payment, detailed: true),
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
            'payment' => $this->describe($payment->refresh(), detailed: true),
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
            'payment' => $this->describe($payment->refresh(), detailed: true),
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
            'payment' => $this->describe($allocation->payment->refresh(), detailed: true),
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
            'payment' => $this->describe($voided, detailed: true),
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

        return $base + [
            'allocations' => $allocations->map(fn (PaymentAllocation $allocation): array => [
                'id' => $allocation->id,
                'obligation_id' => $allocation->obligation_id,
                'amount_cop' => $allocation->amount_cop,
                'is_reversed' => $allocation->isReversed(),
                'reversed_at' => $allocation->reversed_at?->toIso8601String(),
                'reversal_reason' => $allocation->reversal_reason,
                'obligation' => $this->presenter->describe($allocation->obligation),
            ])->all(),
        ];
    }

    /**
     * The oldest-first plan, computed without writing.
     *
     * @return array<string, mixed>
     */
    private function planFor(Payment $payment): array
    {
        $available = $payment->amount_cop - $payment->allocatedAmount();

        $candidates = MonthlyObligation::query()
            ->where('client_id', $payment->client_id)
            ->with(['period'])
            ->get()
            // One composite key, so the order is the one the application uses:
            // oldest period, then soonest due date, then id. A list of sort callbacks
            // is not a multi-key sort, and getting this wrong would promise a plan
            // that is not the plan.
            ->sortBy(fn (MonthlyObligation $o): string => sprintf(
                '%s|%s|%010d',
                $o->period?->period_month->format('Y-m-d') ?? '',
                $o->due_on?->format('Y-m-d') ?? '',
                $o->id,
            ))
            ->values();

        // The figures for every candidate in two grouped queries rather than two per
        // candidate. A client with a long history would otherwise pay for the whole
        // history to be summed in order to preview one payment.
        $described = $this->presenter->describeMany($candidates)->keyBy('id');

        $planned = [];
        $remaining = $available;

        foreach ($candidates as $obligation) {
            if ($remaining <= 0) {
                break;
            }

            $totals = $described[$obligation->id];
            $balance = (int) $totals['balance_cop'];

            if ($balance <= 0) {
                continue;
            }

            $amount = min($balance, $remaining);
            $remaining -= $amount;

            $planned[] = [
                'obligation_id' => $obligation->id,
                'period_key' => $totals['period_key'],
                'period_label' => $totals['period_label'] ?? null,
                'due_on' => $obligation->due_on?->format('Y-m-d'),
                'balance_cop' => $balance,
                'would_apply_cop' => $amount,
            ];
        }

        return [
            'payment_id' => $payment->id,
            'available_cop' => $available,
            // The whole plan is published, not only its totals: an operator deciding
            // whether to apply a payment has to see which months it would touch.
            'allocations' => $planned,
            'would_apply_count' => count($planned),
            'would_apply_cop' => array_sum(array_column($planned, 'would_apply_cop')),
            'would_remain_unallocated_cop' => $remaining,
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

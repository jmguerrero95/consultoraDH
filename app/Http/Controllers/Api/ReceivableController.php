<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\SettlementState;
use App\Domain\Billing\TrafficLight;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Periods\PeriodStatus;
use App\Domain\Receivables\ReceivablesService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cartera: who owes what.
 *
 * A read-only screen with one permission. It computes, it does not decide: every
 * figure comes from `ReceivablesService`, and the interface is a rendering of those
 * figures rather than a second calculation of them.
 */
final class ReceivableController extends Controller
{
    public function __construct(
        private readonly ReceivablesService $receivables,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('receivables.view'), 403, 'No tiene permisos para ver la cartera.');

        $filters = array_filter([
            'search' => $request->input('search'),
            'company_id' => $request->input('company_id'),
            'client_id' => $request->input('client_id'),
            'period_from' => $request->input('period_from'),
            'period_to' => $request->input('period_to'),
            'settlement_state' => $request->input('settlement_state'),
            'aging_bucket' => $request->input('aging_bucket'),
            'traffic_light' => $request->input('traffic_light'),
            'minimum_balance' => $request->input('minimum_balance'),
            'maximum_balance' => $request->input('maximum_balance'),
            'as_of' => $request->input('as_of'),
            // `outstanding_only` defaults to true: cartera is a list of debtors, and a
            // row with a zero balance is not one. It can be turned off explicitly.
            'outstanding_only' => $request->has('outstanding_only')
                ? $request->boolean('outstanding_only')
                : true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($request->has('overdue')) {
            $filters['overdue'] = $request->boolean('overdue');
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));

        return response()->json($this->receivables->list($filters, $page, $perPage));
    }

    /**
     * One client's account: the statement, without the PDF.
     */
    public function clientAccount(Request $request, Client $client): JsonResponse
    {
        abort_unless($request->user()?->can('receivables.view'), 403, 'No tiene permisos para ver la cuenta del cliente.');

        return response()->json(
            $this->receivables->clientAccount($client, $request->date('as_of')),
        );
    }

    /**
     * The filter vocabulary, so the interface does not own a translation of it.
     */
    public function vocabulary(): JsonResponse
    {
        abort_unless(request()->user()?->can('receivables.view'), 403, 'No tiene permisos para ver la cartera.');

        return response()->json([
            'settlement_states' => array_map(
                fn (SettlementState $state): array => ['value' => $state->value, 'label' => $state->label()],
                SettlementState::cases(),
            ),
            'aging_buckets' => array_map(
                fn (AgingBucket $bucket): array => ['value' => $bucket->value, 'label' => $bucket->label()],
                AgingBucket::cases(),
            ),
            'traffic_lights' => array_map(
                fn (TrafficLight $light): array => [
                    'value' => $light->value,
                    'label' => $light->label(),
                    'css_modifier' => $light->cssModifier(),
                ],
                TrafficLight::cases(),
            ),
            // The payment methods live with the rest of the vocabulary because the
            // interface should not carry its own list of them: a method added on the
            // server has to appear without anybody editing a select in the front end.
            'payment_methods' => array_map(
                fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                PaymentMethod::cases(),
            ),
            'period_statuses' => array_map(
                fn (PeriodStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                PeriodStatus::cases(),
            ),
        ]);
    }
}

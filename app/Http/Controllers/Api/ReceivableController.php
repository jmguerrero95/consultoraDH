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
use App\Http\Requests\Receivables\ListReceivablesRequest;
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

    public function index(ListReceivablesRequest $request): JsonResponse
    {
        // The filters arrive validated and allowlisted: an unknown traffic light or an
        // impossible month is a 422 naming the field, not a `ValueError` from an enum and not
        // a query that quietly returns every debtor.
        //
        // `filters()` is where `overdue=false` stops meaning "overdue only".
        return response()->json($this->receivables->list(
            $request->filters(),
            $request->page(),
            $request->perPage(),
        ));
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

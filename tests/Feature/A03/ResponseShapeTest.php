<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Periods\Actions\CreatePeriod;
use App\Domain\Periods\MonthlyPeriod;

/**
 * The shape of every A03 response the interface reads.
 *
 * These are contracts, not implementation details. The interface renders these keys
 * and nothing else, so a rename here that a unit test cannot see leaves a screen that
 * draws an empty table and reports no error — the worst kind of break, because
 * nothing fails and the numbers are simply absent.
 *
 * Every key the screen reads is asserted. A key added later does not fail these
 * tests, which is deliberate: growing a payload is safe, and only removing or
 * renaming one that something reads is worth failing over.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** Assert that an array has every one of these keys. */
function expect_keys(array $payload, array $keys, string $where): void
{
    foreach ($keys as $key) {
        expect($payload, "{$where} should publish {$key}")->toHaveKey($key);
    }
}

it('publishes the keys the period screens read', function (): void {
    $actor = a03_admin();
    $month = '2026-10';

    a03_employer();
    $period = app(CreatePeriod::class)->execute(
        MonthlyPeriod::fromKey($month),
        $actor,
    );

    app(GeneratePeriodObligations::class)->execute($period, $actor);

    // The list: items, the page numbers, and the current period, which the header
    // shows above the table.
    $list = $this->actingAs($actor)->getJson('/api/periods')->assertOk()->json();

    expect_keys($list, ['items', 'pagination', 'current'], 'the period list');
    expect_keys($list['pagination'], ['total', 'per_page', 'current_page', 'last_page'], 'the period pagination');
    expect_keys($list['items'][0], [
        'id', 'key', 'label', 'period_month', 'starts_on', 'ends_on_exclusive',
        'status', 'status_label', 'opened_at', 'closed_at', 'reopened_at',
        'last_reopen_reason', 'generation_performed_at', 'obligation_count',
        'total_base_cop', 'total_effective_cop', 'total_paid_cop', 'total_balance_cop',
    ], 'a period row');

    // The detail adds what only makes sense for one month.
    $detail = $this->actingAs($actor)->getJson("/api/periods/{$period->id}")->assertOk()->json();

    expect_keys($detail, ['period'], 'the period detail');
    expect_keys($detail['period'], [
        'accepts_structural_change', 'accepts_financial_activity',
    ], 'the period detail flags');

    // The current period, for a caller that only needs that value.
    $current = $this->actingAs($actor)->getJson('/api/periods/current')->assertOk()->json();

    expect_keys($current, ['current', 'has_current', 'open_periods'], 'the current period');

    // The obligations of a month.
    $obligations = $this->actingAs($actor)
        ->getJson("/api/periods/{$period->id}/obligations")
        ->assertOk()
        ->json();

    expect_keys($obligations, ['items', 'pagination'], 'the obligations of a period');
    expect_keys($obligations['items'][0], [
        'id', 'period_id', 'period_key', 'period_label', 'client_id', 'client_name',
        'company_id', 'company_name', 'base_amount_cop', 'adjustments_cop', 'due_on',
        'source', 'source_label', 'effective_amount_cop', 'paid_amount_cop', 'balance_cop',
        'settlement_state', 'settlement_state_label', 'is_overdue', 'days_late',
        'aging_bucket', 'aging_bucket_label',
    ], 'an obligation row');
});

it('publishes the keys the generation preview reads', function (): void {
    $actor = a03_admin();
    a03_employer();
    $period = a03_openPeriod('2026-10');

    $preview = $this->actingAs($actor)
        ->postJson("/api/periods/{$period->id}/obligations/preview", ['missing_only' => true])
        ->assertOk()
        ->json();

    expect_keys($preview, ['period', 'preview'], 'the generation preview');
    expect_keys($preview['preview'], [
        'period', 'candidate_count', 'creatable_count', 'resolved_count',
        'blocker_count', 'total_amount_cop', 'can_generate',
        'candidates', 'blockers', 'warnings',
    ], 'the generation plan');

    expect_keys($preview['preview']['candidates'][0], [
        'client_id', 'company_id', 'client_name', 'company_name', 'assignment_id',
        'amount_cop', 'cutoff', 'rate_id', 'already_exists', 'will_be_created',
        'blockers',
    ], 'a preview candidate');

    // What the cutoff resolved to, so the screen can show the due date that would
    // follow without doing the arithmetic itself.
    expect_keys($preview['preview']['candidates'][0]['cutoff'], [
        'resolved', 'due_on', 'source', 'source_label', 'cutoff_rule_id',
        'cutoff_day', 'month_offset',
    ], 'a resolved cutoff');

    $result = $this->actingAs($actor)
        ->postJson("/api/periods/{$period->id}/obligations/generate", ['missing_only' => true])
        ->assertOk()
        ->json();

    expect_keys($result, ['message', 'result', 'period'], 'the generation result');
});

it('publishes the keys the adjustments screen reads', function (): void {
    $actor = a03_admin();
    $obligation = a03_payable(200000, '2026-10', a03_employerFor('ajustes-forma'));

    $created = $this->actingAs($actor)
        ->postJson("/api/obligations/{$obligation->id}/adjustments", [
            'type' => 'correction',
            'delta_cop' => 50000,
            'reason' => 'Prueba de la forma del ajuste',
        ])
        ->assertCreated()
        ->json();

    expect_keys($created, ['message', 'adjustment', 'obligation'], 'a recorded adjustment');

    $list = $this->actingAs($actor)
        ->getJson("/api/obligations/{$obligation->id}/adjustments")
        ->assertOk()
        ->json();

    expect_keys($list, ['items', 'adjustments_total_cop'], 'the adjustments of an obligation');

    // The screen has to be able to tell a live adjustment from a cancelled one, or it
    // will offer to reverse it a second time.
    expect_keys($list['items'][0], [
        'id', 'obligation_id', 'type', 'type_label', 'delta_cop', 'reason',
        'reverses_adjustment_id', 'reversed_at', 'reversed_by', 'reversal_reason',
        'created_at',
    ], 'an adjustment row');
});

it('publishes the keys the payments screen reads', function (): void {
    $actor = a03_admin();
    $obligation = a03_payable(200000, '2026-10', a03_employerFor('pagos-forma'));
    $payments = app(ManagePayments::class);

    $registered = $payments->register(
        $obligation->client,
        ['amount_cop' => 300000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        $actor,
    );

    $list = $this->actingAs($actor)->getJson('/api/payments')->assertOk()->json();

    expect_keys($list, ['items', 'pagination', 'methods'], 'the payment list');
    expect_keys($list['items'][0], [
        'id', 'client_id', 'client_name', 'amount_cop', 'received_on', 'method',
        'method_label', 'reference', 'notes', 'allocated_amount_cop',
        'unallocated_amount_cop', 'reconciliation_state', 'reconciliation_state_label',
        'requires_reconciliation', 'is_voided', 'voided_at', 'void_reason',
    ], 'a payment row');

    $stored = $this->actingAs($actor)
        ->postJson('/api/payments', [
            'client_id' => $obligation->client_id,
            'amount_cop' => 50000,
            'received_on' => '2026-11-06',
            'method' => 'cash',
        ])
        ->assertCreated()
        ->json();

    expect_keys($stored, ['message', 'payment', 'unallocated_amount_cop'], 'a recorded payment');

    // The plan is shown before it is committed, so its shape is part of the contract.
    $plan = $this->actingAs($actor)
        ->postJson("/api/payments/{$registered->id}/auto-allocate/preview")
        ->assertOk()
        ->json();

    expect_keys($plan, ['plan'], 'the allocation plan');
    expect_keys($plan['plan'], [
        'payment_id', 'available_cop', 'allocations', 'would_apply_count',
        'would_apply_cop', 'would_remain_unallocated_cop',
    ], 'the allocation plan');

    expect_keys($plan['plan']['allocations'][0], [
        'obligation_id', 'period_key', 'period_label', 'due_on', 'balance_cop',
        'would_apply_cop',
    ], 'a planned allocation');
});

it('publishes the keys the receivables screens read', function (): void {
    $actor = a03_admin();
    a03_payable(200000, '2026-10', a03_employerFor('cartera-forma'));

    $list = $this->actingAs($actor)->getJson('/api/receivables')->assertOk()->json();

    expect_keys($list, [
        'items', 'summary', 'total', 'page', 'per_page', 'last_page',
    ], 'the portfolio');
    expect_keys($list['items'][0], [
        'client_id', 'full_name', 'document_label', 'company_names', 'balance_cop',
        'paid_amount_cop', 'overdue_balance_cop', 'open_obligations_count',
        'overdue_obligations_count', 'owed_periods', 'oldest_due_on', 'aging_bucket',
        'traffic_light', 'traffic_light_label', 'traffic_light_reason',
    ], 'a portfolio row');
    expect_keys($list['summary'], [
        'outstanding_balance_cop', 'overdue_balance_cop', 'total_effective_obligations_cop',
        'total_paid_cop', 'clients_with_debt', 'open_obligations_count',
        'overdue_obligations_count', 'unallocated_credit_cop',
        'payments_requiring_reconciliation',
    ], 'the portfolio summary');

    // The vocabulary, so the interface does not own a translation of any of it.
    $vocabulary = $this->actingAs($actor)->getJson('/api/receivables/vocabulary')->assertOk()->json();

    expect_keys($vocabulary, [
        'settlement_states', 'aging_buckets', 'traffic_lights', 'payment_methods',
        'period_statuses',
    ], 'the financial vocabulary');

    $account = $this->actingAs($actor)
        ->getJson('/api/receivables')
        ->assertOk()
        ->json();

    $clientId = $account['items'][0]['client_id'];

    $statement = $this->actingAs($actor)
        ->getJson("/api/clients/{$clientId}/account")
        ->assertOk()
        ->json();

    expect_keys($statement, ['client', 'as_of', 'summary', 'obligations'], 'the client account');
    expect_keys($statement['client'], ['id', 'full_name', 'document_label'], 'the account client');
    expect_keys($statement['summary'], [
        'total_effective_obligations_cop', 'total_paid_cop', 'outstanding_balance_cop',
        'overdue_balance_cop', 'unallocated_credit_cop', 'open_obligations_count',
        'overdue_obligations_count', 'owed_periods',
    ], 'the account summary');
});

it('publishes the keys the configuration screen reads', function (): void {
    $actor = a03_admin();
    a03_employer();

    $rules = $this->actingAs($actor)->getJson('/api/cutoff-rules')->assertOk()->json();

    expect_keys($rules, ['items', 'pagination', 'scopes', 'offsets'], 'the cutoff rules');

    // The screen disables the day of a rule a month has already used, so it needs to
    // know which those are and to have the month named for it.
    expect_keys($rules['items'][0], [
        'id', 'scope', 'scope_label', 'company_id', 'company_name', 'client_id',
        'client_name', 'effective_month', 'effective_month_label', 'cutoff_day',
        'month_offset', 'month_offset_label', 'example_due_on', 'example_period',
        'notes', 'in_use',
    ], 'a cutoff rule');

    $rates = $this->actingAs($actor)->getJson('/api/rates')->assertOk()->json();

    expect_keys($rates, ['items', 'pagination'], 'the rates');
    expect_keys($rates['items'][0], [
        'id', 'client_id', 'client_name', 'company_id', 'company_name',
        'effective_month', 'effective_month_label', 'amount_cop', 'notes', 'in_use',
    ], 'a rate');
});

it('publishes the financial position on the dashboard', function (): void {
    a03_payable(200000, '2026-10', a03_employerFor('tablero-forma'));

    $dashboard = $this->actingAs(a03_admin())->getJson('/api/dashboard')->assertOk()->json();

    // Only for a role that may read the portfolio: an absent section means withheld,
    // and the interface must not draw a withheld figure as a zero.
    expect_keys($dashboard['portfolio']['financial'], [
        'outstanding_balance_cop', 'overdue_balance_cop', 'total_paid_cop',
        'clients_with_debt', 'payments_requiring_reconciliation',
    ], 'the dashboard financial position');
});

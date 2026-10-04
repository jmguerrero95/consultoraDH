<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Payments\Actions\ManagePayments;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\ObligationAdjustment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A03-R1 §29, §30, §37, §40, §41, §42, §44 and §55: what each permission is allowed to
 * see, and what a filter is allowed to mean.
 *
 * ## The two themes
 *
 * **Permission leaks.** A role was shown figures, or given a link to a screen, that its
 * permissions did not cover: the period list published what a month was worth to anybody who
 * could see the calendar, the dashboard offered a link to a route its own guard would
 * refuse, and the payments screen needed a receivables permission it had no reason to hold.
 *
 * **Silent filter semantics.** `overdue=false` and `requires_reconciliation=false` both
 * narrowed the list, because the query acted on the *presence* of the key and the interface
 * always sends the boolean. Two screens, one bug, and the review asks explicitly for
 * regression tests so it is not copied a third time.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * An account holding exactly the permissions named, and nothing else.
 *
 * §55 requires minimal custom users rather than the seeded roles: a seeded role is a bundle,
 * and a bundle cannot prove that a single permission is what grants or withholds something.
 */
function a03_minimalUser(array $permissions, string $name = 'mínimo'): User
{
    $user = User::factory()->create([
        'email' => Str::random(12).'@consultora-dh.test',
        'name' => 'Cuenta '.$name.' '.substr(md5($name.microtime()), 0, 6),
    ]);

    $user->forceFill(['password' => bcrypt('Aa1b2c3d4e5f')])->save();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    $user->unsetRelation('permissions')->unsetRelation('roles');

    return $user->refresh();
}

// --- §37: a period's money is not period metadata -----------------------------

it('publishes a period\'s monetary figures with the view permission', function (): void {
    a03_payable(250000, '2026-10', a03_employer());

    // `periods.view` is what admits the caller to the list at all; the probe is whether the
    // **monetary** keys follow from the obligations permission as well.
    $item = $this->actingAs(a03_minimalUser(['periods.view', 'obligations.view'], 've'))
        ->getJson('/api/periods')
        ->assertOk()
        ->json('items.0');

    expect($item)->toHaveKey('total_balance_cop')
        ->and($item)->toHaveKey('obligation_count')
        ->and($item['total_balance_cop'])->toBe(250000);
});

it('publishes a period\'s monetary figures with the generate permission', function (): void {
    a03_payable(250000, '2026-10', a03_employer());

    // Somebody who may write a month's obligations has to see what they wrote.
    $item = $this->actingAs(a03_minimalUser(['periods.view', 'obligations.generate'], 'genera'))
        ->getJson('/api/periods')
        ->assertOk()
        ->json('items.0');

    expect($item)->toHaveKey('total_balance_cop');
});

it('hides a period\'s monetary figures from a calendar-only role', function (): void {
    a03_payable(250000, '2026-10', a03_employer());

    // `periods.view` alone sees the month and its state, and **no** monetary key at all.
    //
    // Not zero: a zero would be a claim that the month is worth nothing, which is a
    // financial statement this permission does not entitle anybody to make.
    $item = $this->actingAs(a03_minimalUser(['periods.view'], 'periodos'))
        ->getJson('/api/periods')
        ->assertOk()
        ->json('items.0');

    foreach ([
        'obligation_count', 'total_base_cop', 'total_effective_cop',
        'total_paid_cop', 'total_balance_cop',
    ] as $key) {
        expect($item)->not->toHaveKey($key);
    }

    // What `periods.view` does see is the month's own state.
    expect($item)->toHaveKey('key')
        ->and($item)->toHaveKey('status')
        ->and($item)->toHaveKey('generation_performed_at');
});

/**
 * §5. The two period endpoints that were not passing the gate.
 *
 * `index()` has always asked `maySeeMoney()`, and the tests above cover it. `current()` and
 * `show()` did not: both called `summarise()` and left `withMoney` at its default of `true`,
 * so `total_base_cop`, `total_effective_cop`, `total_paid_cop`, `total_balance_cop` and
 * `obligation_count` came back to a role holding `periods.view` and nothing else.
 *
 * That is the same authority the list withholds, reached through a different door — and the
 * door an operator actually uses, because the shell asks "which month is current" on load.
 * The period list is a calendar; the amounts are somebody else's question.
 */
it('hides the figures from every period endpoint, not just the list', function (): void {
    a03_payable(250000, '2026-10', a03_employer());

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    $money = ['obligation_count', 'total_base_cop', 'total_effective_cop', 'total_paid_cop', 'total_balance_cop'];

    // Authenticated once and then read three times: re-authenticating between requests
    // starts a new session each time and the later requests come back 401, which is a
    // property of the test client rather than of the endpoints.
    $this->actingAs(a03_minimalUser(['periods.view'], 'periodos'));

    // Every surface that publishes a period: the list, the current one, and one month.
    $surfaces = [
        'GET /api/periods' => fn (): array => $this->getJson('/api/periods')->assertOk()->json('items.0'),
        'GET /api/periods/current' => fn (): array => $this->getJson('/api/periods/current')
            ->assertOk()
            ->json('current'),
        "GET /api/periods/{$period->id}" => fn (): array => $this->getJson("/api/periods/{$period->id}")
            ->assertOk()
            ->json('period'),
    ];

    foreach ($surfaces as $surface => $read) {
        $payload = $read();

        expect($payload)->not->toBeNull($surface);

        foreach ($money as $key) {
            expect($payload, $surface.' → '.$key)->not->toHaveKey($key);
        }

        // The month's own state is still there: this permission is not useless, it is about
        // the calendar rather than the money.
        expect($payload, $surface)->toHaveKey('status')->toHaveKey('key');
    }

    // And the figures are not withheld from the roles that may have them. A separate
    // example rather than a loop over both, because switching the authenticated user
    // between requests in one example leaves the second call unauthenticated — the test
    // client's session cookie belongs to the first.
});

it('publishes the figures to a role with obligations authority', function (string $permission): void {
    a03_payable(250000, '2026-10', a03_employer());

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    $money = ['obligation_count', 'total_base_cop', 'total_effective_cop', 'total_paid_cop', 'total_balance_cop'];

    $with = $this->actingAs(a03_minimalUser(['periods.view', $permission], 'dinero-'.$permission))
        ->getJson("/api/periods/{$period->id}")
        ->assertOk()
        ->json('period');

    foreach ($money as $key) {
        expect($with, $permission.' → '.$key)->toHaveKey($key);
    }

    // So the gate withholds the figures from the calendar-only role without taking them away
    // from anybody: a missing key is not a zero.
    expect($with['total_base_cop'])->toBe(250000)
        ->and($with['obligation_count'])->toBe(1)
        ->and($with['total_paid_cop'])->toBe(0)
        ->and($with['total_balance_cop'])->toBe(250000);
})->with(['obligations.view', 'obligations.generate']);

it('omits the monetary keys for a calendar-only role without erroring', function (): void {
    a03_payable(250000, '2026-10', a03_employer());

    $without = $this->actingAs(a03_minimalUser(['periods.view'], 'periodos'))
        ->getJson('/api/periods')->assertOk();

    // The aggregates are not even computed when they cannot be published, so a role that may
    // not see them pays nothing for them.
    expect($without->json('items.0'))->not->toHaveKey('total_balance_cop');
});

// --- §40: received, applied and credit are three numbers ----------------------

it('separates money received from money applied', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);

    // 300000 arrives and nothing is allocated yet.
    a03_payablePayment(300000, $employer);

    $financial = fn (): array => $this->actingAs(a03_admin())->getJson('/api/dashboard')->assertOk()->json('portfolio.financial');

    $before = $financial();

    // The old dashboard published `total_paid_cop` under the label "Recaudado", so on this
    // day it said nothing had been collected while 300000 sat in the bank.
    expect($before['total_received_cop'])->toBe(300000)
        ->and($before['total_applied_cop'])->toBe(0)
        ->and($before['unallocated_credit_cop'])->toBe(300000);

    app(ManagePayments::class)
        ->allocate(Payment::query()->firstOrFail(), $obligation, 200000, actingAsRole());

    $after = $financial();

    expect($after['total_received_cop'])->toBe(300000)
        ->and($after['total_applied_cop'])->toBe(200000)
        ->and($after['unallocated_credit_cop'])->toBe(100000)
        ->and($after['outstanding_balance_cop'])->toBe(0);
});

it('counts a voided payment as neither received nor credit', function (): void {
    $employer = a03_employer();
    $payment = a03_payablePayment(300000, $employer);

    expect($this->actingAs(a03_admin())->getJson('/api/dashboard')->json('portfolio.financial.total_received_cop'))
        ->toBe(300000);

    app(ManagePayments::class)->void($payment, 'Se recibió por duplicado.', actingAsRole());

    $financial = $this->actingAs(a03_admin())->getJson('/api/dashboard')->json('portfolio.financial');

    expect($financial['total_received_cop'])->toBe(0)
        ->and($financial['unallocated_credit_cop'])->toBe(0);
});

// --- §42: the payments domain stands on its own permissions -------------------

it('lets a payments role record a payment without a receivables permission', function (): void {
    $user = a03_minimalUser(['payments.view', 'payments.create', 'payments.allocate'], 'cartera-sin-receivables');

    expect($user->can('payments.view'))->toBeTrue()
        ->and($user->can('receivables.view'))->toBeFalse();

    // The vocabulary the payments screen needs.
    $vocabulary = $this->actingAs($user)->getJson('/api/payments/vocabulary')->assertOk()->json();

    expect($vocabulary['methods'])->not->toBeEmpty()
        ->and($vocabulary['reconciliation_states'])->not->toBeEmpty();

    // And a payment can be recorded.
    $employer = a03_employer();

    $this->actingAs($user)->postJson('/api/payments', [
        'client_id' => $employer['client']->id,
        'amount_cop' => 150000,
        'received_on' => '2026-10-15',
        'method' => 'bank_transfer',
        'reference' => 'REC-42',
    ])->assertStatus(201);
});

it('lets a payments role allocate without a receivables permission', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    $user = a03_minimalUser(['payments.view', 'payments.allocate'], 'aplica-sin-receivables');

    // The endpoint the interface uses to choose a debt.
    $allocatable = $this->actingAs($user)
        ->getJson("/api/payments/clients/{$employer['client']->id}/allocatable")
        ->assertOk()
        ->json('items');

    expect($allocatable)->not->toBeEmpty()
        ->and($allocatable[0])->toHaveKeys(['obligation_id', 'balance_cop', 'period_label', 'company_name', 'due_on']);

    $this->actingAs($user)->postJson("/api/payments/{$payment->id}/allocations", [
        'obligation_id' => $obligation->id,
        'amount_cop' => 200000,
    ])->assertStatus(201);

    expect($obligation->refresh()->balance())->toBe(0);
});

it('keeps the allocatable endpoint to what allocating needs and no more', function (): void {
    $employer = a03_employer();
    a03_payable(200000, '2026-10', $employer);

    $row = $this->actingAs(a03_admin())
        ->getJson("/api/payments/clients/{$employer['client']->id}/allocatable")
        ->assertOk()
        ->json('items.0');

    // No statement, no aging, no portfolio totals: those are receivables information and
    // this is not the receivables screen.
    foreach (['summary', 'obligations', 'traffic_light', 'aging_bucket', 'as_of'] as $forbidden) {
        expect($row)->not->toHaveKey($forbidden);
    }
});

it('refuses the allocatable endpoint without payments.allocate', function (): void {
    $employer = a03_employer();

    $this->actingAs(a03_minimalUser(['payments.view'], 'solo-ver'))
        ->getJson("/api/payments/clients/{$employer['client']->id}/allocatable")
        ->assertStatus(403);
});

// --- §29: the payments list filters -----------------------------------------

it('applies the reconciliation filter only when it is true', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payments = app(ManagePayments::class);

    $applied = a03_payablePayment(200000, $employer, '2026-10-10');
    $payments->allocate($applied, $obligation, 200000, actingAsRole());

    $pending = a03_payablePayment(50000, $employer, '2026-10-11');

    $ids = fn (string $query): array => $this->actingAs(a03_admin())
        ->getJson('/api/payments'.$query)->assertOk()->json('items.*.id');

    // Absent and false are the same question. The interface sends the literal `false` for an
    // unchecked box, so the default screen used to list only unreconciled payments — a fully
    // reconciled client's payments disappeared.
    expect($ids(''))->toContain($applied->id, $pending->id)
        ->and($ids('?requires_reconciliation=false'))->toContain($applied->id, $pending->id);

    // True narrows to what still has unapplied money.
    $only = $ids('?requires_reconciliation=true');

    expect($only)->toContain($pending->id)
        ->and($only)->not->toContain($applied->id);
});

it('refuses an unknown payment filter with a 422 rather than a 500', function (mixed $query, string $field): void {
    $this->actingAs(a03_admin())
        ->getJson('/api/payments'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'state' => ['?state=inventado', 'state'],
    'method' => ['?method=bitcoin', 'method'],
    'date' => ['?date_from=15-01-2026', 'date_from'],
    'per page' => ['?per_page=9000', 'per_page'],
]);

it('refuses a reversed payment date range', function (): void {
    $this->actingAs(a03_admin())
        ->getJson('/api/payments?date_from=2026-10-01&date_to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('date_from');
});

it('searches a client name case-insensitively and without wildcard surprises', function (): void {
    $employer = a03_employer();
    $employer['client']->forceFill(['first_names' => 'Ana María', 'last_names' => 'Ríos'])->save();
    a03_payable(100000, '2026-10', $employer);
    a03_payablePayment(100000, $employer);

    $found = fn (string $term): array => $this->actingAs(a03_admin())
        ->getJson('/api/payments?search='.urlencode($term))->assertOk()->json('items.*.client_id');

    // `lower(column) LIKE 'Ana%'` does not match `Ana María`: the column was folded and the
    // needle was not.
    expect($found('Ana'))->toContain($employer['client']->id)
        ->and($found('ana mar'))->toContain($employer['client']->id)
        ->and($found('Ríos'))->toContain($employer['client']->id)
        ->and($found('%%'))->not->toContain($employer['client']->id);
});

// --- §44: payment detail does not grow per allocation ------------------------

it('keeps the payment detail query count flat as allocations accumulate', function (): void {
    $client = Client::factory()->create();
    $companies = collect(range(1, 12))->map(fn (): Company => Company::factory()->create());

    foreach ($companies as $company) {
        a03_employer(client: $client, company: $company, relationshipStart: '2024-01-01', amountCop: 10000);
        a03_payable(10000, '2026-01', ['client' => $client, 'company' => $company]);
    }

    $payments = app(ManagePayments::class);

    $small = a03_payablePayment(60000, ['client' => $client, 'company' => $companies[0]]);
    $small->allocations()->delete();

    $big = a03_payablePayment(120000, ['client' => $client, 'company' => $companies[0]]);

    foreach (MonthlyObligation::query()->where('client_id', $client->id)->get() as $obligation) {
        $payments->allocate($big, $obligation, 10000, actingAsRole());
    }

    $count = function (Payment $payment): int {
        $this->actingAs(a03_admin());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/payments/{$payment->id}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $one = $count($small);
    $thirty = $count($big);

    // Twelve allocations, presented one at a time, cost twelve obligations' worth of
    // queries. The bound is what matters, not an exact figure.
    expect($thirty)->toBeLessThanOrEqual($one + 6)
        ->and($one)->toBeGreaterThan(0);
});

it('publishes an allocation with both its state and its obligation', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);

    $allocation = app(ManagePayments::class)->allocate($payment, $obligation, 80000, actingAsRole());

    $item = $this->actingAs(a03_admin())
        ->getJson("/api/payments/{$payment->id}")
        ->assertOk()
        ->json('payment.allocations.0');

    // Both names for the same state, so the interface can filter on one and render the
    // other, and the row stays visible after a reversal.
    expect($item['id'])->toBe($allocation->id)
        ->and($item['is_reversed'])->toBeFalse()
        ->and($item['is_active'])->toBeTrue()
        ->and($item['amount_cop'])->toBe(80000)
        ->and($item['obligation'])->toHaveKey('balance_cop')
        // Flat copies, so a table column does not have to reach three levels in.
        ->and($item['obligation_label'])->not->toBeNull()
        ->and($item['company_name'])->not->toBeNull();

    app(ManagePayments::class)->reverseAllocation($allocation, 'Mal aplicado.', actingAsRole());

    $after = $this->actingAs(a03_admin())
        ->getJson("/api/payments/{$payment->id}")
        ->json('payment.allocations.0');

    // The reversed row is still there, marked as such.
    expect($after['is_reversed'])->toBeTrue()
        ->and($after['is_active'])->toBeFalse()
        ->and($after['reversal_reason'])->toBe('Mal aplicado.')
        ->and($after['reversed_at'])->not->toBeNull();
});

// --- §55: the server refuses what the interface hides ------------------------

it('refuses a financial write for a role that may only read', function (mixed $method, string $uri, array $body = []): void {
    $user = a03_minimalUser([
        'periods.view', 'obligations.view', 'payments.view',
        'receivables.view', 'cutoffs.view', 'rates.view',
    ], 'lectura');

    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(200000, $employer);
    $adjustment = ObligationAdjustment::query()
        ->where('obligation_id', $obligation->id)
        ->first()
        ?? app(AdjustObligation::class)->execute(
            $obligation,
            AdjustmentType::Correction,
            -1000,
            'Ajuste para poder intentar revertirlo.',
            $user,
        );

    $allocation = PaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->first()
        ?? app(ManagePayments::class)->allocate($payment, $obligation, 1000, $user);

    $uri = strtr($uri, [
        ':period' => (string) $period->id,
        ':obligation' => (string) $obligation->id,
        ':payment' => (string) $payment->id,
        ':allocation' => (string) $allocation->id,
        ':adjustment' => (string) $adjustment->id,
        ':client' => (string) $employer['client']->id,
        ':company' => (string) $employer['company']->id,
    ]);

    $body = array_map(
        fn (mixed $value): mixed => strtr((string) $value, [':period' => (string) $period->id]),
        $body,
    );

    $this->actingAs($user)->json($method, $uri, $body)->assertStatus(403);
})->with([
    'open a period' => ['POST', '/api/periods', ['period_month' => '2026-11']],
    'generate' => ['POST', '/api/periods/:period/obligations/generate'],
    'close' => ['POST', '/api/periods/:period/close', ['confirm' => true]],
    'reopen' => ['POST', '/api/periods/:period/reopen', ['reason' => 'Porque sí.', 'confirm' => true]],
    'create a rate' => ['POST', '/api/rates', ['client_id' => ':client', 'company_id' => ':company', 'effective_month' => '2026-01-01', 'amount_cop' => 1000]],
    'create a cutoff' => ['POST', '/api/cutoff-rules', ['scope' => 'general', 'effective_month' => '2026-01-01', 'cutoff_day' => 10, 'month_offset' => 1]],
    'create a payment' => ['POST', '/api/payments', ['client_id' => ':client', 'amount_cop' => 1000, 'received_on' => '2026-10-15', 'method' => 'cash']],
    'allocate' => ['POST', '/api/payments/:payment/allocations', ['obligation_id' => ':obligation', 'amount_cop' => 1000]],
    'auto-allocate' => ['POST', '/api/payments/:payment/auto-allocate'],
    'void' => ['POST', '/api/payments/:payment/void', ['reason' => 'Porque sí.', 'confirm' => true]],
    'adjust' => ['POST', '/api/obligations/:obligation/adjustments', ['type' => 'correction', 'delta_cop' => 1000, 'reason' => 'Porque sí.']],
    'reverse an adjustment' => ['POST', '/api/obligation-adjustments/:adjustment/reverse', ['reason' => 'Porque sí.', 'confirm' => true]],
    'reverse an allocation' => ['POST', '/api/payment-allocations/:allocation/reverse', ['reason' => 'Porque sí.', 'confirm' => true]],
]);

it('keeps receivables.view away from the payments domain', function (): void {
    $user = a03_minimalUser(['receivables.view'], 'solo-cartera');

    // Reads the cartera.
    $this->actingAs($user)->getJson('/api/receivables')->assertOk();

    // And nothing in the payments or configuration domains.
    $this->actingAs($user)->getJson('/api/payments')->assertStatus(403);
    $this->actingAs($user)->getJson('/api/payments/vocabulary')->assertStatus(403);
    $this->actingAs($user)->getJson('/api/cutoff-rules')->assertStatus(403);
    $this->actingAs($user)->getJson('/api/rates')->assertStatus(403);
    $this->actingAs($user)->getJson('/api/periods')->assertStatus(403);
});

it('lets a rates-only role read rates and not cutoffs', function (): void {
    $user = a03_minimalUser(['rates.view'], 'solo-valores');

    $this->actingAs($user)->getJson('/api/rates')->assertOk();
    $this->actingAs($user)->getJson('/api/cutoff-rules')->assertStatus(403);
});

it('lets a cutoffs-only role read cutoffs and not rates', function (): void {
    $user = a03_minimalUser(['cutoffs.view'], 'solo-cortes');

    $this->actingAs($user)->getJson('/api/cutoff-rules')->assertOk();
    $this->actingAs($user)->getJson('/api/rates')->assertStatus(403);
});

it('lets a generator preview without a separate view permission', function (): void {
    // §36. Being able to act but not to see the confirmation step is the nonsensical state.
    $user = a03_minimalUser(['periods.view', 'obligations.generate'], 'genera');
    $period = a03_generatedPeriod('2026-10');

    $this->actingAs($user)
        ->postJson("/api/periods/{$period->id}/obligations/preview")
        ->assertOk()
        ->assertJsonPath('preview.can_generate', true);
});

it('lets a viewer read the generation plan without being able to execute it', function (): void {
    $viewer = a03_minimalUser(['periods.view', 'obligations.view'], 've-generacion');
    $period = a03_generatedPeriod('2026-10');

    $this->actingAs($viewer)
        ->postJson("/api/periods/{$period->id}/obligations/preview")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/periods/{$period->id}/obligations/generate")
        ->assertStatus(403);
});

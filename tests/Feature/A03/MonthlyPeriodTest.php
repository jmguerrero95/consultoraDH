<?php

declare(strict_types=1);

use App\Domain\Periods\Actions\ClosePeriod;
use App\Domain\Periods\Actions\CreatePeriod;
use App\Domain\Periods\Actions\ReopenPeriod;
use App\Domain\Periods\ClosePeriodBlocked;
use App\Domain\Periods\MonthlyPeriod as Month;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodAlreadyExists;
use App\Domain\Periods\PeriodAlreadyOpen;
use App\Domain\Periods\ReopenReasonRequired;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod as PeriodRow;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;

/**
 * Monthly periods: the month boundary, creation, and the state machine.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

// --- The month itself -------------------------------------------------------

it('reads a period as the half-open interval it is', function (): void {
    $october = Month::fromKey('2026-10');

    // The same convention as A02's EffectivePeriod: [starts, ends). A relationship
    // that ends on the first of November was not in October.
    expect($october->key())->toBe('2026-10')
        ->and($october->startsOn()->format('Y-m-d'))->toBe('2026-10-01')
        ->and($october->endsOnExclusive()->format('Y-m-d'))->toBe('2026-11-01')
        ->and($october->label())->toBe('Octubre 2026');
});

it('refuses a month that does not exist instead of correcting it', function (string $key): void {
    // `2026-13` repaired to January 2027 would invent a period nobody asked for.
    expect(fn () => Month::fromKey($key))->toThrow(InvalidArgumentException::class);
})->with(['2026-13', '2026-00', 'octubre', '20261', '2026-1']);

it('builds from a year and a month the way a person counts', function (): void {
    expect(Month::fromYearMonth(2026, 1)->key())->toBe('2026-01')
        ->and(Month::fromYearMonth(2026, 12)->key())->toBe('2026-12');
});

it('knows the last day of a short month', function (): void {
    expect(Month::fromKey('2026-02')->lastDay()->format('Y-m-d'))->toBe('2026-02-28')
        // A leap year, which is the case a clamping rule gets wrong once every four
        // years.
        ->and(Month::fromKey('2028-02')->lastDay()->format('Y-m-d'))->toBe('2028-02-29');
});

// --- Creation ---------------------------------------------------------------

it('creates a period only because somebody asked', function (): void {
    expect(PeriodRow::query()->count())->toBe(0);

    $period = app(CreatePeriod::class)->execute(
        Month::fromKey('2026-10'),
        actingAsRole(),
    );

    expect($period->isOpen())->toBeTrue()
        ->and($period->key())->toBe('2026-10')
        ->and($period->period_month->format('d'))->toBe('01')
        // The calendar advancing does not create months.
        ->and(PeriodRow::query()->count())->toBe(1);
});

it('refuses a month that already exists rather than returning it', function (): void {
    $resolver = app(MonthlyPeriodResolver::class);
    app(CreatePeriod::class)->execute(Month::fromKey('2026-10'), actingAsRole());

    // Silently returning the existing period would let an operator believe they had
    // opened a month when they had done nothing.
    expect(fn () => app(CreatePeriod::class)->execute(Month::fromKey('2026-10'), actingAsRole()))
        ->toThrow(PeriodAlreadyExists::class);

    expect(PeriodRow::query()->count())->toBe(1);
});

// --- Current period ---------------------------------------------------------

it('prefers the period matching the calendar month even when an earlier one is open', function (): void {
    $resolver = app(MonthlyPeriodResolver::class);
    $september = app(CreatePeriod::class)->execute(Month::fromKey('2026-09'), actingAsRole());
    $october = app(CreatePeriod::class)->execute(Month::fromKey('2026-10'), actingAsRole());

    // Two open periods is legitimate: September may still be being corrected while
    // October is billed. The one that matches today is the current one.
    expect($resolver->current(Carbon::parse('2026-10-15'))->id)->toBe($october->id);

    // And a closed period is never current.
    $october->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    expect($resolver->current(Carbon::parse('2026-10-15'))->id)->toBe($september->id);
});

it('falls back to the most recent open period when the month has not been created', function (): void {
    $resolver = app(MonthlyPeriodResolver::class);
    $september = app(CreatePeriod::class)->execute(Month::fromKey('2026-09'), actingAsRole());

    // On the first of the month, before anybody has created it: the newest open
    // period is where work happens. Answering "none" would make the screen useless
    // for the first few days of every month.
    expect($resolver->current(Carbon::parse('2026-10-02'))->id)->toBe($september->id);
});

it('has no current period when everything is closed or nothing exists', function (): void {
    expect(app(MonthlyPeriodResolver::class)->current())->toBeNull();

    $period = a03_generatedPeriod('2026-10');
    app(ClosePeriod::class)->execute($period, actingAsRole());

    expect(app(MonthlyPeriodResolver::class)->current())->toBeNull();
});

// --- Closing ----------------------------------------------------------------

it('closes a period that has been generated', function (): void {
    $period = a03_generatedPeriod('2026-10');
    $actor = actingAsRole();

    $closed = app(ClosePeriod::class)->execute($period, $actor);

    expect($closed->isClosed())->toBeTrue()
        ->and($closed->closed_at)->not->toBeNull()
        // Who closed a month is the first question asked about a corrected one.
        ->and($closed->closed_by)->toBe($actor->id);
});

it('refuses to close a period nobody has generated into', function (): void {
    $period = a03_openPeriod('2026-10');

    // An untouched month and an empty month look identical from the obligation
    // table, and only this marker tells them apart.
    expect(fn () => app(ClosePeriod::class)->execute($period, actingAsRole()))
        ->toThrow(ClosePeriodBlocked::class);

    expect($period->fresh()->isOpen())->toBeTrue();
});

it('closes a period that was generated and produced nothing', function (): void {
    $period = a03_generatedPeriod('2026-10');

    // A month in which nobody was billed is a real answer. Refusing to close it would
    // leave the system unable to settle an empty portfolio for ever.
    $closed = app(ClosePeriod::class)->execute($period, actingAsRole());

    expect($closed->isClosed())->toBeTrue();
});

it('refuses to close a period that is already closed', function (): void {
    $period = a03_generatedPeriod('2026-10');
    $closer = app(ClosePeriod::class);
    $closer->execute($period, actingAsRole());

    expect(fn () => $closer->execute($period->refresh(), actingAsRole()))
        ->toThrow(ClosePeriodBlocked::class);
});

// --- Reopening --------------------------------------------------------------

it('reopens a closed period with a reason, and keeps the reason', function (): void {
    $period = a03_generatedPeriod('2026-10');
    app(ClosePeriod::class)->execute($period, actingAsRole());

    $reopened = app(ReopenPeriod::class)->execute(
        $period->refresh(),
        actingAsRole(),
        'Se detectó que faltaba un valor de marzo',
    );

    expect($reopened->isOpen())->toBeTrue()
        ->and($reopened->closed_at)->toBeNull()
        ->and($reopened->reopened_at)->not->toBeNull()
        ->and($reopened->last_reopen_reason)->toBe('Se detectó que faltaba un valor de marzo');
});

it('refuses to reopen without a reason', function (): void {
    $period = a03_generatedPeriod('2026-10');
    app(ClosePeriod::class)->execute($period, actingAsRole());

    expect(fn () => app(ReopenPeriod::class)->execute($period->refresh(), actingAsRole(), '   '))
        ->toThrow(ReopenReasonRequired::class);
});

it('refuses to reopen a period that is already open', function (): void {
    $period = a03_generatedPeriod('2026-10');

    expect(fn () => app(ReopenPeriod::class)->execute($period, actingAsRole(), 'Porque sí'))
        ->toThrow(PeriodAlreadyOpen::class);
});

it('does not delete money when a period is reopened', function (): void {
    // Reopening withdraws a settled statement. It does not remove the payments made
    // against it: that would destroy evidence rather than correct a mistake.
    $period = a03_generatedPeriod('2026-10');
    $employer = a03_employer();

    $obligation = MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'base_amount_cop' => 235000,
        'due_on' => '2026-11-10',
    ]);

    $payment = Payment::factory()->create([
        'client_id' => $employer['client']->id,
        'amount_cop' => 100000,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'obligation_id' => $obligation->id,
        'amount_cop' => 100000,
    ]);

    app(ClosePeriod::class)->execute($period, actingAsRole());
    app(ReopenPeriod::class)->execute($period->refresh(), actingAsRole(), 'Corrección de marzo');

    expect(PaymentAllocation::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        ->and($obligation->fresh()->exists())->toBeTrue();
});

it('does not regenerate anything when a period is reopened', function (): void {
    $period = a03_generatedPeriod('2026-10');
    $employer = a03_employer();

    $obligation = MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'base_amount_cop' => 235000,
        'due_on' => '2026-11-10',
    ]);

    app(ClosePeriod::class)->execute($period, actingAsRole());
    app(ReopenPeriod::class)->execute($period->refresh(), actingAsRole(), 'Corrección de marzo');

    // Reopening makes generation possible again; it does not perform it. The amount
    // that was generated is still the amount that was generated.
    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and($obligation->fresh()->base_amount_cop)->toBe(235000);
});

// --- Permissions ------------------------------------------------------------

it('gates each period operation behind its own permission', function (): void {
    $period = a03_generatedPeriod('2026-10');

    // Only the reopen permission. Creating, closing and viewing are all refused,
    // which is what proves each gate is separate rather than one broad financial
    // permission wearing four names.
    $onlyReopen = userWithPermissions(['periods.reopen']);

    $this->actingAs($onlyReopen)->getJson('/api/periods')->assertForbidden();
    $this->actingAs($onlyReopen)->postJson('/api/periods', ['period_month' => '2026-11'])->assertForbidden();
    $this->actingAs($onlyReopen)->postJson("/api/periods/{$period->id}/close", ['confirm' => true])->assertForbidden();

    // And it can reopen.
    app(ClosePeriod::class)->execute($period, actingAsRole());
    $this->actingAs($onlyReopen)
        ->postJson("/api/periods/{$period->id}/reopen", ['reason' => 'Motivo suficientemente largo', 'confirm' => true])
        ->assertOk();
});

it('refuses closing to a role that may only view', function (): void {
    $period = a03_generatedPeriod('2026-10');

    $this->actingAs(userWithPermissions(['periods.view']))
        ->postJson("/api/periods/{$period->id}/close", ['confirm' => true])
        ->assertForbidden();
});

it('refuses a close without the confirmation', function (): void {
    $period = a03_generatedPeriod('2026-10');

    $this->actingAs(actingAsRole())
        ->postJson("/api/periods/{$period->id}/close", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('confirm');
});

it('keeps the two deliberate asymmetries in the role matrix', function (): void {
    // Operations closes a month as the ordinary end of billing, but does not reopen
    // one: reopening withdraws a settled statement and leaves a reason behind.
    expect(actingAsRole('Operations')->can('periods.close'))->toBeTrue()
        ->and(actingAsRole('Operations')->can('periods.reopen'))->toBeFalse();

    // Collections settles money and can void a payment that was recorded wrongly.
    // It cannot reduce what somebody is owed: that is a financial correction, not a
    // collection action.
    expect(actingAsRole('Collections')->can('payments.view'))->toBeTrue()
        ->and(actingAsRole('Collections')->can('payments.create'))->toBeTrue()
        ->and(actingAsRole('Collections')->can('payments.allocate'))->toBeTrue()
        ->and(actingAsRole('Collections')->can('payments.void'))->toBeTrue()
        ->and(actingAsRole('Collections')->can('obligations.adjust'))->toBeFalse()
        // And it cannot manufacture the debts it is settling.
        ->and(actingAsRole('Collections')->can('obligations.generate'))->toBeFalse();

    // Read Only sees every financial screen and changes nothing at all.
    foreach ([
        'periods.view', 'cutoffs.view', 'rates.view', 'obligations.view',
        'payments.view', 'receivables.view',
    ] as $permission) {
        expect(actingAsRole('Read Only')->can($permission))->toBeTrue();
    }

    foreach ([
        'periods.create', 'periods.close', 'periods.reopen',
        'cutoffs.manage', 'rates.manage',
        'obligations.adjust', 'obligations.generate',
        'payments.create', 'payments.allocate', 'payments.void',
    ] as $permission) {
        expect(actingAsRole('Read Only')->can($permission))->toBeFalse();
    }
});

// --- A03-R1: the value object always represents the first day ----------------

it('normalizes any date in a month to the first day', function (): void {
    // The defect this fixes: `fromFirstDay` dropped the time but kept the day, so the
    // object claimed to be a month while carrying 2026-10-15. A `MonthlyPeriod` that is
    // not the first day of its month breaks every comparison against `period_month`.
    expect(Month::fromFirstDay('2026-10-15')->startsOn()->format('Y-m-d'))->toBe('2026-10-01')
        ->and(Month::fromFirstDay('2026-10-01')->startsOn()->format('Y-m-d'))->toBe('2026-10-01')
        ->and(Month::fromFirstDay('2026-10-31')->startsOn()->format('Y-m-d'))->toBe('2026-10-01')
        // The key follows, so a normalized instance still names its own month.
        ->and(Month::fromFirstDay('2026-10-15')->key())->toBe('2026-10');
});

it('normalizes a date carrying a time to midnight on the first day', function (): void {
    $month = Month::fromFirstDay(Carbon::parse('2026-10-15 14:30:45'));

    expect($month->startsOn()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:00:00')
        ->and($month->key())->toBe('2026-10');
});

it('normalizes through parse as well, so no path keeps a day', function (string $input, string $expected): void {
    expect(Month::parse($input)->startsOn()->format('Y-m-d'))->toBe($expected);
})->with([
    'key' => ['2026-10', '2026-10-01'],
    'first day' => ['2026-10-01', '2026-10-01'],
    'mid month' => ['2026-10-15', '2026-10-01'],
    'with time' => ['2026-10-15T23:59:59', '2026-10-01'],
    'last day' => ['2026-10-31', '2026-10-01'],
]);

it('resolves October when September, October and November are all open', function (): void {
    $resolver = app(MonthlyPeriodResolver::class);

    $september = app(CreatePeriod::class)->execute(Month::fromKey('2026-09'), actingAsRole());
    $october = app(CreatePeriod::class)->execute(Month::fromKey('2026-10'), actingAsRole());
    $november = app(CreatePeriod::class)->execute(Month::fromKey('2026-11'), actingAsRole());

    // The reproduction from the review. With the un-normalized value object the exact
    // match was asked for `2026-10-15`, found nothing, and fell through to "newest
    // open period" — which is November. Billing the wrong month is the failure this
    // asserts against, so all three periods exist and November is deliberately the
    // one the broken fallback would have chosen.
    expect($resolver->current(Carbon::parse('2026-10-15'))->id)->toBe($october->id)
        ->and($resolver->current(Carbon::parse('2026-10-15'))->id)->not->toBe($november->id)
        ->and($resolver->current(Carbon::parse('2026-10-15'))->id)->not->toBe($september->id);

    // The first of the month, with a time, is still October.
    expect($resolver->current(Carbon::parse('2026-10-01 00:00:01'))->id)->toBe($october->id)
        ->and($resolver->current(Carbon::parse('2026-10-31 23:59:59'))->id)->toBe($october->id);
});

it('falls back to the newest open period when October itself does not exist', function (): void {
    $resolver = app(MonthlyPeriodResolver::class);

    $september = app(CreatePeriod::class)->execute(Month::fromKey('2026-09'), actingAsRole());
    $november = app(CreatePeriod::class)->execute(Month::fromKey('2026-11'), actingAsRole());

    // With September and November open and no October row, there is nothing to match,
    // so the documented fallback applies: the newest open period is where work
    // happens. This is stated behaviour, not an accident.
    expect($resolver->current(Carbon::parse('2026-10-15'))->id)->toBe($november->id)
        ->and($resolver->current(Carbon::parse('2026-10-15'))->id)->not->toBe($september->id);
});

// --- A03-R1: an impossible month is a validation error, never a 500 ------------

it('refuses an impossible month at the HTTP boundary with 422 and a field error', function (string $month): void {
    $this->actingAs(actingAsRole());

    $response = $this->postJson('/api/periods', ['period_month' => $month]);

    // A 500 would mean `Carbon::parse` or PostgreSQL threw instead of the request
    // being refused. The field has to be named, or the operator cannot tell what to
    // correct.
    $response->assertStatus(422)->assertJsonValidationErrors('period_month');

    expect($response->json('errors.period_month.0'))->toBeString()->not->toBeEmpty();
})->with([
    'month zero' => ['2026-00'],
    'month thirteen' => ['2026-13'],
    'month ninety nine' => ['2026-99'],
    'month negative shape' => ['2026-1'],
    'year too small' => ['0001-01'],
    'year too large' => ['9999-01'],
]);

it('refuses an impossible effective month for rates and cutoffs with 422', function (string $month): void {
    $this->actingAs(actingAsRole());
    $employer = a03_employerFor('imposible');
    $client = $employer['client'];
    $company = $employer['company'];

    $this->postJson('/api/rates', [
        'client_id' => $client->id,
        'company_id' => $company->id,
        'effective_month' => $month,
        'amount_cop' => 235000,
    ])->assertStatus(422)->assertJsonValidationErrors('effective_month');

    $this->postJson('/api/cutoff-rules', [
        'scope' => 'general',
        'effective_month' => $month,
        'cutoff_day' => 10,
        'month_offset' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('effective_month');
})->with([
    'month zero' => ['2026-00-01'],
    'month thirteen' => ['2026-13-01'],
    'month ninety nine' => ['2026-99-01'],
    'not the first day' => ['2026-10-15'],
]);

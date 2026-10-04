<?php

declare(strict_types=1);

use App\Models\MonthlyPeriod;

/**
 * A03-R2 §7: a reference date that cannot be read is refused, not dropped.
 *
 * ## The defect
 *
 * Two endpoints took a bare `Request` and read their reference date with
 * `$request->date('as_of')`. That method answers `null` for anything it cannot parse, and the
 * callers treated `null` as "no reference date given":
 *
 *     $asOf = $request->date('as_of') ?? now();     // PeriodController::obligations()
 *     $this->receivables->clientAccount($client, $request->date('as_of'));
 *
 * So `?as_of=2026-13-45` and `?as_of=ayer` were not errors. They were dropped, and the screen
 * came back with today's figures.
 *
 * That is the worst shape this bug could have taken. A filter that is ignored shows nothing:
 * the table simply does not move and the operator tries the next thing. A filter that is
 * *silently replaced* shows a confident, plausible, wrong answer — a balance that was overdue
 * on the day asked about shown as current, or a settled obligation shown as still owed. The
 * receivables list has always refused the same value (`date_format:Y-m-d`), so the two screens
 * that accept a reference date disagreed about whether a bad one was an error.
 *
 * ## What is asserted
 *
 * Every spelling of a date the database cannot read is a 422 naming the field. Every spelling
 * it can read is honoured, including the ones that produce a genuinely different answer, so
 * the fix is shown not to have become "always answer with today's figures".
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** @return array<string, array{string}> */
function unreadableDates(): array
{
    return [
        'impossible month' => ['2026-13-01'],
        'impossible day' => ['2026-02-30'],
        'words' => ['ayer'],
        'sql' => ['2026-01-01 OR 1=1'],
        'time only' => ['10:30'],
        'timestamp' => ['2026-01-01T10:30:00'],
        'slashes' => ['2026/01/01'],
        'month name' => ['enero 2026'],
        'trailing junk' => ['2026-01-01x'],
    ];
}

it('refuses an unreadable reference date on the portfolio instead of answering for today', function (string $date): void {
    a03_payable(250000, '2026-10', a03_employer());

    $response = $this->actingAs(userWithPermissions(['receivables.view']))
        ->getJson('/api/receivables?as_of='.urlencode($date));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('as_of');

    expect($response->json('errors.as_of.0'))->toContain('fecha');
})->with(unreadableDates());

it('refuses an unreadable reference date on the client account too', function (string $date): void {
    $employer = a03_employer();
    a03_payable(250000, '2026-10', $employer);

    $this->actingAs(userWithPermissions(['receivables.view']))
        ->getJson("/api/clients/{$employer['client']->id}/account?as_of=".urlencode($date))
        ->assertStatus(422)
        ->assertJsonValidationErrors('as_of');
})->with(unreadableDates());

it('refuses an unreadable reference date on the month\'s obligations', function (string $date): void {
    $employer = a03_employer();
    a03_payable(250000, '2026-10', $employer);

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    // §4 of R3. This asserted only the status code and the word "fecha", which a hand-rolled
    // `abort(422, $message)` in the controller satisfied — and that response has no `errors`
    // envelope at all, so a client could not tell which input had been rejected. The endpoint
    // now takes `ListPeriodObligationsRequest`, like the two receivables endpoints, and the
    // standard field error is asserted here so it cannot go back to a bare message.
    $this->actingAs(userWithPermissions(['obligations.view', 'periods.view']))
        ->getJson("/api/periods/{$period->id}/obligations?as_of=".urlencode($date))
        ->assertStatus(422)
        ->assertJsonValidationErrors('as_of');

    expect($this->actingAs(userWithPermissions(['obligations.view', 'periods.view']))
        ->getJson("/api/periods/{$period->id}/obligations?as_of=".urlencode($date))
        ->json('errors.as_of.0'))->toContain('fecha de referencia');
})->with(unreadableDates());

it('answers the three reference-date surfaces in the same validation shape', function (): void {
    // The finding behind §4: three endpoints validate one field, and one of them did it in a
    // different shape. A caller cannot branch on "was it rejected" and get a consistent
    // answer, and the inconsistent one was the period's.
    $employer = a03_employer();
    a03_payable(250000, '2026-10', $employer);

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    $this->actingAs(userWithPermissions(['receivables.view', 'obligations.view', 'periods.view']));

    $endpoints = [
        'GET /api/receivables' => '/api/receivables?as_of=2026-13-01',
        'GET /api/clients/{id}/account' => '/api/clients/'.$employer['client']->id.'/account?as_of=2026-13-01',
        'GET /api/periods/{id}/obligations' => "/api/periods/{$period->id}/obligations?as_of=2026-13-01",
    ];

    foreach ($endpoints as $label => $url) {
        $response = $this->getJson($url)->assertStatus(422)->assertJsonValidationErrors('as_of');

        // The same envelope, the same field, the same sentence. A caller that reads
        // `errors.as_of` works against all three.
        expect($response->json('errors'), $label)->toHaveKey('as_of')
            ->and($response->json('errors.as_of.0'), $label)->toContain('fecha de referencia');
    }
});

it('honours a reference date that can be read, and it changes the answer', function (): void {
    $employer = a03_employer();

    // Due 10 November 2026. Today is later than that in the test's clock, so the obligation
    // is overdue now.
    $obligation = a03_payable(250000, '2026-10', $employer);
    expect($obligation->due_on->toDateString())->toBe('2026-11-10');

    $this->actingAs(userWithPermissions(['receivables.view', 'obligations.view', 'periods.view']));

    $asOf = fn (string $date) => collect(
        $this->getJson("/api/clients/{$employer['client']->id}/account?as_of={$date}")
            ->assertOk()
            ->json('obligations')
    )->first();

    // The reference date is honoured on the account.
    expect($asOf('2026-11-01')['is_overdue'])->toBeFalse()
        ->and($asOf('2026-11-10')['is_overdue'])->toBeFalse()
        // The day after it falls due it is overdue.
        ->and($asOf('2026-11-11')['is_overdue'])->toBeTrue();

    // And the totals follow it, which is the point: an answer that did not change with the
    // date would be today's answer wearing a date.
    expect($asOf('2026-11-01')['days_late'])->toBe(0)
        ->and($asOf('2026-11-16')['days_late'])->toBe(6)
        ->and($asOf('2026-11-16')['aging_bucket'])->toBe('1_30');

    // No `as_of` at all is today's figures, and that is still allowed.
    $without = collect(
        $this->getJson("/api/clients/{$employer['client']->id}/account")
            ->assertOk()
            ->json('obligations')
    )->first();

    expect($without)->toHaveKeys(['is_overdue', 'days_late', 'aging_bucket']);

    // A blank reference date is an **absent** one, not an unreadable one: clearing a date
    // input sends an empty parameter, and that has to mean "today" rather than being an
    // error the operator cannot cause and cannot clear.
    expect($this->getJson('/api/receivables?as_of=')->assertOk()
        ->json('applied_filters.as_of'))->not->toBe('');

    // The same on the list, and on the month's obligations.
    expect($this->getJson('/api/receivables?as_of=2026-11-01')->assertOk()
        ->json('applied_filters.as_of'))->toBe('2026-11-01');

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    $inNovember = collect(
        $this->getJson("/api/periods/{$period->id}/obligations?as_of=2026-11-01")
            ->assertOk()
            ->json('items')
    )->first();

    expect($inNovember['is_overdue'])->toBeFalse();
});

it('agrees on the same date across the three surfaces that take one', function (): void {
    $employer = a03_employer();
    a03_payable(250000, '2026-10', $employer);

    $period = MonthlyPeriod::query()->where('period_month', '2026-10-01')->firstOrFail();

    $this->actingAs(userWithPermissions(['receivables.view', 'obligations.view', 'periods.view']));

    foreach (['2026-11-01', '2026-11-11', '2026-12-20'] as $date) {
        $onAccount = collect(
            $this->getJson("/api/clients/{$employer['client']->id}/account?as_of={$date}")
                ->assertOk()
                ->json('obligations')
        )->first();

        $onPeriod = collect(
            $this->getJson("/api/periods/{$period->id}/obligations?as_of={$date}")
                ->assertOk()
                ->json('items')
        )->first();

        expect($onPeriod['is_overdue'], $date)->toBe($onAccount['is_overdue'])
            ->and($onPeriod['days_late'], $date)->toBe($onAccount['days_late'])
            ->and($onPeriod['aging_bucket'], $date)->toBe($onAccount['aging_bucket'])
            ->and($onPeriod['balance_cop'], $date)->toBe($onAccount['balance_cop']);
    }
});

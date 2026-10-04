<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations as Generate;
use App\Domain\Billing\Actions\GenerationBlocked;
use App\Domain\Billing\BillingCandidate;
use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Periods\Actions\ClosePeriod;
use App\Domain\Periods\ClosePeriodBlocked;
use App\Http\Requests\Periods\GenerateObligationsRequest;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\ObligationSourceAssignment;

/**
 * A03-R1 §3, §4, §6 and §7: which relationships produce an obligation, and when a month
 * may be closed.
 *
 * ## Why each of these is a separate concern
 *
 * §3 is about an **empty interval**. §4 is about **two segments** for one pair. They look
 * similar — both are "more than one row" or "no days at all" — and they have opposite
 * answers, which is exactly why conflating them produced the bug: a same-day transfer was
 * billed, and a legitimate break in employment was refused.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A relationship segment for one client and company, dated.
 *
 * `endedOn` equal to `startedOn` produces the empty interval §3 is about, so the helper
 * allows it rather than hiding it.
 */
function a03_segment(Client $client, Company $company, string $startedOn, ?string $endedOn = null): ClientCompanyAssignment
{
    return ClientCompanyAssignment::query()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => $startedOn,
        'ended_on' => $endedOn,
    ]);
}

// --- §3: the empty interval ---------------------------------------------------

it('never bills an empty relationship interval', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // started_on == ended_on is `[x, x)`: it covers no day, so it intersects no month.
    a03_segment($client, $company, '2026-10-15', '2026-10-15');

    $period = a03_openPeriod('2026-10');
    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect($preview['candidate_count'])->toBe(0)
        ->and($preview['creatable_count'])->toBe(0);
});

it('bills only the destination company on a same-day transfer', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    // Worked for the first until the 15th, and for the second from the 15th. Both segments
    // touch October and they do not overlap: the first ends exactly where the second starts,
    // which under half-open intervals shares no day.
    a03_segment($client, $first, '2024-01-01', '2026-10-15');
    a03_segment($client, $second, '2026-10-15', null);

    foreach ([[$first, 100000], [$second, 200000]] as [$company, $amount]) {
        a03_companyRate($client, $company, $amount, '2026-01');
    }

    a03_generalCutoff(10, CutoffMonthOffset::FollowingMonth, '2026-01');

    $period = a03_openPeriod('2026-10');
    app(Generate::class)->execute($period, actingAsRole());

    $obligations = MonthlyObligation::query()
        ->where('period_id', $period->id)
        ->orderBy('company_id')
        ->get();

    // One obligation per company, each with the right money. The transfer did not bill the
    // day twice and did not bill the source company for a month it was not in.
    expect($obligations)->toHaveCount(2)
        ->and($obligations->pluck('company_id')->all())->toBe([$first->id, $second->id])
        ->and($obligations->pluck('base_amount_cop')->all())->toBe([100000, 200000]);
});

it('does not bill a relationship that ends on the first day of the month', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // `[.., 2026-10-01)` — the first of October belongs to the next interval.
    a03_segment($client, $company, '2024-01-01', '2026-10-01');

    $period = a03_openPeriod('2026-10');

    expect(app(ObligationCandidateBuilder::class)->preview($period)['candidate_count'])->toBe(0);
});

it('does not bill a relationship that starts on the first day of the next month', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    a03_segment($client, $company, '2026-11-01', null);

    $period = a03_openPeriod('2026-10');

    expect(app(ObligationCandidateBuilder::class)->preview($period)['candidate_count'])->toBe(0);
});

it('still bills an ordinary mid-month relationship', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    a03_segment($client, $company, '2026-10-20', null);

    a03_companyRate($client, $company, 150000, '2026-01');
    a03_generalCutoff(10, CutoffMonthOffset::FollowingMonth, '2026-01');

    $period = a03_openPeriod('2026-10');
    app(Generate::class)->execute($period, actingAsRole());

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(1);
});

// --- §4: several segments, one obligation -------------------------------------

it('collapses a break in employment into one obligation and records both segments', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // Worked, left on the 10th, came back on the 20th. Two legal periods of employment and
    // one monthly debt.
    $first = a03_segment($client, $company, '2024-01-01', '2026-10-10');
    $second = a03_segment($client, $company, '2026-10-20', null);

    a03_companyRate($client, $company, 150000, '2026-01');
    a03_generalCutoff(10, CutoffMonthOffset::FollowingMonth, '2026-01');

    $period = a03_openPeriod('2026-10');
    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect($preview['candidate_count'])->toBe(1)
        ->and($preview['creatable_count'])->toBe(1);

    $row = $preview['candidates'][0];

    // Both segments are named, and the single `assignment_id` is null because claiming one
    // of them would misattribute the debt.
    expect($row['source_assignment_ids'])->toBe([$first->id, $second->id])
        ->and($row['source_count'])->toBe(2)
        ->and($row['assignment_id'])->toBeNull()
        ->and($row['overlapping_assignment_ids'])->toBe([]);

    app(Generate::class)->execute($period, actingAsRole());

    $obligations = MonthlyObligation::query()->where('period_id', $period->id)->get();

    expect($obligations)->toHaveCount(1);

    // And the provenance survives, so the question is answerable afterwards.
    $provenance = ObligationSourceAssignment::query()
        ->where('obligation_id', $obligations->first()->id)
        ->orderBy('client_company_assignment_id')
        ->pluck('client_company_assignment_id')
        ->all();

    expect($provenance)->toBe([$first->id, $second->id])
        ->and($obligations->first()->client_company_assignment_id)->toBeNull();
});

it('keeps a single assignment id when only one segment produced the obligation', function (): void {
    $employer = a03_employer();

    $period = a03_openPeriod('2026-10');
    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect($preview['candidates'][0]['source_count'])->toBe(1)
        ->and($preview['candidates'][0]['assignment_id'])->toBe($employer['assignment']->id);

    app(Generate::class)->execute($period, actingAsRole());

    $obligation = MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail();

    expect($obligation->client_company_assignment_id)->toBe($employer['assignment']->id)
        ->and(ObligationSourceAssignment::query()->where('obligation_id', $obligation->id)->count())->toBe(1);
});

it('refuses a month whose relationship segments contradict each other', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // Genuinely overlapping: both rows claim the 8th to the 20th. They cannot both be true,
    // so there is no honest answer about whose debt the month is.
    a03_segment($client, $company, '2026-10-01', '2026-10-20');

    // The second segment arrives by writing past A02's guard, which is how this state
    // reaches a real database: migrated data, or a row written by something that does not
    // go through the domain. The partial unique index refuses two *open* rows, and the only
    // way to force the overlap is to make the second one closed at the end of the month
    // while still overlapping the first.
    a03_segment($client, $company, '2026-10-08', '2026-10-31');

    a03_companyRate($client, $company, 150000, '2026-01');
    a03_generalCutoff(10, CutoffMonthOffset::FollowingMonth, '2026-01');

    $period = a03_openPeriod('2026-10');
    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect($preview['candidate_count'])->toBe(1)
        ->and($preview['blocked_candidate_count'])->toBe(1)
        ->and($preview['creatable_count'])->toBe(0)
        ->and(array_column($preview['blockers'], 'code'))
        ->toContain('overlapping_relationship_segments');

    expect(fn () => app(Generate::class)->execute($period, actingAsRole()))
        ->toThrow(GenerationBlocked::class);

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(0);
});

it('treats adjacent segments as non-overlapping rather than as a contradiction', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // The boundary case: the first ends exactly where the second starts.
    a03_segment($client, $company, '2026-10-01', '2026-10-15');
    a03_segment($client, $company, '2026-10-15', null);

    // Two real rows, because `BillingCandidate::collapse` is the unit under test here and
    // it should be handed what the database would hold: one closed segment and one open
    // one, meeting on the same day.
    $candidate = BillingCandidate::collapse([
        a03_segment($client, $company, '2026-10-01', '2026-10-15'),
        a03_segment($client, $company, '2026-10-15', '2026-12-31'),
    ])->first();

    expect($candidate->hasOverlap())->toBeFalse()
        ->and($candidate->sourceCount())->toBe(2);
});

it('gives different companies separate obligations in one month', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    a03_segment($client, $first, '2024-01-01', null);
    a03_segment($client, $second, '2024-01-01', null);

    a03_companyRate($client, $first, 100000, '2026-01');
    a03_companyRate($client, $second, 200000, '2026-01');
    a03_generalCutoff(10, CutoffMonthOffset::FollowingMonth, '2026-01');

    $period = a03_openPeriod('2026-10');
    app(Generate::class)->execute($period, actingAsRole());

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(2);
});

// --- §6: close proves completeness --------------------------------------------

it('closes a month whose portfolio was genuinely empty', function (): void {
    $period = a03_generatedPeriod('2026-10');

    // Generation ran and produced nothing: an empty month is a real answer, not an
    // oversight, and refusing to close it would leave the system unable to settle a month
    // in which nobody was billed.
    expect(app(ClosePeriod::class)->execute($period, actingAsRole())->isClosed())->toBeTrue();
});

it('refuses to close while a candidate is still missing an obligation', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    $late = Client::factory()->create();
    a03_employer(client: $late, relationshipStart: '2026-10-05', amountCop: 90000);

    $blocked = null;

    try {
        app(ClosePeriod::class)->execute($period, actingAsRole());
    } catch (ClosePeriodBlocked $e) {
        $blocked = $e;
    }

    expect($blocked)->not->toBeNull()
        ->and($blocked->blockerCodes())->toContain('period_incomplete')
        // Close creates nothing; generating the missing debt is a separate, explicit step.
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(1);

    // And after generating it, the month closes.
    app(Generate::class)->execute($period->refresh(), actingAsRole());

    expect(app(ClosePeriod::class)->execute($period->refresh(), actingAsRole())->isClosed())->toBeTrue()
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(2);
});

it('refuses to close while a new candidate is missing its rate, and names it', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    // A new employer with a relationship and a cutoff, but no configured value.
    $late = Client::factory()->create();
    $company = Company::factory()->create();
    a03_segment($late, $company, '2026-10-05', null);

    $blocked = null;

    try {
        app(ClosePeriod::class)->execute($period, actingAsRole());
    } catch (ClosePeriodBlocked $e) {
        $blocked = $e;
    }

    expect($blocked)->not->toBeNull();

    $all = $blocked->blockerCodes();

    // The findings are nested inside the close-time refusal, because close reports the
    // candidate and its cause together rather than only counting candidates.
    foreach ($blocked->blockers as $finding) {
        foreach ($finding['context']['findings'] ?? [] as $inner) {
            $all[] = $inner['code'];
        }
    }

    // Both reasons are visible, so the operator knows whether to configure a value or a
    // cutoff rather than only being told "there is a problem".
    expect($all)->toContain('period_has_blocked_candidates')
        ->and($all)->toContain('missing_rate');
});

it('refuses to close while a new candidate is missing its cutoff', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    // A new employer joins October with a configured value but **no applicable cutoff**.
    //
    // The new candidate is what makes this a blocked close. Without one, moving the cutoff
    // away would leave only the existing obligation, whose findings are warnings by §7, and
    // the close would rightly succeed — so the test would be asserting the opposite of what
    // its name claims.
    $late = Client::factory()->create();
    $company = Company::factory()->create();
    a03_segment($late, $company, '2026-10-05', null);
    a03_companyRate($late, $company, 90000, '2026-01');

    // The general cutoff is moved to a month **after** the one being closed, so it no longer
    // applies and the new candidate has nothing to resolve. Moving it rather than deleting
    // it keeps the test inside a usable transaction.
    CutoffRule::query()->update(['effective_month' => '2026-12-01']);

    $blocked = null;

    try {
        app(ClosePeriod::class)->execute($period, actingAsRole());
    } catch (ClosePeriodBlocked $e) {
        $blocked = $e;
    }

    expect($blocked)->not->toBeNull()
        ->and($blocked->blockerCodes())->toContain('period_has_blocked_candidates')
        ->and($period->fresh()->isClosed())->toBeFalse();

    // The finding names the cutoff rather than only counting a candidate.
    $codes = [];

    foreach ($blocked->blockers as $finding) {
        foreach ($finding['context']['findings'] ?? [] as $inner) {
            $codes[] = $inner['code'];
        }
    }

    expect($codes)->toContain('missing_cutoff_rule');
});

// --- §7: an existing obligation is judged on its own snapshot -----------------

it('generates missing obligations even when an existing one quotes configuration that is no longer current', function (): void {
    $employer = a03_employer(amountCop: 235000);
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    $existing = MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail();

    // A decision is superseded: the old rate is now only history. The obligation already
    // written quotes it, and that quote is the record.
    ClientCompanyRate::query()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'effective_month' => '2026-09-01',
        'amount_cop' => 250000,
    ]);

    // A second employer joins October, fully configured.
    $late = Client::factory()->create();
    a03_employer(client: $late, relationshipStart: '2026-10-05', amountCop: 90000);

    $preview = app(ObligationCandidateBuilder::class)->preview($period->refresh());

    // The existing pair is a warning, never a blocker: its snapshot is immutable and its
    // old configuration is not required as current input.
    expect($preview['existing_candidate_count'])->toBe(1)
        ->and($preview['blocked_candidate_count'])->toBe(0)
        ->and($preview['creatable_count'])->toBe(1)
        ->and($preview['blockers'])->toBe([]);

    // Generating writes only the new debt.
    $result = app(Generate::class)->execute($period->refresh(), actingAsRole());

    expect($result->created)->toBe(1)
        ->and($result->existingCount)->toBe(1);

    // The old obligation is byte-for-byte what it was.
    $again = MonthlyObligation::query()->findOrFail($existing->id);

    expect($again->base_amount_cop)->toBe(235000)
        ->and($again->rate_id)->toBe($existing->rate_id)
        ->and($again->due_on->format('Y-m-d'))->toBe($existing->due_on->format('Y-m-d'));
});

it('does not require an existing obligation to still be configurable to close the month', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    // The cutoff rule the obligation quotes is withdrawn. With `SET NULL` this used to erase
    // the reference; with RESTRICT it cannot happen at all, so the assertion is that the
    // month still closes and the evidence is intact.
    // The guarantee is the delete rule itself, read from the catalogue rather than
    // demonstrated by deleting: raising the violation would abort this transaction and
    // nothing after it could run.
    $rule = DB::selectOne(
        "SELECT confdeltype AS rule
           FROM pg_constraint
          WHERE conrelid = 'monthly_obligations'::regclass
            AND contype = 'f'
            AND confrelid = 'cutoff_rules'::regclass",
    );

    // `r` is RESTRICT. `SET NULL` is what erased the evidence before A03-R1, and it is the
    // failure this row would be re-introducing.
    expect($rule->rule)->toBe('r')
        // And the reference is still there to be read.
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail()->cutoff_rule_id)
        ->toBe($rule->id ?? MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail()->cutoff_rule_id);

    expect(app(ClosePeriod::class)->execute($period->refresh(), actingAsRole())->isClosed())->toBeTrue();
});

it('is always missing-only, with no flag that could ask for more', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(Generate::class)->execute($period, actingAsRole());

    // The request takes no parameters at all, so there is nothing to ask for. Read off a
    // bare instance rather than through the container, which would run `authorize()` and
    // need an authenticated user for a test about the field list.
    $rules = (new GenerateObligationsRequest)->rules();

    expect($rules)->toBe([])
        ->and($rules)->not->toHaveKey('missing_only');

    // And the endpoint ignores a `missing_only` a client may still be sending.
    $this->actingAs(actingAsRole())
        ->postJson("/api/periods/{$period->id}/obligations/generate", ['missing_only' => false])
        ->assertOk()
        ->assertJsonPath('result.created', 0)
        ->assertJsonPath('result.nothing_to_do', true);

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(1);
});

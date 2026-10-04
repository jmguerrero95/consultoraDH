<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Billing\Actions\GenerationBlocked;
use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\GenerationResult;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Billing\ObligationSource;
use App\Domain\Billing\RateResolver;
use App\Domain\Periods\Actions\ClosePeriod;
use App\Domain\Periods\MonthlyPeriod as Month;
use App\Domain\Periods\PeriodIsClosed;
use App\Domain\Periods\PeriodStatus;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Generation: which relationships count, what is produced, and what is refused.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * Generate a month's obligations, returning the result.
 */
function a03_generate(MonthlyPeriod $period, ?Client $actor = null): GenerationResult
{
    return app(GeneratePeriodObligations::class)->execute($period, $actor ?? actingAsRole());
}

// --- Who belongs to a month --------------------------------------------------

it('includes a relationship that overlaps the month at all', function (): void {
    // The rule: a relationship belongs to a month when the two half-open intervals
    // intersect. Somebody who started on the twentieth worked for that company for
    // part of the month, and the month is billed to the company they worked for.
    $startMid = Client::factory()->create();
    $endMid = Client::factory()->create();
    $full = Client::factory()->create();

    // Started on the 20th of the month.
    a03_employer(client: $startMid, relationshipStart: '2026-10-20');
    // Ended on the 5th of the month.
    a03_employer(client: $endMid, relationshipStart: '2026-01-01', relationshipEnd: '2026-10-05');
    // The whole month.
    a03_employer(client: $full, relationshipStart: '2024-01-01');

    $period = a03_openPeriod('2026-10');

    $preview = (new ObligationCandidateBuilder)->preview($period);

    $ids = collect($preview['candidates'])->pluck('client_id');

    expect($ids)->toContain($startMid->id)
        ->and($ids)->toContain($endMid->id)
        ->and($ids)->toContain($full->id)
        ->and($preview['candidate_count'])->toBe(3);
});

it('excludes a relationship that ended before the month began', function (): void {
    // `ended_on` is the first day the relationship is **no longer** effective, so
    // ending on the first of the month means the whole previous month and none of
    // this one.
    $before = Client::factory()->create();
    a03_employer(client: $before, relationshipStart: '2025-01-01', relationshipEnd: '2026-10-01');

    $period = a03_openPeriod('2026-10');

    $preview = (new ObligationCandidateBuilder)->preview($period);

    expect(collect($preview['candidates'])->pluck('client_id'))->not->toContain($before->id)
        ->and($preview['candidate_count'])->toBe(0);
});

it('excludes a relationship that starts after the month ends', function (): void {
    $after = Client::factory()->create();
    a03_employer(client: $after, relationshipStart: '2026-11-01');

    $period = a03_openPeriod('2026-10');

    $preview = (new ObligationCandidateBuilder)->preview($period);

    expect(collect($preview['candidates'])->pluck('client_id'))->not->toContain($after->id)
        ->and($preview['candidate_count'])->toBe(0);
});

it('creates a separate obligation for each different company', function (): void {
    // A client working for two companies in one month is ordinary, and each employer
    // is billed separately.
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    a03_employer(client: $client, company: $first);
    a03_employer(client: $client, company: $second);

    $period = a03_openPeriod('2026-10');
    $result = a03_generate($period);

    expect($result->created)->toBe(2)
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(2)
        ->and(MonthlyObligation::query()->pluck('company_id')->unique())->toHaveCount(2);
});

// --- The snapshot -----------------------------------------------------------

it('stores the amount and the due date it resolved', function (): void {
    $employer = a03_employer(amountCop: 235000, cutoffDay: 10, offset: CutoffMonthOffset::FollowingMonth);

    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    $obligation = MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail();

    expect($obligation->base_amount_cop)->toBe(235000)
        ->and($obligation->due_on->format('Y-m-d'))->toBe('2026-11-10')
        ->and($obligation->rate_id)->toBe($employer['rate']->id)
        ->and($obligation->cutoff_rule_id)->toBe($employer['rule']->id)
        ->and($obligation->source)->toBe(ObligationSource::Generated);
});

it('does not rewrite a generated obligation when the rate changes afterwards', function (): void {
    $employer = a03_employer(amountCop: 235000);
    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    // A later decision about what this client costs, starting next month.
    ClientCompanyRate::factory()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'effective_month' => Month::fromKey('2026-11')->startsOn(),
        'amount_cop' => 280000,
    ]);

    $obligation = MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail();

    // The obligation still says what October was billed. An obligation that tracked
    // its inputs would make "what were we billed?" answerable only for the last
    // month somebody edited.
    expect($obligation->fresh()->base_amount_cop)->toBe(235000);
});

it('does not rewrite a generated obligation when the cutoff rule changes afterwards', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    a03_companyRule($employer['company'], 20, CutoffMonthOffset::FollowingMonth);

    expect(MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail()->due_on->format('Y-m-d'))
        ->toBe('2026-11-10');
});

it('takes the newest rate that had started by the month', function (): void {
    $employer = a03_employer(amountCop: 235000);

    ClientCompanyRate::factory()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'effective_month' => Month::fromKey('2026-06')->startsOn(),
        'amount_cop' => 250000,
    ]);

    $resolver = app(RateResolver::class);

    expect($resolver->amountFor($employer['client']->id, $employer['company']->id, Month::fromKey('2026-05')))
        ->toBe(235000)
        ->and($resolver->amountFor($employer['client']->id, $employer['company']->id, Month::fromKey('2026-06')))
        ->toBe(250000);
});

// --- Blockers ---------------------------------------------------------------

it('blocks generation and writes nothing when a rate is missing', function (): void {
    $employer = a03_employer();
    ClientCompanyRate::query()->whereKey($employer['rate']->id)->delete();

    $period = a03_openPeriod('2026-10');

    expect(fn () => a03_generate($period))->toThrow(GenerationBlocked::class);

    // Nothing was written: not an obligation of zero, not one carrying somebody
    // else's amount.
    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and($period->fresh()->generation_performed_at)->toBeNull();
});

it('blocks generation and writes nothing when a cutoff rule is missing', function (): void {
    a03_employer();
    CutoffRule::query()->delete();

    $period = a03_openPeriod('2026-10');

    try {
        a03_generate($period);
        $this->fail('generation should have been refused');
    } catch (GenerationBlocked $e) {
        expect($e->distinctCodes())->toContain('missing_cutoff_rule');
    }

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(0);
});

it('names every missing configuration at once rather than one at a time', function (): void {
    // An operator fixing a month needs the whole list; reporting "no rate
    // configured" one client at a time, for two hundred clients, is a day of
    // clicking.
    a03_employer();
    a03_employer();
    a03_employer();
    ClientCompanyRate::query()->delete();

    $period = a03_openPeriod('2026-10');

    try {
        a03_generate($period);
        $this->fail('generation should have been refused');
    } catch (GenerationBlocked $e) {
        expect($e->blockers)->toHaveCount(3)
            ->and($e->distinctCodes())->toBe(['missing_rate']);
    }
});

// --- The preview ------------------------------------------------------------

it('writes nothing when previewing', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    $before = [
        'obligations' => MonthlyObligation::query()->count(),
        'periods' => MonthlyPeriod::query()->count(),
        'rates' => ClientCompanyRate::query()->count(),
        'rules' => CutoffRule::query()->count(),
        'assignments' => ClientCompanyAssignment::query()->count(),
    ];

    $this->actingAs(actingAsRole())
        ->postJson("/api/periods/{$period->id}/obligations/preview")
        ->assertOk()
        ->assertJsonPath('preview.candidate_count', 1)
        ->assertJsonPath('preview.can_generate', true)
        // `creatable_amount_cop`, not the old overloaded `total_amount_cop`: the question
        // a preview has to answer is "how much money will this write", and the amount an
        // already-generated obligation holds is not money about to be created.
        ->assertJsonPath('preview.creatable_amount_cop', $employer['rate']->amount_cop);

    // A preview is a calculation. Nothing about the business changed.
    expect([
        'obligations' => MonthlyObligation::query()->count(),
        'periods' => MonthlyPeriod::query()->count(),
        'rates' => ClientCompanyRate::query()->count(),
        'rules' => CutoffRule::query()->count(),
        'assignments' => ClientCompanyAssignment::query()->count(),
    ])->toBe($before);
});

it('reports the blockers in the preview without writing anything', function (): void {
    $employer = a03_employer();
    ClientCompanyRate::query()->whereKey($employer['rate']->id)->delete();

    $period = a03_openPeriod('2026-10');

    $this->actingAs(actingAsRole())
        ->postJson("/api/periods/{$period->id}/obligations/preview")
        ->assertOk()
        ->assertJsonPath('preview.can_generate', false)
        ->assertJsonPath('preview.blocker_count', 1)
        ->assertJsonPath('preview.blockers.0.code', 'missing_rate');

    expect(MonthlyObligation::query()->count())->toBe(0);
});

it('never exposes raw SQL in a blocker', function (): void {
    a03_employer();
    CutoffRule::query()->delete();

    $period = a03_openPeriod('2026-10');

    $body = $this->actingAs(actingAsRole())
        ->postJson("/api/periods/{$period->id}/obligations/preview")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('select ')->not->toContain('SQLSTATE')->not->toContain('from "');
});

// --- Idempotency ------------------------------------------------------------

it('creates nothing the second time generation runs', function (): void {
    a03_employer();
    $period = a03_openPeriod('2026-10');

    $first = a03_generate($period);
    $second = a03_generate($period->refresh());

    expect($first->created)->toBe(1)
        ->and($second->created)->toBe(0)
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(1);
});

it('never regenerates an obligation that already exists', function (): void {
    $employer = a03_employer(amountCop: 235000);
    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    $obligation = MonthlyObligation::query()->where('period_id', $period->id)->firstOrFail();
    $snapshot = fn (MonthlyObligation $o): array => [
        'base_amount_cop' => $o->base_amount_cop,
        'due_on' => $o->due_on?->format('Y-m-d'),
        'rate_id' => $o->rate_id,
        'cutoff_rule_id' => $o->cutoff_rule_id,
        'generated_at' => $o->generated_at?->toIso8601String(),
    ];

    $before = $snapshot($obligation);

    // Somebody corrects the configuration, and the resolved values now differ from
    // what is stored. A later rate applies to November, not October, so October still
    // resolves to the old one; a later company cutoff rule is the case that would
    // change the answer if generation re-read its inputs.
    a03_companyRule($employer['company'], 20, CutoffMonthOffset::SameMonth);

    $second = a03_generate($period->refresh());

    expect($second->created)->toBe(0)
        // Byte for byte, including when it was generated. Re-running generation is
        // not a refresh.
        ->and($snapshot($obligation->fresh()))->toBe($before);
});

it('refuses to delete a rate an obligation already quotes', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    // The rate is the evidence for what was billed. Deleting it would leave an
    // obligation that cannot explain its own amount, and the way to change a value
    // is a new row with a later effective month.
    expect(fn () => ClientCompanyRate::query()->where('client_id', $employer['client']->id)->delete())
        ->toThrow(QueryException::class);
});

it('generates only what is missing when membership changes', function (): void {
    a03_employer();
    $period = a03_openPeriod('2026-10');
    a03_generate($period);

    // A second employer appears for the same client. This is the case "generate the
    // missing obligations" exists for.
    $client = Client::query()->firstOrFail();
    a03_employer(client: $client, company: Company::factory()->create());

    $second = a03_generate($period->refresh());

    expect($second->created)->toBe(1)
        ->and(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(2);
});

it('refuses to generate into a closed period', function (): void {
    a03_employer();
    $period = a03_generatedPeriod('2026-10');
    $period->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    expect(fn () => a03_generate($period->fresh()))->toThrow(PeriodIsClosed::class)
        ->and(MonthlyObligation::query()->count())->toBe(0);
});

it('refuses a stale copy of an open period once it has been closed', function (): void {
    a03_employer();
    $period = a03_generatedPeriod('2026-10');

    // The instance the caller holds says open.
    $stale = $period->fresh();
    expect($stale->isOpen())->toBeTrue();

    $period->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    // The decision is made from the row read under the lock, not from the copy the
    // caller was handed.
    expect(fn () => a03_generate($stale))->toThrow(PeriodIsClosed::class);
});

it('marks the period as generated even when it produced nothing', function (): void {
    // Otherwise an empty month and an untouched month are the same row, and the
    // month in which nobody was billed could never be closed.
    $period = a03_openPeriod('2026-10');

    $result = a03_generate($period);

    expect($result->created)->toBe(0)
        ->and($period->fresh()->generation_performed_at)->not->toBeNull();
});

// --- Blockers that are about the data, not the configuration ----------------

it('refuses to write a new debt against an inactive client', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    // The month has not been generated yet, so nothing is owed. Writing an obligation
    // now would be creating a debt after the person was deactivated, and
    // reactivating them would not bring the debt back.
    $employer['client']->update(['status' => 'inactive']);

    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect($preview['blockers'])->not->toBeEmpty()
        ->and(collect($preview['blockers'])->pluck('code')->all())->toContain('inactive_or_invalid_reference')
        ->and(MonthlyObligation::query()->count())->toBe(0);

    expect(fn () => app(GeneratePeriodObligations::class)->execute($period, actingAsRole()))
        ->toThrow(GenerationBlocked::class);
});

it('refuses to write a new debt against an inactive company', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    $employer['company']->update(['status' => 'inactive']);

    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect(collect($preview['blockers'])->pluck('code')->all())->toContain('inactive_or_invalid_reference')
        ->and(MonthlyObligation::query()->count())->toBe(0);
});

it('still collects the months already generated before a client went inactive', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    app(GeneratePeriodObligations::class)->execute($period, actingAsRole());
    expect(MonthlyObligation::query()->count())->toBe(1);

    $employer['client']->update(['status' => 'inactive']);

    // The existing obligation stands: it was written while the relationship was
    // active, and debt does not evaporate because a directory record changed.
    // No `missing_only`: generation is unconditionally missing-only, and the preview has
    // one shape for every caller.
    $preview = app(ObligationCandidateBuilder::class)->preview($period);
    expect(collect($preview['blockers'])->pluck('code')->all())->not->toContain('inactive_or_invalid_reference')
        ->and(collect($preview['warnings'])->pluck('code')->all())->toContain('inactive_or_invalid_reference');

    // And the month still closes, because its generation is done.
    $closed = app(ClosePeriod::class)->execute($period, actingAsRole());
    expect($closed->status)->toBe(PeriodStatus::Closed)
        ->and($closed->status->value)->toBe('closed');
});

it('refuses to create two open relationships for the same pair, so the ambiguity cannot arise', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    // Two overlapping open relationships would make it impossible to say whose debt
    // an obligation belongs to. The database refuses it, so generation never has to
    // guess: the check in the builder is a second line for data that arrives from
    // outside, not the primary defence.
    // Nested, so the failure rolls back to a savepoint. A refused statement outside
    // one leaves the surrounding transaction unusable and every later statement in
    // the test would fail for a reason that has nothing to do with the assertion.
    expect(fn () => DB::transaction(fn () => ClientCompanyAssignment::query()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'started_on' => '2025-01-01',
        'ended_on' => null,
        'created_by' => null,
    ])))->toThrow(UniqueConstraintViolationException::class);

    // With one relationship, the month is unambiguous and generates normally.
    $result = app(GeneratePeriodObligations::class)->execute($period, actingAsRole());
    expect($result->created)->toBe(1);
});

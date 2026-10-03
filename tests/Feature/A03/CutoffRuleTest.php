<?php

declare(strict_types=1);

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffResolver;
use App\Domain\Billing\CutoffScope;
use App\Domain\Periods\MonthlyPeriod;
use App\Models\Company;
use App\Models\CutoffRule;
use Illuminate\Database\QueryException;

/**
 * Cutoff rules: the hierarchy, the month offset, and the short months.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

// --- The hierarchy ----------------------------------------------------------

it('resolves the general rule when nothing more specific applies', function (): void {
    $employer = a03_employer();

    $resolved = (new CutoffResolver)->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    expect($resolved->isMissing())->toBeFalse()
        ->and($resolved->source)->toBe(CutoffScope::General)
        ->and($resolved->rule?->id)->toBe($employer['rule']->id);
});

it('lets a company rule override the general one', function (): void {
    $employer = a03_employer();
    a03_companyRule($employer['company'], 8, CutoffMonthOffset::FollowingMonth);

    $resolved = (new CutoffResolver)->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    expect($resolved->source)->toBe(CutoffScope::Company)
        ->and($resolved->dueOn?->format('Y-m-d'))->toBe('2026-11-08');
});

it('lets a client exception within a company override both', function (): void {
    $employer = a03_employer();
    a03_companyRule($employer['company'], 8, CutoffMonthOffset::FollowingMonth);
    a03_clientRule($employer['client'], $employer['company'], 5, CutoffMonthOffset::SameMonth);

    $resolved = (new CutoffResolver)->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    // The most specific scope wins, because it is consulted first. The general rule
    // is still configured and still applies to everybody else.
    expect($resolved->source)->toBe(CutoffScope::Client)
        ->and($resolved->dueOn?->format('Y-m-d'))->toBe('2026-10-05');
});

it('keeps a client exception unambiguous when the client works for two companies', function (): void {
    // The same person, two employers. An exception that did not name a company would
    // be an instruction nobody could carry out, so the exception is scoped to the
    // pair and the other employer keeps the company rule.
    $employer = a03_employer();
    $other = a03_employer(client: $employer['client'], company: Company::factory()->create());

    a03_clientRule($employer['client'], $employer['company'], 5, CutoffMonthOffset::SameMonth);
    a03_companyRule($other['company'], 20, CutoffMonthOffset::FollowingMonth);
    a03_companyRule($employer['company'], 15, CutoffMonthOffset::FollowingMonth);

    $resolver = new CutoffResolver;

    $firstCompany = $resolver->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    $secondCompany = $resolver->resolve(
        $employer['client']->id,
        $other['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    expect($firstCompany->source)->toBe(CutoffScope::Client)
        ->and($firstCompany->dueOn?->format('Y-m-d'))->toBe('2026-10-05')
        // The other employer has no exception, so its own company rule answers.
        ->and($secondCompany->source)->toBe(CutoffScope::Company)
        ->and($secondCompany->dueOn?->format('Y-m-d'))->toBe('2026-11-20');
});

it('takes the newest rule that had started by the month being generated', function (): void {
    $employer = a03_employer();

    // The rule the employer fixture created starts in 2024-01. A later decision
    // supersedes it for the months it has reached, and not before.
    a03_companyRule($employer['company'], 20, CutoffMonthOffset::FollowingMonth, '2026-05');

    $resolver = new CutoffResolver;

    // April is before the new rule starts, so October's rule is still in force.
    $april = $resolver->resolve($employer['client']->id, $employer['company']->id, MonthlyPeriod::fromKey('2026-04'));
    expect($april->dueOn?->format('Y-m-d'))->toBe('2026-05-10');

    // October is after, so the new decision answers.
    $october = $resolver->resolve($employer['client']->id, $employer['company']->id, MonthlyPeriod::fromKey('2026-10'));
    expect($october->dueOn?->format('Y-m-d'))->toBe('2026-11-20');

    // And the rule that was in force is still readable: history is a sequence of
    // decisions, not an overwrite.
    expect(CutoffRule::query()->count())->toBe(2);
});

// --- The month offset -------------------------------------------------------

it('counts the cutoff in the same month when the offset is zero', function (): void {
    $rule = CutoffRule::factory()->general(15, CutoffMonthOffset::SameMonth, '2026-01')->create();

    expect($rule->resolveFor(MonthlyPeriod::fromKey('2026-10'))->format('Y-m-d'))
        ->toBe('2026-10-15');
});

it('counts the cutoff in the following month when the offset is one', function (): void {
    $rule = CutoffRule::factory()->general(15, CutoffMonthOffset::FollowingMonth, '2026-01')->create();

    // October's contribution is due on the fifteenth of November.
    expect($rule->resolveFor(MonthlyPeriod::fromKey('2026-10'))->format('Y-m-d'))
        ->toBe('2026-11-15');
});

it('uses the last calendar day when the cutoff day does not exist', function (): void {
    // "Cut on the 31st" is a real instruction and February is a real month, so the
    // rule clamps rather than failing. Getting this wrong makes every February
    // obligation undated.
    $rule = CutoffRule::factory()->general(31, CutoffMonthOffset::SameMonth, '2026-01')->create();
    $month = MonthlyPeriod::class;

    expect($rule->resolveFor($month::fromKey('2026-02'))->format('Y-m-d'))->toBe('2026-02-28')
        // A leap year, which is the case a naive day-clamp gets wrong once every
        // four years.
        ->and($rule->resolveFor($month::fromKey('2028-02'))->format('Y-m-d'))->toBe('2028-02-29')
        ->and($rule->resolveFor($month::fromKey('2026-04'))->format('Y-m-d'))->toBe('2026-04-30')
        // The thirty-first exists in a thirty-one day month, so nothing is clamped.
        ->and($rule->resolveFor($month::fromKey('2026-01'))->format('Y-m-d'))->toBe('2026-01-31');
});

it('clamps in the target month, not the period month', function (): void {
    // October plus one month is November, which has thirty days, so the 31st clamps
    // to the 30th of November rather than to anything in October.
    $rule = CutoffRule::factory()->general(31, CutoffMonthOffset::FollowingMonth, '2026-01')->create();

    expect($rule->resolveFor(MonthlyPeriod::fromKey('2026-10'))->format('Y-m-d'))
        ->toBe('2026-11-30');
});

// --- Nothing is invented ----------------------------------------------------

it('reports missing rather than guessing a date', function (): void {
    // No rule at all. Every one of these would put a real due date on a real
    // obligation and none of them is a fact anybody told the system.
    $employer = a03_employer();
    CutoffRule::query()->delete();

    $resolved = (new CutoffResolver)->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    expect($resolved->isMissing())->toBeTrue()
        ->and($resolved->dueOn)->toBeNull()
        ->and($resolved->rule)->toBeNull()
        ->and($resolved->source)->toBeNull();
});

it('does not borrow a company cutoff that belongs to somebody else', function (): void {
    $employer = a03_employer();
    $other = a03_employer();

    // A rule for the other employer, and none at all for this one. Falling through to
    // the other company's rule would mean a client's due date depends on somebody
    // else's arrangement.
    CutoffRule::query()->delete();
    a03_companyRule($other['company'], 8, CutoffMonthOffset::FollowingMonth);

    $resolved = (new CutoffResolver)->resolve(
        $employer['client']->id,
        $employer['company']->id,
        MonthlyPeriod::fromKey('2026-10'),
    );

    expect($resolved->isMissing())->toBeTrue()
        ->and(CutoffRule::query()->count())->toBe(1);
});

// --- The rule shapes the database enforces ----------------------------------

it('refuses a client exception without a company', function (): void {
    $employer = a03_employer();

    // The dangerous shape: it reads like an exception for a person and would never
    // resolve, because a client working for two companies has no single company to
    // apply it to.
    expect(fn () => CutoffRule::query()->create([
        'scope' => CutoffScope::Client->value,
        'company_id' => null,
        'client_id' => $employer['client']->id,
        'effective_month' => '2026-01-01',
        'cutoff_day' => 5,
        'month_offset' => 0,
    ]))->toThrow(QueryException::class);
});

it('refuses a general rule carrying a company', function (): void {
    $employer = a03_employer();

    expect(fn () => CutoffRule::query()->create([
        'scope' => CutoffScope::General->value,
        'company_id' => $employer['company']->id,
        'client_id' => null,
        'effective_month' => '2026-02-01',
        'cutoff_day' => 5,
        'month_offset' => 0,
    ]))->toThrow(QueryException::class);
});

it('refuses a second rule for the same scope and month', function (): void {
    $employer = a03_employer();
    a03_companyRule($employer['company'], 8, CutoffMonthOffset::SameMonth);

    // Two answers for the same question would make the resolution depend on
    // insertion order, which is not something a person should have to know to
    // predict a due date.
    expect(fn () => a03_companyRule($employer['company'], 20, CutoffMonthOffset::FollowingMonth))
        ->toThrow(QueryException::class);
});

it('refuses a cutoff day outside the calendar', function (): void {
    foreach ([0, 32, -1] as $day) {
        expect(fn () => CutoffRule::factory()->general($day, CutoffMonthOffset::SameMonth, '2026-01')->create())
            ->toThrow(QueryException::class);
    }
});

it('answers the same for two rules created in the same month', function (): void {
    // The partial indexes are what make this true: a plain unique index over columns
    // containing NULL would accept two general rules for the same month, because
    // NULLs are distinct in a PostgreSQL unique index.
    CutoffRule::factory()->general(10, CutoffMonthOffset::SameMonth, '2026-01')->create();

    expect(fn () => CutoffRule::factory()->general(20, CutoffMonthOffset::SameMonth, '2026-01')->create())
        ->toThrow(QueryException::class);
});

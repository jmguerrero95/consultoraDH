<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Models\ClientCompanyRate;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;

/**
 * Configuration is history, and history does not get rewritten.
 *
 * A month that has been generated has already said what it billed and when it was
 * due. If the configuration behind those statements can then be edited, the record of
 * what was billed changes after the fact, and the system stops being able to answer
 * "what did we bill in March" with any confidence. The way forward is a new row with
 * a later effective month, never an edit to the row that was used.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A month whose obligations were generated, so its configuration is in use.
 */
function a03_generatedMonth(string $monthKey = '2026-10'): array
{
    $employer = a03_employer(effectiveMonth: '2024-01');
    $period = a03_openPeriod($monthKey);

    app(GeneratePeriodObligations::class)->execute($period, actingAsRole());

    return [$employer, $period];
}

it('refuses to change a cutoff rule an obligation already used', function (): void {
    [$employer, $period] = a03_generatedMonth();
    $actor = a03_admin();

    // The general rule the generated obligation quoted.
    $rule = CutoffRule::query()
        ->where('scope', 'general')
        ->whereNull('client_id')
        ->whereNull('company_id')
        ->firstOrFail();

    $before = $rule->cutoff_day;

    $this->actingAs($actor)
        ->patchJson("/api/cutoff-rules/{$rule->id}", ['cutoff_day' => 20])
        ->assertStatus(409)
        ->assertJsonPath('code', 'cutoff_rule_in_use')
        // Told which months are affected: "you cannot change this" is much less
        // useful than "you cannot change this, and here is what it has billed".
        ->assertJsonFragment(['affected_periods' => ['Octubre 2026']]);

    expect($rule->fresh()->cutoff_day)->toBe($before)
        ->and(MonthlyPeriod::query()->count())->toBe(1);
});

it('lets a note be corrected on a cutoff rule that is in use', function (): void {
    [$employer, $period] = a03_generatedMonth();
    $actor = a03_admin();

    $rule = CutoffRule::query()->where('scope', 'general')->firstOrFail();

    // A note describes the decision, it is not the decision. Correcting a typo in one
    // has changed nobody's bill, so refusing it would only teach operators to leave
    // wrong notes in place.
    $this->actingAs($actor)
        ->patchJson("/api/cutoff-rules/{$rule->id}", ['notes' => 'Acordado con la empresa'])
        ->assertOk();

    expect($rule->fresh()->notes)->toBe('Acordado con la empresa')
        ->and($rule->fresh()->cutoff_day)->toBe(10);
});

it('allows an unused cutoff rule to be corrected', function (): void {
    $actor = a03_admin();

    // Created for a month that will never be generated, so nothing quotes it.
    $rule = CutoffRule::query()->create([
        'scope' => 'general',
        'company_id' => null,
        'client_id' => null,
        'effective_month' => '2030-01-01',
        'cutoff_day' => 5,
        'month_offset' => 1,
        'created_by' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/cutoff-rules/{$rule->id}", ['cutoff_day' => 8])
        ->assertOk();

    expect($rule->fresh()->cutoff_day)->toBe(8);
});

it('refuses to move a cutoff rule back into the past', function (): void {
    $actor = a03_admin();

    $rule = CutoffRule::query()->create([
        'scope' => 'general',
        'company_id' => null,
        'client_id' => null,
        'effective_month' => '2026-06-01',
        'cutoff_day' => 10,
        'month_offset' => 1,
        'created_by' => null,
    ]);

    // Moving it earlier would take the answer away from the months it already
    // covered, and those months were generated against the later rule.
    $this->actingAs($actor)
        ->patchJson("/api/cutoff-rules/{$rule->id}", ['effective_month' => '2026-01-01'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'effective_month_must_not_go_backwards');

    expect($rule->fresh()->effective_month->format('Y-m-d'))->toBe('2026-06-01');
});

it('refuses to change a rate an obligation already used', function (): void {
    [$employer, $period] = a03_generatedMonth();
    $actor = a03_admin();

    $rate = ClientCompanyRate::query()->firstOrFail();

    $this->actingAs($actor)
        ->patchJson("/api/rates/{$rate->id}", ['amount_cop' => 999999])
        ->assertStatus(409)
        ->assertJsonPath('code', 'rate_in_use');

    expect($rate->fresh()->amount_cop)->toBe(235000);
});

it('lets a note be corrected on a rate that is in use', function (): void {
    [$employer, $period] = a03_generatedMonth();
    $actor = a03_admin();

    $rate = ClientCompanyRate::query()->firstOrFail();

    $this->actingAs($actor)
        ->patchJson("/api/rates/{$rate->id}", ['notes' => 'Valor acordado'])
        ->assertOk();

    expect($rate->fresh()->notes)->toBe('Valor acordado');
});

it('lets a rate that nothing has used be corrected', function (): void {
    $actor = a03_admin();

    // A rate for a future month: not quoted by anything yet.
    $rate = ClientCompanyRate::query()->create([
        'client_id' => a03_employerFor('futuro')['client']->id,
        'company_id' => a03_employerFor('futuro')['company']->id,
        'effective_month' => '2030-01-01',
        'amount_cop' => 200000,
        'notes' => null,
        'created_by' => null,
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/rates/{$rate->id}", ['amount_cop' => 210000])
        ->assertOk();

    expect($rate->fresh()->amount_cop)->toBe(210000);
});

it('keeps the old configuration and answers March with the March numbers', function (): void {
    // A rate that changes for July, with March already generated at the old value.
    $employer = a03_employer(effectiveMonth: '2024-01', amountCop: 235000);
    $period = a03_openPeriod('2026-03');

    app(GeneratePeriodObligations::class)->execute($period, actingAsRole());
    $actor = a03_admin();

    ClientCompanyRate::query()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'effective_month' => '2026-07-01',
        'amount_cop' => 260000,
        'notes' => 'Ajuste pactado para el segundo semestre',
        'created_by' => null,
    ]);

    // March still says what it said when it was generated, and July resolves to the
    // new figure. This is the whole point of effective-dated configuration.
    $march = MonthlyObligation::query()->firstOrFail();
    expect($march->base_amount_cop)->toBe(235000);

    $july = a03_openPeriod('2026-07');
    app(GeneratePeriodObligations::class)->execute($july, actingAsRole());

    $julyObligation = MonthlyObligation::query()->where('period_id', $july->id)->firstOrFail();
    expect($julyObligation->base_amount_cop)->toBe(260000)
        ->and($march->fresh()->base_amount_cop)->toBe(235000);
});

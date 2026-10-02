<?php

declare(strict_types=1);

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\SocialSecurityEntity;

/**
 * The five ARL risk classes, and what each one means.
 *
 * They are ordinal: class I is the least risk and class V the most. An earlier
 * version of this enum treated class V as "not classified", on the assumption that
 * the law only defined four classes, and that error was visible in the interface:
 * a worker at maximum risk was listed as though their classification were unknown.
 *
 * The distinction being protected here is between a 5 and a null. A 5 is what the
 * source said; a null is what the source did not say.
 */
it('accepts class five as the highest class', function (): void {
    expect(ArlRiskClass::from(5))->toBe(ArlRiskClass::LevelFive)
        ->and(ArlRiskClass::LevelFive->value())->toBe(5)
        ->and(ArlRiskClass::values())->toBe([1, 2, 3, 4, 5]);
});

it('labels the five classes without describing class five as unclassified', function (): void {
    $labels = array_map(
        static fn (ArlRiskClass $level): string => $level->label(),
        ArlRiskClass::cases(),
    );

    expect($labels)->toBe(['Riesgo I', 'Riesgo II', 'Riesgo III', 'Riesgo IV', 'Riesgo V'])
        ->and(implode(' ', $labels))->not->toContain('clasificado');

    // The words the class actually carries, for the places that need them.
    expect(ArlRiskClass::LevelOne->severityLabel())->toBe('Riesgo mínimo')
        ->and(ArlRiskClass::LevelTwo->severityLabel())->toBe('Riesgo bajo')
        ->and(ArlRiskClass::LevelThree->severityLabel())->toBe('Riesgo medio')
        ->and(ArlRiskClass::LevelFour->severityLabel())->toBe('Riesgo alto')
        ->and(ArlRiskClass::LevelFive->severityLabel())->toBe('Riesgo máximo');
});

it('treats class five as the highest risk, not as the least informative', function (): void {
    // The method under test once worked around a class it believed meant "unknown"
    // by ranking a high number as safer.
    expect(ArlRiskClass::LevelFive->isSaferThan(ArlRiskClass::LevelFour))->toBeFalse()
        ->and(ArlRiskClass::LevelFive->isRiskierThan(ArlRiskClass::LevelFour))->toBeTrue();

    foreach (ArlRiskClass::cases() as $level) {
        foreach (ArlRiskClass::cases() as $other) {
            // The two questions are exact opposites for every pair, including class
            // V against class I, which is the pair the old definition got wrong.
            expect($level->isSaferThan($other))->toBe($level->value < $other->value)
                ->and($level->isRiskierThan($other))->toBe($level->value > $other->value);
        }
    }
});

it('stores class five as a real classification, distinct from an unknown one', function (): void {
    $client = Client::factory()->create();
    $arl = SocialSecurityEntity::factory()->create([
        'type' => SocialSecurityEntityType::Arl,
        'name' => 'ARL De Las Cinco Clases',
    ]);

    $highest = ClientAffiliation::factory()->arl(ArlRiskClass::LevelFive)->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $arl->id,
        'type' => SocialSecurityEntityType::Arl,
        'ended_on' => null,
    ]);

    expect($highest->refresh()->arl_risk_class)->toBe(5)
        ->and($highest->arlRiskClass())->toBe(ArlRiskClass::LevelFive)
        ->and($highest->hasInvalidRiskClass())->toBeFalse();

    // The unknown one is a null, and it is a different thing. A second client,
    // because a client may only have one open affiliation per type.
    $unknown = ClientAffiliation::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'social_security_entity_id' => $arl->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => null,
        'ended_on' => null,
    ]);

    expect($unknown->refresh()->arl_risk_class)->toBeNull()
        ->and($unknown->arlRiskClass())->toBeNull();
});

it('accepts class five through the API and returns its label', function (): void {
    seedPortfolioRoles();

    $client = Client::factory()->create();
    $arl = SocialSecurityEntity::factory()->create([
        'type' => SocialSecurityEntityType::Arl,
        'name' => 'ARL Del Api',
    ]);

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/affiliations", [
        'social_security_entity_id' => $arl->id,
        'type' => 'ARL',
        'arl_risk_class' => 5,
    ])->assertCreated()
        ->assertJsonPath('affiliation.arl_risk_class', 5)
        ->assertJsonPath('affiliation.arl_risk_label', 'Riesgo V');
});

it('sends the five classes to the interface from the enum', function (): void {
    seedPortfolioRoles();

    $client = Client::factory()->create();

    $options = $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->json('risk_options');

    expect($options)->toBe([
        ['value' => 1, 'label' => 'Riesgo I'],
        ['value' => 2, 'label' => 'Riesgo II'],
        ['value' => 3, 'label' => 'Riesgo III'],
        ['value' => 4, 'label' => 'Riesgo IV'],
        ['value' => 5, 'label' => 'Riesgo V'],
    ]);
});

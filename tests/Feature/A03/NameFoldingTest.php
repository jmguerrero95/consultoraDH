<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\SocialSecurityEntity;

/**
 * A03-R2 §11, and one thing the audit did not list.
 *
 * ## The listed defect
 *
 * Every search matched `first_names` and `last_names` separately, so a name that lives in
 * two columns could not be typed in one piece. `Ana María Gómez` found nobody; `Ana` and
 * `Gómez` each worked. Because the billing configuration screen picks a client through this
 * search, a client-scoped cutoff or rate could not be configured for a person found by name.
 *
 * ## The one the audit did not list
 *
 * This database is created with the `C` collation:
 *
 *     select datcollate from pg_database where datname = current_database();  -- C
 *
 * The C locale case-folds ASCII and nothing else. So
 *
 *     select lower('Única'), ('Única' like '%única%');
 *     -- Única, false
 *
 * while PHP's `mb_strtolower('Única')` is `única`. The two sides of every search disagreed
 * about exactly one class of character.
 *
 * It hid well because it is *uneven*. `Gómez` searches fine: the `ó` is already lowercase in
 * the column and PHP lowercases it in the needle, so nothing has to be folded and the two
 * agree by accident. Only the **capital** accented letter breaks, and the capital accented
 * letter is almost always the first letter of a name — `Única`, `Ñoño`, `Épsilon`, `Ángel`,
 * `Óscar`, `Iñigo`. So an accented person was findable by surname and unfindable by first
 * name, and unaccented spellings missed them too, because there is no accent on either side
 * to strip.
 *
 * The fix folds **both** sides in SQL — `lower(unaccent(column)) LIKE lower(unaccent(?))` —
 * rather than transliterating the needle in PHP. Two implementations of the same rules is
 * one more than there should be, and a disagreement between them would present as a person
 * who cannot be found.
 *
 * These are real requests against real PostgreSQL. A search that folds differently on this
 * collation than on a UTF-8 one is exactly the kind of defect a unit test with a mock would
 * have passed.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A name per accent, each starting with a capital accented letter.
 *
 * Every entry is a letter whose *capital* is the problem, which is why they are not
 * redundant: `@` would test nothing here, and `Gómez` would pass without the fix.
 *
 * @return array<string, array{string, string}>
 */
function accentedNames(): array
{
    return [
        'acute' => ['Única', 'Excepción'],
        'ñ' => ['Ñoño', 'Peña'],
        'e acute' => ['Épsilon', 'Época'],
        'a acute' => ['Ángel', 'Ávila'],
        'o acute' => ['Óscar', 'Óñez'],
        'i acute' => ['Íñigo', 'Ítalo'],
        'umlaut' => ['Ürsula', 'Müller'],
        'cedilla' => ['Çedilla', 'Peña'],
    ];
}

it('finds a client whose name begins with a capital accented letter', function (string $first, string $last): void {
    $client = Client::factory()->create([
        'first_names' => $first,
        'last_names' => $last,
        'document_number' => '90'.substr(md5($first.$last), 0, 6),
    ]);

    $this->actingAs(userWithPermissions(['clients.view']));

    $find = fn (string $term) => collect(
        $this->getJson('/api/clients?search='.urlencode($term))->assertOk()->json('clients')
    )->pluck('id')->all();

    // The name as written.
    expect($find($first), $first)->toContain($client->id)
        // The surname on its own.
        ->and($find($last), $last)->toContain($client->id)
        // Both, in one piece — the §11 defect.
        ->and($find($first.' '.$last), $first.' '.$last)->toContain($client->id);
})->with(accentedNames());

it('finds a client by an unaccented spelling, which is what somebody types when the keyboard is not Spanish', function (string $first, string $last): void {
    $client = Client::factory()->create([
        'first_names' => $first,
        'last_names' => $last,
        'document_number' => '91'.substr(md5($first.$last), 0, 6),
    ]);

    $this->actingAs(userWithPermissions(['clients.view']));

    // The space matters: the folded full name is `unica excepcion`, so a needle without
    // one cannot match it however the letters are spelled.
    $plain = strtr($first.' '.$last, [
        'Ú' => 'U', 'Ñ' => 'N', 'É' => 'E', 'Á' => 'A', 'Ó' => 'O',
        'Í' => 'I', 'Ü' => 'U', 'Ç' => 'C', 'ó' => 'o', 'ñ' => 'n',
        'é' => 'e', 'á' => 'a', 'í' => 'i', 'ü' => 'u', 'ç' => 'c',
    ]);

    $found = collect(
        $this->getJson('/api/clients?search='.urlencode($plain))->assertOk()->json('clients')
    )->pluck('id');

    expect($plain)->not->toBe($first.$last)
        ->and($found, $plain)->toContain($client->id);
})->with(accentedNames());

it('folds case and accents together, so every spelling of a name finds the same person', function (): void {
    $client = Client::factory()->create([
        'first_names' => 'José María',
        'last_names' => 'Muñoz',
        'document_number' => '92555555',
    ]);

    $this->actingAs(userWithPermissions(['clients.view']));

    // Capital, lowercase, mixed, accented, unaccented, abbreviated, and the whole thing.
    // All of them are the same person.
    foreach ([
        'José María', 'josé maría', 'JOSÉ MARÍA', 'Jose Maria', 'jose maria',
        'Muñoz', 'muñoz', 'MUNOZ', 'munoz', 'José', 'Muño', 'jose',
    ] as $term) {
        $found = collect(
            $this->getJson('/api/clients?search='.urlencode($term))->assertOk()->json('clients')
        )->pluck('id');

        expect($found, $term)->toContain($client->id);
    }

    // And a name nobody has must still find nobody, on every spelling.
    foreach (['Zapata', 'zapata', 'ZAPATA', 'Zápata'] as $term) {
        $found = collect(
            $this->getJson('/api/clients?search='.urlencode($term))->assertOk()->json('clients')
        )->pluck('id');

        expect($found, $term)->not->toContain($client->id);
    }
});

it('finds a company by an accented capital, on both names', function (): void {
    $company = Company::factory()->create([
        'legal_name' => 'Óptica del Ñabo S.A.S.',
        'trade_name' => 'Ñabo',
    ]);

    $this->actingAs(userWithPermissions(['companies.view']));

    foreach (['Óptica del Ñabo', 'optica del nabo', 'NABO', 'ñabo', 'óptica'] as $term) {
        $found = collect(
            $this->getJson('/api/companies?search='.urlencode($term))->assertOk()->json('companies')
        )->pluck('id');

        expect($found, $term)->toContain($company->id);
    }
});

it('finds a social security entity by an accented capital', function (): void {
    $entity = SocialSecurityEntity::factory()->create([
        'name' => 'Ángela Prima S.A.',
        'code' => 'AP-01',
    ]);

    $this->actingAs(userWithPermissions(['social_security_entities.view']));

    foreach (['Ángela', 'angela', 'ÁNGELA'] as $term) {
        $found = collect(
            $this->getJson('/api/social-security-entities?search='.urlencode($term))
                ->assertOk()
                ->json('entities')
        )->pluck('id');

        expect($found, $term)->toContain($entity->id);
    }
});

it('finds a company in the relationship picker by an accented capital', function (): void {
    // A third code path: `ClientController::companyOptions()` folds its own needle rather
    // than going through the list request, so it had to be folded separately and could
    // easily have been missed.
    $company = Company::factory()->create([
        'legal_name' => 'Única Agrupación S.A.S.',
        'trade_name' => 'Única',
    ]);

    $this->actingAs(userWithPermissions(['companies.view']));

    foreach (['Única', 'unica', 'ÚNICA', 'Única Agrupación', 'unica agrupacion'] as $term) {
        $found = collect(
            $this->getJson('/api/company-options?search='.urlencode($term))
                ->assertOk()
                ->json('companies')
        )->pluck('id');

        expect($found, $term)->toContain($company->id);
    }
});

it('keeps the escaping after the folding, because unaccent passes wildcards through', function (): void {
    // `unaccent` rewrites letters and leaves `%` and `_` alone, so the escaping still has to
    // do its job — and it is now applied on **both** sides of an `unaccent` call, which is
    // exactly where a rewrite like this could have lost it.
    $literal = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => '100% accompaniment',
        'document_number' => '93000001',
    ]);

    // Two clients an unescaped `%` would sweep in, and the exact counts pin down the point.
    $hundreds = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => '100 pesos accompaniment',
        'document_number' => '93000002',
    ]);

    Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => '1000 accompaniment',
        'document_number' => '93000005',
    ]);

    $this->actingAs(userWithPermissions(['clients.view']));

    $ids = fn (string $term) => collect(
        $this->getJson('/api/clients?search='.urlencode($term))->assertOk()->json('clients')
    )->pluck('id');

    // A typed `%` is a percent sign: it means "contains a percent sign", not "contains 100".
    $percent = $ids('100%');

    expect($percent)->toContain($literal->id)
        ->and($percent)->not->toContain($hundreds->id)
        ->and($percent)->toHaveCount(1);

    // Without the sign the same three are all there, so the count above is the escaping
    // rather than the data: `100` is a substring of every one of them.
    $plain = $ids('100');

    expect($plain)->toContain($literal->id)
        ->and($plain)->toContain($hundreds->id)
        ->and($plain)->toHaveCount(3);

    // And the underscore, which is the single-character wildcard: `AEB` must not come back
    // for `a_b`, or the letter the operator typed would be matching a different letter.
    $underscored = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => 'A_B accompaniment',
        'document_number' => '93000003',
    ]);

    $lookalike = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => 'AEB accompaniment',
        'document_number' => '93000004',
    ]);

    $found = $ids('a_b');

    expect($found)->toContain($underscored->id)
        ->and($found)->not->toContain($lookalike->id)
        ->and($found)->toHaveCount(1);
});

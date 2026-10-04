<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ManagePayments;
use App\Models\Client;
use App\Models\Company;

/**
 * A03-R2 §11: a full name is searchable, wherever A03 searches for a person.
 *
 * ## The defect
 *
 * Every one of these searches matched the term against `first_names` **or**
 * `last_names`, separately. With
 *
 *     first_names = "Ana María"
 *     last_names  = "Gómez"
 *
 * the term `Ana María Gómez` matches neither column. `Ana` finds her. `Gómez` finds
 * her. Her full name — the one thing a person actually types — finds nobody, and the
 * screen reports an empty result with no indication that the person exists.
 *
 * It was filed as an A02 observation and left there. That was the wrong place to leave
 * it, because A03 depends on it: the billing configuration screen picks the client a
 * cutoff exception is about through this search, so a client-scoped rule could not be
 * created for a client found by name. The E2E journeys had to work around it by
 * searching a fragment of one column, which is exactly the kind of thing that makes a
 * screen look broken to whoever has to use it.
 *
 * ## What is asserted
 *
 * The terms a person would really type, on **every** surface A03 searches a person, plus
 * the two properties that must survive: a literal `%` and `_` are characters, not
 * wildcards, and matching stays case-insensitive.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** A person whose name cannot be matched by any single column. */
function searchableClient(): array
{
    $client = Client::factory()->create([
        'first_names' => 'Ana María',
        'last_names' => 'Gómez',
        'document_number' => '52998877',
    ]);

    $company = Company::factory()->create([
        'legal_name' => 'Constructora Andina S.A.S.',
        'trade_name' => 'Andina',
    ]);

    $employer = a03_employer(
        client: $client,
        company: $company,
        relationshipStart: now()->subMonthsNoOverflow(6)->startOfYear()->format('Y-m-d'),
        effectiveMonth: now()->subMonthsNoOverflow(6)->startOfYear()->format('Y-m'),
    );

    return compact('client', 'company', 'employer');
}

/** Every term a person would type, and whether it must find Ana. */
function nameTerms(): array
{
    return [
        'first name only' => ['Ana María', true],
        'first word only' => ['Ana', true],
        'first name, folded' => ['ana maría', true],
        'two leading words' => ['ana mar', true],
        'surname only' => ['Gómez', true],
        'surname, folded' => ['gómez', true],
        // The whole name, spanning both columns. This is the one that found nobody.
        'full name' => ['Ana María Gómez', true],
        'full name, folded and unaccented prefix' => ['ana maría gómez', true],
        'document' => ['52998877', true],
        'a term she has nothing to do with' => ['Zapata', false],
    ];
}

it('finds a client by any part of the name, including the whole of it', function (string $term, bool $found): void {
    $seeded = searchableClient();

    $response = $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson('/api/clients?search='.urlencode($term).'&per_page=50')
        ->assertOk();

    $ids = collect($response->json('clients'))->pluck('id')->all();

    if ($found) {
        expect($ids, $term)->toContain($seeded['client']->id);
    } else {
        expect($ids, $term)->not->toContain($seeded['client']->id);
    }
})->with(nameTerms());

it('finds a company by its legal name and its trade name', function (): void {
    searchableClient();

    $this->actingAs(userWithPermissions(['companies.view']));

    foreach (['Constructora Andina', 'Andina', 'constructora andina'] as $term) {
        $ids = collect(
            $this->getJson('/api/companies?search='.urlencode($term))->assertOk()->json('companies')
        )->pluck('id')->all();

        expect($ids, $term)->not->toBeEmpty();
    }
});

it('finds a payment by the full name of the client it belongs to', function (string $term): void {
    $seeded = searchableClient();

    app(ManagePayments::class)->register(
        $seeded['client'],
        ['amount_cop' => 120000, 'received_on' => now()->format('Y-m-d'), 'method' => 'cash'],
        actingAsRole(),
    );

    $this->actingAs(userWithPermissions(['payments.view']));

    foreach (['Ana María Gómez', 'Ana María', 'Gómez'] as $needle) {
        $rows = $this->getJson('/api/payments?search='.urlencode($needle))
            ->assertOk()
            ->json('items');

        expect(collect($rows)->pluck('client_id')->all(), $needle)
            ->toContain($seeded['client']->id);
    }
})->with(['full name', 'first name', 'surname']);

it('finds a debtor by the full name on the portfolio', function (): void {
    $seeded = searchableClient();

    a03_payable(250000, now()->subMonthsNoOverflow(2)->format('Y-m'), $seeded['employer']);

    $this->actingAs(userWithPermissions(['receivables.view']));

    foreach (['Ana María Gómez', 'ana maría', 'Ana María'] as $term) {
        $rows = $this->getJson('/api/receivables?search='.urlencode($term))
            ->assertOk()
            ->json('items');

        expect(collect($rows)->pluck('client_id')->all(), $term)
            ->toContain($seeded['client']->id);
    }
});

it('finds the client for a billing picker by the full name', function (): void {
    // The screen that depends on it: a client-scoped cutoff rule cannot be created
    // unless the client can be picked, and the picker searched one column.
    $seeded = searchableClient();

    // `clients.view` is what the picker needs, which is why a role without it cannot use
    // the billing screen at all: a separate question, answered by the permission probes.
    $this->actingAs(userWithPermissions(['clients.view', 'cutoffs.view']));

    $rows = $this->getJson('/api/clients?search='.urlencode('Ana María Gómez').'&per_page=25')
        ->assertOk()
        ->json('clients');

    expect(collect($rows)->pluck('id')->all())->toContain($seeded['client']->id);
});

it('treats a typed percent sign and underscore as characters', function (): void {
    // A wildcard that reached `LIKE` unescaped turns "100%" into "anything containing
    // 100", which returns wrong answers rather than an error.
    Client::factory()->create([
        'first_names' => 'Discount',
        'last_names' => 'Hundred',
    ]);

    $literal = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => '100% Off',
    ]);

    $this->actingAs(userWithPermissions(['clients.view']));

    $found = collect(
        $this->getJson('/api/clients?search='.urlencode('100%'))->assertOk()->json('clients')
    )->pluck('id');

    expect($found)->toContain($literal->id)
        // The client with a "Hundred" surname must not come back because `%` matched
        // "anything" — this is the assertion that would fail without the escaping.
        ->and($found)->toHaveCount(1);

    // The same for `_`, which is the single-character wildcard.
    $underscored = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => 'A_B',
    ]);

    $matches = collect(
        $this->getJson('/api/clients?search='.urlencode('a_b'))->assertOk()->json('clients')
    )->pluck('id');

    expect($matches)->toContain($underscored->id)->toHaveCount(1);
});

it('escapes wildcards on the portfolio and on the payment list too', function (): void {
    $noise = searchableClient();

    $literal = Client::factory()->create([
        'first_names' => 'Plan',
        'last_names' => '50_50',
    ]);

    $literalEmployer = a03_employer(client: $literal);

    a03_payable(10000, now()->subMonthsNoOverflow(1)->format('Y-m'), $literalEmployer);
    a03_payable(10000, now()->subMonthsNoOverflow(1)->format('Y-m'), $noise['employer']);

    $this->actingAs(userWithPermissions(['receivables.view']));

    $found = collect(
        $this->getJson('/api/receivables?search='.urlencode('50_50'))
            ->assertOk()
            ->json('items')
    )->pluck('client_id');

    expect($found)->toContain($literal->id)
        ->and($found)->toHaveCount(1);
});

it('matches without regard to case, and does not lose the folded behaviour', function (): void {
    searchableClient();

    $this->actingAs(userWithPermissions(['clients.view']));

    // The reason the needle is folded on both sides: `lower(first_names) LIKE 'ana%'`
    // does not match `Ana María` when the literal is not folded too.
    foreach (['ana', 'ANA', 'AnA'] as $term) {
        $ids = collect(
            $this->getJson('/api/clients?search='.urlencode($term))->assertOk()->json('clients')
        )->pluck('id');

        expect($ids, $term)->not->toBeEmpty();
    }
});

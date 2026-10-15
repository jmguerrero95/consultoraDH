<?php

declare(strict_types=1);

use App\Domain\Affiliations\Actions\ManageCatalogueEntities;
use App\Domain\Affiliations\LoadVerifiedEntityCatalogue;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Affiliations\VerifiedEntityCatalogue;
use App\Domain\Shared\RecordStatus;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A catalogue load is audited as somebody's decision, so it needs somebody.
 *
 * `LoadVerifiedEntityCatalogue` deliberately refuses to invent an actor — a catalogue change with a
 * fabricated author is exactly the "resolved" claim §4.1 exists to prevent. These tests therefore
 * create a real operator rather than relaxing that guard to make them pass.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    userWithRole('Operations');
});

/**
 * The catalogue manifest: what it claims, and what it refuses to claim.
 *
 * These are assertions about **evidence**, not about the database. Most of them would pass against
 * an empty catalogue, which is the point: a test that only counted rows would be satisfied by a
 * manifest full of plausible inventions.
 */
it('records the authority and the date for every verified name', function (): void {
    $evidence = VerifiedEntityCatalogue::evidence();

    expect($evidence)->toHaveKeys(['authority', 'url', 'published']);
    expect($evidence['authority'])->not->toBeEmpty();
    expect($evidence['url'])->toStartWith('https://');
    expect($evidence['published'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');

    // Every entry carries that evidence, so no name can be in the manifest anonymously.
    foreach (VerifiedEntityCatalogue::entities() as $entry) {
        expect($entry['evidence'])->toBe($evidence);
    }
});

it('invents no code or tax id, because none was verified', function (): void {
    foreach (VerifiedEntityCatalogue::entities() as $entry) {
        expect($entry['code'])->toBeNull();
        expect($entry['tax_id'])->toBeNull();
    }
});

it('never crosses a type', function (): void {
    $seen = [];

    foreach (VerifiedEntityCatalogue::entities() as $entry) {
        expect(SocialSecurityEntityType::cases())->toContain($entry['type']);
        $seen[$entry['type']->value][] = $entry['name'];
    }

    // A name may appear once per type — `COMPENSAR` is legitimately both an EPS and a CCF — but the
    // manifest must never carry the same name twice *within* one type, which would be two rows for
    // one organisation.
    foreach ($seen as $type => $names) {
        expect($names)->toBe(array_values(array_unique($names)), "nombres repetidos en {$type}");
    }
});

it('says which types it has nothing for, instead of leaving it ambiguous', function (): void {
    $coverage = VerifiedEntityCatalogue::coverage();

    foreach (SocialSecurityEntityType::cases() as $type) {
        expect($coverage)->toHaveKey($type->value);
    }

    $unverified = VerifiedEntityCatalogue::unverifiedByType();

    // Every type with zero coverage must carry a reason. A gap that looks like an oversight is
    // safe to act on; a gap that looks like a decision is not.
    foreach ($coverage as $type => $count) {
        if ($count === 0) {
            expect($unverified)->toHaveKey($type);
            expect($unverified[$type])->toContain('No verificado');
        }
    }

    // And the reason names the thing that actually blocked it.
    expect($unverified[SocialSecurityEntityType::Afp->value])->toContain('JavaScript');
    expect($unverified[SocialSecurityEntityType::Ccf->value])->toContain('rechazó la conexión');
});

it('proposes aliases without applying any of them', function (): void {
    $aliases = VerifiedEntityCatalogue::proposedAliases();

    expect($aliases)->not->toBeEmpty();

    $targets = array_map(
        static fn (array $a): string => $a['type']->value.':'.$a['target'],
        $aliases,
    );

    // Every proposed target must be a name the manifest actually verified, within its own type.
    // An alias pointing at something not in the catalogue would resolve to nothing and silently
    // do nothing — the §9.2 fuzzy merge that never merged.
    $verified = array_map(
        static fn (array $e): string => $e['type']->value.':'.$e['name'],
        VerifiedEntityCatalogue::entities(),
    );

    foreach ($targets as $target) {
        expect($verified)->toContain($target);
    }

    // Each carries a reason and a confidence, so approving one is not a guess.
    foreach ($aliases as $alias) {
        expect($alias['reason'])->not->toBeEmpty();
        expect($alias['confidence'])->toBeIn(['exact', 'typo']);
        expect($alias['token'])->not->toBe($alias['target']);
    }
});

it('keeps the four false ARL words out of every alias proposal', function (): void {
    foreach (VerifiedEntityCatalogue::proposedAliases() as $alias) {
        expect($alias['target'])->not->toBeIn(['UNO', 'TRES', 'AUXILIAR', 'MECANICO']);
        expect($alias['token'])->not->toBeIn(['UNO', 'TRES', 'AUXILIAR', 'MECANICO']);
    }

    // And no verified entity carries one either.
    foreach (VerifiedEntityCatalogue::entities() as $entry) {
        expect($entry['name'])->not->toBeIn(['UNO', 'TRES', 'AUXILIAR', 'MECANICO']);
    }
});

/**
 * Idempotence, on a real database.
 *
 * This is the test that has to run twice and prove nothing changed the second time, because a
 * catalogue that duplicates on every run turns a ten-name load into ten rows the next week and a
 * hundred the month after.
 */
it('loads the catalogue without duplicating anything, however many times it runs', function (): void {
    $before = SocialSecurityEntity::query()->count();

    $loader = app(LoadVerifiedEntityCatalogue::class);
    $first = $loader->run(apply: true, actor: User::query()->firstOrFail());

    expect($first['created'])->not->toBeEmpty();
    expect($first['already_present'])->toBeEmpty();

    $afterFirst = SocialSecurityEntity::query()->count();
    expect($afterFirst)->toBe($before + count($first['created']));

    // Second and third run: nothing new, nothing touched.
    foreach ([2, 3] as $run) {
        $again = $loader->run(apply: true, actor: User::query()->firstOrFail());

        expect($again['created'])->toBeEmpty("la corrida {$run} creó entidades nuevas");
        expect($again['already_present'])->toHaveCount(count($first['created']));
        expect(SocialSecurityEntity::query()->count())->toBe($afterFirst);
    }

    // Every created row is one the manifest actually lists, and none is duplicated by name+type.
    $created = SocialSecurityEntity::query()
        ->whereIn('id', array_map(
            fn (string $key) => SocialSecurityEntity::query()
                ->where('type', strstr($key, ':', true))
                ->where('name', substr($key, strpos($key, ':') + 1))
                ->value('id'),
            $first['created'],
        ))
        ->get();

    expect($created)->toHaveCount(count($first['created']));

    // The 28 EPS carry the code and NIT the Ministry's table gave for them. The 42 CCF carry
    // neither, because the SSF directory publishes neither — and the distinction has to survive a
    // loader that handles both, or one source's shape gets imposed on the other.
    foreach ($created as $entity) {
        if ($entity->getRawOriginal('type') === SocialSecurityEntityType::Eps->value) {
            expect($entity->getRawOriginal('code'))->not->toBeNull();
            expect($entity->getRawOriginal('tax_id'))->toMatch('/^\d{9}$/');
        } elseif ($entity->getRawOriginal('type') === SocialSecurityEntityType::Ccf->value) {
            // The SSF directory publishes neither a code nor a NIT for its 42 cajas.
            expect($entity->getRawOriginal('code'))->toBeNull();
            expect($entity->getRawOriginal('tax_id'))->toBeNull();
        } else {
            // AFP. The four private funds carry the SFC code as a namespaced **pair**, because
            // `002` alone is not unique across issuers. Colpensiones carries none, and that is the
            // correct answer rather than a gap: the RNVEI namespace codes institutions the
            // Superintendencia supervises as financial entities, and it is not one.
            $code = $entity->getRawOriginal('code');
            $isPublic = $entity->getRawOriginal('tax_id') === '9003360047';

            if ($isPublic) {
                expect($code)->toBeNull('una administradora pública no tiene código de AFP privada');
            } else {
                expect($code)->toStartWith('RNVEI:');
            }

            expect($entity->getRawOriginal('tax_id'))->not->toBeNull();
        }
    }
});

it('leaves an existing row completely alone, including a code added by hand', function (): void {
    // Somebody already registered this EPS and filled in its code from the registry. A catalogue
    // load must not null that code, rename the entity, or touch the row at all.
    $existing = app(ManageCatalogueEntities::class)->create([
        'type' => SocialSecurityEntityType::Eps->value,
        'name' => 'eps sanitas',
        'code' => 'EPS005',
        'tax_id' => '800251440',
    ], User::query()->firstOrFail());

    $before = $existing->fresh()?->toArray();
    $touchedAt = $existing->updated_at->toDateTimeString();

    $result = app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: User::query()->firstOrFail());

    expect($result['already_present'])->toContain(SocialSecurityEntityType::Eps->value.':EPS SANITAS');
    expect($result['created'])->not->toContain(SocialSecurityEntityType::Eps->value.':EPS SANITAS');

    // Case and accents are folded for matching, and nothing else moved.
    $after = $existing->fresh()?->toArray();
    expect($after['code'])->toBe('EPS005');
    expect($after['tax_id'])->toBe('800251440');
    expect($after['name'])->toBe($before['name']);
    expect($after['status'])->toBe($before['status']);

    // Untouched, not rewritten to the same value: `updated_at` is what a load would move, and it
    // is the only field here that distinguishes "left alone" from "rewritten identically".
    // Read through the model on both sides, because `toArray()` hands back the raw column and the
    // attribute hands back a Carbon — comparing those two would fail on formatting alone and prove
    // nothing about whether the row was touched.
    expect($existing->fresh()?->updated_at?->toDateTimeString())->toBe($touchedAt);
});

it('keeps COMPENSAR as two entities, one per type', function (): void {
    $loader = app(LoadVerifiedEntityCatalogue::class);
    $loader->run(apply: true, actor: User::query()->firstOrFail());

    // The EPS manifest verifies COMPENSAR as an EPS; the CCF manifest has its own COMPENSAR caja.
    // Same organisation, two functions, two catalogue rows — and the loader must have created both
    // rather than collapsing them into one.
    $compensar = SocialSecurityEntity::query()
        ->whereRaw('lower(unaccent(name)) = ?', ['compensar'])
        ->orWhereRaw('lower(unaccent(name)) = ?', ['compensar eps'])
        // The CCF row is sigla-qualified, because the directory publishes no distinguishing name
        // for it beyond "Caja de Compensación Familiar".
        ->orWhereRaw('lower(unaccent(name)) like ?', ['%compensar%'])
        ->get();

    // Sorted on both sides: the rows come back in whatever order the planner chooses, and a test
    // that depends on that is a test that fails for no reason on a different day.
    $types = $compensar->pluck('type')->unique()->map(fn ($t) => $t->value)->toArray();
    sort($types);

    expect($types)->toBe([SocialSecurityEntityType::Ccf->value, SocialSecurityEntityType::Eps->value]);
});

it('reports what it would do without writing, which is the default', function (): void {
    $before = SocialSecurityEntity::query()->count();

    $dry = app(LoadVerifiedEntityCatalogue::class)->run();

    expect($dry['created'])->toBeEmpty();
    expect($dry['would_create'])->not->toBeEmpty();
    expect(SocialSecurityEntity::query()->count())->toBe($before);
});

it('refuses to write without an explicit actor, and says why', function (): void {
    $before = SocialSecurityEntity::query()->count();

    // The defect this pins: `actor()` used to pick the first row of `users`, which attributes an
    // authorisation to whoever registered first. That is not evidence of anything and it looks
    // like evidence, which is worse than none.
    expect(fn () => app(LoadVerifiedEntityCatalogue::class)->run(apply: true))
        ->toThrow(RuntimeException::class, 'actor explícito');

    expect(SocialSecurityEntity::query()->count())->toBe($before);

    // The dry run writes nothing, so it needs no author and must not be blocked for lack of one.
    $dry = app(LoadVerifiedEntityCatalogue::class)->run();

    expect($dry['created'])->toBeEmpty();
    expect($dry['would_create'])->not->toBeEmpty();
    expect(SocialSecurityEntity::query()->count())->toBe($before);
});

it('leaves a deactivated entity deactivated rather than reactivating it', function (): void {
    $existing = app(ManageCatalogueEntities::class)->create([
        'type' => SocialSecurityEntityType::Eps->value,
        'name' => 'FAMISANAR',
    ], User::query()->firstOrFail());

    app(ManageCatalogueEntities::class)
        ->deactivate($existing, User::query()->firstOrFail());

    $result = app(LoadVerifiedEntityCatalogue::class)
        ->run(apply: true, actor: User::query()->firstOrFail());

    // Reactivating would undo a person's decision because a later list happens to contain the name.
    expect($result['skipped_inactive'])->toContain(SocialSecurityEntityType::Eps->value.':FAMISANAR');
    expect($result['created'])->not->toContain(SocialSecurityEntityType::Eps->value.':FAMISANAR');
    expect($result['already_present'])->not->toContain(SocialSecurityEntityType::Eps->value.':FAMISANAR');

    expect($existing->fresh()?->getRawOriginal('status'))->not->toBe(RecordStatus::Active->value);
});

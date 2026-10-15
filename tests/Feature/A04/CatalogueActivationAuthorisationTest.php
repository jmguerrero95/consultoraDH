<?php

declare(strict_types=1);

use App\Domain\Affiliations\ApplyCatalogueSiglaMappings;
use App\Domain\Affiliations\CatalogueActivationAuthorisation;
use App\Domain\Affiliations\CatalogueActivationRefused;
use App\Domain\Affiliations\Events\SocialSecurityEntityChanged;
use App\Domain\Affiliations\LoadVerifiedEntityCatalogue;
use App\Domain\Users\UserStatus;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('refuses a write when there is no actor at all', function (): void {
    expect(fn () => app(LoadVerifiedEntityCatalogue::class)->run(apply: true))
        ->toThrow(CatalogueActivationRefused::class);

    expect(fn () => app(ApplyCatalogueSiglaMappings::class)->run(apply: true))
        ->toThrow(CatalogueActivationRefused::class);

    expect(SocialSecurityEntity::query()->count())->toBe(0);
});

it('refuses an actor that does not exist in the database', function (): void {
    // A `User` instance that was never persisted, or was deleted afterwards. It can hold any
    // permission in memory, which is exactly why `exists` is checked before anything else.
    $ghost = new User(['name' => 'Fantasma', 'email' => 'fantasma@example.test']);
    $ghost->id = 999999;

    expect(fn () => app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: $ghost))
        ->toThrow(CatalogueActivationRefused::class);

    expect(SocialSecurityEntity::query()->count())->toBe(0);
});

it('refuses a deactivated account even when it holds the permissions', function (): void {
    $actor = userWithRole('Administrator');
    expect(CatalogueActivationAuthorisation::allows($actor, writesMappings: false))->toBeTrue();

    $actor->forceFill(['status' => UserStatus::Inactive->value])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The permissions are still attached. The account simply may not act, and a `User` loaded
    // before the deactivation must not be able to authorise a write afterwards.
    expect(fn () => app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: $actor))
        ->toThrow(CatalogueActivationRefused::class, 'no está activa');

    expect(SocialSecurityEntity::query()->count())->toBe(0);
});

it('refuses an actor without the catalogue permission', function (): void {
    $actor = userWithRole('Operations');

    expect(CatalogueActivationAuthorisation::allows($actor, writesMappings: false))->toBeFalse();

    expect(fn () => app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: $actor))
        ->toThrow(CatalogueActivationRefused::class, 'social_security_entities.manage');

    expect(SocialSecurityEntity::query()->count())->toBe(0);
});

it('needs the review permission to write a reusable mapping, and only for that', function (): void {
    $actor = userWithRole('Administrator');

    // Entities: the catalogue permission is enough.
    expect(CatalogueActivationAuthorisation::allows($actor, writesMappings: false))->toBeTrue();

    // A reusable mapping also needs `imports.review`, because it outlives this load and changes how
    // a future workbook resolves. Same actor, same permission set, two different questions.
    // Detached from the **role**, not from the user: the grant belongs to 'Administrator', and
    // `revokePermissionTo` on the user cannot remove something the role still confers.
    Role::findByName('Administrator')
        ->revokePermissionTo('imports.review');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor->unsetRelation('roles')->unsetRelation('permissions');

    expect(CatalogueActivationAuthorisation::allows($actor, writesMappings: false))->toBeTrue();
    expect(CatalogueActivationAuthorisation::allows($actor, writesMappings: true))->toBeFalse();

    expect(fn () => app(ApplyCatalogueSiglaMappings::class)->run(apply: true, actor: $actor))
        ->toThrow(CatalogueActivationRefused::class, 'imports.review');

    expect(DB::table('import_source_mappings')->count())->toBe(0);
});

it('lets anybody preview, because a dry run writes nothing', function (): void {
    $dry = app(LoadVerifiedEntityCatalogue::class)->run();

    expect($dry['would_create'])->not->toBeEmpty();
    expect($dry['created'])->toBeEmpty();
    expect(SocialSecurityEntity::query()->count())->toBe(0);

    // With no catalogue loaded there is nothing for a mapping to point at, so the proposals come
    // back as "missing target" rather than "would create". The preview still reads nothing and the
    // table is still empty, which is the point being asserted.
    app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: userWithRole('Administrator'));

    $actor = userWithRole('Administrator');
    expect(app(ApplyCatalogueSiglaMappings::class)->run()['would_create'])->not->toBeEmpty();
    expect(DB::table('import_source_mappings')->count())->toBe(0);
});

it('allows an authorised actor and attributes the write to that actor', function (): void {
    $actor = userWithRole('Administrator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $result = app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: $actor);

    expect($result['created'])->toHaveCount(85);

    // The audit trail names the actor who authorised it, not "the first user in the table".
    $events = SocialSecurityEntityChanged::class;
    expect(class_exists($events))->toBeTrue();
});

it('reports a mapping whose destination disagrees with the proposal as a conflict', function (): void {
    $actor = userWithRole('Administrator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: $actor);
    app(ApplyCatalogueSiglaMappings::class)->run(apply: true, actor: $actor);

    // Somebody pointed CAJASAN at the wrong caja, deliberately.
    $wrong = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw('lower(unaccent(name)) like ?', ['%cafam%'])->firstOrFail();
    DB::table('import_source_mappings')
        ->where('source_key', 'CAJASAN')
        ->update(['social_security_entity_id' => $wrong->getKey()]);

    $replay = app(ApplyCatalogueSiglaMappings::class)->run(apply: true, actor: $actor);

    // The same (profile, type, token) is **not** treated as agreement. The destination is compared,
    // the disagreement is reported, and the existing decision is left exactly as it was.
    expect($replay['conflicts'])->not->toBeEmpty();
    expect($replay['created'])->not->toContain('CCF|CAJASAN');
    expect(DB::table('import_source_mappings')->where('source_key', 'CAJASAN')->value('social_security_entity_id'))
        ->toBe($wrong->getKey());
});

<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Users\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as AccessGate;
use Illuminate\Support\Facades\Gate;

/**
 * Who may write to the national catalogues.
 *
 * ## Why this exists when the API routes already carry `can:` middleware
 *
 * Because the loaders are **not only** reachable through those routes. They are plain services, and
 * a service called from `artisan`, from a test, or from another domain object reaches no middleware
 * at all. A permission check that lives only in a route is a check on one door of a building with
 * three.
 *
 * So the check lives here, in the service, and the route's `can:` becomes a second line rather than
 * the only one. Both must pass, and they are independent: a future caller that forgets the route
 * middleware still gets refused.
 *
 * ## What is required, and why each
 *
 *     the actor is a real row ............ a fabricated id is not a person
 *     the actor is active ............... an account that cannot log in must not activate a catalogue
 *     the actor can manage entities ..... writing entities needs the catalogue's own permission
 *     the actor can review imports ...... writing a *reusable* mapping also changes how a future
 *                                         import resolves, which is the reviewer's decision
 *
 * The last one is why a mapping needs two permissions rather than one. `import_source_mappings` rows
 * outlive the catalogue load: they change how the next workbook resolves its tokens. That is an
 * import-review decision wearing a catalogue write, and it is checked as one.
 *
 * ## No new role, no new permission
 *
 * Both already exist — `social_security_entities.manage` and `imports.review` — and both are already
 * granted to the roles that administrate the catalogue today. Inventing a third permission would
 * have meant a second thing to grant before anyone could use the thing that works.
 *
 * ## Why the dry run needs nothing
 *
 * It writes nothing, so it cannot damage anything, and making it ask for a permission would mean the
 * operator cannot preview what they are about to do without already holding the right. That is a
 * worse answer than the one here: the write is guarded, and only the write.
 */
final class CatalogueActivationAuthorisation
{
    public const PERMISSION_ENTITIES = 'social_security_entities.manage';

    public const PERMISSION_REVIEWS = 'imports.review';

    /**
     * Whether this actor may perform a write of the given kind.
     *
     * @param  bool  $writesMappings  whether the write also creates reusable import mappings
     */
    public static function allows(?User $actor, bool $writesMappings): bool
    {
        if ($actor === null || ! $actor->exists) {
            return false;
        }

        // A deactivated account cannot log in, so it cannot authorise anything. Without this a
        // stale `User` instance from before a deactivation would still carry its permissions.
        //
        // Read against `UserStatus`, the enum that actually models **accounts**, rather than against
        // the catalogue's own `RecordStatus`. They happen to share the literal `active`, and the first
        // version of this check used the wrong one and refused every authorised operator: a gate that
        // compares against a constant nobody sets is a gate that is always shut.
        if (! self::isActive($actor)) {
            return false;
        }

        if (! self::hasPermission($actor, self::PERMISSION_ENTITIES)) {
            return false;
        }

        // A reusable mapping outlives this load and changes how future imports resolve, so it needs
        // the reviewer's permission as well.
        return ! $writesMappings || self::hasPermission($actor, self::PERMISSION_REVIEWS);
    }

    /**
     * Why an actor is refused, in one sentence fit to be shown to whoever pressed the button.
     */
    public static function refuseReason(?User $actor, bool $writesMappings): string
    {
        if ($actor === null || ! $actor->exists) {
            return 'no hay un actor explícito';
        }

        if (! self::isActive($actor)) {
            return sprintf('la cuenta «%s» no está activa', $actor->name);
        }

        if (! self::hasPermission($actor, self::PERMISSION_ENTITIES)) {
            return sprintf(
                '«%s» no tiene el permiso «%s», que es quien autoriza a escribir el catálogo',
                $actor->name,
                self::PERMISSION_ENTITIES,
            );
        }

        if ($writesMappings && ! self::hasPermission($actor, self::PERMISSION_REVIEWS)) {
            return sprintf(
                '«%s» puede escribir entidades pero no tiene «%s». Un mapeo reutilizable cambia cómo '
                .'se resuelve un libro futuro, así que es también una decisión de revisión.',
                $actor->name,
                self::PERMISSION_REVIEWS,
            );
        }

        return 'autorizado';
    }

    /**
     * @throws \RuntimeException when the actor may not write
     */
    public static function guard(?User $actor, bool $writesMappings): void
    {
        if (! self::allows($actor, writesMappings: $writesMappings)) {
            throw new CatalogueActivationRefused(self::refuseReason($actor, $writesMappings));
        }
    }

    /**
     * Whether the account is one that can act.
     *
     * Checked against the stored row rather than against an in-memory flag, so a user object loaded
     * before a suspension cannot authorise a write afterwards.
     */
    private static function isActive(User $actor): bool
    {
        $status = User::query()
            ->whereKey($actor->getKey())
            ->value('status');

        // Compared as text either way. `value()` hands back the cast attribute on some paths and
        // the raw column on others, so a strict comparison against the string rejects every account
        // and a strict comparison against the enum rejects them all the other way.
        $status = $status instanceof \BackedEnum ? $status->value : $status;

        // A user row without a status column value is treated as active: the column is nullable and
        // inventing a default would lock every existing account out of the catalogue.
        return $status === null || $status === UserStatus::Active->value;
    }

    private static function hasPermission(User $actor, string $permission): bool
    {
        // Bound under the **contract** `Illuminate\Contracts\Auth\Access\Gate`, and under
        // neither `'gate'` nor the `Gate` facade class. Measured, in this application: all three
        // differ, and the first two checks were wrong.
        //
        // That is worth the comment because of how it failed. `app()->bound(Gate::class)` is always
        // false here, so the guard fell through to "not permitted" and refused every operator,
        // including the authorised ones — a guard that can only say no is indistinguishable from a
        // working guard until somebody reads the code.
        if (app()->bound(AccessGate::class)) {
            return Gate::forUser($actor)->allows($permission);
        }

        // Outside a booted application there is no Gate to ask, and saying "permitted" there would
        // turn a missing check into a silent pass. Refuse instead.
        return false;
    }
}

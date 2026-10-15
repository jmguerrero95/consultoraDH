<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Affiliations\Actions\ManageCatalogueEntities;
use App\Models\SocialSecurityEntity;
use App\Models\User;

/**
 * Loading {@see VerifiedEntityCatalogue} without ever duplicating a row.
 *
 * ## Why this exists rather than a seeder or a migration
 *
 * Both of those would land in the production database the moment they ran. The manifest is
 * evidence somebody has to approve first, and a seeder that runs on `migrate` removes that decision.
 * So loading is a deliberate act with a dry run, and the dry run is the default shape of the test
 * that exercises it.
 *
 * ## Idempotence, and what it is keyed on
 *
 * Keyed on **(type, folded name)** — never on name alone. `COMPENSAR` is an EPS and a CCF in the
 * same workbook, and two rows with the same name and different types are two different
 * organisations. A catalogue keyed on the name would collapse them and then a payroll record
 * attributed to the wrong kind of administrator.
 *
 * Re-running finds the existing row and leaves it **completely alone** — including its `code` and
 * `tax_id`. It does not null them, does not rename it, and does not reactivate it. Somebody may
 * have corrected a name or added a code by hand, and a catalogue load that overwrites their work
 * would be a data-loss bug wearing the costume of a convenience.
 */
final class LoadVerifiedEntityCatalogue
{
    /**
     * What a load did, for the caller to print or assert on.
     *
     * @return array{created: list<string>, already_present: list<string>, skipped_inactive: list<string>, would_create: list<string>}
     */
    public function run(bool $apply = false): array
    {
        $created = [];
        $alreadyPresent = [];
        $skippedInactive = [];
        $wouldCreate = [];

        foreach (VerifiedEntityCatalogue::entities() as $entry) {
            $type = $entry['type'];
            $name = $entry['name'];

            $existing = SocialSecurityEntity::query()
                ->where('type', $type->value)
                ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$name])
                ->first();

            if ($existing !== null) {
                // Deliberately no writes. See the class docblock.
                $alreadyPresent[] = $type->value.':'.$name;

                continue;
            }

            $wouldCreate[] = $type->value.':'.$name;

            if (! $apply) {
                continue;
            }

            app(ManageCatalogueEntities::class)->create([
                'type' => $type->value,
                'name' => $name,
                // Null, and staying null. The manifest has no verified codes, so there is nothing
                // to write; `ManageCatalogueEntities::nullIfBlank()` would store null anyway.
                'code' => $entry['code'],
                'tax_id' => $entry['tax_id'],
            ], $this->actor());

            $created[] = $type->value.':'.$name;
        }

        return [
            'created' => $created,
            'already_present' => $alreadyPresent,
            'skipped_inactive' => $skippedInactive,
            'would_create' => $wouldCreate,
        ];
    }

    /**
     * A catalogue load is not a person acting on a payroll record, so it must not borrow the audit
     * trail of one that is.
     *
     * The service requires an actor because every catalogue change is somebody's decision. Loading
     * verified evidence is the operator activating a catalogue, and the actor recorded is exactly
     * that — never a fabricated user id.
     */
    private function actor(): User
    {
        $actor = User::query()->orderBy('id')->first();

        if ($actor !== null) {
            return $actor;
        }

        throw new \RuntimeException(
            'No hay ningún usuario para registrar la carga del catálogo. Una carga de catálogo es una '
            .'decisión de una persona y la auditoría necesita un autor real.',
        );
    }
}

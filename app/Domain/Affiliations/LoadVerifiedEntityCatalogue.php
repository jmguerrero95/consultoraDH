<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Affiliations\Actions\ManageCatalogueEntities;
use App\Domain\Shared\RecordStatus;
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
     * @param  User|null  $actor  the authenticated, authorised person performing the load. Required
     *                            whenever `$apply` is true; never invented.
     */
    /**
     * The ARL's institutional nature.
     *
     * A new value, because the existing ones are EPS and pension subtypes and reusing one of those
     * would file an ARL as something it is not. It says what the entity **is**, never whether it may
     * currently write a policy — that distinction is the whole point of keeping this field apart from
     * the authorisation state.
     */
    private const REGULATORY_CLASS_ARL = 'arl';

    public function run(bool $apply = false, ?User $actor = null): array
    {
        if ($apply && $actor === null) {
            // §4.1 reserves a decision for a person who made one. A catalogue load that picked the
            // first row of `users` would attribute an authorisation to whoever registered first,
            // which is not evidence of anything and is worse than no attribution because it looks
            // like one.
            throw new \RuntimeException(
                'Una carga de catálogo requiere un actor explícito, autenticado y autorizado. '
                .'El ensayo en seco no lo necesita porque no escribe nada.',
            );
        }

        $created = [];
        $alreadyPresent = [];
        $skippedInactive = [];
        $wouldCreate = [];

        // One manifest, one type per row. `type` travels with every entry and is what
        // `ManageCatalogueEntities` writes, so a CCF row can never satisfy an EPS lookup even
        // when the two catalogues name the same organisation — which they do, for the four cajas
        // that provide subsidised health services under their own CCF codes.
        foreach ($this->manifest() as $entry) {
            $type = $entry['type'];
            $name = $entry['name'];

            $existing = SocialSecurityEntity::query()
                ->where('type', $type->value)
                ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$name])
                ->first();

            if ($existing !== null) {
                // Deliberately no writes. See the class docblock.
                //
                // `skipped_inactive` is separate from `already_present` on purpose: an entity a
                // person deactivated is not the same as one that is simply there, and reactivating
                // the first because the second matches would undo a decision.
                if (($existing->getRawOriginal('status') ?? null) !== RecordStatus::Active->value) {
                    $skippedInactive[] = $type->value.':'.$name;
                } else {
                    $alreadyPresent[] = $type->value.':'.$name;
                }

                continue;
            }

            $wouldCreate[] = $type->value.':'.$name;

            if (! $apply) {
                continue;
            }

            app(ManageCatalogueEntities::class)->create([
                'type' => $type->value,
                'name' => $name,
                // Null, and staying null, unless the manifest actually verified one.
                'code' => $entry['code'],
                'tax_id' => $entry['tax_id'],
                // Carried on every manifest entry and passed explicitly.
                //
                // This line was once missing while the manifest already held the value, and the
                // whole load produced 75 rows with a null subtype: no error, no warning, and the
                // test that queried through the model could not see it. It was raw SQL that showed
                // it. A catalogue whose subtypes are silently absent reads exactly like one that
                // has none, which is the opposite of what a verified record says.
                'regulatory_class' => $entry['regulatory_class'] ?? null,
            ], $actor);

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
     * Everything this loader knows, national catalogue first.
     *
     * Kept as one method so the loader has a single place to read from and the dry run describes
     * exactly what a real run would do.
     *
     * @return list<array{type: SocialSecurityEntityType, name: string, code: ?string, tax_id: ?string, regulatory_class?: ?string}>
     */
    private function manifest(): array
    {
        $entries = [];

        foreach (EpsNationalCatalogue::records() as $record) {
            $entries[] = [
                'type' => SocialSecurityEntityType::Eps,
                'name' => $record['name'],
                'code' => $record['code'],
                'tax_id' => $record['tax_id'],
                // The subtype, so a caja or an entidad adaptada is not filed as an ordinary EPS.
                'regulatory_class' => $record['regulatory_class'],
            ];
        }

        foreach (CcfNationalCatalogue::records() as $record) {
            $entries[] = [
                'type' => SocialSecurityEntityType::Ccf,
                // The qualified name when the directory gave a bare "Caja de Compensación Familiar";
                // the institutional name otherwise. Either way the sigla is carried alongside, and
                // it is the sigla that identifies the three cajas that all answer to COMFAMILIAR.
                'name' => $record['name'],
                // No code and no NIT: the SSF directory publishes neither, and filling those
                // columns from anywhere else would put a CCF code space into a row the source
                // never described that way.
                'code' => $record['code'],
                'tax_id' => $record['tax_id'],
                'regulatory_class' => null,
            ];
        }

        foreach (PensionNationalCatalogue::records() as $record) {
            $entries[] = [
                // `AFP` is the operational grouping the workbook column already uses. What kind of
                // institution each one actually is lives in `regulatory_class`, and the two are not
                // the same claim: Colpensiones shares the column and is a public prima media
                // administrator, not a private fund.
                'type' => SocialSecurityEntityType::Afp,
                'name' => $record['name'],
                // The SFC code is a **pair** — an institutional type code and an entity code — and
                // neither half means anything alone. `002` on its own is not unique across issuers
                // and would collide the moment another namespace used it, so the stored code is the
                // pair with its namespace, and a test asserts no two records share a stored code.
                //
                // These are regulatory codes. They are **not** PILA codes, which live in a different
                // namespace entirely, and they are not used as such.
                'code' => $record['sfc'] === null ? null : $record['sfc']['namespace'].':'.$record['sfc']['type_code'].'-'.$record['sfc']['entity_code'],
                'tax_id' => $record['tax_id'],
                'regulatory_class' => $record['regulatory_class'],
            ];
        }

        foreach (ArlNationalCatalogue::records() as $record) {
            $entries[] = [
                'type' => SocialSecurityEntityType::Arl,
                'name' => $record['name'],
                // Null on both, and that is the source's answer: this directory publishes no NIT and
                // no authorisation code for an ARL. An ARL's authorisation is granted by the
                // Ministry of Labour and lives in a different namespace from an SFC code, so filling
                // either column from elsewhere would put one instrument in another's field.
                'code' => $record['code'],
                'tax_id' => $record['tax_id'],
                // Institutional nature, deliberately NOT the authorisation. An entity can be an ARL
                // and still be wound up, which is exactly the case Liberty is in, so the two cannot
                // share a field.
                'regulatory_class' => self::REGULATORY_CLASS_ARL,
            ];
        }

        return $entries;
    }
}

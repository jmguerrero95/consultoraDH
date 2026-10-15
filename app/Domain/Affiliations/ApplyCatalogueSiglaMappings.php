<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\SheetMonth;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writing {@see CatalogueSiglaMappings}'s proposals into `import_source_mappings`.
 *
 * ## What makes a sigla reusable
 *
 * `ImportPlanBuilder::resolveEntity()` consults `import_source_mappings` only when `verified_at` is
 * set. So a row is a *draft* until a person approves it, and this class is what turns the ones it
 * proposes into approved rows — which is why it demands an explicit actor and why a dry run is the
 * default.
 *
 * ## What it will never do
 *
 * **Overwrite.** An existing mapping is somebody's decision, possibly correcting a guess this
 * catalogue would have made differently. It is counted and left alone, and that is what makes a
 * second and third run idempotent.
 *
 * **Cross a type.** A mapping is written with the type it was proposed for, so the `COMPENSAR` CCF
 * sigla can never answer an EPS affiliation even though both are COMPENSAR.
 *
 * **Invent an entity.** A proposal whose canonical name is not in the catalogue is skipped and
 * reported, not created. A sigla mapping that had to invent its target would be a guess wearing
 * the costume of a correspondence.
 */
final class ApplyCatalogueSiglaMappings
{
    /**
     * @return array{created: list<string>, already_present: list<string>, would_create: list<string>, missing_target: list<string>}
     */
    public function run(bool $apply = false, ?User $actor = null): array
    {
        if ($apply && $actor === null) {
            throw new \RuntimeException(
                'Escribir mapeos de sigla requiere un actor explícito, autenticado y autorizado. '
                .'Un mapeo aprobado se reutiliza para todos los libros futuros, así que la aprobación '
                .'debe ser de una persona. El ensayo en seco no lo necesita porque no escribe nada.',
            );
        }

        $created = [];
        $alreadyPresent = [];
        $wouldCreate = [];
        $missingTarget = [];

        foreach (CatalogueSiglaMappings::proposals() as $proposal) {
            $key = $proposal['type']->value.'|'.$proposal['token'];

            $target = SocialSecurityEntity::query()
                ->where('type', $proposal['type']->value)
                ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$proposal['canonical_name']])
                ->first();

            if ($target === null) {
                // Never create the entity to satisfy a mapping. Report it and move on.
                $missingTarget[] = $key;

                continue;
            }

            $exists = DB::table('import_source_mappings')
                ->where('profile', ImportProfile::BlindenLegacyMonthlyV1->value)
                ->where('type', $proposal['type']->value)
                ->where('source_key', SheetMonth::fold($proposal['token']))
                ->exists();

            if ($exists) {
                $alreadyPresent[] = $key;

                continue;
            }

            $wouldCreate[] = $key;

            if (! $apply) {
                continue;
            }

            DB::table('import_source_mappings')->insert([
                // `profile` is NOT NULL and is not decorative: a mapping only ever resolves inside
                // the import profile that created it, so a sigla approved under one workbook's
                // rules cannot silently start answering another profile's tokens. Required rather
                // than defaulted, which is what stopped the first version of this from working.
                'profile' => ImportProfile::BlindenLegacyMonthlyV1->value,
                'type' => $proposal['type']->value,
                'source_key' => SheetMonth::fold($proposal['token']),
                'social_security_entity_id' => $target->getKey(),
                'verified_by' => $actor?->getKey(),
                'verified_at' => $apply ? now() : null,
                'note' => $proposal['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created[] = $key;
        }

        return [
            'created' => $created,
            'already_present' => $alreadyPresent,
            'would_create' => $wouldCreate,
            'missing_target' => $missingTarget,
        ];
    }
}

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
     * @return array{created: list<string>, already_present: list<string>, would_create: list<string>, missing_target: list<string>, conflicts: list<array<string, mixed>>, pending_approval: list<string>}
     */
    /**
     * @param  list<array{type: string, name: string, status?: string}>|null  $simulateEntities
     *                                                                                           Entities a dry run is about to create, so a proposal aimed at one counts as having a target
     *                                                                                           rather than none. Never consulted on a write, where they already exist.
     */
    public function run(bool $apply = false, ?User $actor = null, ?array $simulateEntities = null): array
    {
        // A mapping is a reusable decision about how a future workbook resolves, so it needs the
        // reviewer's permission on top of the catalogue one. See `CatalogueActivationAuthorisation`
        // for why this is checked here as well as at the route.
        if ($apply) {
            CatalogueActivationAuthorisation::guard($actor, writesMappings: true);
        }

        $created = [];
        $alreadyPresent = [];
        $wouldCreate = [];
        $missingTarget = [];
        $conflicts = [];
        $pendingApproval = [];

        foreach (CatalogueSiglaMappings::proposals() as $proposal) {
            $key = $proposal['type']->value.'|'.$proposal['token'];

            $existingTarget = SocialSecurityEntity::query()
                ->where('type', $proposal['type']->value)
                ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$proposal['canonical_name']])
                ->first();

            $target = $existingTarget === null
                ? null
                : ProjectedMappingTarget::of($existingTarget);

            $projected = $target === null;

            if ($target === null) {
                $simulated = collect($simulateEntities ?? [])->contains(
                    fn (array $row): bool => $row['type'] === $proposal['type']->value
                        && SheetMonth::fold($row['name']) === SheetMonth::fold($proposal['canonical_name']),
                );

                if ($simulated && ! $apply) {
                    // A dry run reports what the apply will do; it does not have to have done it.
                    // The target is **named**, not faked — see `ProjectedMappingTarget`.
                    $target = ProjectedMappingTarget::projected($proposal['canonical_name']);
                } else {
                    // Never create the entity to satisfy a mapping. Report it and move on.
                    $missingTarget[] = $key;

                    continue;
                }
            }

            // An existing mapping is **not** "already done" merely because (profile, type, token)
            // collides. Somebody may have pointed that same token at a **different** entity, and
            // treating that as agreement would leave the load reporting success while the importer
            // still resolves to the other row. So the destination is compared too, and a
            // disagreement is reported as a conflict for a person to settle — never overwritten.
            $existing = DB::table('import_source_mappings')
                ->where('profile', ImportProfile::BlindenLegacyMonthlyV1->value)
                ->where('type', $proposal['type']->value)
                ->where('source_key', SheetMonth::fold($proposal['token']))
                ->first();

            if ($existing !== null) {
                // A projected target has no key, so it cannot be "already present" at anything.
                if ($target->exists && (int) $existing->social_security_entity_id === $target->key()) {
                    // Same destination. **That is not yet approval.** `verified_at` is what makes a
                    // mapping reusable, and a row without it is a suggestion somebody opened and
                    // nobody answered. Reporting it as `already_present` told the operator the
                    // importer would resolve this token, which is false: `resolveEntity()` filters on
                    // `verified_at`, so the token still raises a finding.
                    if ($existing->verified_at !== null) {
                        $alreadyPresent[] = $key;
                    } else {
                        $pendingApproval[] = $key;
                    }

                    continue;
                }

                $conflicts[] = [
                    'token' => $key,
                    'existing_entity_id' => (int) $existing->social_security_entity_id,
                    'proposed_entity_id' => $target->exists ? $target->key() : null,
                    'existing_verified' => $existing->verified_at !== null,
                ];

                continue;
            }

            $wouldCreate[] = $key;

            if (! $apply) {
                continue;
            }

            if (! $target->exists) {
                // Unreachable on a write — the entities are really there by then — but a projected
                // target reaching an insert would write `social_security_entity_id = 0`. Fail loudly.
                throw new \LogicException('Se intentó escribir un mapeo contra un destino proyectado.');
            }

            DB::table('import_source_mappings')->insert([
                // `profile` is NOT NULL and is not decorative: a mapping only ever resolves inside
                // the import profile that created it, so a sigla approved under one workbook's
                // rules cannot silently start answering another profile's tokens. Required rather
                // than defaulted, which is what stopped the first version of this from working.
                'profile' => ImportProfile::BlindenLegacyMonthlyV1->value,
                'type' => $proposal['type']->value,
                'source_key' => SheetMonth::fold($proposal['token']),
                'social_security_entity_id' => $target->key(),
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
            'conflicts' => $conflicts,
            'pending_approval' => $pendingApproval,
        ];
    }
}

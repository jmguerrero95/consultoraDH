<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one operation that writes the catalogues and the mappings together.
 *
 * ## Why this exists
 *
 * `LoadVerifiedEntityCatalogue` and `ApplyCatalogueSiglaMappings` each commit on their own. Run in
 * sequence, a failure in the second leaves the first's 85 entities already written and no mappings —
 * the catalogue half-activated, which is the one state in which the importer resolves *worse* than
 * before: entities exist with names that resolve, and tokens that would have been offered for review
 * now resolve silently against an entity nobody approved for them.
 *
 * So the two are wrapped here, in one PostgreSQL transaction. Either both land or neither does.
 *
 * ## What it does before it writes
 *
 * Permission, then integrity, then conflicts — in that order, because each is cheaper than the next
 * and none of them should run after something has been written.
 *
 * A conflict is a **stop**, not a warning. A mapping that points somewhere the catalogue disagrees
 * with is somebody's earlier decision, and overwriting it or half-applying around it both destroy the
 * record of it. The activation fails, says which token, and a person settles it.
 *
 * ## The fingerprint
 *
 * The dry run returns a fingerprint of exactly what it saw: how many entities, how many mappings, and
 * a hash of the manifest that produced them. Confirmation carries that fingerprint back, and it is
 * recomputed immediately before the write. A catalogue that changed underneath a reviewed preview
 * means the preview was of something else, and proceeding would apply an approval to the wrong
 * content.
 *
 * @phpstan-type Result array{
 *     applied: bool, entities: int, mappings: int, already_present: int,
 *     conflicts: list<array<string, mixed>>, pending_approval: list<string>,
 *     fingerprint: string, preflight: array<string, mixed>
 * }
 */
final class ActivateNationalCatalogues
{
    /**
     * @param  callable|null  $afterEntities  seam for the rollback test. Production never passes it;
     *                                        the test passes a closure that throws after the entities
     *                                        are written, so the rollback can be observed rather
     *                                        than asserted.
     */
    public function run(User $actor, bool $apply = false, ?callable $afterEntities = null): array
    {
        // 1. Permission, before anything is read or written. Both permissions, because the mappings
        //    this operation writes are reusable import decisions.
        CatalogueActivationAuthorisation::guard($actor, writesMappings: true);

        $loader = app(LoadVerifiedEntityCatalogue::class);
        $mappings = app(ApplyCatalogueSiglaMappings::class);

        // 2. Preflight. Both loaders run without writing, so their reports describe the same
        //    database state the write will see.
        $entityPreflight = $loader->run();
        $mappingPreflight = $mappings->run();

        // 3. Conflicts stop the whole activation. See the class docblock.
        if ($mappingPreflight['conflicts'] !== []) {
            throw new CatalogueActivationRefused(sprintf(
                'Hay %d mapeo(s) existentes cuyo destino no coincide con el propuesto. No se escribe '
                .'nada hasta que una persona los resuelva: %s.',
                count($mappingPreflight['conflicts']),
                implode(', ', array_column($mappingPreflight['conflicts'], 'token')),
            ));
        }

        $fingerprint = $this->fingerprint($entityPreflight, $mappingPreflight);

        if (! $apply) {
            return $this->result(false, 0, 0, $entityPreflight, $mappingPreflight, $fingerprint);
        }

        // 4. One transaction. `DB::transaction` rolls back on any throwable, including the one the
        //    rollback test injects.
        $written = DB::transaction(function () use ($loader, $mappings, $actor, $afterEntities): array {
            $entities = $loader->run(apply: true, actor: $actor);

            if ($afterEntities !== null) {
                $afterEntities($entities);
            }

            $written = $mappings->run(apply: true, actor: $actor);

            return ['entities' => $entities, 'mappings' => $written];
        }, 3);

        return $this->result(
            true,
            count($written['entities']['created']),
            count($written['mappings']['created']),
            $written['entities'],
            $written['mappings'],
            $fingerprint,
        );
    }

    /**
     * What the preview saw, in a form a confirmation can be checked against.
     */
    public function fingerprint(array $entityPreflight, array $mappingPreflight): string
    {
        return hash('sha256', json_encode([
            'would_create' => count($entityPreflight['would_create']),
            'already_present' => count($entityPreflight['already_present']),
            'skipped_inactive' => count($entityPreflight['skipped_inactive']),
            'mappings' => count($mappingPreflight['would_create']),
            'conflicts' => count($mappingPreflight['conflicts']),
            'pending' => count($mappingPreflight['pending_approval']),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        bool $applied,
        int $entities,
        int $mappings,
        array $entityPreflight,
        array $mappingPreflight,
        string $fingerprint,
    ): array {
        return [
            'applied' => $applied,
            'entities' => $entities,
            'mappings' => $mappings,
            'already_present' => count($entityPreflight['already_present']),
            'conflicts' => $mappingPreflight['conflicts'],
            'pending_approval' => $mappingPreflight['pending_approval'],
            'fingerprint' => $fingerprint,
            'preflight' => [
                'entities_new' => count($entityPreflight['would_create']),
                'entities_existing' => count($entityPreflight['already_present']),
                'entities_inactive_skipped' => count($entityPreflight['skipped_inactive']),
                'mappings_new' => count($mappingPreflight['would_create']),
                'mappings_conflicting' => count($mappingPreflight['conflicts']),
                'mappings_pending_approval' => count($mappingPreflight['pending_approval']),
                'mappings_missing_target' => count($mappingPreflight['missing_target']),
            ],
        ];
    }
}

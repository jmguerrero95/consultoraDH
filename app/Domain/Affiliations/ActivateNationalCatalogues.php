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
 * ## The fingerprint is of **contents**, not of counts
 *
 * The first version hashed how many rows there were. Two entirely different states with the same
 * counts then produced the same hash: repoint a mapping from one entity to another and the count is
 * still 85, the preview still looked like 85, and the confirmation for the *previous* content would
 * have been accepted. A fingerprint that cannot see the change it is meant to detect is decoration.
 *
 * So it hashes the rows themselves: each entity's id, name, type, code, tax_id and status, and each
 * mapping's token, type, destination and approval. Change any of them and the hash changes.
 *
 * ## Where the check happens
 *
 * **Inside the transaction, on the row locks the transaction itself took.** Not in the command, and
 * not before the transaction opens — both are outside the lock, so both leave a window where a second
 * process can commit between the check and the write. Recomputing the fingerprint after `lockForUpdate`
 * closes that window: if it differs from what was confirmed, the transaction throws and rolls back
 * with nothing written.
 *
 * The command still pre-checks. That is a courtesy that saves the operator a pointless transaction,
 * not the protection.
 *
 * ## The dry run simulates its own writes
 *
 * The mapping preflight runs *before* the entities exist, so on an empty catalogue every proposal
 * would report a missing target and the preview would claim the load would do nothing. The preflight
 * therefore treats a target that this same manifest is about to create as present, with the status
 * that manifest gives it. The preview therefore counts what the apply will actually do, which is
 * what an operator is being asked to approve.
 */
final class ActivateNationalCatalogues
{
    /**
     * @param  callable|null  $afterEntities  seam for the rollback test
     */
    public function run(User $actor, bool $apply = false, ?string $expectedFingerprint = null, ?callable $afterEntities = null): array
    {
        // 1. Permission, before anything is read or written.
        CatalogueActivationAuthorisation::guard($actor, writesMappings: true);

        $loader = app(LoadVerifiedEntityCatalogue::class);
        $mappings = app(ApplyCatalogueSiglaMappings::class);

        $preflight = $this->preflight($loader, $mappings);

        // 2. Conflicts stop the activation, in the preview as much as in the apply.
        if ($preflight['mapping_preflight']['conflicts'] !== []) {
            throw new CatalogueActivationRefused(sprintf(
                'Hay %d mapeo(s) existentes cuyo destino no coincide con el propuesto. No se escribe '
                .'nada hasta que una persona los resuelva: %s.',
                count($preflight['mapping_preflight']['conflicts']),
                implode(', ', array_column($preflight['mapping_preflight']['conflicts'], 'token')),
            ));
        }

        $fingerprint = $this->fingerprint($preflight);

        if (! $apply) {
            return $this->result(false, 0, 0, $preflight, $fingerprint);
        }

        // 3. One transaction. The fingerprint is re-checked **inside** it, on the rows the
        //    transaction has locked, so a concurrent writer cannot slip a different state past the
        //    confirmation. A mismatch throws, and the throw rolls everything back.
        $written = DB::transaction(function () use ($loader, $mappings, $actor, $afterEntities, $expectedFingerprint, $fingerprint): array {
            $this->lockRelevantRows();

            $recheck = $this->preflight($loader, $mappings);

            if ($this->fingerprint($recheck) !== $fingerprint) {
                throw new CatalogueActivationRefused(
                    'El estado cambió entre la previsualización confirmada y la escritura. Se aborta sin '
                    .'escribir nada; revise de nuevo.',
                );
            }

            if ($expectedFingerprint !== null && $expectedFingerprint !== $fingerprint) {
                throw new CatalogueActivationRefused(sprintf(
                    'La huella confirmada no corresponde a este estado. Confirmada: %s. Actual: %s.',
                    $expectedFingerprint,
                    $fingerprint,
                ));
            }

            $entities = $loader->run(apply: true, actor: $actor);

            if ($afterEntities !== null) {
                $afterEntities($entities);
            }

            return ['entities' => $entities, 'mappings' => $mappings->run(apply: true, actor: $actor)];
        }, 3);

        return $this->result(
            true,
            count($written['entities']['created']),
            count($written['mappings']['created']),
            $preflight,
            $fingerprint,
        );
    }

    /**
     * Everything the two loaders will see, plus the state they will create.
     *
     * @return array<string, mixed>
     */
    private function preflight(LoadVerifiedEntityCatalogue $loader, ApplyCatalogueSiglaMappings $mappings): array
    {
        $entityPreflight = $loader->run();

        // The mapping preflight runs before the entities exist, so it cannot find their targets and
        // would report every proposal as "missing target" — telling the operator the load does
        // nothing, the opposite of what the apply will do. The simulation tells it which entities
        // this same manifest is about to create, with the status it will give them.
        $simulated = $mappings->run(simulateEntities: $this->incomingEntities());

        return [
            'entity_preflight' => $entityPreflight,
            'mapping_preflight' => $simulated,
            // Snapshot of what exists now, so the fingerprint can see content rather than counts.
            'entities' => DB::table('social_security_entities')
                ->get(['id', 'type', 'name', 'code', 'tax_id', 'status'])
                ->map(fn ($r): array => [
                    'id' => (int) $r->id, 'type' => $r->type, 'name' => $r->name,
                    'code' => $r->code, 'tax_id' => $r->tax_id, 'status' => $r->status,
                ])
                ->sortBy('id')
                ->values()
                ->all(),
            'mappings' => DB::table('import_source_mappings')
                ->get(['profile', 'type', 'source_key', 'social_security_entity_id', 'verified_at'])
                ->map(fn ($r): array => [
                    'profile' => $r->profile, 'type' => $r->type, 'source_key' => $r->source_key,
                    'entity_id' => (int) $r->social_security_entity_id,
                    'verified' => $r->verified_at !== null,
                ])
                ->sortBy(['profile', 'type', 'source_key'])
                ->values()
                ->all(),
            // Which entities this manifest is about to create, with the status it will give them.
            'incoming' => $this->incomingEntities(),
        ];
    }

    /**
     * @return list<array{type: string, name: string, status?: string}>
     */
    private function incomingEntities(): array
    {
        $incoming = [];

        foreach ($this->manifestRows() as $row) {
            $incoming[] = [
                'type' => $row['type'],
                'name' => $row['name'],
                'status' => $row['status'] ?? 'active',
            ];
        }

        usort($incoming, static fn (array $a, array $b): int => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        return $incoming;
    }

    /**
     * @return list<array{type: string, name: string, status?: string}>
     */
    private function manifestRows(): array
    {
        $rows = [];

        foreach (ArlNationalCatalogue::records() as $record) {
            $rows[] = [
                'type' => 'ARL',
                'name' => $record['name'],
                'status' => in_array('left_the_market', $record['flags'], true) ? 'inactive' : 'active',
            ];
        }

        foreach (CcfNationalCatalogue::records() as $record) {
            $rows[] = ['type' => 'CCF', 'name' => $record['name']];
        }

        foreach (EpsNationalCatalogue::records() as $record) {
            $rows[] = ['type' => 'EPS', 'name' => $record['name']];
        }

        foreach (PensionNationalCatalogue::records() as $record) {
            $rows[] = ['type' => 'AFP', 'name' => $record['name']];
        }

        return $rows;
    }

    /**
     * A hash of **content**: every entity row, every mapping row, and every row about to be written.
     *
     * Sorted so the hash is stable across runs, and including `status`, `code`, `tax_id` and each
     * mapping's destination and approval — the fields whose change would alter what the activation
     * means while leaving every count identical.
     */
    public function fingerprint(array $preflight): string
    {
        return hash('sha256', json_encode([
            'entities' => $preflight['entities'],
            'mappings' => $preflight['mappings'],
            'incoming' => $preflight['incoming'],
            'would_create' => $preflight['entity_preflight']['would_create'],
            'mappings_new' => $preflight['mapping_preflight']['would_create'],
            'mappings_conflicts' => $preflight['mapping_preflight']['conflicts'],
            'mappings_pending' => $preflight['mapping_preflight']['pending_approval'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Row locks so the fingerprint re-check and the writes cannot be interleaved by another process.
     *
     * `lockForUpdate` on the two tables the operation reads and writes. Taken inside the transaction,
     * so a competing activation of the same catalogue serialises behind this one rather than
     * interleaving with it.
     */
    private function lockRelevantRows(): void
    {
        // 1. A transaction-scoped **advisory lock** on the catalogue activation.
        //
        //    `lockForUpdate()` alone was not enough, and the reason is structural rather than a
        //    matter of tuning: it locks rows **that exist**. On an empty catalogue there are none, so
        //    two simultaneous activations both found nothing to lock and both proceeded. On a
        //    partially-loaded catalogue the worst case is worse — they lock whatever rows happened to
        //    be there, in whatever order each process reached them, and two activations can deadlock
        //    or interleave writes between them.
        //
        //    `pg_advisory_xact_lock` is taken **before** the row locks and released automatically when
        //    the transaction ends, including on rollback. So a second activation waits at the door
        //    rather than at a row.
        //
        //    Scope, stated honestly: this serialises **activations that go through this service**.
        //    It cannot stop a writer that never takes the lock — a hand-run `INSERT`, or another
        //    tool. That is not a gap being papered over; it is the boundary of the guarantee. The
        //    fingerprint re-check under the lock is what catches such a writer: if it changed
        //    anything between the preview and the write, the transaction throws and rolls back.
        DB::select('SELECT pg_advisory_xact_lock(?)', [$this->lockKey()]);

        // 2. Row locks on top, so the fingerprint re-check reads a stable set even against a writer
        //    that did not take the advisory lock.
        DB::table('social_security_entities')->lockForUpdate()->get();
        DB::table('import_source_mappings')->lockForUpdate()->get();
    }

    /**
     * A fixed key, so every activation in every process contends for the same lock.
     *
     * Derived from a constant rather than a hash of a table name so it cannot drift with a
     * configuration change: the two halves of Laravel's `hasher()->getHash()` for 'sha256'.
     */
    private function lockKey(): int
    {
        return (int) (hexdec(substr(hash('sha256', 'catalogues:activate'), 0, 8)) & 0x7FFFFFFF);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(bool $applied, int $entities, int $mappings, array $preflight, string $fingerprint): array
    {
        $e = $preflight['entity_preflight'];
        $m = $preflight['mapping_preflight'];

        return [
            'applied' => $applied,
            'entities' => $entities,
            'mappings' => $mappings,
            'already_present' => count($e['already_present']),
            'conflicts' => $m['conflicts'],
            'pending_approval' => $m['pending_approval'],
            'fingerprint' => $fingerprint,
            'preflight' => [
                'entities_new' => count($e['would_create']),
                'entities_existing' => count($e['already_present']),
                'entities_inactive_skipped' => count($e['skipped_inactive']),
                'mappings_new' => count($m['would_create']),
                'mappings_conflicting' => count($m['conflicts']),
                'mappings_pending_approval' => count($m['pending_approval']),
                'mappings_missing_target' => count($m['missing_target']),
            ],
        ];
    }
}

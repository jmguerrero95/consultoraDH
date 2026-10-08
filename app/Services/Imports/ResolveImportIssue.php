<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\ApprovedSourceMapping;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;
use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\IssueResolution;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;
use App\Models\ImportSourceMapping;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records one human answer, and makes it mean something.
 *
 * ## What the audit found here
 *
 * `POST /imports/{import}/issues/{issue}/resolve` accepted:
 *
 * ```php
 * 'resolution.decision' => ['required', 'string', 'max:64'],
 * 'resolution.value'    => ['nullable'],
 * ```
 *
 * wrote both to the `resolution` JSON column, set `resolved_at`, dispatched a plan rebuild —
 * and **nothing in the application ever read the column**. `grep` for `resolution` found the
 * migration, the model, the controller and the serializer.
 *
 * So the whole §5.3 mechanism was a rubber stamp: any payload at all would mark a blocker
 * resolved, unblock `apply`, and produce a plan built from completely untransformed data. A
 * reviewer who answered every question correctly got the raw file's reading written while the
 * screen reported the batch as ready.
 *
 * ## What happens now
 *
 * Four things, in this order, all inside one transaction:
 *
 * 1. **Validate against the decision's own schema.** `IssueResolution::make()` refuses an
 *    unknown decision, a decision the issue's code does not accept, a missing value key, an
 *    unexpected value key and an out-of-range value. The whitelist is the backend's; the browser
 *    has one too, and a crafted request bypassing it changes nothing.
 * 2. **Check the answer is applicable to *this* finding.** `set_date` with no suggestion and no
 *    typed value is refused; `treat_as_duplicate_of` must name a row that exists in this import;
 *    `map_entity` must name an entity of the right type.
 * 3. **Apply the durable side effect.** §5.5: an approved `map_entity` becomes a *verified*
 *    `import_source_mappings` row, so the next workbook with the same spelling resolves without
 *    anyone deciding again. `create_entity` becomes an unverified catalogue entry the apply will
 *    write. Nothing else has a durable effect — a per-row date is not a policy.
 * 4. **Persist it, and narrow `blocking` when the answer settles the question.**
 *
 * ## `blocking` narrows, `severity` does not
 *
 * §5.3 lets a reviewer say "this one does not block, I checked", and `IssueResolutionDecision::
 * resolves()` says which answers do that. The `blocking` column is written; `severity` is not,
 * because "this stopped being a blocker" and "this stopped being serious" are different claims.
 */
final class ResolveImportIssue
{
    /**
     * @param  array<string, mixed>  $payload  the request's `resolution` value
     * @return array{issue: LegacyImportIssueModel, resolution: IssueResolution, mapping: ImportSourceMapping|null}
     *
     * @throws InvalidIssueResolution
     */
    public function resolve(
        LegacyImport $import,
        LegacyImportIssueModel $issue,
        array $payload,
        ?int $actorId,
    ): array {
        if ((int) $issue->legacy_import_id !== (int) $import->id) {
            throw InvalidIssueResolution::sourceRowMissing('la incidencia no pertenece a esta importación');
        }

        return DB::transaction(function () use ($import, $issue, $payload, $actorId): array {
            // ## The import row is locked first, and this ordering is the whole fix
            //
            // A04-R1 read `$import->status->isTerminal()` **before** opening the transaction, on
            // a model the controller had loaded at the start of the request. Apply takes the same
            // row with `FOR UPDATE` in `ImportLifecycle::claimApply()`, so the sequence was:
            //
            // ```text
            //   resolve:  read status = ready          (no lock)
            //   apply:    lock row → status = applying
            //   resolve:  BEGIN → lock the *issue* row → write resolved_at
            // ```
            //
            // The answer was recorded against a batch that was mid-apply or already applied. Its
            // `resolved_at` and `resolution` are in the audit trail, the review screen shows the
            // question as answered, and nothing will ever act on it — because the plan that would
            // have honoured it was built before the answer and is being applied right now. The
            // rebuild the resolve endpoint dispatches then finds a terminal import and refuses.
            //
            // Locking the import first, then re-reading its status under that lock, is the
            // check-then-act discipline `ImportLifecycle` already documents: the terminal test and
            // the write are inside one critical section, so they cannot straddle an apply.
            $lockedImport = DB::table('legacy_imports')->where('id', $import->id)->lockForUpdate()->first();

            if ($lockedImport === null) {
                throw InvalidIssueResolution::inapplicable($issue->code, 'la importación ya no existe');
            }

            // A cancelled or applied batch has no plan left to rebuild, so an answer recorded
            // against it would be a claim about something that will never be applied. Read from
            // the locked row, never from the caller's model.
            $status = LegacyImportStatus::from((string) $lockedImport->status);

            if ($status->isTerminal()) {
                throw InvalidIssueResolution::inapplicable(
                    $issue->code,
                    'la importación está en estado «'.$status->label().'»',
                );
            }

            // §5.3: a finding that no longer applies cannot be answered. Checked under the lock
            // alongside everything else, so a resolution cannot land on a question a concurrent
            // rebuild just withdrew.
            //
            // Locked second, so two answers to the same question cannot both be written and one
            // silently lost. §12.2's discipline applied to review rather than to apply.
            $locked = DB::table('legacy_import_issues')->where('id', $issue->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw InvalidIssueResolution::sourceRowMissing('la incidencia ya no existe');
            }

            if ($locked->superseded_at !== null) {
                throw InvalidIssueResolution::inapplicable(
                    $issue->code,
                    'esta pregunta ya no aplica: la reconstrucción cambió',
                );
            }

            $issue->refresh();

            $resolution = IssueResolution::make(
                $issue->code,
                $payload['decision'] ?? null,
                $payload['value'] ?? null,
                is_array($issue->context) ? $issue->context : [],
            );

            $this->assertApplicable($import, $issue, $resolution);

            $mapping = $this->durableEffect($import, $issue, $resolution, $actorId);

            $issue->forceFill([
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => $resolution->toArray(),
                // §5.3's column, and the only thing this writes about blocking.
                'blocking' => $resolution->unblocks() ? false : $issue->blocking,
            ])->save();

            $this->invalidatePlanIdentity($import->id);

            return ['issue' => $issue->refresh(), 'resolution' => $resolution, 'mapping' => $mapping];
        }, 3);
    }

    /**
     * §5.4: an approval belongs to the plan the reviewer was *shown*.
     *
     * ## Why this is written here rather than left to the rebuild
     *
     * The answer is stored, and then `BuildLegacyImportPlan` is dispatched. Between those two
     * facts the import still carries the **old** revision and the **old** digest, and that pair is
     * exactly what `apply` accepts. So a reviewer who had the plan open could press Apply in that
     * window and the request would be accepted against a plan built from decisions that no longer
     * hold — including the answer they had just given. The screen would report a successful
     * application of a plan that silently omitted their own instruction.
     *
     * The row lock taken at the top of this transaction is what makes the withdrawal safe: a
     * concurrent `apply` either held it first and is already applying the plan the reviewer saw,
     * or waits and then finds the identity withdrawn and is refused. There is no interleaving in
     * which an approval outlives the plan it approved.
     *
     * ## Why the revision goes back to zero rather than advancing
     *
     * The obvious encoding is "clear the digest, advance the revision". The schema refuses it:
     * `legacy_imports_plan_identity_check` requires `plan_revision = 0` **iff** the digest and
     * `plan_built_at` are null, with the reason spelled out in the migration — "a revision without
     * a plan is a revision of nothing; a plan without a revision cannot be ordered. Both halves are
     * one fact." That is a better rule than the one I would have written, because it leaves no way
     * to describe a plan that does not exist while implying that some plan once did.
     *
     * So withdrawal is the zero state, and the rebuild that follows produces revision 1 again.
     * Two plans can therefore share a revision number, and that is sound because the *pair* is
     * what identifies a plan: a stale approval is refused either because the digest differs, or —
     * when a rebuild reproduced identical content — because the content it approves really is the
     * content that would be written.
     */
    private function invalidatePlanIdentity(int $importId): void
    {
        DB::table('legacy_imports')
            ->where('id', $importId)
            ->update([
                'plan_digest' => null,
                'plan_revision' => 0,
                'plan_built_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Refuse an answer that is well-formed but cannot be honoured for *this* finding.
     *
     * `IssueResolution` validates shape; this validates applicability. The distinction matters:
     * `set_date` on an `invalid_affiliation_date` is a perfectly valid payload, and it is still
     * the wrong answer if the finding is about a duplicate row rather than a date.
     *
     * @throws InvalidIssueResolution
     */
    /**
     * §7.3's `treat_as_duplicate_of` may only point at a row that can actually stand in for this
     * one.
     *
     * Three things are refused, and each of them produced a silently wrong plan rather than an
     * error:
     *
     * - **a row pointing at itself**, which collapses the observation into nothing;
     * - **a row outside its duplicate group**, which would let a reviewer discard one person's month
     *   by naming an unrelated row, including a row belonging to a different person entirely;
     * - **a chain that loops**, e.g. A says it duplicates B while B already says it duplicates A,
     *   which makes both rows disappear and leaves no observation for that month at all.
     *
     * The group is the row this finding already names as the other side of the conflict, plus that
     * row's own answers, so a chain inside one group stays legal and a jump out of it does not.
     */
    private function assertUsableDuplicateTarget(
        LegacyImport $import,
        LegacyImportIssueModel $issue,
        string $targetKey,
    ): void {
        $own = $issue->row?->source_key;

        if ($own !== null && (string) $own === $targetKey) {
            throw InvalidIssueResolution::invalidDuplicateTarget(
                'una fila no puede señalarse a sí misma como su propio duplicado.',
            );
        }

        $context = is_array($issue->context) ? $issue->context : [];
        $counterpart = isset($context['conflicts_with_row']) ? (string) $context['conflicts_with_row'] : null;

        if ($counterpart !== null && ! $this->withinDuplicateGroup($import, $counterpart, $targetKey)) {
            throw InvalidIssueResolution::invalidDuplicateTarget(
                'sólo puede señalarse la fila con la que ésta entra en conflicto, o una fila a la '
                .'que aquélla ya apunta.',
            );
        }

        // Walk the answers already recorded for this import. If the chain comes back to this row,
        // the whole group would collapse into nothing: both rows stop contributing and that month
        // loses its only observation. A cycle has to be refused at answer time, because by the
        // time the reconstruction runs there is no way to tell a cycle from a very short chain.
        $seen = [];
        $cursor = $targetKey;

        while ($cursor !== null && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;

            if ($own !== null && $cursor === (string) $own) {
                throw InvalidIssueResolution::invalidDuplicateTarget(
                    'la fila '.$targetKey.' ya declara que duplica a ésta, y las dos quedarían sin '
                    .'observación para ese mes.',
                );
            }

            $cursor = $this->recordedDuplicateTargetOf($import, $cursor);
        }
    }

    /**
     * Whether `$targetKey` is inside the §7.3 group identified by a row number.
     *
     * The group's members are the two rows of this conflict plus anything either already points at,
     * so a chain inside one group stays legal while a jump to an unrelated row does not. Comparing
     * row *numbers* is what ties the walk to this finding: `conflicts_with_row` is the counterpart's
     * source row number, which is the only identifier the parser could name at parse time.
     */
    private function withinDuplicateGroup(LegacyImport $import, string $counterpartRowNumber, string $targetKey): bool
    {
        $target = LegacyImportRow::query()
            ->where('legacy_import_id', $import->id)
            ->where('source_key', $targetKey)
            ->first();

        if ($target === null) {
            return false;
        }

        $rowNumber = (string) $target->source_row_number;

        if ($rowNumber === $counterpartRowNumber) {
            return true;
        }

        // Otherwise: is the target one this conflict's counterpart already points at?
        $cursor = $this->sourceKeyForRowNumber($import, $counterpartRowNumber);
        $seen = [];

        while ($cursor !== null && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;

            if ($cursor === $targetKey) {
                return true;
            }

            $cursor = $this->recordedDuplicateTargetOf($import, $cursor);
        }

        return false;
    }

    private function sourceKeyForRowNumber(LegacyImport $import, string $rowNumber): ?string
    {
        $row = LegacyImportRow::query()
            ->where('legacy_import_id', $import->id)
            ->where('source_row_number', $rowNumber)
            ->first();

        return $row === null ? null : (string) $row->source_key;
    }

    /**
     * The row that a given row's already-recorded `duplicate_conflicting_row` answer names.
     *
     * Read from stored answers rather than from the decision set, so the walk sees the answers that
     * existed before this request — which is the only way a loop can be caught at all.
     */
    private function recordedDuplicateTargetOf(LegacyImport $import, string $sourceKey): ?string
    {
        $issue = LegacyImportIssueModel::query()
            ->where('legacy_import_id', $import->id)
            ->where('code', LegacyImportIssue::DuplicateConflictingRow->value)
            ->whereNull('superseded_at')
            ->whereNotNull('resolved_at')
            ->whereHas('row', fn ($query) => $query->where('source_key', $sourceKey))
            ->first();

        if ($issue === null || ! is_array($issue->resolution)) {
            return null;
        }

        if (($issue->resolution['decision'] ?? null) !== IssueResolutionDecision::TreatAsDuplicateOf->value) {
            return null;
        }

        $target = $issue->resolution['value']['source_key'] ?? null;

        return is_string($target) ? $target : null;
    }

    private function assertApplicable(
        LegacyImport $import,
        LegacyImportIssueModel $issue,
        IssueResolution $resolution,
    ): void {
        $decision = $resolution->decision;
        $context = is_array($issue->context) ? $issue->context : [];

        if ($decision === IssueResolutionDecision::UseSuggestedDate) {
            $sourceKey = (string) ($context['source_key'] ?? '');
            $row = $sourceKey === '' ? null : LegacyImportRow::query()
                ->where('legacy_import_id', $import->id)
                ->where('source_key', $sourceKey)
                ->first();

            if ($row?->affiliation_date_suggestion === null) {
                throw InvalidIssueResolution::inapplicable(
                    $issue->code,
                    '§8.1 no dejó ninguna fecha sugerida para esa celda, así que hay que escribirla',
                );
            }

            return;
        }

        if ($decision === IssueResolutionDecision::TreatAsDuplicateOf) {
            $sourceKey = (string) $resolution->value['source_key'];

            $target = LegacyImportRow::query()
                ->where('legacy_import_id', $import->id)
                ->where('source_key', $sourceKey)
                ->first();

            if ($target === null) {
                throw InvalidIssueResolution::sourceRowMissing($sourceKey);
            }

            $this->assertUsableDuplicateTarget($import, $issue, $sourceKey);

            return;
        }

        if ($decision === IssueResolutionDecision::MapEntity) {
            // No `with('type')`: `type` is an enum-cast *column* on this model, not a relation,
            // and eager-loading a non-existent one is a `RelationNotFoundException` — a 500 on a
            // perfectly valid answer.
            $entity = SocialSecurityEntity::query()
                ->find((int) $resolution->value['social_security_entity_id']);

            if ($entity === null) {
                throw InvalidIssueResolution::targetMissing('la entidad', (string) $resolution->value['social_security_entity_id']);
            }

            $expected = $this->expectedEntityType($issue, $context);

            if ($expected !== null && $entity->type !== $expected) {
                throw InvalidIssueResolution::inapplicable(
                    $issue->code,
                    sprintf(
                        'la entidad %d es de tipo %s y la celda es de tipo %s',
                        $entity->id,
                        $entity->type->value,
                        $expected->value,
                    ),
                );
            }

            return;
        }

        if ($decision === IssueResolutionDecision::LinkExistingClient) {
            $exists = DB::table('clients')->where('id', (int) $resolution->value['client_id'])->exists();

            if (! $exists) {
                throw InvalidIssueResolution::targetMissing('el cliente', (string) $resolution->value['client_id']);
            }

            return;
        }

        if ($decision === IssueResolutionDecision::LinkExistingCompany) {
            $exists = DB::table('companies')->where('id', (int) $resolution->value['company_id'])->exists();

            if (! $exists) {
                throw InvalidIssueResolution::targetMissing('la empresa', (string) $resolution->value['company_id']);
            }
        }
    }

    /**
     * §5.5's durable effect: an approved mapping, verified, so nobody decides it twice.
     *
     * ## Why only `map_entity`
     *
     * The table is `import_source_mappings`, and §5.5's last line is "Sólo guardar mappings
     * aprobados explícitamente." So the *only* thing written here is an approval, with
     * `verified_at` and `verified_by` both set — the migration's own CHECK requires them
     * together, so a mapping cannot end up looking verified with nobody behind it.
     *
     * The audit's finding was the opposite problem: "`grep -rn "ImportSourceMapping::" app/` →
     * only the model; there is **no writer**." §9.2's "guardar un mapping sólo después de
     * aprobación" had no implementation at all, so approving a token resolved it for that one
     * import and never again.
     *
     * `create_entity` does **not** write here: §9.3 says the catalogue entry itself is proposed,
     * and the plan writes it as a `create_social_entity` action under the apply. Writing an
     * unverified row with a fabricated entity id would be §9.3's prohibited invention, and the
     * column is `NOT NULL` with a restrictive foreign key, so it cannot honestly be done.
     *
     * @throws InvalidIssueResolution
     */
    private function durableEffect(
        LegacyImport $import,
        LegacyImportIssueModel $issue,
        IssueResolution $resolution,
        ?int $actorId,
    ): ?ImportSourceMapping {
        // `create_entity` counts here **only** when the chosen entity already exists.
        //
        // §9.3 proposes the catalogue entry and the plan writes it as a `create_social_entity`
        // action, so at review time a *new* entity has no id and nothing honest can be recorded:
        // the column is `NOT NULL` with a restrictive foreign key, and a fabricated id is exactly
        // the invention §9.3 forbids. Those mappings are written instead by
        // `ApplyImportPlan::writeSocialEntity()`, inside the apply transaction, once the real id
        // exists — which is the only moment there is one.
        //
        // When the reviewer pointed at an entity the catalogue already has, the id is real now, and
        // the approved spelling becomes reusable immediately. `ApprovedSourceMapping::record()`
        // decides what "may be recorded" means, so this path and the apply path cannot disagree.
        if (! in_array($resolution->decision, [
            IssueResolutionDecision::MapEntity,
            IssueResolutionDecision::CreateEntity,
        ], true)) {
            return null;
        }

        $context = is_array($issue->context) ? $issue->context : [];
        $token = self::tokenOf($context);
        $type = $this->expectedEntityType($issue, $context);

        if ($token === null || $type === null) {
            throw InvalidIssueResolution::inapplicable(
                $issue->code,
                'la incidencia no registra qué token hay que asociar',
            );
        }

        $entityId = match ($resolution->decision) {
            IssueResolutionDecision::MapEntity => (int) $resolution->value['social_security_entity_id'],

            // `create_entity` names the entity rather than an id; the id has to be looked up in the
            // catalogue the reviewer chose it from.
            default => (int) SocialSecurityEntity::query()
                ->where('type', $type->value)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $resolution->value['name']))])
                ->value('id'),
        };

        if ($entityId <= 0) {
            // Not in the catalogue yet: the Apply will create it and record the mapping then.
            return null;
        }

        // A bare affirmative gets no global mapping, from either decision. The per-import answer
        // is already stored on the issue and still applies to this import.
        if (SourceEntityToken::isBareAffirmativeEntityToken($token)) {
            return null;
        }

        $existing = ImportSourceMapping::query()
            ->where('profile', $import->profile instanceof ImportProfile ? $import->profile->value : $import->profile)
            ->where('type', $type->value)
            ->where('source_key', SheetMonth::fold($token))
            ->first();

        // A verified mapping against a *different* entity is somebody else's decision about every
        // future import. §5.5 puts that outside an import, so it is refused rather than rewritten —
        // and refused loudly, because the reviewer is being told their answer will not generalise.
        if ($existing !== null
            && $existing->verified_at !== null
            && (int) $existing->social_security_entity_id !== $entityId) {
            throw InvalidIssueResolution::inapplicable(
                $issue->code,
                sprintf(
                    '«%s» ya está asociado a otra entidad del catálogo. Cambiar esa decisión afecta '
                    .'a todas las importaciones futuras, y eso se decide fuera de una importación.',
                    $token,
                ),
            );
        }

        return ApprovedSourceMapping::record(
            $import->profile instanceof ImportProfile ? $import->profile : ImportProfile::from($import->profile),
            $type,
            $token,
            $entityId,
            $actorId === null ? null : User::find($actorId),
        );
    }

    /**
     * The folded token a finding names, or null when it records none.
     *
     * §5.3's `context` is sanitised on the way in, so the token is read from the same two keys the
     * parser writes rather than from a column that may not exist.
     */
    private static function tokenOf(array $context): ?string
    {
        foreach (['token', 'entity_token'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
                return SheetMonth::fold($context[$key]);
            }
        }

        return null;
    }

    /**
     * Which entity type a finding is about, from its context or its field.
     *
     * §9.1's four columns, and the `field` column is what §5.3 provides for exactly this: it
     * names the column the reviewer has to look at.
     */
    private function expectedEntityType(LegacyImportIssueModel $issue, array $context): ?SocialSecurityEntityType
    {
        foreach ([$context['entity_type'] ?? null, $context['type'] ?? null, $issue->field] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $type = SocialSecurityEntityType::tryFrom(strtoupper($candidate));

            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }
}

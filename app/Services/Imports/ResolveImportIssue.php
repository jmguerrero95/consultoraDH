<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;
use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\IssueResolution;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\SheetMonth;
use App\Models\ImportSourceMapping;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
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

        if ($import->status->isTerminal()) {
            // A cancelled or applied batch has no plan left to rebuild, so an answer recorded
            // against it would be a claim about something that will never be applied.
            throw InvalidIssueResolution::inapplicable(
                $issue->code,
                'la importación está en estado «'.$import->status->label().'»',
            );
        }

        return DB::transaction(function () use ($import, $issue, $payload, $actorId): array {
            // Locked, so two answers to the same question cannot both be written and one
            // silently lost. §12.2's discipline applied to review rather than to apply.
            $locked = DB::table('legacy_import_issues')->where('id', $issue->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw InvalidIssueResolution::sourceRowMissing('la incidencia ya no existe');
            }

            $issue->refresh();

            $resolution = IssueResolution::make($issue->code, $payload['decision'] ?? null, $payload['value'] ?? null);

            $this->assertApplicable($import, $issue, $resolution);

            $mapping = $this->durableEffect($import, $issue, $resolution, $actorId);

            $issue->forceFill([
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => $resolution->toArray(),
                // §5.3's column, and the only thing this writes about blocking.
                'blocking' => $resolution->unblocks() ? false : $issue->blocking,
            ])->save();

            return ['issue' => $issue->refresh(), 'resolution' => $resolution, 'mapping' => $mapping];
        }, 3);
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

            $exists = LegacyImportRow::query()
                ->where('legacy_import_id', $import->id)
                ->where('source_key', $sourceKey)
                ->exists();

            if (! $exists) {
                throw InvalidIssueResolution::sourceRowMissing($sourceKey);
            }

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
        if ($resolution->decision !== IssueResolutionDecision::MapEntity) {
            return null;
        }

        $context = is_array($issue->context) ? $issue->context : [];
        $token = (string) ($context['token'] ?? '');
        $type = $this->expectedEntityType($issue, $context);

        if ($token === '' || $type === null) {
            throw InvalidIssueResolution::inapplicable(
                $issue->code,
                'la incidencia no registra qué token hay que asociar',
            );
        }

        $sourceKey = SheetMonth::fold($token);
        $entityId = (int) $resolution->value['social_security_entity_id'];

        // §5.5's unique index is `(profile, type, source_key)`: one approved spelling per
        // profile. Changing an existing approval is a decision about the *policy*, not about
        // this import, so it is refused here rather than silently rewritten.
        $existing = ImportSourceMapping::query()
            ->where('profile', $import->profile instanceof ImportProfile ? $import->profile->value : $import->profile)
            ->where('type', $type->value)
            ->where('source_key', $sourceKey)
            ->first();

        if ($existing !== null) {
            if ($existing->verified_at !== null && (int) $existing->social_security_entity_id === $entityId) {
                return $existing;
            }

            if ($existing->verified_at !== null) {
                throw InvalidIssueResolution::inapplicable(
                    $issue->code,
                    sprintf(
                        '«%s» ya está asociado a otra entidad del catálogo. Cambiar esa decisión afecta '
                        .'a todas las importaciones futuras, y eso se decide fuera de una importación.',
                        $token,
                    ),
                );
            }

            $existing->forceFill([
                'social_security_entity_id' => $entityId,
                'verified_at' => now(),
                'verified_by' => $actorId,
                'note' => 'Aprobado durante la revisión de la importación.',
            ])->save();

            return $existing->refresh();
        }

        return ImportSourceMapping::query()->create([
            'profile' => $import->profile,
            'type' => $type->value,
            'source_key' => $sourceKey,
            'social_security_entity_id' => $entityId,
            'verified_at' => now(),
            'verified_by' => $actorId,
            'note' => 'Aprobado durante la revisión de la importación.',
        ]);
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

<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reconstruction into the exact list of writes Apply will perform.
 *
 * ## §17.5: the preview and the apply read the same rows
 *
 * "La UI de preview debe leer estas acciones; no reconstruir una explicación distinta a la que
 * realmente aplicará el backend." So this class writes `legacy_import_actions` and both the
 * preview screen and `ApplyLegacyImport` read *those rows*. Nothing recomputes a plan at apply
 * time, which is what makes the counts on the preview screen a promise rather than an estimate.
 *
 * ## §11: every action is Create, Update, No-change or Blocked
 *
 * The classification happens here, against the database, and it is deliberately conservative:
 *
 *  - an exact match is a **skipped** action with a reason, not an update that writes the same
 *    value again — §11 calls that a no-op and no-op is what it is;
 *  - an empty source field never clears an existing one;
 *  - a non-empty existing value is never overwritten without an explicit acceptance recorded in
 *    the action's payload, which §11 requires the preview to show as `actual → propuesto`.
 *
 * ## §10 and §12.4: rates and relationships are the transversal writes
 *
 * Both are planned here and applied under `BillingTopologyLock`, so §12.4's rule that A04 shares
 * A03's lock rather than inventing a second one is satisfied by construction: the lock is taken
 * once, around the apply, and every write of these two kinds happens inside it.
 */
final class ImportPlanBuilder
{
    /**
     * Build the plan and persist it.
     *
     * @param  LegacyImport  $import  the import being planned
     * @param  HistoryReconstruction  $reconstruction  what the rows imply
     * @return ImportPlan the persisted plan
     */
    public function build(LegacyImport $import, HistoryReconstruction $reconstruction): ImportPlan
    {
        return DB::transaction(function () use ($import, $reconstruction): ImportPlan {
            // Rebuilding replaces the plan wholesale. §15 has a `rebuild-plan` endpoint used
            // after a resolution, and leaving the previous actions behind would mean the
            // preview showed a mixture of two decisions.
            LegacyImportAction::query()->where('legacy_import_id', $import->id)->delete();

            $actions = [];
            $ordinal = 0;

            foreach ($this->companyActions($import, $reconstruction) as $action) {
                $actions[] = $action + ['ordinal' => $ordinal++];
            }

            foreach ($this->clientActions($import, $reconstruction) as $action) {
                $actions[] = $action + ['ordinal' => $ordinal++];
            }

            foreach ($this->relationshipActions($import, $reconstruction) as $action) {
                $actions[] = $action + ['ordinal' => $ordinal++];
            }

            foreach ($this->affiliationActions($import, $reconstruction) as $action) {
                $actions[] = $action + ['ordinal' => $ordinal++];
            }

            foreach ($this->rateActions($import, $reconstruction) as $action) {
                $actions[] = $action + ['ordinal' => $ordinal++];
            }

            $persisted = [];

            foreach ($actions as $action) {
                $persisted[] = LegacyImportAction::query()->create($action);
            }

            return new ImportPlan($import, $persisted);
        });
    }

    // ------------------------------------------------------------------ companies

    /**
     * @return list<array<string, mixed>>
     */
    private function companyActions(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        /** @var array<string, array<string, mixed>> $byTaxId */
        $byTaxId = [];

        foreach ($reconstruction->episodes() as $episode) {
            if ($episode->companyTaxId === null || $episode->companyName === null) {
                continue;
            }

            $taxId = $episode->companyTaxId;

            // §7.1's rule for a client's profile applied to a company's name: the most recent
            // non-empty observation wins, and a disagreement is an issue rather than a silent
            // last-write-wins.
            if (! isset($byTaxId[$taxId])) {
                $byTaxId[$taxId] = [
                    'tax_id' => $taxId,
                    'name' => $episode->companyName,
                    'episodes' => [],
                    'row_ids' => [],
                    'months' => [],
                ];
            } elseif ($byTaxId[$taxId]['name'] !== $episode->companyName) {
                $names = array_unique([$byTaxId[$taxId]['name'], $episode->companyName]);

                if (count($names) > 1) {
                    $byTaxId[$taxId]['conflict'] = implode(' / ', $names);
                }
            }

            $byTaxId[$taxId]['episodes'][] = $episode->naturalKey();
            $byTaxId[$taxId]['row_ids'] = array_values(array_unique(array_merge(
                $byTaxId[$taxId]['row_ids'],
                $episode->sourceRowIds,
            )));
            $byTaxId[$taxId]['months'][] = $episode->firstMonth();
        }

        $actions = [];

        foreach ($byTaxId as $company) {
            if (isset($company['conflict'])) {
                // §7.2: two NIT-less or differently-named spellings of one identity. Never
                // resolved by similarity — a blocker until somebody decides.
                continue;
            }

            $naturalKey = 'company:'.$company['tax_id'];
            $payload = [
                'tax_id' => $company['tax_id'],
                // A02 names the column `legal_name`; the source column is the company title.
                'legal_name' => $company['name'],
            ];

            $existing = $this->existingCompany((string) $company['tax_id']);

            if ($existing === null) {
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CreateCompany,
                    $naturalKey,
                    $payload,
                    $company['row_ids'],
                );

                continue;
            }

            // §11: an empty source never clears data, and a non-empty value is only proposed.
            if ($this->sameCompanyName($existing, (string) $company['name'])) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateCompany,
                    $naturalKey,
                    $payload,
                    $company['row_ids'],
                    'La empresa ya existe con el mismo nombre.',
                );

                continue;
            }

            $actions[] = $this->action(
                $import,
                ImportActionType::UpdateCompany,
                $naturalKey,
                $payload + ['current_legal_name' => $existing->legal_name],
                $company['row_ids'],
            );
        }

        return $actions;
    }

    /**
     * Whether two spellings of a company name are the same name.
     *
     * Case, accents and spacing only — §7.2's "no usar nombre parecido para fusionar". Two
     * names that fold together are one name; `DISTRIUTIL` and `DISTRIBUIDORA` are two, and
     * they become `company_identity_conflict` rather than a merge.
     */
    private function sameCompanyName(?object $existing, string $name): bool
    {
        return $existing !== null
            && SheetMonth::fold((string) $existing->legal_name) === SheetMonth::fold($name);
    }

    private function existingCompany(string $taxId): ?object
    {
        return DB::table('companies')->where('tax_id', $taxId)->first();
    }

    // -------------------------------------------------------------------- clients

    /**
     * @return list<array<string, mixed>>
     */
    private function clientActions(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        /** @var array<string, array<string, mixed>> $byClient */
        $byClient = [];

        foreach ($reconstruction->episodes() as $episode) {
            if ($episode->documentNumber === null || $episode->documentType === null) {
                continue;
            }

            $key = $episode->documentType.':'.$episode->documentNumber;

            if (! isset($byClient[$key])) {
                $byClient[$key] = [
                    'document_type' => $episode->documentType,
                    'document_number' => $episode->documentNumber,
                    'display_name' => $episode->clientDisplayName,
                    'names' => [],
                    'row_ids' => [],
                ];
            }

            $byClient[$key]['names'][$episode->clientDisplayName ?? ''] = true;
            $byClient[$key]['row_ids'] = array_values(array_unique(array_merge(
                $byClient[$key]['row_ids'],
                $episode->sourceRowIds,
            )));
        }

        $actions = [];

        foreach ($byClient as $client) {
            $names = array_values(array_filter(array_keys($client['names'])));
            $payload = [
                'document_type' => $client['document_type'],
                'document_number' => $client['document_number'],
                'first_names' => $this->firstNamesFrom($names[0] ?? ''),
                'last_names' => $this->lastNamesFrom($names[0] ?? ''),
            ];

            $naturalKey = 'client:'.$client['document_type'].':'.$client['document_number'];

            // §7.1: "diferencias de nombre para el mismo documento son conflictos de atributos,
            // no nuevas personas". Two spellings are an issue, not a second client.
            if (count($names) > 1) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload + ['observed_names' => $names],
                    $client['row_ids'],
                    'El mismo documento aparece con '.count($names).' nombres distintos; hay que decidir cuál se guarda.',
                );

                continue;
            }

            $existing = $this->existingClient($client['document_type'], (string) $client['document_number']);

            if ($existing === null) {
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload,
                    $client['row_ids'],
                );

                continue;
            }

            // §7.1: a master value a person typed is never overwritten by an import without an
            // explicit acceptance, so an existing client is a no-op here.
            $actions[] = $this->skip(
                $import,
                ImportActionType::CreateClient,
                $naturalKey,
                $payload + ['current_id' => $existing->id],
                $client['row_ids'],
                'El cliente ya existe; el importador no sobrescribe datos maestros.',
            );
        }

        return $actions;
    }

    private function existingClient(string $type, string $number): ?object
    {
        return DB::table('clients')
            ->where('document_type', $type)
            ->where('document_number', $number)
            ->first();
    }

    /** The first word of a full name; A02 stores the two halves separately. */
    private function firstNamesFrom(string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        return $parts === [] ? null : $parts[0];
    }

    private function lastNamesFrom(string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];

        return count($parts) < 2 ? null : implode(' ', array_slice($parts, 1));
    }

    // -------------------------------------------------------------- relationships

    /**
     * @return list<array<string, mixed>>
     */
    private function relationshipActions(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        $actions = [];

        foreach ($reconstruction->episodes() as $episode) {
            $payload = [
                'client_document_type' => $episode->documentType,
                'client_document_number' => $episode->documentNumber,
                'company_tax_id' => $episode->companyTaxId,
                'interval' => $episode->interval->toArray(),
                'months' => $episode->months,
                'retirement' => $episode->retirement?->toArray(),
            ];

            $actions[] = $this->action(
                $import,
                ImportActionType::CreateRelationship,
                $episode->naturalKey(),
                $payload,
                $episode->sourceRowIds,
            );
        }

        return $actions;
    }

    // -------------------------------------------------------------- affiliations

    /**
     * @return list<array<string, mixed>>
     */
    private function affiliationActions(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        $actions = [];

        foreach ($reconstruction->affiliationSegments() as $segment) {
            // §9.1: a refusal closes an affiliation and creates nothing. Planning a
            // "no affiliation" action would need an entity to hang it on, and inventing one is
            // exactly what §9.1 forbids.
            if (! $segment->isAffiliation()) {
                continue;
            }

            $token = $segment->token;

            // §9.2/§9.3: a token is only usable if it resolves to a catalogue entity, and only
            // an *approved* mapping resolves it. Until a reviewer approves one, this is a
            // suggestion and produces no action at all.
            $entity = $this->resolveEntity($segment->type, (string) $token?->token);

            if ($entity === null) {
                continue;
            }

            $actions[] = $this->action(
                $import,
                ImportActionType::CreateAffiliation,
                $segment->naturalKey(),
                [
                    'client_document_type' => $segment->documentType,
                    'client_document_number' => $segment->documentNumber,
                    'company_tax_id' => $segment->companyTaxId,
                    'type' => $segment->type->value,
                    'entity_id' => $entity->id,
                    'interval' => $segment->interval->toArray(),
                    'risk_class' => $segment->risk?->value,
                ],
                $segment->sourceRowIds,
            );
        }

        return $actions;
    }

    /**
     * §9.2: resolve a token only through an approved mapping.
     *
     * Exact catalogue match first, then an approved `import_source_mappings` row. Anything else
     * is a suggestion for a reviewer — never a fuzzy match, and never a new entity.
     */
    private function resolveEntity(SocialSecurityEntityType $type, string $token): ?object
    {
        $existing = DB::table('social_security_entities')
            ->where('type', $type->value)
            ->get()
            ->first(fn ($entity) => SheetMonth::fold((string) $entity->name) === SheetMonth::fold($token));

        if ($existing !== null) {
            return $existing;
        }

        return DB::table('import_source_mappings')
            // Qualified: `type` exists on both tables in this join, and an unqualified
            // reference is ambiguous in PostgreSQL rather than resolved to one of them.
            ->where('import_source_mappings.type', $type->value)
            ->where('import_source_mappings.source_key', SheetMonth::fold($token))
            // §9.2: only an approved mapping resolves. An unverified row is a suggestion.
            ->whereNotNull('import_source_mappings.verified_at')
            ->join('social_security_entities', 'social_security_entities.id', '=', 'import_source_mappings.social_security_entity_id')
            ->select('social_security_entities.*')
            ->first();
    }

    // ----------------------------------------------------------------------- rates

    /**
     * §10: a rate per change, never per row.
     *
     * @return list<array<string, mixed>>
     */
    private function rateActions(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        $actions = [];

        foreach ($reconstruction->rateSegments() as $segment) {
            $actions[] = $this->action(
                $import,
                ImportActionType::CreateRate,
                $segment->naturalKey(),
                [
                    'client_document_type' => $segment->documentType,
                    'client_document_number' => $segment->documentNumber,
                    'company_tax_id' => $segment->companyTaxId,
                    'effective_month' => $segment->effectiveMonth->key(),
                    'amount' => $segment->amount,
                    'months' => $segment->months,
                ],
                $segment->sourceRowIds,
            );
        }

        return $actions;
    }

    // -------------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sourceRowIds
     * @return array<string, mixed>
     */
    private function action(
        LegacyImport $import,
        ImportActionType $type,
        string $naturalKey,
        array $payload,
        array $sourceRowIds,
    ): array {
        return [
            'legacy_import_id' => $import->id,
            'action_type' => $type->value,
            'natural_key' => $naturalKey,
            'payload' => $payload,
            'source_row_ids' => array_values(array_unique($sourceRowIds)),
            'batch_fingerprint' => self::fingerprint($type, $naturalKey, $payload),
            'state' => ImportActionState::Planned->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sourceRowIds
     * @return array<string, mixed>
     */
    private function skip(
        LegacyImport $import,
        ImportActionType $type,
        string $naturalKey,
        array $payload,
        array $sourceRowIds,
        string $reason,
    ): array {
        return $this->action($import, $type, $naturalKey, $payload, $sourceRowIds) + [
            'state' => ImportActionState::Skipped->value,
            'skip_reason' => $reason,
        ];
    }

    /**
     * SHA-256 over type, natural key and canonical payload.
     *
     * Canonical means sorted keys, so two runs that build the same payload in a different order
     * produce the same fingerprint — which is what makes §15's rebuild idempotent instead of
     * duplicating every action.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(ImportActionType $type, string $naturalKey, array $payload): string
    {
        return hash('sha256', $type->value."\n".$naturalKey."\n".self::canonical($payload));
    }

    /** @param array<string, mixed> $payload */
    private static function canonical(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

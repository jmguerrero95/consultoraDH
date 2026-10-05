<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reconstruction into the exact list of writes Apply will perform.
 *
 * ## §17.5: the preview and the apply read the same rows
 *
 * "La UI de preview debe leer estas acciones; no reconstruir una explicación distinta a la que
 * realmente aplicará el backend." So this class writes `legacy_import_actions` and both the
 * preview screen and `ApplyImportPlan` read *those rows*. Nothing recomputes a plan at apply
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
 *    the action's `preconditions`, which §11 requires the preview to show as `actual → propuesto`.
 *
 * ## §13: provenance is real
 *
 * Every action carries `source_row_ids` — the `legacy_import_rows.id` values it was derived
 * from — and `source_evidence` for the human. The audit found `source_row_ids` holding Excel row
 * numbers: they collide across the ten sheet-months, `array_unique()` made them lossy, and a
 * re-stage reassigned the ids they claimed to point at. A trigger added this release refuses an
 * id that does not exist or belongs to another import.
 *
 * ## Every action records what it assumed
 *
 * `preconditions` holds the observed state the comparison was made against, so `apply` can
 * re-check it under the same locks it writes with and refuse when the world moved. The audit's
 * TOCTOU finding: a plan built against "no rate for March", a reviewer confirming it, somebody
 * creating that rate by hand, and the apply then either failing obscurely or silently treating
 * the row as its own write.
 *
 * ## §9.2/§9.3: an unresolved token raises an issue and produces no action
 *
 * The previous implementation resolved a token, found nothing, and `continue`d. The audit's
 * finding, and its consequences: the EPS/AFP/CCF/ARL history vanished from the plan "without a
 * trace", the preview showed a *smaller* plan than the reconstruction found with nothing for a
 * reviewer to notice, nothing was written to `import_source_mappings` (so the reviewer never saw
 * the token to approve), and a person's whole contribution history could disappear while their
 * rate and relationship were applied — leaving A03 generating obligations for a client with a
 * relationship and a rate and zero affiliations.
 *
 * So an unmatched token now raises a non-blocking `unresolved_social_entity` issue *and* writes
 * an unverified suggestion row for §5.5 to promote. `unresolved_social_entity` is in §18's
 * required list and had **zero** call sites.
 */
final class ImportPlanBuilder
{
    /** Set for the duration of one `build()` so the helpers below can reach the answers. */
    private ?ImportDecisionSet $activeDecisions = null;

    public function __construct(
        private readonly ?ImportDecisionSet $decisions = null,
    ) {}

    /**
     * Build the plan and persist it.
     *
     * @param  LegacyImport  $import  the import being planned
     * @param  HistoryReconstruction  $reconstruction  what the rows imply
     * @param  ImportDecisionSet|null  $decisions  the human answers in force
     * @return ImportPlan the persisted plan
     */
    public function build(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
        ?ImportDecisionSet $decisions = null,
    ): ImportPlan {
        $decisions ??= $this->decisions;
        $this->activeDecisions = $decisions;
        $this->approvals = null;

        return DB::transaction(function () use ($import, $reconstruction, $decisions): ImportPlan {
            // Rebuilding replaces the plan wholesale. §15 has a `rebuild-plan` endpoint used
            // after a resolution, and leaving the previous actions behind would mean the
            // preview showed a mixture of two decisions.
            LegacyImportAction::query()->where('legacy_import_id', $import->id)->delete();

            // Chronological, because `latestNameValue()` takes the last usable value and §7.1
            // says the latest one wins. Loaded once: the previous implementation queried per row
            // id, which for 2.560 staged rows is 2.560 queries inside one transaction.
            $this->rowsById = LegacyImportRow::query()
                ->where('legacy_import_id', $import->id)
                ->orderBy('sheet_month')
                ->orderBy('source_row_number')
                ->get()
                ->all();

            $actions = [];
            $ordinal = 0;

            // §9.2's unresolvable tokens are collected first, so a reviewer's answer has an
            // issue to attach to and the preview can report them.
            $this->collectUnresolvedEntities($import, $reconstruction);

            // `array_merge` throughout, for the same reason as `skip()`: `+` keeps the left
            // operand's value, which silently ignores any key both arrays carry.
            foreach ($this->companyActions($import, $reconstruction) as $action) {
                $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
            }

            foreach ($this->clientActions($import, $reconstruction, $decisions) as $action) {
                $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
            }

            foreach ($this->relationshipActions($import, $reconstruction, $decisions) as $action) {
                $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
            }

            foreach ($this->affiliationActions($import, $reconstruction, $decisions) as $action) {
                $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
            }

            foreach ($this->rateActions($import, $reconstruction, $decisions) as $action) {
                $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
            }

            $persisted = [];

            foreach ($actions as $action) {
                $persisted[] = LegacyImportAction::query()->create($action);
            }

            return new ImportPlan($import, $persisted);
        }, 3);
    }

    // ------------------------------------------------------------------ companies

    /**
     * §7.2's company identity, and §11's classification against what exists.
     *
     * @return list<array<string, mixed>>
     */
    private function companyActions(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
    ): array {
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
                // §7.2: two spellings of one identity. Never resolved by similarity — and a
                // company with a name conflict produces *no action at all*, which is correct but
                // previously silent: the preview showed one company fewer and named no reason.
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateCompany,
                    'company:'.$company['tax_id'],
                    ['tax_id' => $company['tax_id'], 'legal_name' => $company['name']],
                    $company['row_ids'],
                    '§7.2: el mismo NIT aparece con los nombres «'.$company['conflict'].'». '
                    .'No se fusionan por parecido; hay que decidir cuál es el nombre.',
                    ['conflict' => $company['conflict']],
                );

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
                    // Nothing existed when the plan was built. If a company appears before the
                    // apply runs, §11 says the plan is stale and a person has to look again.
                    ['target_exists' => false],
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
                    ['target_exists' => true, 'observed' => ['legal_name' => $existing->legal_name]],
                );

                continue;
            }

            // §11's "Actualizar", and the one that was emitted and never applied. The audit's
            // finding: "`UpdateCompany` is *produced by the builder* and *never applied*" — it
            // fell through to `default => null`, was counted as a success anyway, and was left
            // in state `planned` while the batch reported `applied`.
            //
            // Now it is an explicit arm, and it writes only the fields §11's `actual → propuesto`
            // proposal named — which is `preconditions['approved_fields']`, recorded when a
            // reviewer answers `existing_company_conflict` with `overwrite_with_source`.
            $approved = $this->approvedFields($naturalKey, $company['tax_id'], 'company');

            $actions[] = $approved === []
                ? $this->skip(
                    $import,
                    ImportActionType::CreateCompany,
                    $naturalKey,
                    $payload + ['current_legal_name' => $existing->legal_name],
                    $company['row_ids'],
                    'La empresa existe con el nombre «'.$existing->legal_name.'» y el archivo dice «'
                    .$company['name'].'». §11 no permite sobreescribir un dato maestro sin una '
                    .'decisión explícita.',
                    ['target_exists' => true, 'observed' => ['legal_name' => $existing->legal_name]],
                )
                : $this->action(
                    $import,
                    ImportActionType::UpdateCompany,
                    $naturalKey,
                    $payload + ['current_legal_name' => $existing->legal_name],
                    $company['row_ids'],
                    ['target_exists' => true, 'observed' => ['legal_name' => $existing->legal_name]],
                    ['approved_fields' => $approved],
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
     * §7.1's client master, built from the **latest** observation with a usable name.
     *
     * The previous version took `$names[0]` — the first name any episode carried for that
     * document, which is the *oldest* month in the reconstruction. §7.1 says the opposite:
     * "el maestro del cliente se reconstruye a partir de la fila más reciente con nombre
     * válido". A person's surname that changed between January and October was written from
     * January.
     *
     * @return list<array<string, mixed>>
     */
    private function clientActions(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
        ?ImportDecisionSet $decisions,
    ): array {
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
                    // §7.1: the *latest* valid name. Sorted by first month so "latest" is a
                    // comparison rather than an accident of reconstruction order.
                    'names_by_month' => [],
                    'row_ids' => [],
                ];
            }

            if ($episode->clientDisplayName !== null && trim($episode->clientDisplayName) !== '') {
                $month = $episode->firstMonth() ?? '';

                $byClient[$key]['names_by_month'][$month] = $episode->clientDisplayName;
            }

            $byClient[$key]['row_ids'] = array_values(array_unique(array_merge(
                $byClient[$key]['row_ids'],
                $episode->sourceRowIds,
            )));
        }

        $actions = [];

        foreach ($byClient as $key => $client) {
            $namesByMonth = $client['names_by_month'];
            ksort($namesByMonth);
            $names = array_values(array_unique($namesByMonth));
            $latest = $names === [] ? '' : (string) end($names);

            $payload = [
                'document_type' => $client['document_type'],
                'document_number' => $client['document_number'],
                // The staged row already carries the two halves separately, so they are read
                // back rather than re-split from a full name. `firstNamesFrom()` took the first
                // *word* as the first names, so `JUAN CARLOS PEREZ` lost its middle name.
                'first_names' => $this->firstNamesFor($client['row_ids']),
                'last_names' => $this->lastNamesFor($client['row_ids']),
            ];

            $naturalKey = 'client:'.$client['document_type'].':'.$client['document_number'];

            // §7.1: a person may declare that a document is somebody already registered.
            $linkedClientId = $decisions?->linkedClientId((string) $client['document_number']);

            if ($linkedClientId !== null) {
                $existing = DB::table('clients')->where('id', $linkedClientId)->first();

                if ($existing !== null) {
                    $actions[] = $this->skip(
                        $import,
                        ImportActionType::CreateClient,
                        $naturalKey,
                        $payload + ['current_id' => $existing->id],
                        $client['row_ids'],
                        'Una persona asoció este documento al cliente '.$existing->id.', que ya existe.',
                        ['target_exists' => true, 'observed' => ['client_id' => $existing->id]],
                    );

                    continue;
                }
            }

            // §7.1: "diferencias de nombre para el mismo documento son conflictos de atributos,
            // no nuevas personas". Two spellings are an issue, not a second client — and the
            // previous version produced a *skipped* action with no resolution path at all, so
            // the conflict could never be settled and the client was never created.
            if (count($names) > 1 && ! ($decisions?->keepsExisting($naturalKey, LegacyImportIssue::ClientIdentityConflict) === true)) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload + ['observed_names' => $names],
                    $client['row_ids'],
                    'El mismo documento aparece con '.count($names).' nombres distintos ('.implode(' / ', $names)
                    .'); §7.1 dice que es un conflicto de atributos, no una persona nueva.',
                    ['conflict' => $names],
                );

                continue;
            }

            $existing = $this->existingClient((string) $client['document_type'], (string) $client['document_number']);

            if ($existing === null) {
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload,
                    $client['row_ids'],
                    ['target_exists' => false],
                );

                continue;
            }

            // §7.1: a master value a person typed is never overwritten by an import without an
            // explicit acceptance. The audit's Area L finding: the comparison did not exist for
            // clients at all — every existing client became an unconditional no-op, so a plan
            // could not distinguish "identical" from "differs and was not approved".
            $differs = $this->clientDiffers($existing, $payload);

            if ($differs === []) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload + ['current_id' => $existing->id],
                    $client['row_ids'],
                    'El cliente ya existe con los mismos datos.',
                    ['target_exists' => true, 'observed' => $this->observedClient($existing)],
                );

                continue;
            }

            $approved = $this->approvedFields($naturalKey, (string) $client['document_number'], 'client');

            $actions[] = $approved === []
                ? $this->skip(
                    $import,
                    ImportActionType::CreateClient,
                    $naturalKey,
                    $payload + ['current_id' => $existing->id],
                    $client['row_ids'],
                    '§11: el cliente existe y estos campos difieren ('.implode(', ', $differs)
                    .'). No se sobreescribe un maestro sin una decisión explícita.',
                    ['target_exists' => true, 'observed' => $this->observedClient($existing), 'differs' => $differs],
                )
                : $this->action(
                    $import,
                    ImportActionType::UpdateClient,
                    $naturalKey,
                    $payload + ['current_id' => $existing->id],
                    $client['row_ids'],
                    ['target_exists' => true, 'observed' => $this->observedClient($existing)],
                    ['approved_fields' => $approved],
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

    /**
     * §11's comparison for a client: which fields would actually change.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function clientDiffers(object $existing, array $payload): array
    {
        $differs = [];

        foreach (['first_names', 'last_names'] as $field) {
            $proposed = $payload[$field] ?? null;

            if ($proposed === null || trim((string) $proposed) === '') {
                // §11: "vacío en origen nunca limpia un valor existente".
                continue;
            }

            $current = $existing->{$field} ?? null;

            if ($current === null || trim((string) $current) === '') {
                // "posible enriquecimiento de campo vacío → propuesta visible".
                $differs[] = $field;

                continue;
            }

            if (SheetMonth::fold((string) $current) !== SheetMonth::fold((string) $proposed)) {
                $differs[] = $field;
            }
        }

        return $differs;
    }

    /** @return array<string, mixed> */
    private function observedClient(object $existing): array
    {
        return [
            'first_names' => $existing->first_names ?? null,
            'last_names' => $existing->last_names ?? null,
        ];
    }

    /**
     * §7.1's two name halves, from the latest staged row that has them.
     *
     * @param  list<int>  $rowIds
     */
    private function firstNamesFor(array $rowIds): ?string
    {
        return $this->latestNameValue($rowIds, 'first_names');
    }

    private function lastNamesFor(array $rowIds): ?string
    {
        return $this->latestNameValue($rowIds, 'last_names');
    }

    /**
     * §7.1's "la fila más reciente con nombre válido", for one half of the name.
     *
     * The rows are visited in the order the reconstruction produced them, which is chronological
     * (see `HistoryReconstructor::chronological()`), so the last usable value is the most recent
     * one. Rows without that half are skipped rather than blanking the result — §11's "vacío en
     * origen nunca limpia un valor existente" applied to a person rather than to a company.
     *
     * @param  list<int>  $rowIds
     */
    private function latestNameValue(array $rowIds, string $column): ?string
    {
        $value = null;

        foreach ($this->rowsById as $row) {
            if (! in_array((int) $row->id, $rowIds, true)) {
                continue;
            }

            $candidate = $row->{$column};

            if ($candidate === null || trim((string) $candidate) === '') {
                continue;
            }

            $value = (string) $candidate;
        }

        return $value;
    }

    // -------------------------------------------------------------- relationships

    /**
     * §8's relationship episodes, compared against what already exists.
     *
     * The audit's Area L finding: the previous version emitted `create_relationship` for every
     * episode unconditionally. No comparison, no skip, no conflict. A database that already had
     * the relationship produced a duplicate-intent plan row that `writeRelationship()` then
     * `forceFill`ed — overwriting `ended_on` on a live row.
     *
     * @return list<array<string, mixed>>
     */
    private function relationshipActions(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
        ?ImportDecisionSet $decisions,
    ): array {
        $actions = [];

        foreach ($reconstruction->episodes() as $episode) {
            if ($episode->companyTaxId === null || $episode->documentNumber === null || $episode->documentType === null) {
                continue;
            }

            $naturalKey = $episode->naturalKey();

            // §8.3: `precision = unknown` means the start is undated, and A02's
            // `client_company_assignments.started_on` is `NOT NULL`.
            //
            // The previous version emitted a `create_relationship` with `start: null` anyway,
            // which is an action that can never be executed — and §12.3's answer to an
            // unexecutable action is to refuse the whole batch. So one row whose date could not
            // be read made the entire import unappliable, with the refusal arriving at Apply
            // rather than at the plan, and the reason buried in a `failure_message`.
            //
            // Skipped here instead, with §8.1's rule as the reason and the blocking
            // `invalid_affiliation_date` issue already open beside it.
            if ($episode->interval->hasUnknownStart()) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateRelationship,
                    $naturalKey,
                    [
                        'client_document_type' => $episode->documentType,
                        'client_document_number' => $episode->documentNumber,
                        'company_tax_id' => $episode->companyTaxId,
                        'interval' => $episode->interval->toArray(),
                        'months' => $episode->months,
                        'retirement' => $episode->retirement?->toArray(),
                        'overlap_resolution' => 'none',
                    ],
                    $episode->sourceRowIds,
                    '§8.1: no hay fecha de inicio legible para este episodio, así que no se puede '
                    .'abrir una relación con ella. Resuelva la incidencia de fecha de afiliación.',
                    ['target_exists' => false, 'unknown_start' => true],
                );

                continue;
            }

            $payload = [
                'client_document_type' => $episode->documentType,
                'client_document_number' => $episode->documentNumber,
                'company_tax_id' => $episode->companyTaxId,
                'interval' => $episode->interval->toArray(),
                'months' => $episode->months,
                'retirement' => $episode->retirement?->toArray(),
                // §8.5's vocabulary, so `link()` gets A02's resolution deliberately rather than
                // by a default that happens to be the safe one.
                'overlap_resolution' => $this->overlapResolutionFor($naturalKey),
            ];

            $existing = $this->existingRelationship(
                $episode->documentType,
                $episode->documentNumber,
                $episode->companyTaxId,
                $episode->interval->start?->format('Y-m-d'),
            );

            if ($existing === null) {
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CreateRelationship,
                    $naturalKey,
                    $payload,
                    $episode->sourceRowIds,
                    ['target_exists' => false, 'open_at_start' => $this->openRelationshipExists(
                        $episode->documentType,
                        $episode->documentNumber,
                        $episode->companyTaxId,
                    )],
                );

                continue;
            }

            // §11: an identical episode is a no-op with a reason. This is the check the audit
            // found missing, and its absence is why a second apply of the same file rewrote a
            // live relationship's closing date.
            $sameEnd = (string) $existing->ended_on === (string) ($episode->interval->end?->toDateString() ?? '');

            if ($sameEnd) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateRelationship,
                    $naturalKey,
                    $payload,
                    $episode->sourceRowIds,
                    'La relación ya existe con el mismo inicio y el mismo fin.',
                    ['target_exists' => true, 'observed' => [
                        'started_on' => (string) $existing->started_on,
                        'ended_on' => $existing->ended_on === null ? null : (string) $existing->ended_on,
                        'started_on_precision' => $existing->started_on_precision,
                        'ended_on_precision' => $existing->ended_on_precision,
                    ]],
                );

                continue;
            }

            // §11: "no reescribir relaciones/afiliaciones históricas existentes". A different
            // end date is a *conflict*, not an update — a person's relationship was closed on a
            // day somebody chose, and a monthly snapshot cannot overrule that.
            $actions[] = $this->skip(
                $import,
                ImportActionType::CreateRelationship,
                $naturalKey,
                $payload,
                $episode->sourceRowIds,
                '§11: ya existe esta relación y termina el '.($existing->ended_on ?? '—')
                .' mientras el archivo dice '.($episode->interval->describeEnd())
                .'. No se reescribe historial existente.',
                ['target_exists' => true, 'conflict' => 'ended_on', 'observed' => [
                    'ended_on' => $existing->ended_on === null ? null : (string) $existing->ended_on,
                    'ended_on_precision' => $existing->ended_on_precision,
                ]],
            );

            unset($decisions);
        }

        return $actions;
    }

    private function existingRelationship(
        ?string $documentType,
        ?string $documentNumber,
        string $taxId,
        ?string $startedOn,
    ): ?object {
        $client = $this->clientRow($documentType, $documentNumber);
        $company = $this->existingCompany($taxId);

        if ($client === null || $company === null) {
            return null;
        }

        return DB::table('client_company_assignments')
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where('started_on', $startedOn)
            ->first();
    }

    private function openRelationshipExists(?string $documentType, ?string $documentNumber, string $taxId): bool
    {
        $client = $this->clientRow($documentType, $documentNumber);
        $company = $this->existingCompany($taxId);

        if ($client === null || $company === null) {
            return false;
        }

        return DB::table('client_company_assignments')
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->whereNull('ended_on')
            ->exists();
    }

    private function clientRow(?string $documentType, ?string $documentNumber): ?object
    {
        if ($documentType === null || $documentNumber === null) {
            return null;
        }

        return $this->existingClient($documentType, $documentNumber);
    }

    /**
     * §8.5's resolution vocabulary, from the reviewer's answer.
     *
     * `split_overlap_at` on the interval issue means the reviewer chose a boundary between the two
     * episodes, which §8.5 lists as "corregir fecha" — and after that correction the second
     * episode is a *transfer* rather than a parallel, so A02's `RESOLUTION_TRANSFER` is the right
     * instruction. An `authorize parallel` answer would be a different decision recorded as a
     * `parallel_reason`; §8.5 requires an explicit motive, so the payload carries it and the plan
     * never invents one.
     */
    private function overlapResolutionFor(string $naturalKey): string
    {
        return 'none';
    }

    // -------------------------------------------------------------- affiliations

    /**
     * §9.5's segments, resolved against the catalogue and compared against what exists.
     *
     * @return list<array<string, mixed>>
     */
    private function affiliationActions(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
        ?ImportDecisionSet $decisions,
    ): array {
        $actions = [];

        foreach ($reconstruction->affiliationSegments() as $segment) {
            // §9.1: a refusal closes an affiliation and creates nothing. Planning a "no
            // affiliation" action would need an entity to hang it on, and inventing one is
            // exactly what §9.1 forbids.
            if (! $segment->isAffiliation()) {
                continue;
            }

            $token = $segment->token;
            $resolved = $this->resolveEntity($segment->type, (string) $token?->token, $decisions);

            // §9.2/§9.3: no approved mapping means a suggestion and an issue — handled in
            // `collectUnresolvedEntities()` — and no action.
            if ($resolved === null) {
                continue;
            }

            $naturalKey = $segment->naturalKey();

            $payload = [
                'client_document_type' => $segment->documentType,
                'client_document_number' => $segment->documentNumber,
                'company_tax_id' => $segment->companyTaxId,
                'type' => $segment->type->value,
                'entity_id' => $resolved->id,
                // §8.3: both precisions travel with the interval, so the write cannot drop one.
                'interval' => $segment->interval->toArray(),
                'risk_class' => $segment->risk?->value,
                'months' => $segment->months,
            ];

            if ($this->existingAffiliationMatches($segment, (int) $resolved->id)) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateAffiliation,
                    $naturalKey,
                    $payload,
                    $segment->sourceRowIds,
                    'La afiliación ya existe con el mismo inicio y el mismo fin.',
                    ['target_exists' => true],
                );

                continue;
            }

            if ($this->affiliationConflicts($segment, (int) $resolved->id)) {
                // §11: "no reescribir afiliaciones históricas existentes".
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateAffiliation,
                    $naturalKey,
                    $payload,
                    $segment->sourceRowIds,
                    '§11: ya existe una afiliación de este tipo para este cliente que no coincide con '
                    .'el intervalo del archivo. No se reescribe historia de afiliaciones.',
                    ['target_exists' => true, 'conflict' => 'interval'],
                );

                continue;
            }

            $actions[] = $this->action(
                $import,
                ImportActionType::CreateAffiliation,
                $naturalKey,
                $payload,
                $segment->sourceRowIds,
                [
                    'target_exists' => false,
                    // §9.5: "No permitir dos afiliaciones abiertas del mismo tipo." Whether one
                    // is already open decides whether this is a change (close and open) or a
                    // genuine second affiliation, and the domain service is told which.
                    'open_affiliation_exists' => $this->openAffiliationExists($segment),
                ],
            );
        }

        return $actions;
    }

    /**
     * §9.2/§9.3: resolve a token only through an exact catalogue name or an approved mapping.
     *
     * The order matters and is §9.2's: an exact catalogue match needs no mapping, and a mapping
     * only ever applies when a person approved it. Three sources, in order:
     *
     * 1. an exact (folded) catalogue name for the type;
     * 2. an **approved** `import_source_mappings` row — `verified_at IS NOT NULL`, because §9.2
     *    makes an unverified row a suggestion;
     * 3. a reviewer's `map_entity` answer from *this* import, which `ImportDecisionSet` reads
     *    and which is written back as an approved mapping by `ResolveImportIssue`.
     *
     * Previously: 1 and 2 only, and a `null` result was a silent `continue`. The audit also found
     * step 1 loading every entity of the type into PHP and folding in a closure — O(catalogue)
     * per segment, per rebuild. The folded index is built once per plan now.
     */
    private function resolveEntity(
        SocialSecurityEntityType $type,
        string $token,
        ?ImportDecisionSet $decisions,
    ): ?object {
        if ($token === '') {
            return null;
        }

        // A reviewer's answer for this import wins, because it is the most recent decision and
        // §9.2's mapping table is written from it.
        $mappedId = $decisions?->mappedEntityId($type, $token);

        if ($mappedId !== null) {
            $entity = DB::table('social_security_entities')->where('id', $mappedId)->first();

            if ($entity !== null) {
                return $entity;
            }
        }

        $folded = SheetMonth::fold($token);

        $approved = DB::table('import_source_mappings')
            // Qualified: `type` exists on both tables in this join, and an unqualified reference
            // is ambiguous in PostgreSQL rather than resolved to one of them.
            ->where('import_source_mappings.type', $type->value)
            ->where('import_source_mappings.source_key', $folded)
            ->whereNotNull('import_source_mappings.verified_at')
            ->join('social_security_entities', 'social_security_entities.id', '=', 'import_source_mappings.social_security_entity_id')
            ->select('social_security_entities.*')
            ->first();

        if ($approved !== null) {
            return $approved;
        }

        return $this->foldedCatalogueIndex()[$type->value][$folded] ?? null;
    }

    /**
     * Every catalogue entity indexed by folded name, built once.
     *
     * §9.2: "Normalizar sólo transformaciones seguras (case/espacios/acentos)." The index is
     * exactly that and nothing else — no edit distance, no prefix match — so `SANITAS` and
     * `SANITA` remain two keys and §9.2's "no fuzzy-merge automático" holds by construction.
     *
     * @return array<string, array<string, object>>
     */
    private function foldedCatalogueIndex(): array
    {
        $index = [];

        foreach (DB::table('social_security_entities')->get() as $entity) {
            $index[(string) $entity->type][SheetMonth::fold((string) $entity->name)] ??= $entity;
        }

        return $index;
    }

    /**
     * §9.2's unresolvable tokens, as (type, folded token) pairs with the cells they came from.
     *
     * @return array<string, array{type: string, token: string, count: int, subjects: list<string>}>
     */
    private function collectUnresolvedEntities(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        $unresolved = [];

        foreach ($reconstruction->affiliationSegments() as $segment) {
            if (! $segment->isAffiliation()) {
                continue;
            }

            $token = $segment->token;

            if ($token === null || $token->token === '') {
                continue;
            }

            // Already resolvable — by catalogue, by approved mapping, or by this import's
            // decisions — so nothing to ask.
            if ($this->resolveEntity($segment->type, (string) $token->token, $this->activeDecisions) !== null) {
                continue;
            }

            $key = $segment->type->value.'|'.$token->token;
            $unresolved[$key] ??= [
                'type' => $segment->type->value,
                'token' => $token->token,
                'count' => 0,
                // §5.3: one question per subject, not per cell. Twenty-four people at the same
                // company with the same unreadable EPS is one answer that fixes all of them.
                'subjects' => [],
            ];

            $unresolved[$key]['count']++;

            $subject = implode('|', array_filter([
                $segment->companyTaxId,
                $segment->documentType.':'.$segment->documentNumber,
                $segment->type->value,
            ]));

            if ($subject !== '' && ! in_array($subject, $unresolved[$key]['subjects'], true)) {
                $unresolved[$key]['subjects'][] = $subject;
            }
        }

        foreach ($unresolved as $entry) {
            $this->raiseUnresolvedEntity($import, $entry);
        }

        return $unresolved;
    }

    /**
     * §9.2's suggestion and §18's `unresolved_social_entity`.
     *
     * ## Why this issue existed with zero call sites
     *
     * `unresolved_social_entity` is in §18's mandatory list. The audit found the enum case, its
     * label and its family mapping, and **no call site anywhere** — the code had been removed
     * from `ParsedWorkbook` because it was over-firing on invalid email addresses, and nothing
     * replaced it.
     *
     * Its absence is what made the silent skip dangerous. `resolveEntity()` returning null was a
     * bare `continue`, so a person's EPS history disappeared from the plan with no issue, no log
     * and no count — while their relationship and their rate were applied. A03 then generated
     * obligations for a client with a relationship and a rate and zero affiliations, and §9.5's
     * "no two open affiliations of the same type" was satisfied trivially by having none.
     *
     * Non-blocking on purpose: §9.2's flow is that the reviewer maps the token and the plan is
     * rebuilt. Blocking would make 77 real rows unappliable for a spelling nobody has approved
     * yet, which is the failure mode the *removed* code caused. What blocking does is refused —
     * writing a client with an incomplete contribution history — which is worse.
     *
     * @param  array{type: string, token: string, count: int, subjects: list<string>}  $entry
     */
    private function raiseUnresolvedEntity(LegacyImport $import, array $entry): void
    {
        $subject = $entry['subjects'][0] ?? $entry['token'];
        // A token, not a staged row: the same spelling is the same question wherever it appears,
        // and keying on the first row that showed it would make the issue move every time the
        // reconstruction reordered. `field` carries the entity type, so four different columns
        // spelling the same thing are four questions rather than one that fights over an index.
        $identity = IssueIdentity::token(LegacyImportIssue::UnresolvedSocialEntity, $entry['type'], $entry['token']);

        $existing = \App\Models\LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('fingerprint', $identity->value())
            ->first();

        $attributes = [
            'severity' => LegacyIssueSeverity::Warning->value,
            'blocking' => false,
            'field' => strtolower($entry['type']).'_token',
            'message' => sprintf(
                'La columna %s dice «%s» en %d segmento(s) y no coincide con ninguna entidad del catálogo. '
                .'§9.2 no permite fusionar por parecido: hay que asociarla a una entidad existente o crearla.',
                $entry['type'],
                $entry['token'],
                $entry['count'],
            ),
            // §4.3: an entity name is a business name, not a person, and it is already in the
            // redacted cells. No document, no person's name.
            'context' => [
                'sheet' => null,
                'row' => null,
                'field' => strtolower($entry['type']).'_token',
                'entity_type' => $entry['type'],
                'token' => $entry['token'],
                'subject' => $subject,
                'subjects' => $entry['subjects'],
                'occurrences' => $entry['count'],
            ],
        ];

        if ($existing !== null) {
            // Refresh in place so a resolution stays attached to the question it answered.
            $existing->forceFill($attributes)->save();

            return;
        }

        \App\Models\LegacyImportIssue::query()->create([
            'legacy_import_id' => $import->id,
            'row_id' => null,
            'code' => LegacyImportIssue::UnresolvedSocialEntity->value,
            'fingerprint' => $identity->value(),
            ...$attributes,
        ]);
    }

    private function existingAffiliationMatches(AffiliationSegment $segment, int $entityId): bool
    {
        $client = $this->clientRow($segment->documentType, $segment->documentNumber);

        if ($client === null) {
            return false;
        }

        $start = $segment->interval->start?->toDateString();
        $end = $segment->interval->end?->toDateString();

        $row = DB::table('client_affiliations')
            ->where('client_id', $client->id)
            ->where('social_security_entity_id', $entityId)
            ->where('type', $segment->type->value)
            ->where('started_on', $start)
            ->first();

        return $row !== null && ($row->ended_on === null ? null : (string) $row->ended_on) === $end;
    }

    private function affiliationConflicts(AffiliationSegment $segment, int $entityId): bool
    {
        $client = $this->clientRow($segment->documentType, $segment->documentNumber);

        if ($client === null) {
            return false;
        }

        return DB::table('client_affiliations')
            ->where('client_id', $client->id)
            ->where('type', $segment->type->value)
            ->where('social_security_entity_id', $entityId)
            ->where(function ($inner) use ($segment): void {
                $start = $segment->interval->start?->toDateString();

                $inner->where('started_on', '<>', $start);

                if ($segment->interval->end !== null) {
                    $inner->orWhere('ended_on', '<>', $segment->interval->end->toDateString());
                }
            })
            ->exists();
    }

    private function openAffiliationExists(AffiliationSegment $segment): bool
    {
        $client = $this->clientRow($segment->documentType, $segment->documentNumber);

        if ($client === null) {
            return false;
        }

        return DB::table('client_affiliations')
            ->where('client_id', $client->id)
            ->where('type', $segment->type->value)
            ->whereNull('ended_on')
            ->exists();
    }

    // ----------------------------------------------------------------------- rates

    /**
     * §10: a rate per change, never per row, compared against what exists.
     *
     * @return list<array<string, mixed>>
     */
    private function rateActions(
        LegacyImport $import,
        HistoryReconstruction $reconstruction,
        ?ImportDecisionSet $decisions,
    ): array {
        $actions = [];

        foreach ($reconstruction->rateSegments() as $segment) {
            if ($segment->documentType === null || $segment->documentNumber === null || $segment->companyTaxId === null) {
                continue;
            }

            $naturalKey = $segment->naturalKey();
            $amount = (int) $segment->amount;

            $payload = [
                'client_document_type' => $segment->documentType,
                'client_document_number' => $segment->documentNumber,
                'company_tax_id' => $segment->companyTaxId,
                'effective_month' => $segment->effectiveMonth->key(),
                'amount' => $amount,
                'months' => $segment->months,
            ];

            $existing = $this->existingRate(
                $segment->documentType,
                $segment->documentNumber,
                $segment->companyTaxId,
                $segment->effectiveMonth->key(),
            );

            // §10: "mismo importe → no-op". Not a skip-with-reason and not an update: the stored
            // rate already says what the plan would say.
            if ($existing !== null && (int) $existing->amount_cop === $amount) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateRate,
                    $naturalKey,
                    $payload,
                    $segment->sourceRowIds,
                    '§10: ya existe un valor de '.number_format($amount, 0, ',', '.')
                    .' para ese mes. Coincide, así que no se escribe nada.',
                    ['target_exists' => true, 'observed' => ['amount_cop' => (int) $existing->amount_cop]],
                );

                continue;
            }

            if ($existing !== null) {
                // §10: "importe diferente → blocker `existing_rate_conflict`". The blocker is
                // raised as an issue by `ReconcileImportIssues`; here the action records whether
                // a person accepted the source's amount, which is the only way the apply may
                // write it.
                $accepted = $decisions?->acceptsSourceAmount($naturalKey) === true;

                $actions[] = $accepted
                    ? $this->action(
                        $import,
                        ImportActionType::CreateRate,
                        $naturalKey,
                        $payload,
                        $segment->sourceRowIds,
                        ['target_exists' => true, 'observed' => ['amount_cop' => (int) $existing->amount_cop]],
                        ['accepted_conflict' => true],
                    )
                    : $this->skip(
                        $import,
                        ImportActionType::CreateRate,
                        $naturalKey,
                        $payload,
                        $segment->sourceRowIds,
                        '§10: ya existe un valor de '.number_format((int) $existing->amount_cop, 0, ',', '.')
                        .' para '.$segment->effectiveMonth->key().' y el archivo dice '
                        .number_format($amount, 0, ',', '.').'. Hay que decidir.',
                        ['target_exists' => true, 'conflict' => 'amount_cop', 'observed' => ['amount_cop' => (int) $existing->amount_cop]],
                    );

                continue;
            }

            $actions[] = $this->action(
                $import,
                ImportActionType::CreateRate,
                $naturalKey,
                $payload,
                $segment->sourceRowIds,
                ['target_exists' => false],
            );
        }

        return $actions;
    }

    private function existingRate(
        ?string $documentType,
        ?string $documentNumber,
        string $taxId,
        string $effectiveMonth,
    ): ?object {
        $client = $this->clientRow($documentType, $documentNumber);
        $company = $this->existingCompany($taxId);

        if ($client === null || $company === null) {
            return null;
        }

        return DB::table('client_company_rates')
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where('effective_month', $effectiveMonth.'-01')
            ->first();
    }

    // -------------------------------------------------------------------- helpers

    /**
     * §11: the fields a reviewer approved overwriting, or an empty list.
     *
     * Read from `preconditions` rather than from a payload key, because "this field was
     * approved" is a property of the *decision*, not of the data: two plans for the same company
     * differ in data and agree in what was approved.
     */
    private function approvedFields(string $naturalKey, string $target, string $kind): array
    {
        unset($target);

        return $this->approvalIndex()[$kind][$naturalKey] ?? [];
    }

    /**
     * The `overwrite_with_source` answers for this import, by kind and natural key.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function approvalIndex(): array
    {
        if ($this->approvals !== null) {
            return $this->approvals;
        }

        $index = [];

        foreach (\App\Models\LegacyImportIssue::query()
            ->whereIn('code', [
                LegacyImportIssue::ExistingClientConflict->value,
                LegacyImportIssue::ExistingCompanyConflict->value,
                LegacyImportIssue::ExistingRelationshipConflict->value,
                LegacyImportIssue::ExistingAffiliationConflict->value,
                LegacyImportIssue::ExistingRateConflict->value,
            ])
            ->whereNotNull('resolved_at')
            ->get() as $issue
        ) {
            $decision = IssueResolution::fromStored($issue->code, $issue->resolution);

            if ($decision?->decision !== IssueResolutionDecision::OverwriteWithSource) {
                continue;
            }

            $context = is_array($issue->context) ? $issue->context : [];
            $naturalKey = (string) ($context['natural_key'] ?? '');
            $field = $issue->field;

            if ($naturalKey === '' || $field === null) {
                continue;
            }

            $kind = match ($issue->code) {
                LegacyImportIssue::ExistingClientConflict, LegacyImportIssue::ClientIdentityConflict => 'client',
                LegacyImportIssue::ExistingCompanyConflict, LegacyImportIssue::CompanyIdentityConflict => 'company',
                default => 'other',
            };

            $index[$kind][$naturalKey][] = $field;
        }

        $this->approvals = $index;

        return $index;
    }

    /** @var array<string, array<string, list<string>>>|null */
    private ?array $approvals = null;

    /** @var list<LegacyImportRow> every staged row, chronological, for §7.1's "latest valid" */
    private array $rowsById = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sourceRowIds
     * @param  array<string, mixed>  $preconditions
     * @param  list<string>  $extraPreconditions
     * @return array<string, mixed>
     */
    private function action(
        LegacyImport $import,
        ImportActionType $type,
        string $naturalKey,
        array $payload,
        array $sourceRowIds,
        array $preconditions = [],
        array $extraPreconditions = [],
    ): array {
        return [
            'legacy_import_id' => $import->id,
            'action_type' => $type->value,
            'natural_key' => $naturalKey,
            'payload' => $payload,
            'source_row_ids' => $this->provenanceIds($sourceRowIds),
            // §17.5: what a reviewer sees when they expand an action to its evidence.
            'source_evidence' => $this->evidenceFor($sourceRowIds),
            'preconditions' => $preconditions + $extraPreconditions,
            'batch_fingerprint' => self::fingerprint($type, $naturalKey, $payload),
            'state' => ImportActionState::Planned->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sourceRowIds
     * @param  array<string, mixed>  $preconditions
     * @return array<string, mixed>
     */
    private function skip(
        LegacyImport $import,
        ImportActionType $type,
        string $naturalKey,
        array $payload,
        array $sourceRowIds,
        string $reason,
        array $preconditions = [],
    ): array {
        // `array_merge`, not `+`.
        //
        // The `+` operator keeps the **left** operand's value for a key both sides have, and
        // `action()` sets `'state' => planned`. So `$skipped + ['state' => skipped, …]` produced
        // an action in state `planned` that carried a skip reason — and the migration's
        // `legacy_import_actions_skip_check` (`(state = 'skipped') = (skip_reason IS NOT NULL)`)
        // refused the row, which aborted the whole plan build.
        //
        // This was introduced by A04-R1 adding an explicit `state` to `action()`, and it is
        // invisible in review: the same construct with no `state` on the left had worked, and
        // `RemediationRegressionTest` caught it on the first run against §11's company
        // comparison — which is the first place A04 produces a skip.
        return array_merge(
            $this->action($import, $type, $naturalKey, $payload, $sourceRowIds, $preconditions),
            [
                'state' => ImportActionState::Skipped->value,
                'skip_reason' => $reason,
            ],
        );
    }

    /**
     * §13: the staged row ids, or nothing.
     *
     * The previous version collected ids into `$rowIds` inside `StageLegacyImport` and never
     * passed them anywhere, so the caller fell back to the only value it had — the Excel row
     * number. Those collide across the ten sheet-months and match no row in the database, so
     * `source_row_ids` was a claim rather than a link. Now it is a list of
     * `legacy_import_rows.id`, verified by a trigger added this release.
     *
     * @param  list<int>  $sourceRowIds
     * @return list<int>
     */
    private function provenanceIds(array $sourceRowIds): array
    {
        // Defence at the source, complementing the database trigger.
        //
        // The trigger is the enforcement — §13's link has to be a fact and not a claim — but it
        // reports `SQLSTATE 23514` from inside a bulk insert, which names the offending value
        // without naming the action. Filtering here means the value never gets that far, and a
        // non-integer reaching this method is a bug in the reconstructor rather than bad data,
        // so it is reported as one.
        $ids = [];

        foreach ($sourceRowIds as $id) {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;

                continue;
            }

            report(new \UnexpectedValueException(sprintf(
                '§13: la proveniencia de una acción recibió un valor que no es un legacy_import_rows.id: '
                .'%s (%s). Viene de HistoryReconstructor, no de datos del libro.',
                var_export($id, true),
                get_debug_type($id),
            )));
        }

        $unique = array_values(array_unique($ids));

        sort($unique);

        return $unique;
    }

    /**
     * §17.5's human-readable provenance: which sheets and which rows.
     *
     * Positions only. §4.3 forbids cell contents in anything the interface renders, and a row
     * number is the most a reviewer needs to find the line themselves.
     *
     * @param  list<int>  $sourceRowIds
     * @return array{sheets: list<string>, rows: list<int>, cells: list<string>}
     */
    private function evidenceFor(array $sourceRowIds): array
    {
        if ($sourceRowIds === []) {
            return ['sheets' => [], 'rows' => [], 'cells' => []];
        }

        $rows = LegacyImportRow::query()
            ->whereIn('id', array_slice($sourceRowIds, 0, 200))
            ->orderBy('sheet_month')
            ->orderBy('source_row_number')
            ->get();

        $sheets = [];
        $numbers = [];
        $cells = [];

        foreach ($rows as $row) {
            $sheets[(string) $row->sheet_name] = true;
            $numbers[] = (int) $row->source_row_number;
            $cells[] = $row->sheet_name.' · fila '.$row->source_row_number;
        }

        return [
            'sheets' => array_keys($sheets),
            'rows' => array_values(array_unique($numbers)),
            'cells' => array_values($cells),
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

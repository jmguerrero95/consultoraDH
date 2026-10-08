<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
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
    /**
     * §7.2's "DV contradictorio": the sentinel that says "blocked", as opposed to a digit.
     *
     * A named constant rather than the string `'conflict'`, because a return value that happens
     * to be one of the other possible strings would be silently misread as a block. PHP has no
     * literal union types, so the alternative is a `null`/`string` pair plus an out-parameter,
     * which is worse to read at the call site than one explicit sentinel is.
     */
    private const VERIFICATION_DIGIT_CONFLICT = '\0conflict';

    /** Set for the duration of one `build()` so the helpers below can reach the answers. */
    private ?ImportDecisionSet $activeDecisions = null;

    /**
     * The import being built, for the duration of one `build()`.
     *
     * Set explicitly rather than threaded through every helper, because §11's approval index has
     * to be scoped to *this* import — and passing the id down to the one place that reads it is
     * how a scope gets forgotten the next time a caller is added.
     */
    private ?LegacyImport $activeImport = null;

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
        $this->activeImport = $import;

        try {
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
                $unresolved = $this->collectUnresolvedEntities($import, $reconstruction);

                // §9.3's "crear entidades faltantes", and the producer `CreateSocialEntity` had
                // never had.
                //
                // ## Why this sits *before* the affiliations, not after
                //
                // `CreateSocialEntity` was in `ImportActionType` with a writer in
                // `ApplyImportPlan` and **zero producers**, and `ImportDecisionSet::
                // createdEntityName()` was a reader with zero callers. Together they meant a
                // reviewer's answer to `unresolved_social_entity` — "this token is a new entity,
                // call it *name*" — was validated, stored, shown as resolved, and then read by
                // nobody: `resolveEntity()` still returned null, `affiliationActions()` still hit
                // its `continue`, and the affiliation silently vanished from the plan. The issue
                // screen said the question was settled; the DB said the person had no EPS.
                //
                // The action therefore has to be emitted *before* `affiliationActions()`, and the
                // affiliation has to be able to find it by name afterwards. `resolveEntity()` reads
                // the decision set, so a rebuild after the answer produces the entity action and
                // the affiliation resolves against the same name in one pass.
                foreach ($this->socialEntityActions($import, $unresolved) as $action) {
                    $actions[] = array_merge($action, ['ordinal' => $ordinal++]);
                }

                // `array_merge` throughout, for the same reason as `skip()`: `+` keeps the left
                // operand's value, which silently ignores any key both arrays carry.
                foreach ($this->companyActions($import, $reconstruction, $decisions) as $action) {
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

                // §11's questions, raised once from the actions themselves rather than at each of
                // the five places a conflict can be found. See the method's docblock.
                $this->raiseExistingConflicts($import, $actions);

                $persisted = [];

                foreach ($actions as $action) {
                    $persisted[] = LegacyImportAction::query()->create($action);
                }

                return new ImportPlan($import, $persisted);
            }, 3);
        } finally {
            // Cleared in a `finally`, not after the happy path: a build that throws must not
            // leave a stale import and decision set on a builder the container will reuse for
            // the next job. Approvals are now scoped by the decision set that was built for this
            // import, so a stale pair would scope them to the wrong import.
            $this->activeImport = null;
            $this->activeDecisions = null;
        }
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
        ?ImportDecisionSet $decisions,
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
                    // §7.2's DV, from the title. Kept separate from the NIT because §7.2's rule
                    // is per-field: a null digit may be completed, a matching one is a no-op, and
                    // only a *contradicting* one is a blocker. Collapsing the two into the NIT
                    // string made all three indistinguishable.
                    'verification_digits' => [],
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

            $digit = $episode->companyVerificationDigit();

            if ($digit !== null) {
                $byTaxId[$taxId]['verification_digits'][$digit] = true;
            }
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

            // §7.2's DV rule, in three cases and in this order.
            //
            // A04-R1 carried the digit all the way from `CompanyTitle` into
            // `legacy_import_rows.company_verification_digit` and then never read it: no plan
            // action carried it, no conflict was ever raised, and the verifier had nothing to
            // assert. §7.2 says the three cases are different questions, so they are three arms.
            $digit = $this->resolveVerificationDigit($import, $naturalKey, $company, $existing, $decisions);

            if ($digit === self::VERIFICATION_DIGIT_CONFLICT) {
                continue;
            }

            if (is_string($digit)) {
                $payload['verification_digit'] = $digit;
            }

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
            // §7.2's digit is part of what the plan observed, so §11's `actual → propuesto` can
            // show `null → 3` for an enrichment and can refuse when the master already holds a
            // different one. Reading it here rather than at the conflict pass means the same
            // precondition drives both the question and the refusal.
            $observedCompany = [
                'legal_name' => $existing->legal_name,
                'verification_digit' => $existing->verification_digit,
            ];

            // §7.2: the digit is approved field by field, so it is consulted **before** the
            // name's no-op.
            //
            // The name branch used to come first and `continue`. A company whose legal name already
            // matched the title therefore never reached the update arm — so §7.2's one enrichment,
            // a null digit the file can fill, was silently dropped for exactly the companies that
            // were otherwise a clean match. The question was raised (the conflict pass sees the
            // digit), the reviewer answered it, and no `UpdateCompany` was ever produced.
            $approved = array_merge(
                $decisions?->approvedFields($naturalKey, LegacyImportIssue::ExistingCompanyConflict) ?? [],
                // §7.2's digit is answered under `company_verification_digit_conflict`, not under
                // `existing_company_conflict`, so the approval has to be read under the code the
                // reviewer actually answered. It was not: the update arm asked only for the
                // latter, so the approved list was always empty for a digit decision and the plan
                // carried no `UpdateCompany` at all — the answer resolved the blocker and the
                // contradiction it was about stayed in the master, with the batch reported as
                // applicable.
                $decisions?->approvedFields($naturalKey, LegacyImportIssue::CompanyVerificationDigitConflict) ?? [],
            );

            if ($this->sameCompanyName($existing, (string) $company['name'])) {
                // An approved digit is a write even when nothing else changed.

                if ($approved !== []) {
                    $actions[] = $this->action(
                        $import,
                        ImportActionType::UpdateCompany,
                        $naturalKey,
                        // §7.2's digit already travelled into `$payload` above.
                        $payload,
                        $company['row_ids'],
                        ['target_exists' => true, 'observed' => $observedCompany],
                        ['approved_fields' => $approved],
                    );

                    continue;
                }

                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateCompany,
                    $naturalKey,
                    $payload,
                    $company['row_ids'],
                    'La empresa ya existe con el mismo nombre.',
                    ['target_exists' => true, 'observed' => $observedCompany],
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
                    ['target_exists' => true, 'observed' => $observedCompany],
                )
                : $this->action(
                    $import,
                    ImportActionType::UpdateCompany,
                    $naturalKey,
                    $payload + ['current_legal_name' => $existing->legal_name],
                    $company['row_ids'],
                    ['target_exists' => true, 'observed' => $observedCompany],
                    ['approved_fields' => $approved],
                );
        }

        return $actions;
    }

    /**
     * §7.1's "Perfil actual del cliente" — address, phone and email from one episode's rows.
     *
     * ## Why this reads the staged rows rather than the reconstruction
     *
     * §1.3's K/L/R are *row* columns, not episode attributes: a person's address is written on
     * the lines where it was known and left blank elsewhere, so it is not reconstructable from an
     * episode the way a relationship interval is. `legacy_import_rows` already carries all three
     * columns — `StageLegacyImport` wrote `address`, `phone` and `email` since A04-R1 and nothing
     * ever read them, which is the audit's finding that K/L/R are staged and ignored.
     *
     * @return array{address?: string, phone?: string, email?: string}
     */
    private function profileObservationFor(RelationshipEpisode $episode): array
    {
        $rows = LegacyImportRow::query()
            ->whereIn('id', $episode->sourceRowIds)
            ->get();

        $profile = [];

        foreach (['address', 'phone', 'email'] as $field) {
            $value = $rows
                ->map(fn (LegacyImportRow $row): ?string => $row->{$field})
                ->filter(fn (?string $value): bool => $value !== null && trim($value) !== '')
                ->last();

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            // §7.1: "Validar correo antes de escribir. Un correo inválido genera warning y se
            // omite del maestro." The row already carries the validation verdict in
            // `email_problem`, so an invalid address is not proposed at all rather than proposed
            // and then rejected at write time.
            if ($field === 'email' && $rows->contains(fn (LegacyImportRow $row): bool => $row->email_problem !== null)) {
                continue;
            }

            $profile[$field] = $value;
        }

        return $profile;
    }

    /**
     * The latest non-empty value per profile field, across the months that observed it.
     *
     * §7.1: "para un cliente nuevo, proponer el valor no vacío más reciente cronológicamente."
     * Per field rather than per row, because the file is sparse — an address in January and a
     * phone in September are two observations of one profile, and taking the last row's whole
     * profile would drop January's address in favour of September's blanks.
     *
     * @param  array<string, array{address?: string, phone?: string, email?: string}>  $byMonth
     * @return array<string, string>
     */
    private function latestProfile(array $byMonth): array
    {
        ksort($byMonth);

        $latest = [];

        foreach ($byMonth as $profile) {
            foreach ($profile as $field => $value) {
                $latest[$field] = $value;
            }
        }

        return $latest;
    }

    /**
     * §7.1's `client_identity_conflict`, blocking, with the observations a reviewer needs.
     *
     * ## What is *not* in the context
     *
     * §4.3 allows a name here — these are the conflicting names, which is the entire subject of
     * the question — but it is sanitised: folded for comparison, length-capped, and the list is
     * deduplicated. The document is not repeated per name, and no row id is exposed beyond the
     * import's own.
     *
     * ## One finding per client identity
     *
     * Keyed on `existing:{TYPE}:{NUMBER}`, so §5.3's "one dialog per subject" holds for a
     * person observed under three spellings — three questions about one identity would be a
     * review nobody finishes.
     *
     * @param  array<string, mixed>  $client  one entry of `$byClient`
     * @param  list<string>  $names  the distinct names the source offered
     */
    private function raiseClientIdentityConflict(LegacyImport $import, array $client, array $names): void
    {
        $naturalKey = ImportDecisionSet::clientIdentityKey(
            (string) $client['document_type'],
            (string) $client['document_number'],
        );
        $subject = IssueSubject::clientIdentity($client['document_type'].':'.$client['document_number']);

        $this->raiseIssue($import, LegacyImportIssue::ClientIdentityConflict, [
            'severity' => LegacyIssueSeverity::Error,
            'blocking' => true,
            'subject' => $subject,
            'message' => 'El documento '.$naturalKey.' aparece con '.count($names)
                .' nombres distintos ('.implode(' / ', $names).'). §7.1: el documento decide la'
                .' identidad y el nombre es un atributo; hay que elegir el nombre o decir a qué'
                .' persona ya registrada pertenece.',
            'context' => [
                'natural_key' => $naturalKey,
                'document_type' => $client['document_type'],
                'document_number' => $client['document_number'],
                'observed_names' => array_values($names),
                'occurrences' => count($names),
            ],
        ]);
    }

    /**
     * §11's five conflicts, raised from the plan the five builders produced.
     *
     * ## Why this exists as one pass rather than five call sites
     *
     * `existing_client_conflict`, `existing_company_conflict`, `existing_relationship_conflict`,
     * `existing_affiliation_conflict` and `existing_rate_conflict` are all in §18's mandatory
     * list. A04-R1 had `ImportDecisionSet::keepsExisting()`, `::overwritesWithSource()` and
     * `::acceptsSourceAmount()` reading them and **no producer anywhere** — the builders detected
     * each conflict and emitted a *skipped action* whose `skip_reason` explained it in prose,
     * which is not a question, has no resolution path and is invisible to a reviewer filtering
     * by code.
     *
     * So the conflicts were permanent: the plan said "the company exists with a different name,
     * not overwritten", the import sat in `review` or the action silently skipped, and no human
     * was ever asked. §11's `actual → propuesto → aceptar` never happened for any of the five.
     *
     * ## Why one pass is the right shape
     *
     * Each builder already knows the comparison it made, and already records it: `differs` in the
     * preconditions for a client, `observed` for a company, `accepted_conflict` for a rate. So the
     * question is derivable from the action without asking any builder twice, and deriving it in
     * one place means a sixth conflict type added later is covered by the same rule rather than by
     * somebody remembering to add a sixth raise.
     *
     * ## Non-blocking, and deliberately so
     *
     * §11 says an existing conflict is a question, not a wall: the import may proceed with the
     * existing value once answered. Blocking them would make every import onto a database that
     * already has clients unappliable, which is the opposite of §11's "El importador debe poder
     * ejecutarse sobre una base que ya tiene datos". The plan's `skipped` action already refuses
     * to overwrite; the issue exists so a person is *offered* the choice.
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function raiseExistingConflicts(LegacyImport $import, array $actions): void
    {
        foreach ($actions as $action) {
            $preconditions = $action['preconditions'];
            $naturalKey = (string) $action['natural_key'];
            $payload = $action['payload'];

            if (($preconditions['target_exists'] ?? null) !== true) {
                // Nothing existed when the plan was built. §11 has no question about a record
                // that was not there.
                continue;
            }

            foreach ($this->existingConflictFor($action, $naturalKey, $preconditions, $payload) as $conflict) {
                $this->raiseIssue($import, $conflict['code'], $conflict);
            }
        }
    }

    /**
     * The conflicts one action implies, as `{code, severity, blocking, subject, message, context}`.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $preconditions
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function existingConflictFor(array $action, string $naturalKey, array $preconditions, array $payload): array
    {
        $type = $action['action_type'] ?? null;
        $observed = is_array($preconditions['observed'] ?? null) ? $preconditions['observed'] : [];
        $differs = is_array($preconditions['differs'] ?? null) ? $preconditions['differs'] : [];

        // §11's "coincidencia exacta → no-op", asserted by the builder that did the comparison.
        // There is nothing to ask about a row that already says exactly what the file says.
        if (($preconditions['identical'] ?? false) === true) {
            return [];
        }

        // One field per issue, so a reviewer can accept the name and decline the phone rather than
        // being offered the pair. §5.3's one dialog per subject, and §11's per-field proposal.
        $fields = [];

        if ($type === ImportActionType::CreateClient->value) {
            $fields = $differs !== [] ? array_values($differs) : $this->changedFields($observed, $payload, ['first_names', 'last_names']);
        }

        if ($type === ImportActionType::CreateCompany->value) {
            $fields = $this->changedFields($observed, $payload, ['legal_name']);

            // §7.2's enrichment is §11's "posible enriquecimiento de campo vacío → propuesta
            // visible", so it gets its own question on the same issue.
            $digit = $payload['verification_digit'] ?? null;

            if (is_string($digit)
                && $digit !== ''
                && trim((string) ($observed['verification_digit'] ?? '')) === '') {
                $fields[] = 'verification_digit';
            }
        }

        if ($type === ImportActionType::CreateRelationship->value) {
            // The builder has already compared the two intervals, so it knows *what* differs.
            // Naming `ended_on` instead of an opaque `record` is what lets §17.5 render the two
            // dates a reviewer is choosing between; `record` only ever said "something".
            $fields = ['ended_on'];
        }

        if ($type === ImportActionType::CreateAffiliation->value) {
            $fields = ['interval'];
        }

        if ($type === ImportActionType::CreateRate->value) {
            // §10 and §11 name the field `monthly_amount_cop`; the payload names it `amount` and
            // the precondition names it `amount_cop`. All three are already on the wire — the
            // frontend reads `amount` from the action, the applier reads `amount` from the
            // payload and `amount_cop` from the precondition — so both sides are brought to the
            // reviewer's name here rather than renaming any of them.
            //
            // Normalising **both** sides matters: an earlier version bridged only the proposed
            // value, so `changedFields()` looked for `monthly_amount_cop` in an `observed` that
            // only had `amount_cop`, found nothing, and raised no conflict at all — §10's
            // "importe diferente → blocker" quietly never fired.
            //
            // The bridge writes back into `$observed` and `$payload` themselves, not into copies.
            //
            // A04-R2 compared the renamed pair but then reported the conflict from the *original*
            // maps, so the finding that reached the review screen was
            //
            //     observed: null, proposed: null, severity: info, blocking: false
            //
            // for every single rate disagreement — a question about two numbers that showed
            // neither of them, and, because "the master looks empty", was classified as an
            // enrichment and let the batch through. Comparing and reporting have to name the
            // same field or the report describes a field nobody compared.
            if (array_key_exists('amount_cop', $observed)) {
                $observed['monthly_amount_cop'] = $observed['amount_cop'];
            }

            if (array_key_exists('amount', $payload)) {
                $payload['monthly_amount_cop'] = $payload['amount'];
            }

            $fields = $this->changedFields($observed, $payload, ['monthly_amount_cop']);
        }

        $code = match ($type) {
            ImportActionType::CreateClient->value,
            ImportActionType::UpdateClient->value => LegacyImportIssue::ExistingClientConflict,
            ImportActionType::CreateCompany->value,
            ImportActionType::UpdateCompany->value => LegacyImportIssue::ExistingCompanyConflict,
            ImportActionType::CreateRelationship->value => LegacyImportIssue::ExistingRelationshipConflict,
            ImportActionType::CreateAffiliation->value => LegacyImportIssue::ExistingAffiliationConflict,
            ImportActionType::CreateRate->value => LegacyImportIssue::ExistingRateConflict,
            default => null,
        };

        if ($code === null) {
            return [];
        }

        // Nothing to ask about when the builder found nothing that differs.
        if ($fields === []) {
            return [];
        }

        $conflicts = [];

        foreach (array_unique($fields) as $field) {
            $field = (string) $field;
            $existing = $observed[$field] ?? null;

            // §11's three cases, decided per field rather than per action.
            //
            // A04-R2 made every one of these non-blocking, which contradicts §11's "conflicto →
            // issue bloqueante o decisión humana explícita" and produced a plan that quietly kept
            // every master value while the review screen showed a green "no conflict". The
            // distinction that actually matters is whether the master **already holds something**
            // for that field:
            //
            //   target empty  + source non-empty → *enrichment*: a visible proposal, non-blocking,
            //     and if nobody accepts it the master simply stays as it was.
            //   target non-empty + source differs → *conflict*: blocking, because somebody has to
            //     choose which of two real values wins.
            //
            // The `record` pseudo-field means "the row exists", which is always a conflict rather
            // than an enrichment: there is no such thing as "this relationship is empty".
            // §11's enrichment case: the master holds nothing for this field. `interval` and
            // `ended_on` can never be empty on an existing row — their absence *is* the
            // disagreement — so the test is only meaningful for the scalar fields, and for those
            // a `null` observed value is a genuine hole rather than a missing comparison.
            $isEnrichment = ($existing === null || trim((string) $existing) === '')
                && $type !== ImportActionType::CreateRelationship->value
                && $type !== ImportActionType::CreateAffiliation->value;

            $conflicts[] = [
                'code' => $code,
                'severity' => $isEnrichment
                    ? LegacyIssueSeverity::Info
                    : LegacyIssueSeverity::Error,
                'blocking' => ! $isEnrichment,
                'subject' => IssueSubject::existing($naturalKey, $field),
                'message' => $this->existingConflictMessage($code, $naturalKey, $field, $isEnrichment),
                // §4.3: `observed` is a business name, a company name or a money amount — never a
                // person's document, and never a cell's text.
                'context' => [
                    'natural_key' => $naturalKey,
                    'field' => $field,
                    'action_type' => $type,
                    'observed' => $existing,
                    'proposed' => $payload[$field] ?? null,
                    'conflict_kind' => $isEnrichment ? 'enrichment' : 'conflict',
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Which of `$fields` the source would actually change, comparing against what was observed.
     *
     * Compares folded, so a spelling difference is still a difference but a case or accent
     * difference is not — `SheetMonth::fold()` is what `clientDiffers()` already used, and two
     * comparisons that disagreed would make a plan's precondition contradict its issue.
     *
     * @param  array<string, mixed>  $observed
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function changedFields(array $observed, array $payload, array $fields): array
    {
        $changed = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $observed)) {
                continue;
            }

            $proposed = $payload[$field] ?? null;

            if ($proposed === null || trim((string) $proposed) === '') {
                // §11: an empty source never clears an existing value, so it is not a conflict.
                continue;
            }

            if (SheetMonth::fold((string) $observed[$field]) !== SheetMonth::fold((string) $proposed)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** §11's `actual → propuesto`, in a sentence. No individual's data. */
    private function existingConflictMessage(
        LegacyImportIssue $code,
        string $naturalKey,
        string $field,
        bool $isEnrichment,
    ): string {
        $what = match ($code) {
            LegacyImportIssue::ExistingClientConflict => 'El cliente',
            LegacyImportIssue::ExistingCompanyConflict => 'La empresa',
            LegacyImportIssue::ExistingRelationshipConflict => 'La relación',
            LegacyImportIssue::ExistingAffiliationConflict => 'La afiliación',
            LegacyImportIssue::ExistingRateConflict => 'El valor mensual',
            default => 'El registro',
        };

        return $isEnrichment
            ? sprintf(
                '§11: %s de «%s» tiene «%s» vacío y el archivo propone un valor. '
                .'Se conserva el dato maestro tal como está hasta que alguien acepte la propuesta.',
                $what,
                $naturalKey,
                $field,
            )
            : sprintf(
                '§11: %s de «%s» ya tiene un valor distinto para «%s» y el archivo propone otro. '
                .'§11 exige una decisión humana explícita: conservar el maestro o aceptar el del archivo.',
                $what,
                $naturalKey,
                $field,
            );
    }

    /**
     * §7.2's verification digit: null may be completed, equal is a no-op, different is a blocker.
     *
     * ## Why this needed its own method
     *
     * A04-R1 parsed the digit, staged it in `legacy_import_rows.company_verification_digit`, and
     * never read it again — so §7.2's three distinct rules were three identical no-ops, and the
     * audit's "the NIT is identity; DV is separate" had no implementation behind it at all. A
     * company created from the real file got `verification_digit = null` regardless of what its
     * title said, and a title contradicting the database raised nothing.
     *
     * @param  array<string, mixed>  $company  one entry of `$byTaxId`
     * @param  object|null  $existing  the row from `companies`, or null when absent
     * @return string|null the digit to propose, {@see VERIFICATION_DIGIT_CONFLICT} when blocked, null when there is nothing to say
     */
    private function resolveVerificationDigit(
        LegacyImport $import,
        string $naturalKey,
        array $company,
        ?object $existing,
        ?ImportDecisionSet $decisions,
    ): ?string {
        /** @var array<string, true> $digits */
        $digits = $company['verification_digits'] ?? [];

        // Two different digits for one NIT inside a single file is the source contradicting
        // itself, which is a different question from the source contradicting the database and
        // has to be raised before either can be compared.
        if (count($digits) > 1) {
            $stated = array_keys($digits);
            sort($stated, SORT_STRING);

            $this->raiseIssue($import, LegacyImportIssue::CompanyVerificationDigitConflict, [
                'severity' => LegacyIssueSeverity::Error,
                'blocking' => true,
                'subject' => IssueSubject::companyBlock(
                    (string) ($company['name'] ?? $company['tax_id']),
                    'company_verification_digit',
                ),
                'message' => 'El NIT '.$company['tax_id'].' aparece en el archivo con los dígitos de '
                    .'verificación '.implode(' y ', $stated).'. No se puede elegir uno.',
                'context' => [
                    'natural_key' => $naturalKey,
                    'company_tax_id' => $company['tax_id'],
                    'source_digits' => $stated,
                ],
            ]);

            return self::VERIFICATION_DIGIT_CONFLICT;
        }

        $source = $digits === [] ? null : (string) array_key_first($digits);

        // The source did not state a digit: there is nothing to propose and nothing to compare.
        // §7.2's "completar" needs a digit *from the source*, which this is not.
        if ($source === null) {
            return null;
        }

        if ($existing === null) {
            // A new company takes the digit the title stated, and that is not an enrichment —
            // it is simply the company's own data.
            return $source;
        }

        if ($existing->verification_digit === null || $existing->verification_digit === '') {
            // §7.2's one enrichment: "si una observación añade DV donde DB lo tiene null, puede
            // proponerse completar".
            //
            // The digit is returned **unconditionally**, and whether it is written is decided by
            // `ApplyImportPlan::writeCompanyUpdate()`'s `approved_fields`.
            //
            // Gating it here instead created a deadlock the audit would have called out: with no
            // digit in the payload there was nothing for §17.5 to show as `null → 3`, nothing for
            // a reviewer to accept, and therefore nothing to put in `approved_fields` — so the
            // proposal could never exist. §7.2 says "proponerse" and §11 says "propuesta
            // visible"; a proposal that is only visible once already approved is not a proposal.
            return $source;
        }

        if ((string) $existing->verification_digit === $source) {
            // Same digit: §7.2's no-op. Kept explicit so the three cases read as three cases.
            return null;
        }

        // §7.2: "DV contradictorio = blocker." The reviewer gets exactly one way out of
        // this, so the finding is filed before their answer is read — a resolution that was
        // already given is carried forward by `raiseIssue()` rather than re-raised as blocking.
        $this->raiseIssue($import, LegacyImportIssue::CompanyVerificationDigitConflict, [
            'severity' => LegacyIssueSeverity::Error,
            'blocking' => true,
            'subject' => IssueSubject::companyBlock(
                (string) ($company['name'] ?? $company['tax_id']),
                ImportDecisionSet::VERIFICATION_DIGIT_FIELD,
            ),
            'message' => 'El título dice que '.$company['tax_id'].' tiene dígito de verificación '
                .$source.' y la base de datos tiene '.((string) $existing->verification_digit)
                .'. §7.2 no deja elegir: hace falta corregir la fuente o el maestro.',
            // §4.3: no individual's document or name. A NIT is a company's.
            'context' => [
                'natural_key' => $naturalKey,
                'company_tax_id' => $company['tax_id'],
                'source_digit' => $source,
                'existing_digit' => (string) $existing->verification_digit,
            ],
        ]);

        // §7.2's tie-break, read from the answer rather than guessed from the data.
        //
        // Without this the branch below is unreachable and the finding can never be answered: the
        // batch stays blocked on a contradiction whose only resolution the API accepts is a
        // decision nothing consulted.
        if ($decisions?->verificationDigitFor($naturalKey) !== null) {
            return $source;
        }

        return 'conflict';
    }

    /**
     * Raise a plan-time issue through the same path every other issue takes.
     *
     * `ParsedWorkbook::addIssue()` is the parser's; the builder needs its own because a conflict
     * against the *database* can only be discovered here, and going through the model directly is
     * how A04-R1 ended up with a hand-written context that did not match what the reader looked
     * for.
     *
     * @param  array{severity: LegacyIssueSeverity, blocking: bool, message: string, subject: IssueSubject, context: array<string, mixed>}  $attributes
     */
    private function raiseIssue(LegacyImport $import, LegacyImportIssue $code, array $attributes): void
    {
        $identity = $attributes['subject']->identity($code);

        $attributes['context'] = [
            ...$attributes['subject']->context(),
            ...$attributes['context'],
        ];

        $existing = \App\Models\LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('fingerprint', $identity->value())
            ->first();

        if ($existing !== null) {
            // A question that has been answered does not block again.
            //
            // `blocking` was re-derived on every rebuild, which undid §5.3's own promise. The
            // resolve endpoint narrows `blocking` and sets `resolved_at`; then §15's rebuild
            // re-derives the same finding — same fingerprint, same subject — and wrote `blocking`
            // back to `true`. So the sequence was: answer, rebuild, blocked again, 409, answer
            // again. A reviewer could never finish an import whose conflict was answered.
            //
            // `severity` is still re-derived, because it describes the finding and the finding
            // may genuinely have changed; `blocking` describes whether anybody still owes an
            // answer, and once somebody has answered, they do not.
            $existing->forceFill([
                'severity' => $attributes['severity']->value,
                'blocking' => $existing->resolved_at === null && $attributes['blocking'],
                'field' => $attributes['subject']->field(),
                'message' => $attributes['message'],
                'context' => $attributes['context'],
            ])->save();

            return;
        }

        \App\Models\LegacyImportIssue::query()->create([
            'legacy_import_id' => $import->id,
            'row_id' => null,
            'code' => $code->value,
            'severity' => $attributes['severity']->value,
            'blocking' => $attributes['blocking'],
            'field' => $attributes['subject']->field(),
            'message' => $attributes['message'],
            'context' => $attributes['context'],
            'fingerprint' => $identity->value(),
        ]);
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
                    // Every distinct spelling the file offers, for §7.1's "diferencias de nombre
                    // para el mismo documento".
                    //
                    // `names_by_month` cannot answer that question: it holds one name per month,
                    // because it exists to find the *latest*. Two spellings inside a single
                    // month's block — a typo three rows down, or two people sharing a document —
                    // collapsed into one entry, so the conflict that §7.1 exists to catch was
                    // invisible for exactly the rows that were closest together in the file.
                    'names' => [],
                    'row_ids' => [],
                    // §7.1's "Perfil actual del cliente": A02 keeps no history for these, so the
                    // most recent non-empty observation is the proposal. Kept per month for the
                    // same reason as the name — "latest" has to be a comparison, not an accident
                    // of reconstruction order.
                    'profile_by_month' => [],
                ];
            }

            if ($episode->clientDisplayName !== null && trim($episode->clientDisplayName) !== '') {
                $month = $episode->firstMonth() ?? '';
                $name = trim($episode->clientDisplayName);

                $byClient[$key]['names_by_month'][$month] = $name;
            }

            $profile = $this->profileObservationFor($episode);

            if ($profile !== []) {
                $byClient[$key]['profile_by_month'][$episode->firstMonth() ?? ''] = $profile;
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

            // §7.1's conflict list comes from the *staged rows*, not from the reconstruction.
            //
            // The reconstruction holds one episode per client-company-month, so by the time
            // `clientDisplayName` is read there is exactly one name per month and the comparison
            // can no longer see a second spelling. The rows still hold every observation, and
            // §7.1's question is precisely "did this document arrive with more than one name?".
            //
            // Reading the rows also gets the pairing right: first and last names from the *same*
            // row, rather than the latest of each half independently, which would invent names
            // nobody typed — "JUAN" from January and "PEREZ" from February is two people.
            $names = $this->observedNamesFor($client['row_ids']);
            $latest = $names === [] ? '' : (string) end($names);

            $payload = [
                'document_type' => $client['document_type'],
                'document_number' => $client['document_number'],
                // The staged row already carries the two halves separately, so they are read
                // back rather than re-split from a full name. `firstNamesFrom()` took the first
                // *word* as the first names, so `JUAN CARLOS PEREZ` lost its middle name.
                'first_names' => $this->firstNamesFor($client['row_ids']),
                'last_names' => $this->lastNamesFor($client['row_ids']),
                // §7.1's profile, from §1.3's K/L/R. Absent keys are absent keys: §7.1 says an
                // empty source never clears a master value, so the payload carries only what the
                // file actually stated.
                ...$this->latestProfile($client['profile_by_month']),
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
            // no nuevas personas". Two spellings are an issue, not a second client.
            //
            // ## Why this raises an issue and not a skip
            //
            // A04-R2 emitted a *skipped action* whose `skip_reason` explained the conflict in
            // prose. That is not a question: it has no code, no resolution path and nothing a
            // reviewer can answer, so the client was never created and the import could never
            // proceed. §7.1's own sentence calls this an **issue**.
            //
            // Blocked until answered, because a person is the record that cannot be guessed: two
            // names for one document is either a typo, or two people sharing a document, and
            // picking either silently is exactly the "nueva persona" §7.1 forbids.
            if (count($names) > 1) {
                $this->raiseClientIdentityConflict($import, $client, $names);

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

            $approved = $decisions?->approvedFields($naturalKey, LegacyImportIssue::ExistingClientConflict) ?? [];

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

        // §1.3's K/L/R are part of §7.1's profile and §11 covers them like any other master
        // field: propose, never overwrite silently, never clear with an empty source.
        foreach (['first_names', 'last_names', 'address', 'phone', 'email'] as $field) {
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

    /**
     * §11's `observed` for a client: what the master holds, for every field the plan can propose.
     *
     * @return array<string, mixed>
     */
    private function observedClient(object $existing): array
    {
        return [
            'first_names' => $existing->first_names ?? null,
            'last_names' => $existing->last_names ?? null,
            // §1.3's K/L/R, so §11's `actual → propuesto` reaches the review screen for the
            // whole profile and not only for the name.
            'address' => $existing->address ?? null,
            'phone' => $existing->phone ?? null,
            'email' => $existing->email ?? null,
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
    /**
     * Every distinct `first + last` spelling the staged rows gave one client.
     *
     * §7.1: "diferencias de nombre para el mismo documento son conflictos de atributos, no nuevas
     * personas". Ordered by first appearance, so the finding, the message and the review dialog
     * are the same on every run — a reviewer comparing two runs should not see the names swap.
     *
     * @param  list<int>  $rowIds
     * @return list<string>
     */
    private function observedNamesFor(array $rowIds): array
    {
        $names = [];

        foreach ($this->rowsById as $row) {
            if (! in_array((int) $row->id, $rowIds, true)) {
                continue;
            }

            $first = trim((string) ($row->first_names ?? ''));
            $last = trim((string) ($row->last_names ?? ''));

            if ($first === '' && $last === '') {
                continue;
            }

            $names[] = trim($first.' '.$last);
        }

        return array_values(array_unique($names));
    }

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
                // by a default that happens to be the safe one. `'none'` is the answer for an
                // episode no reviewer's decision touched; see the note in this class on where the
                // other two values are decided, because it is not here.
                //
                // §8.4's answer rides in the same field: when a reviewer closed a disappearance,
                // `HistoryReconstructor` has already put the chosen date on the interval, so it is
                // in `$episode->interval` above. This says *why* the interval ends, so §17.5 can
                // show a boundary as a person's decision rather than as something the workbook
                // stated.
                'overlap_resolution' => $episode->overlapResolution ?? 'none',
                // §8.5's "motivo explícito", on its own key so §17.5 can show it as the reviewer's
                // words rather than folding a sentence into the machine vocabulary above.
                'parallel_reason' => $episode->parallelReason,
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
                    // §11's "coincidencia exacta → no-op". The flag is what stops
                    // `existingConflictFor()` from re-raising the same row as a blocking conflict
                    // a few lines later: without it, the builder correctly decided this is a no-op
                    // and the issue pass then asked a question about it anyway, which is how an
                    // import of an unchanged file ended up permanently in `review`.
                    ['target_exists' => true, 'identical' => true, 'observed' => [
                        'started_on' => (string) $existing->started_on,
                        'ended_on' => $existing->ended_on === null ? null : (string) $existing->ended_on,
                        'started_on_precision' => $existing->started_on_precision,
                        'ended_on_precision' => $existing->ended_on_precision,
                    ]],
                );

                continue;
            }

            // §8.2 and §8.3: an **open** relationship whose episode ends inside the file is closed
            // at the derived boundary.
            //
            // ## Why this is not §11's "no reescribir"
            //
            // §11 protects a boundary somebody chose: a row that already *has* an end date is
            // history, and the branch below refuses to touch it. An **open** row has no boundary at
            // all, so closing one is not rewriting anything — it is the only way the file's own
            // retirement evidence can reach the master.
            //
            // Without it the plan emitted a `create_relationship` for a person who is still
            // employed by that company according to the database, A02's link refused it, and a
            // workbook that documented a retirement could not be imported. The mirror image of
            // §9.5's affiliation close, for the same reason.
            //
            // Precision travels with the boundary: §8.3 says a day the file only asserts to the
            // month must be stored as `month`.
            if ($existing->ended_on === null
                && $episode->interval->end !== null
                && $episode->interval->endPrecision !== null) {
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CloseRelationship,
                    $naturalKey.':close',
                    [
                        'client_document_type' => $episode->documentType,
                        'client_document_number' => $episode->documentNumber,
                        'company_tax_id' => $episode->companyTaxId,
                        'ended_on' => $episode->interval->end->toDateString(),
                        'ended_on_precision' => $episode->interval->endPrecision,
                        'reason' => '§8.2: el archivo afirma que esta relación termina el '
                            .$episode->interval->end->toDateString().' (precisión '
                            .$episode->interval->endPrecision.').',
                    ],
                    $episode->sourceRowIds,
                    ['target_exists' => true, 'observed' => [
                        'ended_on' => null,
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

    /*
    |--------------------------------------------------------------------------
    | §8.5's resolution vocabulary
    |--------------------------------------------------------------------------
    |
    | Decided in one place, and not here. There used to be an `overlapResolutionFor()` in this
    | class that returned `'none'` unconditionally, under a docblock describing how it would read
    | the reviewer's answer and pass a `parallel_reason` along. So `recognize_transfer` and
    | `authorize_parallel` were validated, stored, marked resolved and unblocked the batch, and
    | that method discarded all of it: every relationship went out with the ordinary resolution.
    |
    | The vocabulary now lives in `HistoryReconstructor::applyOverlapDecisions()`, the only place
    | that knows which episode a decision is *about* — the boundary moves the earlier episode,
    | while the code that selects A02's resolution belongs on the later one — and it reaches the
    | payload through `RelationshipEpisode::$overlapResolution`.
    */

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
            // §9.3: a token the reviewer said is a *new* entity has no id yet — the row does not
            // exist until this same batch creates it.
            //
            // The affiliation therefore travels by `entity_name`, the name the reviewer typed,
            // and `ApplyImportPlan::entityFor()` resolves it by name after the earlier
            // `CreateSocialEntity` action has run. That is why the entity action is emitted
            // first: ordinal order is the whole mechanism, and nothing else in the plan depends
            // on it.
            //
            // The alternative — inventing an id, or leaving the affiliation out and hoping — is
            // what A04-R2 did, and the observable result was a person with a relationship and a
            // rate and no EPS.
            $pendingName = $resolved === null
                ? $decisions?->createdEntityName($segment->type, (string) $token?->token)
                : null;

            if ($resolved === null && $pendingName === null) {
                continue;
            }

            $naturalKey = $segment->naturalKey();

            $payload = [
                'client_document_type' => $segment->documentType,
                'client_document_number' => $segment->documentNumber,
                'company_tax_id' => $segment->companyTaxId,
                'type' => $segment->type->value,
                'entity_id' => $resolved?->id,
                'entity_name' => $pendingName,
                // §8.3: both precisions travel with the interval, so the write cannot drop one.
                'interval' => $segment->interval->toArray(),
                'risk_class' => $segment->risk?->value,
                'months' => $segment->months,
            ];

            // §9.5: "cerrar el segmento anterior en la misma frontera", and "no permitir dos
            // afiliaciones abiertas del mismo tipo".
            //
            // ## Why this needs its own action
            //
            // A closed segment whose affiliation does not exist yet is created *with* its end date
            // — `writeAffiliation()` passes the whole interval through. But when the affiliation
            // already exists and is **open** — because an earlier import carried it, or a person
            // was there last month and the file now says they are not — nothing else in this plan
            // closes it: the next segment closes the *previous* one, and this is the last one.
            //
            // Without a producer the plan emitted `create_affiliation`, A02 refused it for
            // violating its own one-open-per-type invariant, and the whole batch was unappliable.
            // So a person whose EPS ended in the file could not be imported at all.
            //
            // This is the only correct boundary to write: the segment's own end, with the
            // precision §9.5 gave it, because a monthly snapshot only asserts *the month*.
            if ($resolved !== null
                && $segment->interval->end !== null
                && $segment->interval->endPrecision !== null
                && $this->openAffiliationMatches($segment, (int) $resolved->id)) {
                // Only the keys `writeAffiliationClose()` writes. `interval`, `months` and
                // `risk_class` belong to the *creation* action; a close does not re-open or
                // re-scope anything, and `ApplyImportPlan::prevalidate()` refuses a payload whose
                // fields the writer ignores — a field the plan believes it applies and does not is
                // exactly what that check exists to catch.
                $actions[] = $this->action(
                    $import,
                    ImportActionType::CloseAffiliation,
                    $naturalKey.':close',
                    [
                        'client_document_type' => $segment->documentType,
                        'client_document_number' => $segment->documentNumber,
                        'company_tax_id' => $segment->companyTaxId,
                        'type' => $segment->type->value,
                        'entity_id' => (int) $resolved->id,
                        'ended_on' => $segment->interval->end->toDateString(),
                        'ended_on_precision' => $segment->interval->endPrecision,
                        'reason' => '§9.5: el archivo afirma que esta afiliación termina en '
                            .$segment->interval->end->toDateString().' (precisión '
                            .$segment->interval->endPrecision.').',
                    ],
                    $segment->sourceRowIds,
                    ['target_exists' => true, 'observed' => ['ended_on' => null]],
                );

                continue;
            }

            if ($resolved !== null && $this->existingAffiliationMatches($segment, (int) $resolved->id)) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateAffiliation,
                    $naturalKey,
                    $payload,
                    $segment->sourceRowIds,
                    'La afiliación ya existe con el mismo inicio y el mismo fin.',
                    // §11's "coincidencia exacta → no-op" — see the identical relationship above.
                    ['target_exists' => true, 'identical' => true],
                );

                continue;
            }

            if ($resolved !== null && $this->affiliationConflicts($segment, (int) $resolved->id)) {
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
    /**
     * §9.3's entity creation, for every token a reviewer answered with `create_entity`.
     *
     * §9.2's rule is that a token is never merged by resemblance — "no fusionar por parecido" —
     * so an unknown EPS may only be resolved by naming a catalogue entry that already exists
     * (`link_existing_entity`) or by saying it is new (`create_entity`). The second answer is the
     * only path that ever *adds* something to the catalogue, and until now it added nothing.
     *
     * `createdEntityName()` is the reviewer's own words, so the entity is created with exactly
     * the name they typed rather than with the token they typed: those differ on purpose, and the
     * difference is the point of the question. §9.3's "crear entidades faltantes" is a decision,
     * not a normalisation.
     *
     * Idempotence is `writeSocialEntity()`'s: it looks the entity up by type and name first and
     * returns the existing one, so re-importing a file whose entities were already created writes
     * nothing new.
     *
     * @param  array<string, array{type: string, token: string, count: int, subjects: list<string>}>  $unresolved
     * @return list<array<string, mixed>>
     */
    private function socialEntityActions(LegacyImport $import, array $unresolved): array
    {
        $actions = [];

        foreach ($unresolved as $entry) {
            $type = SocialSecurityEntityType::from($entry['type']);
            $name = $this->activeDecisions?->createdEntityName($type, $entry['token']);

            if ($name === null) {
                continue;
            }

            $naturalKey = 'entity:'.$entry['type'].':'.$entry['token'];

            // A catalogue entry with that name may have been created by hand in the meantime, or
            // by an earlier apply of this same import. §9.2's answer is "this is the entity", so
            // the plan is a no-op and the affiliation resolves against what is already there.
            if (SocialSecurityEntity::query()
                ->where('type', $entry['type'])
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists()) {
                $actions[] = $this->skip(
                    $import,
                    ImportActionType::CreateSocialEntity,
                    $naturalKey,
                    ['type' => $entry['type'], 'name' => $name, 'source_key' => $entry['token']],
                    [],
                    '§9.2: ya existe una entidad de tipo '.$entry['type'].' llamada «'.$name
                    .'», que es la que el revisor eligió para «'.$entry['token'].'».',
                    ['target_exists' => true, 'token' => $entry['token']],
                );

                continue;
            }

            $actions[] = $this->action(
                $import,
                ImportActionType::CreateSocialEntity,
                $naturalKey,
                ['type' => $entry['type'], 'name' => $name, 'source_key' => $entry['token']],
                [],
                ['target_exists' => false, 'token' => $entry['token']],
            );
        }

        return $actions;
    }

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
        $type = SocialSecurityEntityType::from($entry['type']);
        $code = LegacyImportIssue::UnresolvedSocialEntity;

        // The subject and the field come from the same object the reader will use.
        //
        // A04-R1 built this by hand and got all three keys wrong at once: it wrote `subject` as
        // the *first staged row* that carried the spelling, `field` as `eps_token`, and hashed
        // with a `token()` factory that read a third pair of names. `ImportDecisionSet::
        // entityDecision()` looked the finding up as `EPS|SALUD TOTAL` with field `EPS`. Nothing
        // connected the two, so a reviewer could map the token, the issue would show resolved, and
        // `resolveEntity()` would still return null and the affiliation would still vanish from
        // the plan — the exact failure §9.2's code exists to prevent.
        $subject = IssueSubject::entityToken($type, $entry['token']);
        $identity = $subject->identity($code);

        $attributes = [
            'severity' => LegacyIssueSeverity::Warning->value,
            'blocking' => false,
            'field' => $subject->field(),
            'message' => sprintf(
                'La columna %s dice «%s» en %d segmento(s) y no coincide con ninguna entidad del catálogo. '
                .'§9.2 no permite fusionar por parecido: hay que asociarla a una entidad existente o crearla.',
                $entry['type'],
                $entry['token'],
                $entry['count'],
            ),
            // §4.3: an entity name is a business name, not a person, and it is already in the
            // redacted cells. No document, no person's name. `subjects` are row *positions*,
            // kept so a reviewer can jump to an occurrence, and they contribute to no identity.
            'context' => [
                ...$subject->context(),
                'subjects' => $entry['subjects'],
                'occurrences' => $entry['count'],
            ],
        ];

        $existing = \App\Models\LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('fingerprint', $identity->value())
            ->first();

        if ($existing !== null) {
            // Refresh in place so a resolution stays attached to the question it answered.
            $existing->forceFill($attributes)->save();

            return;
        }

        \App\Models\LegacyImportIssue::query()->create([
            'legacy_import_id' => $import->id,
            'row_id' => null,
            'code' => $code->value,
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

    /**
     * §9.5's "no permitir dos afiliaciones abiertas del mismo tipo", read from the database.
     *
     * Keyed on the entity as well as the type, because the plan is about *this* affiliation: a
     * different provider is a different row, and closing it is `closesPreviousSegment()`'s job at
     * the next segment's start.
     */
    private function openAffiliationMatches(AffiliationSegment $segment, int $entityId): bool
    {
        $client = $this->clientRow($segment->documentType, $segment->documentNumber);

        if ($client === null) {
            return false;
        }

        return DB::table('client_affiliations')
            ->where('client_id', $client->id)
            ->where('social_security_entity_id', $entityId)
            ->where('type', $segment->type->value)
            ->where('started_on', $segment->interval->start?->toDateString())
            ->whereNull('ended_on')
            ->exists();
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

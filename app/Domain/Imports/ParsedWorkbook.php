<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * What a parse produced: the rows, the issues, and the aggregates that describe them.
 *
 * ## Deduplication happens here, not in the plan builder
 *
 * §7.3's two duplicate classes depend on nothing but the parsed rows — a natural key and a
 * fingerprint — so doing it here makes the answer a property of the file rather than of the
 * database it is compared against. The plan builder then sees one row per observation, which
 * is what makes §12's idempotency meaningful: applying the same file twice cannot double a
 * row that the parser already collapsed.
 *
 * ## `blockedRows()` is the gate §17.5's disabled Apply button reads
 *
 * The frontend asks this rather than counting issues, because a warning and a blocker look
 * identical in a list and are not identical in fact. `missing_monthly_value` and an invalid
 * email are warnings and do not appear here; a missing document does.
 */
final class ParsedWorkbook
{
    /** @var list<SourcePersonRow> */
    private array $rows = [];

    /** @var list<ParsedIssue> */
    private array $issues = [];

    /** @var array<string, ParsedBlock> block key => block */
    private array $blocks = [];

    /** @var list<ParsedSheet> */
    private array $sheets = [];

    /** @var array<string, string> month key => the sheet name that claimed it */
    private array $claimedMonths = [];

    /** @var array<string, array{row: SourcePersonRow, count: int}> */
    private array $fingerprints = [];

    /**
     * Folded company name => every NIT base seen under it. §7.2's `company_identity_conflict`.
     *
     * @var array<string, array<string, CompanyTitle>>
     */
    private array $companiesByName = [];

    public function addSheet(RawSheet $sheet): void
    {
        $this->sheets[] = new ParsedSheet(
            $sheet->name,
            $sheet->month?->key(),
            $sheet->month?->label(),
        );
    }

    /**
     * Whether a month has already been read.
     *
     * §6.1: duplicate sheets for one month are a blocker. The caller reports it, and this
     * method only reports that it is not the first.
     */
    public function claimsMonth(string $monthKey, string $sheetName): bool
    {
        if (isset($this->claimedMonths[$monthKey])) {
            return true;
        }

        $this->claimedMonths[$monthKey] = $sheetName;

        return false;
    }

    public function registerBlock(string $blockKey, CompanyTitle $company, RawSheet $sheet): void
    {
        $this->observeCompany($company);

        if (isset($this->blocks[$blockKey])) {
            return;
        }

        $this->blocks[$blockKey] = new ParsedBlock(
            $blockKey,
            $company->blockKey(),
            $company->name,
            $company->taxId,
            $company->verificationDigit,
            $company->arlProvider,
            $sheet->name,
            $sheet->month?->key(),
        );
    }

    /**
     * §7.2's `company_identity_conflict`: one name, two NIT bases.
     *
     * ## Why this needed a whole-file view
     *
     * §7.2 states the case and §7.2 also states the rule it is protecting: "No usar nombre
     * parecido para fusionar empresas sin aprobación." `DISTRIUTIL` appears in the real file
     * with two NIT bases that are visually almost identical — `900123456` and `900123465`, or
     * whatever the actual pair is. Both parse cleanly. Both carry the same name. Neither is
     * invalid, so neither raises `invalid_company_tax_id`. Nothing anywhere compared them.
     *
     * The consequence of not comparing them is worse than a missing warning. The plan would
     * create two companies that are the same employer, and every relationship would be split
     * across both — so §8's history would show a person transferring employers mid-year when
     * they only ever had one. The audit is right that this must be a blocker and that the real
     * file's case must be asserted by the verifier.
     *
     * ## Why name-plus-NIT and not name alone
     *
     * Two companies sharing a name is legal and unremarkable (`ANDINA` in Bogotá and Medellín).
     * §7.2's own wording is "mismo nombre + NIT base diferente" — the *combination* is the
     * signal. So the key is the folded name and the finding fires when one folded name carries
     * two or more distinct NIT bases, which is a question about the source's consistency rather
     * than about the database.
     *
     * ## Why not raise it while reading a block
     *
     * A04-R1 had `company_identity_conflict` in the enum, in §18's label list and in
     * `ImportDecisionSet::companyNitFor()` — a consumer with no producer, which is how the case
     * went unnoticed. The comparison cannot happen inside `CompanyTitle::parse()` either, because
     * one title sees one NIT. It needs the set of titles the workbook contains, so it lives here,
     * at the end of the read, where that set is complete.
     *
     * Names are folded with `SheetMonth::fold()` so `Distriutil S.A.S.` and `DISTRIUTIL` are one
     * name; §4.3 means the folded form goes in the context and the original does not.
     */
    private function observeCompany(CompanyTitle $company): void
    {
        if ($company->name === null || $company->taxId === null) {
            return;
        }

        $this->companiesByName[SheetMonth::fold($company->name)][$company->taxId] ??= $company;
    }

    /**
     * Raise §7.2's conflict for every name that carries more than one NIT base.
     *
     * Called once, after the whole file has been read. Each conflicting title gets its own
     * finding, keyed on its own block, so a reviewer answers about one title and the answer
     * sticks to it — §5.3's one dialog per subject.
     */
    public function raiseCompanyIdentityConflicts(): void
    {
        foreach ($this->companiesByName as $foldedName => $byNit) {
            if (count($byNit) < 2) {
                continue;
            }

            // Sorted so the message and the finding order are the same on every run: a reviewer
            // comparing two runs should not see the dialogs swap places for no reason.
            $nits = array_keys($byNit);
            sort($nits, SORT_STRING);

            $others = array_values(array_diff($nits, [$nits[0]]));

            foreach ($byNit as $taxId => $title) {
                $this->addIssue(
                    LegacyImportIssue::CompanyIdentityConflict,
                    null,
                    null,
                    null,
                    [
                        'severity' => LegacyIssueSeverity::Error,
                        'blocking' => true,
                        'subject' => IssueSubject::companyBlock(
                            $title->blockKey(),
                            ParsedWorkbook::fieldFor(LegacyImportIssue::CompanyIdentityConflict),
                        ),
                        'message' => 'El mismo nombre de empresa «'.$title->name.'» aparece con '.$taxId
                            .' y también con '.implode(', ', $others).' en este archivo. '
                            .'§7.2 no permite fusionar por parecido: hay que decidir si son la misma '
                            .'empresa o dos distintas.',
                        // §4.3: a company name is a business name, not a person, and NITs are
                        // already in the redacted cells. No individual's document or name appears.
                        'context' => [
                            'company_block_key' => $title->blockKey(),
                            'company_name' => $title->name,
                            'folded_name' => $foldedName,
                            'company_tax_id' => $taxId,
                            'other_tax_ids' => $others,
                        ],
                    ],
                );
            }
        }
    }

    /**
     * Stage a row, collapsing §7.3's exact duplicates.
     *
     * Two rows with the same fingerprint are the same observation written twice: the
     * `duplicate_exact_row` warning, once, and the second one never becomes a plan action.
     * Two rows with the same natural key but different fingerprints are a genuine
     * disagreement about one person in one month — `duplicate_conflicting_row`, and blocking,
     * because §7.3 says so and because choosing between them would be guessing.
     */
    public function addRow(SourcePersonRow $row): void
    {
        // §9.4 rule 3, raised per *block* rather than per line.
        //
        // The title and the header disagree about one company's ARL provider, so every person
        // under that title has the same disagreement. §5.3 is explicit that a question about a
        // company gets one dialog: "this NIT is wrong" asked twenty-four times is how a review
        // takes a day and gets abandoned halfway. So `addBlockIssue()` deduplicates by the
        // company key, and the first line of the block raises it.
        $this->addBlockIssue(
            $row,
            LegacyImportIssue::CompanyArlMetadataConflict,
            static function () use ($row): ?array {
                $conflict = $row->arlProviderConflict();

                if ($conflict === null) {
                    return null;
                }

                [$title, $header] = $conflict;

                return [
                    'severity' => LegacyIssueSeverity::Error,
                    'blocking' => true,
                    'message' => 'El título de la empresa dice que su ARL es «'.$title.'» y la columna de la fila dice «'
                        .$header.'». §9.4 da prioridad al título, así que hace falta confirmar cuál vale.',
                    'context' => [
                        'title_provider' => $title,
                        'header_provider' => $header,
                    ],
                ];
            },
        );

        $fingerprint = $row->fingerprint();
        $naturalKey = $row->naturalKey();

        if (isset($this->fingerprints[$fingerprint])) {
            $this->fingerprints[$fingerprint]->sawAnother();

            $this->addIssue(
                LegacyImportIssue::DuplicateExactRow,
                $row->sheetName,
                $row->sourceRowNumber,
                $row,
                [
                    'severity' => LegacyIssueSeverity::Warning,
                    'blocking' => false,
                    'message' => 'La fila '.$row->sourceRowNumber.' de «'.$row->sheetName.'» repite exactamente una fila '
                        .'ya leída del mismo mes. Se conserva una sola.',
                    'context' => ['sheet' => $row->sheetName, 'row' => $row->sourceRowNumber],
                ],
            );

            return;
        }

        // The natural key was seen before with a different payload.
        if (isset($this->naturalKeys[$naturalKey])) {
            $this->addIssue(
                LegacyImportIssue::DuplicateConflictingRow,
                $row->sheetName,
                $row->sourceRowNumber,
                $row,
                [
                    'severity' => LegacyIssueSeverity::Error,
                    'blocking' => true,
                    'message' => 'La fila '.$row->sourceRowNumber.' de «'.$row->sheetName.'» dice lo mismo sobre '
                        .$row->displayName().' que otra fila del mismo mes, pero con datos distintos. '
                        .'Hay que decidir cuál vale.',
                    'context' => [
                        'sheet' => $row->sheetName,
                        'row' => $row->sourceRowNumber,
                        'conflicts_with_row' => $this->naturalKeys[$naturalKey],
                    ],
                ],
            );

            // Still staged: §7.3's conflict is a decision about two rows, and the review
            // screen has to be able to show both to make it. The plan is what refuses.
            $this->rows[] = $row;
            $this->fingerprints[$fingerprint] = new ParsedFingerprint($row, 1);

            return;
        }

        $this->naturalKeys[$naturalKey] = $row->sourceRowNumber;
        $this->rows[] = $row;
        $this->fingerprints[$fingerprint] = new ParsedFingerprint($row, 1);

        foreach ($row->problems() as $code => $message) {
            $this->addIssue(
                self::issueCodeFor($code),
                $row->sheetName,
                $row->sourceRowNumber,
                $row,
                [
                    'severity' => self::severityFor($code),
                    'blocking' => $row->isBlocked(),
                    'message' => $message,
                    'context' => ['sheet' => $row->sheetName, 'row' => $row->sourceRowNumber],
                ],
            );
        }
    }

    /** @var array<string, int> natural key => the row number that first carried it */
    private array $naturalKeys = [];

    /** @var array<string, true> block keys whose block-level finding has already been raised */
    private array $blockIssuesRaised = [];

    /**
     * Raise a finding about a company block, once.
     *
     * §5.3: "Una fila por hallazgo, no un hallazgo por fila". A finding whose subject is a
     * company — its NIT, its ARL metadata, its identity conflict — is asked once for the block,
     * however many people are under it.
     *
     * The context carries `company_block_key` and no `source_key`, which is what makes
     * `StageLegacyImport::identityFor()` classify it as a company finding and key it on the
     * company rather than on the line that happened to raise it. That key is also what lets a
     * resolution survive the next re-parse: a line-level key would move with the line.
     *
     * @param  callable(): ?array{severity: LegacyIssueSeverity, blocking: bool, message: string, context: array<string, mixed>}|null  $attributes
     */
    private function addBlockIssue(SourcePersonRow $row, LegacyImportIssue $code, callable $attributes): void
    {
        $blockKey = $row->company->blockKey();

        if (isset($this->blockIssuesRaised[$blockKey.'|'.$code->value])) {
            return;
        }

        $resolved = $attributes();

        if ($resolved === null) {
            return;
        }

        $this->blockIssuesRaised[$blockKey.'|'.$code->value] = true;

        $this->addIssue($code, $row->sheetName, $row->sourceRowNumber, null, [
            ...$resolved,
            'subject' => IssueSubject::companyBlock(
                $blockKey,
                self::fieldFor($code),
            ),
            'context' => [
                ...$resolved['context'],
                'company_block_key' => $blockKey,
                'company_tax_id' => $row->company->taxId,
                'sheet' => $row->sheetName,
                'row' => $row->sourceRowNumber,
            ],
        ]);
    }

    /**
     * Raise a finding, deriving its identity from what the caller knows.
     *
     * ## Why the subject is derived here and not by the reader
     *
     * A04-R1 handed the reader a free-form context and let it guess the finding's kind. The
     * reconstruction wrote contexts with none of the keys the reader looked for, so every
     * disappearance fell through to the last branch and hashed as `subject=''` — which made 13
     * findings into 1 and then hit the unique index on the 2nd. The subject has to be named by
     * whoever knows the finding, because only they know whether it is about a line, a company
     * block, a token or an interval.
     *
     * A caller that knows better may pass `'subject' => new IssueSubject…` and it wins. That is
     * how {@see HistoryReconstruction} names its interval findings.
     *
     * The `field` is derived from the code by {@see fieldFor()} rather than passed as `'field'`
     * because in A04-R1 it was passed as `'field' => $code`, and `ImportDecisionSet::dateFor()`
     * looked the same finding up under `affiliation_date` — a resolution that saved, set
     * `resolved_at`, and was never read. One map, one name, both halves.
     *
     * @param  array{subject?: IssueSubject, field?: string|null, severity?: LegacyIssueSeverity, blocking?: bool, message?: string, context?: array<string, mixed>}  $attributes
     */
    public function addIssue(
        LegacyImportIssue $code,
        ?string $sheet = null,
        ?int $row = null,
        ?SourcePersonRow $sourceRow = null,
        array $attributes = [],
    ): void {
        $field = array_key_exists('field', $attributes)
            ? $attributes['field']
            : self::fieldFor($code);

        $this->issues[] = new ParsedIssue(
            $code,
            $sheet,
            $row,
            $sourceRow,
            $attributes['severity'] ?? LegacyIssueSeverity::Warning,
            (bool) ($attributes['blocking'] ?? false),
            (string) ($attributes['message'] ?? ''),
            $attributes['subject'] ?? self::subjectFor($code, $sourceRow, $field),
            (array) ($attributes['context'] ?? []),
        );
    }

    /**
     * The canonical field for a code — which column or record the question is about.
     *
     * These are the names `ImportDecisionSet` looks up. They are §1.3's column contract, written
     * in the importer's own vocabulary so no consumer has to remember a letter.
     *
     * @return string|null null for findings that are about one subject with no column in it
     */
    public static function fieldFor(LegacyImportIssue $code): ?string
    {
        return match ($code) {
            // §1.3: F is the affiliation date, H the document, G the monthly value, R the email.
            LegacyImportIssue::InvalidAffiliationDate => 'affiliation_date',
            LegacyImportIssue::MissingMonthlyValue => 'monthly_value',
            LegacyImportIssue::InvalidClientDocument => 'document_number',
            LegacyImportIssue::InvalidEmail => 'email',
            LegacyImportIssue::InvalidCompanyTaxId => 'company_tax_id',
            LegacyImportIssue::CompanyIdentityConflict => 'company_tax_id',
            LegacyImportIssue::CompanyVerificationDigitConflict => 'company_verification_digit',
            LegacyImportIssue::UnknownRiskToken => 'arl_risk_class',
            LegacyImportIssue::AffiliationEntityUnknown => 'entity_token',
            LegacyImportIssue::DuplicateConflictingRow => null,
            LegacyImportIssue::CompanyArlMetadataConflict => 'arl_token',
            LegacyImportIssue::ExistingRateConflict => 'monthly_amount_cop',
            LegacyImportIssue::ExistingClientConflict,
            LegacyImportIssue::ClientIdentityConflict => 'document_number',
            // Interval and whole-file findings are about one subject with no column in it.
            default => null,
        };
    }

    /**
     * The canonical subject for a finding, when the caller did not name one.
     *
     * A line-level finding is keyed by its position; a block-level one by the block; anything
     * else by its sheet, or the whole file when it has none.
     */
    private static function subjectFor(
        LegacyImportIssue $code,
        ?SourcePersonRow $sourceRow,
        ?string $field,
    ): IssueSubject {
        if ($sourceRow !== null) {
            return IssueSubject::cell($sourceRow->sourceKey(), $field);
        }

        return IssueSubject::companyBlock('*', $field);
    }

    /**
     * §18's stable codes, from the short names a row uses.
     *
     * Strict on purpose. An earlier version fell back to `unresolved_social_entity` for
     * anything it did not recognise, which quietly filed 77 invalid email addresses as
     * questions about an EPS — and a mis-filed code is worse than a missing one, because a
     * reviewer filters on it and never finds the thing it names.
     *
     * So a code that reaches here without being in the enum is a programming error and throws.
     * `LegacyImportIssue::from()` is the only way a string becomes a code, which is what makes
     * §18's "el código es contrato API/UI" true rather than aspirational.
     */
    private static function issueCodeFor(string $code): LegacyImportIssue
    {
        return LegacyImportIssue::from($code);
    }

    private static function severityFor(string $code): LegacyIssueSeverity
    {
        // §7.1 makes an invalid email a warning that is skipped rather than a blocker; the
        // rest of a row's problems are errors, and `missing_monthly_value` is the other
        // warning because §10 says it produces no rate rather than failing the import.
        return in_array($code, ['missing_monthly_value', 'invalid_email'], true)
            ? LegacyIssueSeverity::Warning
            : LegacyIssueSeverity::Error;
    }

    /** @return list<SourcePersonRow> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<ParsedIssue> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return array<string, ParsedBlock> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @return list<ParsedSheet> */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /** @return list<SourcePersonRow> */
    public function blockedRows(): array
    {
        return array_values(array_filter($this->rows, static fn (SourcePersonRow $row): bool => $row->isBlocked()));
    }

    public function blockerCount(): int
    {
        return count(array_filter($this->issues, static fn (ParsedIssue $issue): bool => $issue->blocking));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->issues, static fn (ParsedIssue $issue): bool => ! $issue->blocking));
    }

    /**
     * §19's fingerprint: the aggregates, and nothing that identifies a person.
     *
     * This is the only shape in which the parse is allowed to leave the process, and it has
     * no field that could hold a name, a document or a credential. The verifier script prints
     * exactly this and nothing else.
     *
     * @return array<string, int>
     */
    public function fingerprint(): array
    {
        $identities = [];
        $companies = [];

        foreach ($this->rows as $row) {
            if ($row->document->isUsable()) {
                $identities[$row->document->type->value.'|'.$row->document->number] = true;
            }

            if ($row->company->name !== null) {
                // §19 counts *logical* company names, so this is by folded name and not by
                // NIT: the same employer written two ways is one name, and the one NIT that
                // legitimately appears twice is a separate conflict found elsewhere.
                $companies[SheetMonth::fold($row->company->name)] = true;
            }
        }

        $monthlySheets = array_filter($this->sheets, static fn (ParsedSheet $sheet): bool => $sheet->monthKey !== null);

        return [
            'monthly_sheets' => count($monthlySheets),
            'blocks' => count($this->blocks),
            'rows' => count($this->rows),
            'document_identities' => count($identities),
            'company_names' => count($companies),
            'blocking_issues' => $this->blockerCount(),
            'warning_issues' => $this->warningCount(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function countsByIssueCode(): array
    {
        $counts = [];

        foreach ($this->issues as $issue) {
            $counts[$issue->code->value] = ($counts[$issue->code->value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}

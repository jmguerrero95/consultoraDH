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
                    'field' => $code,
                    'context' => ['sheet' => $row->sheetName, 'row' => $row->sourceRowNumber],
                ],
            );
        }
    }

    /** @var array<string, int> natural key => the row number that first carried it */
    private array $naturalKeys = [];

    public function addIssue(
        LegacyImportIssue $code,
        ?string $sheet = null,
        ?int $row = null,
        ?SourcePersonRow $sourceRow = null,
        array $attributes = [],
    ): void {
        $this->issues[] = new ParsedIssue(
            $code,
            $sheet,
            $row,
            $sourceRow,
            $attributes['severity'] ?? LegacyIssueSeverity::Warning,
            (bool) ($attributes['blocking'] ?? false),
            (string) ($attributes['message'] ?? ''),
            $attributes['field'] ?? null,
            $attributes['context'] ?? [],
        );
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

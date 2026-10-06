<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\LegacyImportRow;

/**
 * One person, on one sheet, in one company block.
 *
 * ## Nothing here writes and nothing here decides
 *
 * This is the parser's output and the staging table's input, and it carries *candidates* and
 * *problems* rather than values to save. Every field that could have been resolved by a
 * guess instead carries a problem, because the alternative — resolving it — is the specific
 * failure the whole review stage exists to prevent.
 *
 * ## The fingerprint is what §7.3 and §12 hinge on
 *
 * `fingerprint()` is a SHA-256 over the semantic payload: company identity, month,
 * document, date, amount, the four affiliation tokens and the risk. Two rows with the same
 * fingerprint are the same observation written twice, and §7.3 collapses them. It is built
 * from *normalised* values, so `12.345.678` and `12345678` produce one hash and the real
 * file's harmless spelling differences do not all arrive as conflicts.
 *
 * It is deliberately *not* built from the raw cells. A row that differs only in whitespace
 * is not a conflicting duplicate, it is the same row, and hashing the raw text would turn
 * 2.560 real rows into a review queue full of nothing.
 *
 * ## The amount is parsed here and judged later
 *
 * §10 is explicit that this part is deterministic: a blank is a warning and no rate, and a
 * value that is zero, negative or not a number is a blocker. All three outcomes are kept
 * apart — `amount` may be null with `amountProblem` naming which one it was — because
 * collapsing them into one "invalid" loses the difference between "this person has no salary
 * line this month" and "somebody typed a minus sign".
 */
final readonly class SourcePersonRow
{
    /**
     * @param  int|null  $stagedRowId  `legacy_import_rows.id`, when this row came from staging
     * @param  array<string, SourceEntityToken>  $entities  by `SocialSecurityEntityType` value
     * @param  array<string, string>  $metadata  operator, payroll, reference, phone, address
     */
    private function __construct(
        public string $sheetName,
        public string $sheetMonthKey,
        public int $sourceRowNumber,
        public CompanyTitle $company,
        public int $blockIndex,
        public string $blockKey,
        public SourceDocument $document,
        public ?string $firstNames,
        public ?string $lastNames,
        public SourceAffiliationDate $affiliationDate,
        public ?float $amount,
        public ?string $amountProblem,
        public array $entities,
        public RiskColumns $risk,
        public ?string $novelty,
        public ?RetirementNote $retirement,
        public ?string $email,
        public ?string $emailProblem,
        public array $metadata,
        /**
         * §13's provenance link.
         *
         * NULL on the parse path, where the row has not been staged yet — the stager creates it.
         * Set on the rebuild path, where the row came out of `legacy_import_rows` and this is
         * what makes `legacy_import_actions.source_row_ids` point at real rows instead of at
         * Excel row numbers.
         *
         * The audit's finding: "`source_row_ids` holds **Excel row numbers**, not
         * `legacy_import_rows.id` … Values collide across the 10 sheet-months; `array_unique`
         * makes it lossy. No DTO carries the staging id." `StageLegacyImport` collected the ids
         * into `$rowIds` and then never passed them on, so this field is what finally carries
         * them.
         */
        public ?int $stagedRowId = null,
    ) {}

    /**
     * Build a row from a header's resolved columns and the raw cells beneath it.
     *
     * @param  array<string, float|int|string|null>  $cells  by column letter
     */
    public static function fromCells(
        WorkbookHeader $header,
        SheetMonth $sheet,
        int $rowNumber,
        CompanyTitle $company,
        int $blockIndex,
        array $cells,
        SensitiveSourceRedactor $redactor,
    ): self {
        $sheetName = $sheet->sheetName;

        $cell = static fn (string $field): string => self::text($cells[$header->column($field) ?? ''] ?? null);

        // The risk and job columns are read positionally, not by field name, because §1.1 and
        // §9.4 are explicit that P/Q are swapped between blocks and that their headings lie.
        [$p, $q] = [self::text($cells['P'] ?? null), self::text($cells['Q'] ?? null)];

        // The novelty is redacted once here and the retirement note reads the same redacted
        // text, so the evidence stored on the row and the evidence the note parses can never
        // disagree — and a credential in the novelty column cannot reach `evidence`.
        $novelty = $redactor->redact($cell('novelty'), $sheetName, $rowNumber);
        $retirement = RetirementNote::detect($cell('novelty'), $sheet, $redactor, $sheetName, $rowNumber);

        [$amount, $amountProblem] = self::parseAmount($cells[$header->column('monthly_amount') ?? ''] ?? null);
        [$email, $emailProblem] = self::judgeEmail($cell('email'));

        $metadata = array_filter([
            'operator' => self::cleanName($cell('operator')),
            'payroll' => self::cleanName($cell('payroll')),
            'reference' => self::cleanName($cell('source_reference')),
            'phone' => self::cleanName($cell('phone')),
            'address' => self::cleanName($cell('address')),
            'job_title' => RiskColumns::decide($p, $q)->jobTitle,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return new self(
            $sheetName,
            $sheet->key(),
            $rowNumber,
            $company,
            $blockIndex,
            $company->blockKey(),
            SourceDocument::resolve($cell('document'), $redactor, $sheetName, $rowNumber),
            self::cleanName($cell('first_names')),
            self::cleanName($cell('last_names')),
            SourceAffiliationDate::read($cells[$header->column('affiliation_date') ?? ''] ?? null, $redactor, $sheetName, $rowNumber),
            $amount,
            $amountProblem,
            [
                SocialSecurityEntityType::Eps->value => SourceEntityToken::read($cell('eps'), SocialSecurityEntityType::Eps, $redactor, $sheetName, $rowNumber),
                SocialSecurityEntityType::Afp->value => SourceEntityToken::read($cell('afp'), SocialSecurityEntityType::Afp, $redactor, $sheetName, $rowNumber),
                SocialSecurityEntityType::Ccf->value => SourceEntityToken::read($cell('ccf'), SocialSecurityEntityType::Ccf, $redactor, $sheetName, $rowNumber),
                // §9.4's second priority: the ARL provider comes from the company title, and
                // falls back to a header that *names* an ARL column.
                //
                // ## Not column R
                //
                // A04-R1 read `cells['R']` here, positionally, on the theory that the ARL was the
                // last column. §1.3 settles it: K/L/R are **dirección / teléfono / correo**. So R
                // is a person's email address, and every ARL read from it was reading a secret
                // belonging to a named individual into a company's social-security history — where
                // it became an affiliation, a search result and, on a block-level finding, part
                // of a reviewer's screen.
                //
                // Nothing positional can stand in for a header. A header that says `ARL` is
                // evidence; a column's position is not, and §1.1 says the headings lie anyway.
                SocialSecurityEntityType::Arl->value => SourceEntityToken::read(
                    self::text($cells[$header->arlColumn() ?? ''] ?? null),
                    SocialSecurityEntityType::Arl,
                    $redactor,
                    $sheetName,
                    $rowNumber,
                ),
            ],
            RiskColumns::decide($p, $q),
            $novelty === '' ? null : $novelty,
            $retirement,
            $email,
            $emailProblem,
            $metadata,
        );
    }

    /**
     * Rebuild a row from already-normalised values.
     *
     * ## Why the reconstructor needs this
     *
     * §15's `rebuild-plan` has to run after a resolution without re-uploading or re-parsing, so
     * the history has to be rebuildable from what is in the database. That means the staged row
     * — not the parser's row — has to be an acceptable input, and duplicating the
     * normalisation rules in a second place would let the two drift and produce a plan that
     * disagreed with the file it came from.
     *
     * So the two paths converge on this one type: `fromCells()` for the parser and
     * `fromValues()` for the staged row, with the same invariants. The fields that a staged row
     * cannot supply are passed explicitly rather than guessed.
     *
     * @param  array<string, SourceEntityToken>  $entities
     * @param  array<string, string>  $metadata
     * @param  int|null  $stagedRowId  §13's provenance link; see the constructor
     */
    public static function fromValues(
        string $sheetName,
        string $sheetMonthKey,
        int $sourceRowNumber,
        CompanyTitle $company,
        int $blockIndex,
        SourceDocument $document,
        ?string $firstNames,
        ?string $lastNames,
        SourceAffiliationDate $affiliationDate,
        ?float $amount,
        ?string $amountProblem,
        array $entities,
        RiskColumns $risk,
        ?string $novelty,
        ?RetirementNote $retirement,
        ?string $email,
        ?string $emailProblem,
        array $metadata = [],
        ?int $stagedRowId = null,
    ): self {
        return new self(
            $sheetName,
            $sheetMonthKey,
            $sourceRowNumber,
            $company,
            $blockIndex,
            $company->blockKey(),
            $document,
            $firstNames,
            $lastNames,
            $affiliationDate,
            $amount,
            $amountProblem,
            $entities,
            $risk,
            $novelty,
            $retirement,
            $email,
            $emailProblem,
            $metadata,
            $stagedRowId,
        );
    }

    /**
     * The line's identity by position, stable across re-parses.
     *
     * The same value `LegacyImportRow::sourceKeyFor()` computes, exposed here so the stager and
     * the reconstructor cannot drift apart: an issue keyed on one hash and a row keyed on
     * another would make a resolution unreachable for reasons nobody can see.
     */
    public function sourceKey(): string
    {
        return LegacyImportRow::sourceKeyFor(
            $this->sheetName,
            $this->sheetMonthKey,
            $this->sourceRowNumber,
            $this->blockIndex,
        );
    }

    /**
     * §13's provenance for an action derived from this row.
     *
     * `legacy_import_rows.id` when the row came from staging, and **an empty list** otherwise —
     * never the Excel row number. A bare row number collides across the ten sheet-months and
     * matches nothing in the database, so it is not a weaker link but a wrong one.
     *
     * @return list<int>
     */
    public function provenanceIds(): array
    {
        return $this->stagedRowId === null ? [] : [$this->stagedRowId];
    }

    /**
     * §7.1's natural key: company, month, document.
     *
     * §8.1 adds the start date to the *episode* key, but a row's own identity is the first
     * three. Two rows in the same month for the same person at the same employer are the
     * same observation twice, whether the second is a paste error or a genuine second
     * payroll line — and §7.3 tells those apart by comparing payloads.
     */
    public function naturalKey(): string
    {
        return implode('|', [
            $this->company->identityKey(),
            $this->sheetMonthKey,
            $this->document->type?->value ?? '?',
            $this->document->number !== '' ? $this->document->number : ('row:'.$this->sourceRowNumber),
        ]);
    }

    /**
     * The payload §7.3 compares to tell an exact duplicate from a conflicting one.
     *
     * @return array<string, string|null>
     */
    public function normalizedPayload(): array
    {
        $payload = [
            'company' => $this->company->identityKey(),
            'month' => $this->sheetMonthKey,
            'document_type' => $this->document->type?->value,
            'document_number' => $this->document->number,
            'first_names' => $this->firstNames,
            'last_names' => $this->lastNames,
            'affiliation_date' => $this->affiliationDate->isoDate,
            'affiliation_date_precision' => $this->affiliationDate->precision,
            'amount' => $this->amount === null ? null : number_format($this->amount, 2, '.', ''),
            'risk_class' => $this->risk->riskClass === null ? null : (string) $this->risk->riskClass,
            'retirement_month' => $this->retirement?->month?->key(),
            'retirement_day_count' => $this->retirement === null ? null : (string) $this->retirement->dayCount,
        ];

        foreach ($this->entities as $type => $token) {
            $payload['entity_'.$type] = $token->fingerprintPart();
        }

        return $payload;
    }

    /** SHA-256 over the normalised payload, so equivalent spellings hash the same. */
    public function fingerprint(): string
    {
        $parts = [];

        foreach ($this->normalizedPayload() as $key => $value) {
            // `null` and `''` must not produce different hashes for the same observation:
            // one is a blank cell and the other an absent one, and they mean the same here.
            $parts[] = $key.'='.($value === null ? '' : trim((string) $value));
        }

        return hash('sha256', implode("\n", $parts));
    }

    /** Whether §7.3 considers another row the same observation as this one. */
    public function hasIdenticalPayloadTo(self $other): bool
    {
        return $this->fingerprint() === $other->fingerprint();
    }

    /** Whether the row still has usable affiliation data of the given type. */
    public function entity(SocialSecurityEntityType $type): SourceEntityToken
    {
        return $this->entities[$type->value]
            ?? SourceEntityToken::read('', $type, new SensitiveSourceRedactor);
    }

    /** Every problem this row carries, as `code => human message`. */
    public function problems(): array
    {
        $problems = [];

        if ($this->document->hasProblem()) {
            $problems['invalid_client_document'] = $this->document->problem === 'missing'
                ? 'La fila tiene nombres pero no tiene documento. Sin documento no se crea el cliente.'
                : 'El documento de la fila no se pudo normalizar a un tipo conocido.';
        }

        if ($this->affiliationDate->hasProblem()) {
            $problems['invalid_affiliation_date'] = 'Fecha de afiliación «'.$this->affiliationDate->raw.'» ('
                .$this->affiliationDate->problem.').';
        }

        if ($this->amountProblem !== null) {
            $problems[$this->amountProblem] = $this->amountProblem === 'missing_monthly_value'
                ? 'La fila no tiene valor mensual, así que no se crea un rate para este mes.'
                : 'El valor mensual no es un número positivo, así que es un blocker.';
        }

        if ($this->risk->hasProblem()) {
            $isTypo = $this->risk->problem === 'unknown_risk_token';

            $problems[$isTypo ? 'unknown_risk_token' : 'ambiguous_risk_job_columns'] = $isTypo
                ? 'La columna de riesgo dice «'.($this->risk->riskRaw ?? '').'», que no es un nivel 1-5. Se propone una corrección pero no se escribe.'
                : 'Las columnas P y Q no permiten decidir cuál es el riesgo y cuál es el cargo.';
        }

        foreach ($this->entities as $token) {
            if ($token->problem === SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE) {
                $problems['affiliation_entity_unknown'] = 'Una celda dice «'.$token->token
                    .'» sin nombrar entidad, así que no se crea ninguna.';
            }
        }

        if ($this->emailProblem !== null) {
            $problems['invalid_email'] = 'El correo de la fila no es válido y se omite del maestro.';
        }

        if ($this->company->taxIdProblem === 'invalid') {
            $problems['invalid_company_tax_id'] = 'El NIT del título de empresa no es válido.';
        }

        return $problems;
    }

    /** Whether a blocking problem stands between this row and the plan. */
    public function isBlocked(): bool
    {
        foreach (array_keys($this->problems()) as $code) {
            if (! in_array($code, ['missing_monthly_value', 'invalid_email'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §9.4's third rule: the title and the header name different ARL providers.
     *
     * ```text
     * 1. explicit ARL provider in the company title;
     * 2. header ARL if there is no explicit provider;
     * 3. if both exist and contradict, issue `company_arl_metadata_conflict`.
     * ```
     *
     * This is the comparison the previous implementation made impossible: it applied the
     * priority with `??` at staging time and stored one merged `arl_token`, so a disagreement
     * and an agreement produced the same column and rule 3 was unreachable. §18 lists the code
     * and the audit found zero call sites for it.
     *
     * Both values are returned rather than a boolean, because §17.4's dialog has to show the
     * reviewer what each side said before they pick one.
     *
     * A refusal in column R is not a second opinion: `NO CAJA` there says the person has no
     * ARL, which is §9.1's negative evidence rather than a competing provider name.
     *
     * @return array{0: string, 1: string}|null `[title, header]`, or null when they agree or only one exists
     */
    public function arlProviderConflict(): ?array
    {
        $title = $this->company->arlProvider;
        $header = $this->entities[SocialSecurityEntityType::Arl->value]->token;

        $title = $title === null || trim($title) === '' ? null : trim($title);
        $header = $header === null || trim($header) === '' ? null : trim($header);

        if ($header !== null && SourceEntityToken::isNegativePhrase($header)) {
            $header = null;
        }

        if ($title === null || $header === null) {
            return null;
        }

        // Folded, so `POSITIVA` and `Positiva ` are the same provider and do not raise a
        // conflict nobody can act on.
        return SheetMonth::fold($title) === SheetMonth::fold($header) ? null : [$title, $header];
    }

    /** The person's name, for search and display, without asserting it is their name. */
    public function displayName(): string
    {
        return trim(($this->firstNames ?? '').' '.($this->lastNames ?? '')) ?: '(sin nombres)';
    }

    // ---------------------------------------------------------------- values

    /** A cell as text, for the columns that are strings. */
    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            // A whole float is a number somebody typed, not `42000.0`.
            return $value === floor($value)
                ? (string) (int) $value
                : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * §10's three outcomes, kept apart.
     *
     * Colombian formatting is `1.200.000` for a million and `1.200.000,50` for money with
     * cents, so a dot is a thousands separator and a comma is the decimal — the opposite of
     * the machine's defaults, and reading it backwards turns a salary into a number a
     * thousand times too small.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private static function parseAmount(mixed $value): array
    {
        if ($value === null || trim(self::text($value)) === '') {
            return [null, 'missing_monthly_value'];
        }

        if (is_int($value) || is_float($value)) {
            $amount = (float) $value;

            return $amount > 0 ? [$amount, null] : [null, 'invalid_monthly_value'];
        }

        $numeric = preg_replace('/[^0-9.,]/u', '', self::text($value)) ?? '';

        if ($numeric === '') {
            return [null, 'invalid_monthly_value'];
        }

        // A comma means the decimal point, and any dots are therefore separators.
        if (str_contains($numeric, ',')) {
            $numeric = str_replace(['.', ','], ['', '.'], $numeric);
        } else {
            $numeric = str_replace('.', '', $numeric);
        }

        if (! is_numeric($numeric)) {
            return [null, 'invalid_monthly_value'];
        }

        $amount = (float) $numeric;

        return $amount > 0 ? [$amount, null] : [null, 'invalid_monthly_value'];
    }

    /**
     * §7.1: an invalid email is a warning and is omitted from the master, never a blocker.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function judgeEmail(string $email): array
    {
        $email = trim($email);

        if ($email === '') {
            return [null, null];
        }

        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [null, 'invalid_email'];
        }

        return [mb_strtolower($email), null];
    }

    /** A name cell, with the whitespace an author left collapsed. */
    private static function cleanName(string $value): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $clean === '' ? null : mb_substr($clean, 0, 120);
    }
}

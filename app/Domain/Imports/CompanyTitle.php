<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Companies\TaxIdSyntax;

/**
 * One company block's title row, and everything the domain can safely take from it.
 *
 * ## The name is only a hint; the NIT is the identity
 *
 * §1.2: "el NIT es identidad; el nombre sólo ayuda a revisar". A spreadsheet's company name
 * is typed by hand and drifts — `ANDINA` one month and `ANIDA S.A.S.` the next — so a
 * parser that keyed on the name would create a second company for the same employer. The
 * NIT is a number with a check digit and it is what the rest of the module matches on.
 *
 * ## What the title carries beyond name and NIT
 *
 *     Constructora Andina S.A.S. NIT 900123456-3 ARL POSITIVA RIESGOS 1,2,3
 *         CAJA COMFENSACION  USUARIA CLAVE: hola123
 *
 *  - the ARL provider, used for the ARL affiliation's entity;
 *  - the permitted risks, informational;
 *  - a caja, informational;
 *  - and, in the delivered file, credentials in plain text. The whole value is passed
 *    through `SensitiveSourceRedactor` on the way in, so nothing downstream ever holds it.
 *
 * ## Name extraction stops at the NIT
 *
 * The text before `NIT` is the name candidate. That is the only boundary the source states,
 * and inventing a smarter one — stopping at the ARL, or at the first digit — breaks on the
 * many titles that carry the NIT last.
 */
final readonly class CompanyTitle
{
    /**
     * @param  string  $raw  redacted; the original stays in the private file
     */
    private function __construct(
        public string $raw,
        public ?string $name,
        public ?string $taxId,
        public ?string $verificationDigit,
        public ?string $taxIdProblem,
        public ?string $arlProvider,
        public array $permittedRisks,
    ) {}

    /**
     * @param  SensitiveSourceRedactor  $redactor  so the title is redacted on the way in
     * @param  string|null  $sheet  for a credential finding, if one is found
     */
    public static function parse(string $raw, SensitiveSourceRedactor $redactor, ?string $sheet = null, ?int $row = null): self
    {
        $redacted = $redactor->redact(trim($raw), $sheet, $row) ?? '';

        [$beforeNit, $nitText] = self::splitAtNit($redacted);

        $name = self::cleanName($beforeNit);
        [$taxId, $digit, $problem] = self::readTaxId($nitText);

        $tail = $nitText === null ? '' : substr($redacted, strpos($redacted, 'NIT') ?: 0);

        return new self(
            $redacted,
            $name,
            $taxId,
            $digit,
            $problem,
            self::readArlProvider($tail),
            self::readPermittedRisks($tail),
        );
    }

    /**
     * Rebuild a title from what staging stored, instead of from the text.
     *
     * ## Why this exists
     *
     * §15's `rebuild-plan` must work from the database, not from the file. Feeding the staged
     * `company_tax_id` back through `parse()` looks like it reconstructs the title and does not:
     * with only the NIT and no `NIT` label around it, the name comes back null — and a null name
     * silently dropped every company action from the plan, so an import built a plan with no
     * companies in it and applied cleanly.
     *
     * Staging keeps the NIT, the display name and the ARL provider separately, so this reads all
     * three back rather than trying to re-parse a string that is no longer there.
     */
    public static function fromStored(?string $taxId, ?string $name, ?string $arlProvider = null): self
    {
        return new self(
            trim((string) $name).'',
            $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, 180),
            $taxId === null || trim($taxId) === '' ? null : trim($taxId),
            null,
            $taxId === null || trim($taxId) === '' ? 'missing' : null,
            $arlProvider === null || trim($arlProvider) === '' ? null : trim($arlProvider),
            [],
        );
    }

    /**
     * Split at the first `NIT`, if there is one.
     *
     * @return array{0: string, 1: string|null} text before it, and the NIT expression itself
     */
    private static function splitAtNit(string $value): array
    {
        if (preg_match('/\bN\.?\s*I\.?\s*T\.?\b/iu', $value, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return [$value, null];
        }

        $start = (int) $matches[0][1];
        $before = substr($value, 0, $start);

        // The NIT and its digit, then stop at the next word that is not part of it. A title
        // continues with the ARL, the risks and sometimes a credential, so a greedy match
        // would swallow the company name of nothing and the NIT of the next company.
        $rest = substr($value, $start);

        // The NIT expression itself, and nothing after it. The title continues with the ARL,
        // the risks and sometimes a credential, and `TaxIdSyntax` is a strict grammar — fed
        // `NIT 900123456-3` it rejects the whole string, which would report every company in
        // the file as an invalid NIT.
        $end = preg_match('/\d[\d.\-]{0,20}/u', $rest, $number, PREG_OFFSET_CAPTURE);

        return [$before, $end === 1 ? (string) $number[0][0] : $rest];
    }

    /**
     * The NIT, through A02's strict grammar.
     *
     * §7.2: an invalid NIT is a blocker. Nothing here repairs one — not a missing digit, not
     * a transposition, not a number of the wrong length — because the NIT is the company's
     * identity and a repaired one is a different company.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null} base, digit, problem
     */
    private static function readTaxId(?string $expression): array
    {
        if ($expression === null) {
            // No `NIT` token at all. Not an error here: a block with no NIT is
            // `missing_company_block_header`'s business, not the tax id parser's.
            return [null, null, 'missing'];
        }

        $parsed = TaxIdSyntax::parse($expression);

        if ($parsed === null) {
            return [null, null, 'invalid'];
        }

        return [$parsed['tax_id'], $parsed['verification_digit'], null];
    }

    private static function cleanName(string $beforeNit): ?string
    {
        // Trailing separators the author left behind, and nothing else.
        //
        // A full stop is deliberately *not* in this list: `ANDINA S.A.S.` ends in one and it is
        // part of the legal form, not a separator. Stripping it would make every company's
        // name in the file lose a character, and §19 counts logical company names, so the
        // spelling has to survive the parse intact.
        $name = trim(preg_replace('/[\s\-–—:;,]+$/u', '', $beforeNit) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 180);
    }

    /**
     * The ARL provider named in the title.
     *
     * Matched on the word before `ARL` or after it, because both spellings appear:
     * `ARL POSITIVA` and `POSITIVA ARL`. A provider that is not in the catalogue is
     * returned as a token, not resolved: §9.2 forbids merging a spelling into an entity
     * without somebody's decision.
     */
    private static function readArlProvider(string $tail): ?string
    {
        if (preg_match('/\bARL\b\s+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ ]{2,30})/ui', $tail, $matches) === 1) {
            $candidate = trim((string) $matches[1]);

            return self::trimKnownTrailer($candidate);
        }

        if (preg_match('/([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ ]{2,30})\s+ARL\b/ui', $tail, $matches) === 1) {
            $candidate = trim((string) $matches[1]);

            return self::trimKnownTrailer($candidate);
        }

        return null;
    }

    /**
     * Cut a provider name where the title moves on to the next fact.
     *
     * `POSITIVA RIESGOS 1,2,3` is one provider followed by the risk list, and without this
     * the entity token becomes a phrase that will never match the catalogue.
     */
    private static function trimKnownTrailer(string $candidate): string
    {
        foreach (['RIESGO', 'RIESGOS', 'CAJA', 'CAJAS', 'CARGO', 'NOVEDAD', 'CONTACTO', 'TEL'] as $stop) {
            $position = mb_stripos($candidate, $stop);

            if ($position !== false && $position > 0) {
                $candidate = mb_substr($candidate, 0, $position);
            }
        }

        return trim($candidate);
    }

    /**
     * The risk levels the title permits, for the review screen to show.
     *
     * Informational. The risk that matters is the one on the person row, and §9.4 says that
     * is inferred from content rather than taken from here.
     *
     * @return list<int>
     */
    private static function readPermittedRisks(string $tail): array
    {
        if (preg_match('/RIESGOS?\s*:?\s*([0-9,\s]+)/ui', $tail, $matches) !== 1) {
            return [];
        }

        $numbers = preg_split('/[^0-9]+/', (string) $matches[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $risks = array_values(array_unique(array_filter(array_map(
            static fn (string $n): int => (int) $n,
            $numbers,
        ), static fn (int $n): bool => $n >= 1 && $n <= 5)));

        sort($risks);

        return $risks;
    }

    /**
     * The key two rows of the same block must agree on.
     *
     * §7.3 groups by "empresa + mes + documento", and the company has to be one identity
     * across every row of the block. The NIT is that identity; the name is only what a
     * person reads.
     */
    public function identityKey(): string
    {
        return $this->taxId !== null
            ? 'nit:'.$this->taxId
            : 'name:'.SheetMonth::fold((string) $this->name);
    }

    /** `NIT-NOMBRE`, the block label the review screen shows. */
    public function blockKey(): string
    {
        return $this->taxId !== null
            ? $this->taxId.'-'.($this->name ?? '')
            : ($this->name ?? '(sin nombre)');
    }
}

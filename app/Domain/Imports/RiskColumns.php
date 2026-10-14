<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Decides which of two adjacent columns is the risk level and which is the job title.
 *
 * ## Why content decides and not the heading
 *
 * §1.1: "No confiar ciegamente en las columnas P/Q ni en su encabezado. En algunos bloques
 * el riesgo y el cargo están intercambiados."
 *
 * The delivered file makes the point by having both:
 *
 *     P: UNO      Q: AUXILIAR          → P is the risk
 *     P: AUXILIAR  Q: DOS                → Q is the risk
 *
 * Both are true somewhere in the same workbook, on the same day, for the same employer
 * template. A heading rule therefore cannot work: `POSITIVA` and `LA EQUIDAD` appear as
 * headings in one block and `UNO`/`DOS` appear as values in another.
 *
 * The values are unambiguous even when the headings are not. A risk class in this source is
 * one of five words — `UNO`, `DOS`, `TRES`, `CUATRO`, `CINCO` — and a job title is a
 * noun phrase. So the decision is made by counting which column holds risk words, and the
 * answer is a verdict with a reason rather than a guess.
 *
 * ## The typo is deliberately not a risk
 *
 * `UMO` appears in the real file. §9.4 says a typo only produces a suggestion and is never
 * auto-corrected without an approved mapping, so it comes back as `unknown` and raises
 * `unknown_risk_token`. Guessing that somebody meant `UNO` is right 95% of the time and
 * wrong about one person's risk class, which is the kind of error nobody finds.
 *
 * ## Both or neither is an issue
 *
 * Two risk columns in one row means the parser does not know which is which. `P` is always
 * the risk once the header is read, so a value that is a job title in both columns means the
 * block's own template is unfamiliar, and that is `ambiguous_risk_job_columns` rather than
 * two empty risks.
 */
final readonly class RiskColumns
{
    private function __construct(
        public ?int $riskClass,
        public ?string $riskRaw,
        public ?string $jobTitle,
        public string $verdict,
        public ?string $problem,
    ) {}

    /** Neither column looked like a risk, or both did. */
    public const AMBIGUOUS = 'ambiguous';

    public const RESOLVED_P = 'resolved_p';

    public const RESOLVED_Q = 'resolved_q';

    public const NONE = 'none';

    public const UNKNOWN_TOKEN = 'unknown_token';

    /**
     * Rebuild from what staging recorded.
     *
     * ## Why `decide()` cannot be reused here
     *
     * The rebuild path called `decide($staged->risk_raw, $staged->job_title)`, which runs §9.4's
     * arbitration *again* on two columns that had already lost the P/Q order. `risk_raw` is the
     * winning cell's text and `job_title` the loser's, so the second pass was asked "which of
     * these two is the risk?" with both answers already resolved, and it could reach the
     * opposite conclusion from the first pass on the very same file.
     *
     * §9.4's mapping (`UNO→1 … CINCO→5`, a typo produces a suggestion and never a correction)
     * belongs to the parse. A rebuild restores `riskClass` and keeps the raw text for the review
     * screen.
     *
     * @param  int|null  $riskClass  1..5 as the parse concluded, or NULL when it concluded none
     */
    public static function restore(?int $riskClass, ?string $riskRaw, ?string $jobTitle): self
    {
        $raw = $riskRaw === null || trim($riskRaw) === '' ? null : trim($riskRaw);

        if ($riskClass === null) {
            // A row with a raw token and no class is the §9.4 typo case: `UMO` arrives as
            // `unknown_token`, and the suggestion is the raw text itself.
            return new self(
                null,
                $raw,
                $jobTitle === null || trim($jobTitle) === '' ? null : trim($jobTitle),
                $raw === null ? self::NONE : self::UNKNOWN_TOKEN,
                $raw === null ? null : 'unknown_risk_token',
            );
        }

        return new self(
            $riskClass,
            $raw,
            $jobTitle === null || trim($jobTitle) === '' ? null : trim($jobTitle),
            // The verdict is not recoverable from three columns, and nothing reads it after the
            // parse, so it says "resolved" rather than claiming a column it cannot name.
            self::RESOLVED_P,
            null,
        );
    }

    /** The five words the source uses for a risk class, folded. */
    private const RISK_WORDS = [
        'UNO' => 1,
        'DOS' => 2,
        'TRES' => 3,
        'CUATRO' => 4,
        'CINCO' => 5,
    ];

    /**
     * §9.4's "approved mapping": risk words the source misspells, and the word it meant.
     *
     * §9.4 requires an approved mapping before a typo becomes a write, and until one existed
     * `looksLikeTypo()` could only *report* `UMO`. The project owner has confirmed for this
     * workbook that `UMO` is a mistyping of `UNO`, which is the approval §9.4 asks for.
     *
     * ## Why the table is closed and not a rule
     *
     * The tempting generalisation is "any word one edit away from a risk word is that risk
     * word", and `looksLikeTypo()` already computes exactly that distance. Acting on it would
     * silently rewrite somebody's risk class from a value nobody approved — `DOS` → `D05`,
     * `TRES` → `TRES2`, or any name that happens to sit one edit from a risk word. §9.4's whole
     * point is that the correction has to be *approved*, and approval is per spelling, not per
     * edit distance.
     *
     * So the table is consulted explicitly, holds one confirmed entry, and is configurable so an
     * administrator can approve another spelling with the same visibility. Anything not in it
     * still arrives as `unknown_risk_token` for a person to decide.
     *
     * ## Where the provenance is
     *
     * `riskRaw` keeps the cell's original text, so a row normalized through this table still
     * stages `risk_raw = 'UMO'` beside `arl_risk_class = 1`. The correction is therefore visible
     * on the review screen and in the record without a second column: the raw value *is* the
     * evidence that a normalization happened.
     *
     * @return array<string, int>|null the risk class the approved word means
     */
    private static function approvedRiskToken(string $folded): ?int
    {
        $mapping = config('imports.approved_risk_tokens', []);

        if (! is_array($mapping)) {
            return null;
        }

        $canonical = $mapping[$folded] ?? null;

        if (! is_string($canonical)) {
            return null;
        }

        // Resolved through the same table as the source's own words, so an approved mapping can
        // only ever point at a real risk level. A configuration pointing at something else is
        // ignored rather than trusted.
        return self::RISK_WORDS[SheetMonth::fold($canonical)] ?? null;
    }

    /**
     * Decide from the two values.
     *
     * @param  string  $p  the `P` cell, whatever its heading said
     * @param  string  $q  the `Q` cell, whatever its heading said
     */
    public static function decide(string $p, string $q): self
    {
        $pToken = self::riskWord($p);
        $qToken = self::riskWord($q);

        $pIsRisk = $pToken !== null;
        $qIsRisk = $qToken !== null;

        if ($pIsRisk && $qIsRisk) {
            return new self(null, null, self::firstNonEmpty($q, $p), self::AMBIGUOUS, 'two_risk_columns');
        }

        if (! $pIsRisk && ! $qIsRisk) {
            // Neither is a risk word. If one of them is a near miss — `UMO` — that is a
            // typo worth reporting rather than a cell that was simply blank.
            $typo = self::looksLikeTypo($p) || self::looksLikeTypo($q);

            if ($typo) {
                return new self(
                    null,
                    self::firstNonEmpty($p, $q),
                    null,
                    self::UNKNOWN_TOKEN,
                    'unknown_risk_token',
                );
            }

            return new self(
                null,
                null,
                self::firstNonEmpty($p, $q),
                self::NONE,
                null,
            );
        }

        // Exactly one column holds a risk word, so that column is the risk. `P` first
        // because it is the position the majority of blocks use, and the other column is
        // then the job title.
        if ($pIsRisk) {
            return new self($pToken, trim($p), self::blankToNull($q), self::RESOLVED_P, null);
        }

        return new self($qToken, trim($q), self::blankToNull($p), self::RESOLVED_Q, null);
    }

    /** Whether the decision can be trusted without a person looking at it. */
    public function isResolved(): bool
    {
        return $this->riskClass !== null;
    }

    /**
     * Whether this exact text is the row's job title, per the row's own P/Q content.
     *
     * Used by the §9.4 provider comparison, which must not treat a cargo as an administradora.
     * See `SourcePersonRow::arlProviderConflict()` for why that matters and why asking this
     * question is narrower and more durable than carrying a list of job titles.
     *
     * Comparison is folded, so `Auxiliar` and `AUXILIAR ` are the same text. The risk cell is
     * excluded, so a cell holding a risk word is never mistaken for a job title.
     */
    public function isJobTitle(string $value): bool
    {
        if ($this->jobTitle === null) {
            return false;
        }

        $candidate = SheetMonth::fold($value);
        $title = SheetMonth::fold($this->jobTitle);

        if ($candidate === '' || $title === '') {
            return false;
        }

        return $candidate === $title;
    }

    public function hasProblem(): bool
    {
        return $this->problem !== null;
    }

    /**
     * The risk word and its number, or null when the cell is not one.
     *
     * `3` and `III` are accepted as well as the words, because both appear and both are
     * unambiguous. Anything else is not a risk class.
     */
    private static function riskWord(string $value): ?int
    {
        $folded = SheetMonth::fold($value);

        if ($folded === '') {
            return null;
        }

        if (isset(self::RISK_WORDS[$folded])) {
            return self::RISK_WORDS[$folded];
        }

        // An approved misspelling of a risk word. Checked before the digit and roman forms so
        // `UMO` resolves the same way `UNO` does, and checked as an exact folded match — never by
        // edit distance. See `approvedRiskToken()` for why the table exists.
        $approved = self::approvedRiskToken($folded);

        if ($approved !== null) {
            return $approved;
        }

        if (ctype_digit($folded) && $folded >= '1' && $folded <= '5') {
            return (int) $folded;
        }

        $roman = [
            'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5,
        ];

        return $roman[$folded] ?? null;
    }

    /**
     * Whether a cell is one edit away from a risk word.
     *
     * Deliberately crude and deliberately conservative: it is used to decide whether to
     * raise an issue, not to suggest a correction. §9.4 requires an approved mapping for the
     * suggestion to become a write, and this never writes.
     */
    private static function looksLikeTypo(string $value): bool
    {
        $folded = SheetMonth::fold($value);

        if ($folded === '' || mb_strlen($folded) > 6) {
            return false;
        }

        foreach (array_keys(self::RISK_WORDS) as $word) {
            if ($folded === $word) {
                continue;
            }

            // Same length, and one edit apart.
            if (mb_strlen($folded) === mb_strlen($word) && levenshtein($folded, $word) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function firstNonEmpty(string ...$values): ?string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function blankToNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 120);
    }
}

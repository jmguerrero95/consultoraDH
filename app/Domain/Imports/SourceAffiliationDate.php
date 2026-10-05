<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * `FECHA AFILIACION`, and what it is allowed to assert.
 *
 * ## §8.1 calls this "el candidato fuerte de inicio de la relación empresa"
 *
 * So it is strong enough to open a relationship when it is a real date, and it is not
 * strong enough to be repaired when it is not. Both halves matter: the first is why the
 * history is worth rebuilding at all, the second is why 17 real cells are a review queue
 * rather than 17 guesses.
 *
 * ## The three forms that resolve
 *
 *  1. **An Excel serial.** The dominant form, and the one a naive parser gets wrong: `42000`
 *     is not a year, it is 14 December 2014. Excel's day 1 is 1900-01-01 with its famous
 *     1900 leap-year bug, which the `1899-12-30` epoch absorbs — the arithmetic is off by
 *     one for dates before 1900-03-01 and this workbook has none.
 *  2. **`dd/mm/yyyy`.** Strictly: `checkdate` decides, so `31/02/2026` is a blocker and not
 *     a date rolled forward into March.
 *  3. **`yyyy-mm-dd`.** Also strictly. This is the form a previous import wrote, so it
 *     appears in exports of sheets typed by two different people.
 *
 * ## What is refused, and refused loudly
 *
 * - **Truncated years.** `27/03/26` is `truncated_year`. §8.1: "`206` → `2026` es una
 *   sugerencia, no una escritura". A suggestion is carried in `suggestion` and shown in the
 *   preview; nothing writes it without the operator accepting it.
 * - **`NO`.** §8.1 lists it with the impossible dates. It means *not affiliated*, which is
 *   not the same as an unknown date, and the two produce different plans.
 * - **Ambiguous formats.** `03/04/2026` under `dd/mm` is 3 April; under `mm/dd` it is
 *   4 March. The source is Colombian and its cells are written `dd/mm`, so `dd/mm` is the
 *   reading — but a value whose day and month are both ≤ 12 is flagged
 *   `ambiguous_date` because the reader cannot rule out that this one cell was typed by
 *   somebody who thinks in the other order. Seventeen such cells in the real file.
 */
final readonly class SourceAffiliationDate
{
    /** A resolved, exact day. */
    public const PRECISION_DAY = 'day';

    /** Resolved, but the source asserted only a month. */
    public const PRECISION_MONTH = 'month';

    /** The source said nothing usable. */
    public const PRECISION_UNKNOWN = 'unknown';

    /** Excel's epoch, absorbing the 1900 leap-year bug. */
    private const EXCEL_EPOCH = '1899-12-30';

    /** Excel serial 1 is 1900-01-01; this is 9999-12-31. */
    private const SERIAL_MIN = 1;

    private const SERIAL_MAX = 2_958_465;

    private function __construct(
        /** `Y-m-d`, or null when the cell asserts nothing usable. */
        public ?string $isoDate,
        public string $precision,
        /** Null, or why this cell could not be read: a blocker code. */
        public ?string $problem,
        /** `Y-m-d`, shown in the preview. Never written without acceptance. */
        public ?string $suggestion,
        public string $raw,
    ) {}

    /**
     * Read the `F` cell.
     *
     * @param  float|int|string|\DateTimeInterface|null  $value  the cell as the reader gave
     *                                                           it, before any string cast: a serial arrives as a number and a date-formatted
     *                                                           cell arrives as a date object. The date case is the *good* one — somebody's Excel
     *                                                           stored a real date, so there is no serial and no format to guess at.
     * @param  string|null  $precision  what the caller already knows, used only for a
     *                                  date object. See `readStaged()` for why that matters.
     */
    public static function read(
        float|int|string|\DateTimeInterface|null $value,
        SensitiveSourceRedactor $redactor,
        ?string $sheet = null,
        ?int $row = null,
        ?string $precision = null,
    ): self {
        if ($value === null) {
            return new self(null, self::PRECISION_UNKNOWN, 'missing_date', null, '');
        }

        if ($value instanceof \DateTimeInterface) {
            // A date object carries no precision of its own: `MARZO 2026` in a cell Excel
            // formatted as a date arrives here indistinguishable from a real day. When the
            // caller already knows the source asserted a month, that knowledge is kept —
            // otherwise the value would be silently promoted to a day, which is exactly what
            // §8.3's "no mostrar un mes como un día" forbids and what the audit found
            // happening on every rebuild.
            $resolved = $precision === self::PRECISION_MONTH ? self::PRECISION_MONTH : self::PRECISION_DAY;

            return new self($value->format('Y-m-d'), $resolved, null, null, $value->format('Y-m-d'));
        }

        // A numeric cell is a serial. This has to be tested before the string path,
        // because `(string) 42000.0` is `42000` and would then look like a bare year.
        if (is_int($value) || is_float($value)) {
            return self::fromSerial((float) $value, '');
        }

        $raw = trim((string) $redactor->redact(trim((string) $value), $sheet, $row) ?? '');

        if ($raw === '') {
            return new self(null, self::PRECISION_UNKNOWN, 'missing_date', null, '');
        }

        // A time on the end is a cell format, not an assertion. `15/01/2026 08:30` is the
        // 15th of January; the 08:30 is when somebody opened the sheet. §8.1 says a
        // monthly planilla asserts no time, and dropping one changes no fact — unlike
        // dropping a day, which is why only the time is stripped.
        $withoutTime = preg_replace('/\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AaPp]\.?[Mm]\.?)?$/u', '', $raw) ?? $raw;

        return self::fromText($withoutTime);
    }

    private static function fromSerial(float $value, string $raw): self
    {
        if ($value !== floor($value)) {
            // A date with a time on it. The source is a monthly planilla and asserts no
            // time, so the fraction is a formatting artefact and dropping it is not a
            // change of meaning — unlike dropping a day, which would be.
            $value = floor($value);
        }

        if ($value < self::SERIAL_MIN || $value > self::SERIAL_MAX) {
            return new self(null, self::PRECISION_UNKNOWN, 'impossible_date', null, $raw);
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', (new \DateTimeImmutable(self::EXCEL_EPOCH))
            ->modify('+'.((int) $value).' days')
            ->format('Y-m-d'));

        if ($date === false) {
            return new self(null, self::PRECISION_UNKNOWN, 'impossible_date', null, $raw);
        }

        return new self($date->format('Y-m-d'), self::PRECISION_DAY, null, null, $raw);
    }

    private static function fromText(string $raw): self
    {
        $folded = SheetMonth::fold($raw);

        // §8.1 names `NO` explicitly.
        if (in_array($folded, ['NO', 'N O', 'NO APLICA', 'N A'], true) || str_starts_with($folded, 'NO ')) {
            return new self(null, self::PRECISION_UNKNOWN, 'not_affiliated', null, $raw);
        }

        // A month on its own, with a year: `MARZO 2026`. This asserts a month and no day,
        // which is why `PRECISION_MONTH` exists in A02 at all.
        if (preg_match('/^(?<month>[A-Z]+)\s+(?<year>\d{4})$/u', $folded, $matches) === 1) {
            $month = SheetMonth::monthNumber($matches['month']);

            if ($month === null) {
                return new self(null, self::PRECISION_UNKNOWN, 'unreadable_date', null, $raw);
            }

            return new self(
                sprintf('%04d-%02d-01', (int) $matches['year'], $month),
                self::PRECISION_MONTH,
                null,
                null,
                $raw,
            );
        }

        // `yyyy-mm-dd`
        if (preg_match('/^(?<y>\d{4})-(?<m>\d{1,2})-(?<d>\d{1,2})$/', $raw, $matches) === 1) {
            $year = (int) $matches['y'];
            $month = (int) $matches['m'];
            $day = (int) $matches['d'];

            return checkdate($month, $day, $year)
                ? new self(sprintf('%04d-%02d-%02d', $year, $month, $day), self::PRECISION_DAY, null, null, $raw)
                : new self(null, self::PRECISION_UNKNOWN, 'impossible_date', null, $raw);
        }

        // `d/m/y` — the dominant text form, and the only one read as day-first.
        if (preg_match('#^(?<d>\d{1,2})[/\-.](?<m>\d{1,2})[/\-.](?<y>\d{1,4})$#', $raw, $matches) === 1) {
            $day = (int) $matches['d'];
            $month = (int) $matches['m'];
            $year = (int) $matches['y'];

            // §8.1 names `206` → `2026` as a suggestion and not a write, and the danger is
            // not the arithmetic but the padding: `(int) '206'` is `206`, and `sprintf('%04d')`
            // would quietly turn it into the year 206 and checkdate would accept it. A year
            // that is not exactly four digits never becomes a date here.
            if ($year < 1000) {
                return new self(
                    null,
                    self::PRECISION_UNKNOWN,
                    'truncated_year',
                    self::expandYear($year),
                    $raw,
                );
            }

            if (! checkdate($month, $day, $year)) {
                return new self(null, self::PRECISION_UNKNOWN, 'impossible_date', null, $raw);
            }

            // Both halves are ≤ 12, so this cell reads differently under the two
            // conventions and the reader cannot tell which the author meant.
            if ($day <= 12 && $month <= 12 && $day !== $month) {
                return new self(
                    null,
                    self::PRECISION_UNKNOWN,
                    'ambiguous_date',
                    sprintf('%04d-%02d-%02d', $year, $month, $day),
                    $raw,
                );
            }

            return new self(
                sprintf('%04d-%02d-%02d', $year, $month, $day),
                self::PRECISION_DAY,
                null,
                null,
                $raw,
            );
        }

        return new self(null, self::PRECISION_UNKNOWN, 'unreadable_date', null, $raw);
    }

    /**
     * A short year expanded into this century, or null when nothing is defensible.
     *
     * §8.1 names `206` → `2026`. Three digits has no defensible expansion — `206` is not
     * `0206` and it is not `2006` — so it returns null and the operator types the year.
     */
    private static function expandYear(int $year): ?string
    {
        // Two digits can be a century. Three cannot, and guessing one would be inventing a
        // date, so no suggestion is offered at all.
        if ($year < 0 || $year > 99) {
            return null;
        }

        $thisYear = (int) date('Y');
        $century = intdiv($thisYear, 100) * 100;
        $candidate = $century + $year;

        // Only a candidate within ten years of today is plausible for an affiliation, and
        // only the 20th/21st-century boundary is crossed. Beyond that, no guess.
        if (abs($candidate - $thisYear) > 10) {
            $candidate += 100;
        }

        return abs($candidate - $thisYear) > 10 ? null : (string) $candidate;
    }

    /**
     * Rebuild from what staging stored, without re-diagnosing.
     *
     * ## Why this exists instead of `read()` on the staged column
     *
     * `rebuild-plan` has to reconstruct the row from the database. The previous version called
     * `read()` with the staged `affiliation_date`, which arrived as a `Carbon`, so `read()`
     * took the date-object branch and hard-coded `PRECISION_DAY`. The audit's finding, verbatim:
     * "`hydrate()` passes a Carbon ⇒ `read():89-91` forces `PRECISION_DAY`.
     * `affiliation_date_precision` and `affiliation_date_raw` are never read."
     *
     * The chain that followed, for every one of the workbook's month-precision cells:
     *
     * - `HistoricalInterval::intervalFor()`'s `PRECISION_MONTH => MONTH` arm became unreachable,
     *   so `default => DAY` caught it and `started_on_precision` was written as `day`;
     * - `describeStart()` then rendered `01/03/2026` — a day the source never asserted;
     * - `suggestion` was destroyed, so §17.4's "use the suggested date" button had nothing to
     *   read even after the resolution contract was fixed;
     * - a problem cell was staged with `affiliation_date = NULL` and re-diagnosed as
     *   `missing_date`, while the issue it came from said `ambiguous_date` — so the plan and
     *   the issue list disagreed about the same cell;
     * - and `fingerprint()` differed between the two passes, because `normalizedPayload()`
     *   includes the precision, so the stored fingerprint was wrong for every month row.
     *
     * ## The rule
     *
     * Staging is the record of what the parse concluded. A rebuild restores that conclusion;
     * it never re-derives it. Re-deriving is what turned a reviewer-visible `ambiguous_date`
     * into a plan-level `missing_date` behind the reviewer's back.
     *
     * The parse's own diagnosis is preserved verbatim, which is also why the staged
     * `affiliation_date_problem` and `affiliation_date_suggestion` columns exist.
     */
    public static function readStaged(
        ?string $isoDate,
        ?string $precision,
        ?string $problem,
        ?string $suggestion,
        ?string $raw,
    ): self {
        return new self(
            $isoDate,
            $precision ?? self::PRECISION_UNKNOWN,
            $problem,
            $suggestion,
            $raw ?? '',
        );
    }

    /** Whether this cell may open a relationship without a person deciding first. */
    public function isUsable(): bool
    {
        return $this->problem === null && $this->isoDate !== null;
    }

    public function hasProblem(): bool
    {
        return $this->problem !== null;
    }

    /** `2026-03-27`, `2026-03 (mes)`, or the blocker code. */
    public function label(): string
    {
        if ($this->isoDate === null) {
            return (string) $this->problem;
        }

        return $this->precision === self::PRECISION_MONTH
            ? substr($this->isoDate, 0, 7).' (mes aproximado)'
            : $this->isoDate;
    }

    /** The `DateTimeImmutable` this asserts, or null. Callers check `isUsable()` first. */
    public function date(): ?\DateTimeImmutable
    {
        return $this->isoDate === null
            ? null
            : new \DateTimeImmutable($this->isoDate);
    }
}

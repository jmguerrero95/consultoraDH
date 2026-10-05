<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Periods\MonthlyPeriod;

/**
 * A month, from a sheet's name.
 *
 * ## The sheet is the calendar
 *
 * The workbook has one sheet per month and the sheet name carries the month: `ENERO 2026`,
 * `FEBRERO 2026`. §6.1 requires the sheets to be ordered by the month in the name and not
 * by tab position, because a workbook whose tabs were dragged into alphabetical order would
 * otherwise bill October before January.
 *
 * ## Why the parser is a month and not a date
 *
 * Almost every decision downstream is monthly: the `effective_month` of a rate, the
 * boundary of a relationship segment, the first month a withdrawal can be inferred in. A
 * month is the unit the source actually asserts, and re-deriving it from a date on every
 * row would be a chance to disagree with the sheet.
 *
 * ## December belongs to the year before, in some sheets
 *
 * `RETIRA ... DICIEMBRE` in the January sheet is a withdrawal that happened in the previous
 * year. §8.2 requires the year to come from the sheet's context and nowhere else, and this
 * is the place that context enters.
 */
final readonly class SheetMonth
{
    private function __construct(
        public MonthlyPeriod $month,
        public string $sheetName,
    ) {}

    /**
     * Read `MES AÑO`, in Spanish, with or without an accent and in either case.
     *
     * Returns null rather than throwing, because a workbook may legitimately carry a sheet
     * that is not a month — a `TOTALES` sheet, a `RESUMEN` sheet — and the caller turns that
     * into `invalid_sheet_name` with the sheet name attached, which is a better answer than
     * an exception that says nothing about which sheet.
     */
    public static function fromSheetName(string $sheetName): ?self
    {
        $folded = self::fold($sheetName);

        if (preg_match('/^(?<month>[A-Z]+)\.?\s+(?<year>\d{4})$/u', $folded, $matches) !== 1) {
            return null;
        }

        $monthNumber = self::monthNumber($matches['month']);

        if ($monthNumber === null) {
            return null;
        }

        $year = (int) $matches['year'];

        try {
            return new self(MonthlyPeriod::fromYearMonth($year, $monthNumber), $sheetName);
        } catch (\InvalidArgumentException) {
            // A year outside the domain's range. `invalid_sheet_name` is the right answer:
            // the sheet does not name a month this system can bill.
            return null;
        }
    }

    /** January..December, in Spanish, folded. `DICIEMBRE` and `DIC` are both December. */
    public static function monthNumber(string $foldedName): ?int
    {
        return match ($foldedName) {
            'ENERO', 'ENE' => 1,
            'FEBRERO', 'FEB' => 2,
            'MARZO', 'MAR' => 3,
            'ABRIL', 'ABR' => 4,
            'MAYO' => 5,
            'JUNIO', 'JUN' => 6,
            'JULIO', 'JUL' => 7,
            'AGOSTO', 'AGO' => 8,
            'SEPTIEMBRE', 'SEPT', 'SEP', 'SET' => 9,
            'OCTUBRE', 'OCT' => 10,
            'NOVIEMBRE', 'NOV' => 11,
            'DICIEMBRE', 'DIC' => 12,
            default => null,
        };
    }

    /**
     * The month a withdrawal note refers to, resolved against this sheet.
     *
     * §8.2: "año inferido únicamente por contexto de hoja". A note naming December that
     * appears in the January sheet is the December **before** it. Any other month in the
     * same sheet is the same year.
     *
     * Returns null when the note names no month, and never a guess.
     */
    public function resolveMentionedMonth(string $foldedMonthToken): ?MonthlyPeriod
    {
        $number = self::monthNumber($foldedMonthToken);

        if ($number === null) {
            return null;
        }

        $year = $this->month->year();

        // Only a *later* sheet can name an earlier year, and the only way that happens in a
        // monthly workbook is a withdrawal note about the month before the first one.
        if ($number === 12 && $this->month->month() === 1) {
            $year -= 1;
        }

        try {
            return MonthlyPeriod::fromYearMonth($year, $number);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function key(): string
    {
        return $this->month->key();
    }

    public function label(): string
    {
        return $this->month->label();
    }

    public function startsOn(): \DateTimeInterface
    {
        return $this->month->startsOn();
    }

    /**
     * Uppercase, accent-free, punctuation collapsed to a single space, trimmed.
     *
     * The trim matters more than it looks: `'-'` folds to a space, and a caller asking
     * "is this cell empty?" tests `=== ''`. Without the trim a cell containing a single dash
     * — which the affiliation columns use constantly — reads as a name called `-`.
     */
    public static function fold(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        $stripped = $ascii === false ? $value : $ascii;

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper($stripped)));
    }
}

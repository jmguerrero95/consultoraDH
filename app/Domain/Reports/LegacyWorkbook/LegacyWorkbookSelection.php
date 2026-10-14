<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * Which months and which companies a workbook export covers.
 *
 * ## Why this is its own object and not three query parameters
 *
 * Because the export has to answer one question that the parameters do not: **is this selection
 * real?** An empty month, a month in a year with nothing in it, a company that does not exist —
 * each of those is either a mistake or an empty workbook, and they need different answers. Holding
 * the selection as a value object means the check happens once, in one place, before any query
 * runs, rather than in three places that each handle it slightly differently.
 *
 * ## Why a month with nothing in it is still a sheet
 *
 * The historical workbook has one sheet per month of its coverage, and a month where nobody was
 * employed is a month that genuinely had nobody employed. Dropping it would make an export of
 * "enero–octubre" silently return nine sheets and leave a reader comparing them against the
 * original to conclude something is missing. The sheet is emitted with its structure and no rows.
 *
 * This is a decision and not a default: {@see emptyMonthPolicy()} is the single place that says
 * so, so a reader who wants the other behaviour has one line to change.
 */
final readonly class LegacyWorkbookSelection
{
    public const MONTH_NAMES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /**
     * @param  list<int>  $months  1..12, ascending, deduplicated
     */
    public function __construct(
        public int $year,
        public array $months,
        public ?int $companyId = null,
    ) {}

    /**
     * Validate raw query input, or refuse it with a reason a person can act on.
     *
     * Accepts `month=1,2,3`, `month=1-10`, or both. Not because a range is clever but because
     * "enero a octubre" is how the original file was described and is how somebody will ask for it.
     */
    public static function fromInput(?string $year, ?string $months, ?string $companyId): self
    {
        $resolvedYear = filter_var($year, FILTER_VALIDATE_INT);

        if ($resolvedYear === false || $resolvedYear < 2000 || $resolvedYear > 2100) {
            throw new InvalidLegacyWorkbookSelection('El año debe estar entre 2000 y 2100.');
        }

        return new self(
            $resolvedYear,
            self::parseMonths($months),
            self::parseCompany($companyId),
        );
    }

    /** @return list<int> */
    private static function parseMonths(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            // The whole year. Not a default that hides a mistake: a request with no months is a
            // request for the year, and saying so is cheaper than making somebody notice.
            return range(1, 12);
        }

        $months = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $part, $matches) === 1) {
                $from = (int) $matches[1];
                $to = (int) $matches[2];

                if ($from < 1 || $to > 12 || $from > $to) {
                    throw new InvalidLegacyWorkbookSelection('El rango de meses no es válido: use «1-10».');
                }

                $months = array_merge($months, range($from, $to));

                continue;
            }

            if (preg_match('/^\d{1,2}$/', $part) !== 1) {
                throw new InvalidLegacyWorkbookSelection('Los meses se escriben como «1,2,3» o «1-12».');
            }

            $value = (int) $part;

            if ($value < 1 || $value > 12) {
                throw new InvalidLegacyWorkbookSelection('El mes debe estar entre 1 y 12.');
            }

            $months[] = $value;
        }

        if ($months === []) {
            throw new InvalidLegacyWorkbookSelection('No se indicó ningún mes.');
        }

        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }

    private static function parseCompany(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new InvalidLegacyWorkbookSelection('La empresa indicada no es válida.');
        }

        return (int) $raw;
    }

    /** `YYYY-MM` for each selected month, ascending. The key everything else is looked up by. */
    public function monthKeys(): array
    {
        return array_map(fn (int $month): string => sprintf('%04d-%02d', $this->year, $month), $this->months);
    }

    /** `ENERO 2026` — the sheet name, and the name the importer reads back. */
    public function sheetName(string $monthKey): string
    {
        $month = (int) substr($monthKey, 5, 2);

        return (self::MONTH_NAMES[$month] ?? (string) $month).' '.$this->year;
    }

    /**
     * A month with nothing in it still gets a sheet.
     *
     * The one place that says so, so a reader who wants empty months dropped changes one line
     * rather than hunting for the decision.
     */
    public function emptyMonthPolicy(): string
    {
        return 'emit_empty_sheet';
    }

    /**
     * The download name: `CONSULTORA_DH_2026_ENERO-OCTUBRE.xlsx`.
     *
     * Safe by construction — the year is a validated integer and the month names are this class's
     * own constant — so there is nothing here that a caller could put a path separator into.
     */
    public function filename(): string
    {
        $from = self::MONTH_NAMES[$this->months[0]] ?? (string) $this->months[0];
        $last = $this->months[count($this->months) - 1];
        $to = self::MONTH_NAMES[$last] ?? (string) $last;

        $span = count($this->months) === 1
            ? $from
            : ($from === $to ? $from : $from.'-'.$to);

        return sprintf('CONSULTORA_DH_%d_%s.xlsx', $this->year, $span);
    }

    /**
     * The filters as stored on the `generated_reports` row.
     *
     * `months` is stored as the raw month list rather than the derived range so a later download
     * rebuilds the **same** selection even if the range syntax changes.
     *
     * @return array<string, mixed>
     */
    public function toFilters(): array
    {
        return [
            'year' => $this->year,
            'months' => $this->months,
            'company_id' => $this->companyId,
        ];
    }
}

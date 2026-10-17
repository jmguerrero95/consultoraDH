<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Reports\Exceptions\UnknownReportType;

/**
 * The closed set of reports this milestone offers.
 *
 * §51 is explicit that "dynamic reporting" means safe filters over approved report
 * types, not a query builder handed to a user. So the type is an enum with a fixed
 * case list, a filter schema per case, and a factory that refuses anything else.
 *
 * There is no way to reach a column name, a model or a fragment of SQL from a request
 * body: the only thing a caller may send is a value for a filter this class declares.
 */
enum ReportType: string
{
    case Morosos = 'morosos';
    case Vencidos = 'vencidos';
    case PorEmpresa = 'por_empresa';
    case PorEntidad = 'por_entidad';
    case Planillas = 'planillas';

    /**
     * FIX-EXPORT-IMPORT-03: the operational workbook, in the historical shape.
     *
     * A `ReportType` even though it is not a flat table, so it reaches the existing generated-
     * reports list, the audit trail and the storage lifecycle instead of growing a second set of
     * them beside the first. What it is **not** is a `ReportResult`: the workbook is monthly
     * sheets of company blocks with colours, and forcing it through a `columns()`/`rows()` shape
     * would mean inventing a flat representation and then re-interpreting it — the exact thing the
     * milestone forbids. `ReportFactory` is therefore not consulted for this type; see
     * `ReportGenerator::writeToFile()`.
     */
    case LibroOperativo = 'libro_operativo';

    public function label(): string
    {
        return match ($this) {
            // MEJ-008: the old name, "Morosos / cartera pendiente", read as "people who
            // owe money late" while the report also lists clients whose instalment is
            // not yet due. It is a pending-portfolio report, and it says so now, so it
            // is not confused with the overdue-only one below.
            self::Morosos => 'Cartera pendiente (todos los saldos)',
            self::Vencidos => 'Cartera vencida (solo saldos vencidos)',
            self::PorEmpresa => 'Por empresa',
            self::PorEntidad => 'Por entidad de seguridad social',
            self::Planillas => 'Resumen de planillas',
            self::LibroOperativo => 'Libro operativo (formato original)',
        };
    }

    /** The permission a caller must hold to read this report at all. */
    public function permission(): string
    {
        return match ($this) {
            self::Morosos, self::Vencidos => 'reports.view',
            self::PorEmpresa => 'reports.view',
            self::PorEntidad => 'reports.view',
            self::Planillas => 'planillas.view',
            // `reports.export`, not `reports.view`. The file contains every worker's document
            // number, address, phone and salary across every selected month, which is strictly
            // more than the on-screen portfolio report a role with `reports.view` may read — so
            // viewing and downloading are separate gates, and this one is the stricter.
            self::LibroOperativo => 'reports.export',
        };
    }

    public function supportsFormats(): array
    {
        // XLSX only. The colours, widths and borders are the point, and neither CSV nor PDF can
        // carry them; offering them would offer a file that loses the thing it was asked for.
        return $this === self::LibroOperativo
            ? ['xlsx']
            : ['screen', 'csv', 'xlsx', 'pdf'];
    }

    /**
     * The filters this report accepts, with their validators.
     *
     * Returned as `field => [rule, ...]` so the same declaration builds both the
     * validation rules and the sanitiser. A filter that is not declared here cannot
     * arrive: `ReportFilter::validate()` reads this map and nothing else.
     *
     * @return array<string, list<string>>
     */
    public function filterSchema(): array
    {
        return match ($this) {
            self::Morosos => [
                'as_of' => ['nullable', 'date'],
                'company_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:120'],
                'aging_bucket' => ['nullable', 'string'],
                'traffic_light' => ['nullable', 'string'],
                'min_balance' => ['nullable', 'integer', 'min:0'],
            ],
            self::Vencidos => [
                'as_of' => ['nullable', 'date'],
                'company_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:120'],
                'traffic_light' => ['nullable', 'string'],
            ],
            self::PorEmpresa => [
                'company_id' => ['nullable', 'integer'],
                'as_of' => ['nullable', 'date'],
            ],
            self::PorEntidad => [
                'entity_type' => ['nullable', 'string'],
                'entity_id' => ['nullable', 'integer'],
                'client_id' => ['nullable', 'integer'],
            ],
            self::Planillas => [
                'period_id' => ['nullable', 'integer'],
                'company_id' => ['nullable', 'integer'],
                'operator' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
                'submitted_from' => ['nullable', 'date'],
                'submitted_to' => ['nullable', 'date'],
            ],
            self::LibroOperativo => [
                // Deliberately **not** `integer`. The month filter accepts `1,2,3` and `1-10` as one
                // string, and validating it as an integer here would reject the range before
                // `LegacyWorkbookSelection` ever got to explain what it accepts.
                'year' => ['required', 'integer', 'min:2000', 'max:2100'],
                'months' => ['nullable', 'string', 'max:40'],
                'company_id' => ['nullable', 'integer'],
            ],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw UnknownReportType::for($value);
    }
}

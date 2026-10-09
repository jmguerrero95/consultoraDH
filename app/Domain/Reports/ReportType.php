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

    public function label(): string
    {
        return match ($this) {
            self::Morosos => 'Morosos / cartera pendiente',
            self::Vencidos => 'Vencidos',
            self::PorEmpresa => 'Por empresa',
            self::PorEntidad => 'Por entidad de seguridad social',
            self::Planillas => 'Resumen de planillas',
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
        };
    }

    public function supportsFormats(): array
    {
        return ['screen', 'csv', 'xlsx', 'pdf'];
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

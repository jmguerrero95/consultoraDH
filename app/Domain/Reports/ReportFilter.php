<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\TrafficLight;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\PlanillaOperator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The one filter contract every report, screen and export shares.
 *
 * §53 requires that the browser view, the PDF, the CSV and the XLSX "use the same filter
 * contract". They do because they all call `validate()` here. A screen cannot show one
 * population and its download export another without two code paths disagreeing, and
 * there is only one.
 *
 * ## Nothing undeclared gets through
 *
 * The rules are read from `ReportType::filterSchema()`, so a field that is not declared
 * for that report is dropped rather than passed to a query builder. This is what makes
 * §51's "no arbitrary user-provided SQL, column names or model names" true by
 * construction rather than by review.
 */
final class ReportFilter
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public static function validate(ReportType $type, array $filters): array
    {
        $schema = $type->filterSchema();

        // Only declared fields, so an extra key is silently not a query parameter.
        $filtered = array_intersect_key($filters, $schema);

        $validator = Validator::make($filtered, $schema, [
            'as_of.date' => 'La fecha debe tener formato YYYY-MM-DD.',
            'min_balance.integer' => 'El saldo mínimo debe ser un número entero.',
        ]);

        $validator->validate();

        return $validator->validated();
    }

    /** Convenience for a request: validate a report's filters from the query/body. */
    public static function fromRequest(ReportType $type, Request $request): array
    {
        return self::validate($type, $request->all());
    }

    /**
     * The human-readable filter description printed on a PDF header.
     *
     * §54 requires the PDF to show the active filters, so this has to exist somewhere
     * every format can call.
     *
     * BUG-006: this used to print the field name and its raw value — `aging_bucket:
     * not_due`, `entity_type: CCF` — so the PDF, the CSV and the XLSX headers carried
     * the application's own identifiers. The names are now the words the screen uses
     * and the values are passed through the enum that owns them.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public static function describe(array $filters): array
    {
        $described = [];

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $described[] = sprintf('%s: %s', self::fieldLabel($field), self::valueLabel($field, $value));
        }

        return $described;
    }

    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'as_of' => 'Fecha de corte',
        'company_id' => 'Empresa (id)',
        'client_id' => 'Cliente (id)',
        'entity_id' => 'Entidad (id)',
        'entity_type' => 'Tipo de entidad',
        'period_id' => 'Periodo (id)',
        'search' => 'Búsqueda',
        'aging_bucket' => 'Antigüedad',
        'traffic_light' => 'Semáforo',
        'min_balance' => 'Saldo mínimo',
        'minimum_balance' => 'Saldo mínimo',
        'overdue_only' => 'Solo vencidos',
        'operator' => 'Operador',
        'status' => 'Estado',
        'submitted_from' => 'Enviada desde',
        'submitted_to' => 'Enviada hasta',
        'year' => 'Año',
        'months' => 'Meses',
    ];

    private static function fieldLabel(string $field): string
    {
        // A field the map does not know still must not reach the reader as
        // `some_internal_key`, so it is at least written as words.
        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private static function valueLabel(string $field, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (! is_scalar($value)) {
            return (string) json_encode($value);
        }

        $raw = (string) $value;

        return match ($field) {
            'aging_bucket' => AgingBucket::tryFrom($raw)?->label() ?? $raw,
            'traffic_light' => TrafficLight::tryFrom($raw)?->label() ?? $raw,
            'entity_type' => SocialSecurityEntityType::tryFrom($raw)?->shortLabel() ?? $raw,
            'operator' => PlanillaOperator::tryFrom($raw)?->label() ?? $raw,
            'status' => ContributionSheetStatus::tryFrom($raw)?->label() ?? $raw,
            default => $raw,
        };
    }
}

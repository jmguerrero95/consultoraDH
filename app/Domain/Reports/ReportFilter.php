<?php

declare(strict_types=1);

namespace App\Domain\Reports;

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

            $described[] = sprintf('%s: %s', $field, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $described;
    }
}

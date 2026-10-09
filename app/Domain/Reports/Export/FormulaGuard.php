<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

/**
 * §53: escape a cell that begins with a formula prefix.
 *
 * A debtor called `=HYPERLINK("http://evil","click")` must not become a live formula when
 * an operator opens the CSV in Excel — that is spreadsheet formula injection, and it turns
 * "export the cartera" into "run this on the machine of whoever opens it".
 *
 * The escaping is applied only to text cells. A negative number is legitimate money and
 * is left alone, so `-500` exports as `-500` rather than as a string.
 */
final class FormulaGuard
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($value === '') {
            return $value;
        }

        if (! in_array(mb_substr($value, 0, 1), self::DANGEROUS_PREFIXES, true)) {
            return $value;
        }

        return "'".$value;
    }
}

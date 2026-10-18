<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Domain\Documents\StructuredTable;
use PHPUnit\Framework\TestCase;

/**
 * A07-R5-R2 — the table arithmetic, on its own.
 *
 * These are the numbers a language model repeated wrongly: B's payment read as
 * `25000` instead of `250000`, and a totals row that did not add up. The parser
 * and the computation are pure, so they are asserted here without a database,
 * without a provider and without a file.
 */
final class StructuredTableTest extends TestCase
{
    public function test_it_keeps_an_integer_integer(): void
    {
        $this->assertSame(250000, StructuredTable::parseNumber('250000'));
        $this->assertSame(250000, StructuredTable::parseNumber(' 250000 '));
        $this->assertSame(0, StructuredTable::parseNumber('0'));
    }

    public function test_it_reads_both_separator_conventions(): void
    {
        // Colombian: dot thousands, comma decimals.
        $this->assertSame(250000, StructuredTable::parseNumber('250.000'));
        $this->assertSame(1234567.89, StructuredTable::parseNumber('1.234.567,89'));

        // US: comma thousands, dot decimals.
        $this->assertSame(250000, StructuredTable::parseNumber('250,000'));
        $this->assertSame(1234567.89, StructuredTable::parseNumber('1,234,567.89'));
        $this->assertSame(1234.56, StructuredTable::parseNumber('1234.56'));
        $this->assertSame(-1234.5, StructuredTable::parseNumber('-$1,234.50'));
    }

    public function test_it_returns_null_for_what_is_not_a_number(): void
    {
        $this->assertNull(StructuredTable::parseNumber(''));
        $this->assertNull(StructuredTable::parseNumber('N/A'));
        $this->assertNull(StructuredTable::parseNumber('—'));
        $this->assertNull(StructuredTable::parseNumber('-'));
    }

    public function test_it_computes_the_fixture_balance_and_totals(): void
    {
        $table = StructuredTable::fromRawRows([
            ['Categoría', 'Obligación', 'Pagado'],
            ['A', '300000', '100000'],
            ['B', '250000', '250000'],
            ['C', '500000', '0'],
        ]);

        $this->assertSame(1, $table->obligationColumn);
        $this->assertSame(2, $table->paidColumn);

        $sheet = $table->computedSheet();

        $this->assertSame(1050000, $sheet['totals']['Obligación']);
        $this->assertSame(350000, $sheet['totals']['Pagado']);
        $this->assertSame(700000, $sheet['totals']['Saldo pendiente (calculado)']);

        // A = 200000, B = 0, C = 500000.
        $this->assertSame(200000, $sheet['rows'][0][3]);
        $this->assertSame(0, $sheet['rows'][1][3]);
        $this->assertSame(500000, $sheet['rows'][2][3]);

        // The digits that were lost are not anywhere in the result: B's payment
        // is 250000, never 25000.
        $this->assertSame(250000, $sheet['rows'][1][2]);
        $this->assertNotContains(25000, $sheet['rows'][1], 'The value 25000 must not appear in B\'s row.');

        // The totals row is the last row and carries the computed sums.
        $last = $sheet['rows'][count($sheet['rows']) - 1];
        $this->assertSame('TOTAL', $last[0]);
        $this->assertSame(1050000, $last[1]);
        $this->assertSame(350000, $last[2]);
        $this->assertSame(700000, $last[3]);

        $this->assertSame([], $sheet['inconsistencies']);
    }

    public function test_it_flags_a_declared_balance_that_contradicts_the_rows(): void
    {
        $table = StructuredTable::fromRawRows([
            ['Concepto', 'Obligación', 'Pagado', 'Saldo'],
            ['B', '250000', '250000', '25000'],
        ]);

        $sheet = $table->computedSheet();

        $this->assertCount(1, $sheet['inconsistencies']);
        $this->assertStringContainsString('25.000', $sheet['inconsistencies'][0]['detail']);
        $this->assertSame(0, $sheet['rows'][0][3]);
    }

    public function test_a_header_that_names_a_payment_is_not_taken_for_the_obligation(): void
    {
        $table = StructuredTable::fromRawRows([
            ['Concepto', 'Total pagado'],
            ['A', '100000'],
        ]);

        $this->assertSame(1, $table->paidColumn);
        $this->assertNull($table->obligationColumn);

        $sheet = $table->computedSheet();
        $this->assertNull($sheet['computed_balance_column']);
    }

    public function test_without_a_payment_column_no_balance_is_invented(): void
    {
        $table = StructuredTable::fromRawRows([
            ['Concepto', 'Obligación'],
            ['A', '300000'],
        ]);

        $sheet = $table->computedSheet();

        $this->assertNull($sheet['computed_balance_column']);
        $this->assertSame(['Obligación' => 300000], $sheet['totals']);
    }
}

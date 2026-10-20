<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Services\Ai\Xlsx\FormulaValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FIX-A07-XLSX-01 — the formula allowlist is a parser, not a blocklist.
 *
 * A dangerous function must be refused even when it is nested, spaced or
 * written with another case. An external workbook or DDE reference must never
 * reach PhpSpreadsheet.
 */
final class FormulaValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function acceptedFormulas(): array
    {
        return [
            'subtraction' => ['=B2-C2'],
            'percentage' => ['=D2*10%'],
            'absolute references' => ['=$B$2-$C2'],
            'sum range' => ['=SUM(B2:B4)'],
            'cross-sheet cell' => ['=Cartera!B5'],
            'quoted cross-sheet cell' => ["='Cartera'!B5"],
            'cross-sheet range' => ['=SUM(Cartera!D2:D4)'],
            'countif' => ['=COUNTIF(Cartera!D2:D4,">0")'],
            'guarded division' => ['=IF(Cartera!B5=0,0,Cartera!C5/Cartera!B5)'],
            'iferror' => ['=IFERROR(A1/B1,0)'],
            'round' => ['=ROUND(A1,2)'],
            'comparison' => ['=A1>=B1'],
            'parentheses and power' => ['=(A1+B1)^2'],
            'lowercase function' => ['=sum(B2:B4)'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedFormulas(): array
    {
        return [
            'hyperlink' => ['=HYPERLINK("http://evil.test","x")'],
            'webservice' => ['=WEBSERVICE("http://evil.test")'],
            'indirect' => ['=INDIRECT("A1")'],
            'unknown function' => ['=NO_EXISTE(1)'],
            'external workbook' => ["='[otro.xlsx]Hoja1'!A1"],
            'dde' => ["=cmd|' /C calc'!A0"],
            'standalone range' => ['=B2:B4'],
            'concatenation' => ['=A1&"x"'],
            'missing parenthesis' => ['=SUM(B2:B4'],
            'too many arguments' => ['=IF(A1>0,1)'],
        ];
    }

    #[DataProvider('acceptedFormulas')]
    public function test_supported_formulas_are_accepted(string $formula): void
    {
        $validator = new FormulaValidator;

        $this->assertNotSame('', $validator->validate(
            $formula,
            'Cartera',
            [
                'Cartera' => ['rows' => 5, 'cols' => 6],
                'Resumen' => ['rows' => 8, 'cols' => 2],
            ],
            ['Cartera', 'Resumen'],
        ));
    }

    #[DataProvider('rejectedFormulas')]
    public function test_dangerous_or_malformed_formulas_are_rejected(string $formula): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FormulaValidator)->validate(
            $formula,
            'Cartera',
            [
                'Cartera' => ['rows' => 5, 'cols' => 6],
                'Resumen' => ['rows' => 8, 'cols' => 2],
            ],
            ['Cartera', 'Resumen'],
        );
    }

    public function test_percentage_scale_detection_rejects_the_hundred_multiplier(): void
    {
        $validator = new FormulaValidator;

        $this->assertTrue($validator->isScaledByHundred('=A1/B1*100'));
        $this->assertTrue($validator->isScaledByHundred('=100*A1/B1'));
        $this->assertTrue($validator->isScaledByHundred('=A1/B1/0.01'));
        $this->assertTrue($validator->isScaledByHundred('=A1/B1*10^2'));
        $this->assertFalse($validator->isScaledByHundred('=A1/B1*100%'));
        $this->assertFalse($validator->isScaledByHundred('=A1/B1'));
    }
}

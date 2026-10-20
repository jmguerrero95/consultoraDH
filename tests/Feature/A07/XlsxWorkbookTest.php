<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\Tools\CreateArtifactTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * FIX-A07-XLSX-01 — the Excel contract is more than "the bytes open".
 *
 * These tests load the real workbook, inspect its sheets, formulas, formats and
 * calculated values, then change a source cell and recalculate. A file that
 * merely has an .xlsx extension is not a pass.
 */
final class XlsxWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'artifacts.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['artifacts.create', 'ai.use']);

        Storage::fake('local');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function create(array $arguments): array
    {
        $tool = app(CreateArtifactTool::class);

        return $tool->execute($tool->validate($arguments), $this->user);
    }

    private function load(array $result): Spreadsheet
    {
        $artifact = AiArtifact::query()->findOrFail($result['artifact_id']);

        return IOFactory::load(Storage::disk('local')->path($artifact->storage_path));
    }

    /**
     * @return array<string, mixed>
     */
    private function carteraBook(): array
    {
        $formula = static fn (string $expression): array => [
            'type' => 'formula',
            'expression' => $expression,
        ];

        return [
            'version' => 2,
            'sheets' => [
                [
                    'name' => 'Cartera',
                    'rows' => [
                        ['Cliente', 'Obligación', 'Pagado', 'Saldo pendiente', 'Descuento 10 %', 'Saldo ajustado'],
                        ['Prueba Alfa', 300000, 100000, $formula('=B2-C2'), $formula('=D2*10%'), $formula('=D2-E2')],
                        ['Prueba Beta', 250000, 250000, $formula('=B3-C3'), $formula('=D3*10%'), $formula('=D3-E3')],
                        ['Prueba Gamma', 500000, 0, $formula('=B4-C4'), $formula('=D4*10%'), $formula('=D4-E4')],
                        [
                            'Totales',
                            $formula('=SUM(B2:B4)'),
                            $formula('=SUM(C2:C4)'),
                            $formula('=SUM(D2:D4)'),
                            $formula('=SUM(E2:E4)'),
                            $formula('=SUM(F2:F4)'),
                        ],
                    ],
                    'column_formats' => [
                        'B' => 'currency_cop',
                        'C' => 'currency_cop',
                        'D' => 'currency_cop',
                        'E' => 'currency_cop',
                        'F' => 'currency_cop',
                    ],
                    'freeze_pane' => 'A2',
                ],
                [
                    'name' => 'Resumen',
                    'rows' => [
                        ['Concepto', 'Valor'],
                        ['Obligaciones totales', $formula('=Cartera!B5')],
                        ['Pagado total', $formula('=Cartera!C5')],
                        ['Saldo pendiente', $formula('=Cartera!D5')],
                        ['Descuento total', $formula('=Cartera!E5')],
                        ['Saldo ajustado', $formula('=Cartera!F5')],
                        ['Clientes con deuda', $formula('=COUNTIF(Cartera!D2:D4,">0")')],
                        ['Recuperación', $formula('=IF(Cartera!B5=0,0,Cartera!C5/Cartera!B5)')],
                    ],
                    'cell_formats' => [
                        'B2' => 'currency_cop',
                        'B3' => 'currency_cop',
                        'B4' => 'currency_cop',
                        'B5' => 'currency_cop',
                        'B6' => 'currency_cop',
                        'B7' => 'number',
                        'B8' => 'percentage',
                    ],
                    'freeze_pane' => 'A2',
                ],
            ],
        ];
    }

    private function assertRejected(string $content, string $message): void
    {
        try {
            $this->create([
                'format' => 'xlsx',
                'filename' => 'rechazado',
                'content' => $content,
            ]);

            $this->fail('The invalid XLSX content must be rejected.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(0, AiArtifact::query()->count(), 'A rejected request must not persist an artifact.');
    }

    public function test_a_v2_workbook_has_the_requested_sheets_formulas_and_calculated_totals(): void
    {
        $result = $this->create([
            'format' => 'xlsx',
            'filename' => 'certificacion-cartera',
            'content' => json_encode($this->carteraBook(), JSON_UNESCAPED_UNICODE),
        ]);

        $spreadsheet = $this->load($result);

        $this->assertSame(['Cartera', 'Resumen'], $spreadsheet->getSheetNames());
        $this->assertSame(2, $spreadsheet->getSheetCount(), 'The workbook must not leave an extra empty Datos sheet.');

        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertNotNull($cartera);
        $this->assertNotNull($resumen);

        // Real formulas, not strings that merely start with "=".
        $this->assertSame('=B2-C2', $cartera->getCell('D2')->getValue());
        $this->assertSame('=D2*10%', $cartera->getCell('E2')->getValue());
        $this->assertSame('=D2-E2', $cartera->getCell('F2')->getValue());
        $this->assertSame('=SUM(B2:B4)', $cartera->getCell('B5')->getValue());
        $this->assertSame('=Cartera!B5', $resumen->getCell('B2')->getValue());
        $this->assertSame('=COUNTIF(Cartera!D2:D4,">0")', $resumen->getCell('B7')->getValue());

        $this->assertEqualsWithDelta(200000, $cartera->getCell('D2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(20000, $cartera->getCell('E2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(180000, $cartera->getCell('F2')->getCalculatedValue(), 0.001);

        $this->assertEqualsWithDelta(1050000, $cartera->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(350000, $cartera->getCell('C5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(700000, $cartera->getCell('D5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(70000, $cartera->getCell('E5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(630000, $cartera->getCell('F5')->getCalculatedValue(), 0.001);

        $this->assertEqualsWithDelta(1050000, $resumen->getCell('B2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(350000, $resumen->getCell('B3')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(700000, $resumen->getCell('B4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(70000, $resumen->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(630000, $resumen->getCell('B6')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(2, $resumen->getCell('B7')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1 / 3, $resumen->getCell('B8')->getCalculatedValue(), 0.000001);

        // Numbers stay numeric and carry a COP format.
        $this->assertIsNumeric($cartera->getCell('B2')->getValue());
        $this->assertStringContainsString('$', $cartera->getCell('B2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString('#,##0', $cartera->getCell('B2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00%', $resumen->getCell('B8')->getStyle()->getNumberFormat()->getFormatCode());

        $this->assertSame('A2', $cartera->getFreezePane());
        $this->assertSame('A2', $resumen->getFreezePane());
    }

    public function test_changing_a_source_cell_recalculates_every_dependent_formula_and_survives_save_reopen(): void
    {
        $result = $this->create([
            'format' => 'xlsx',
            'filename' => 'cartera-recalculo',
            'content' => json_encode($this->carteraBook(), JSON_UNESCAPED_UNICODE),
        ]);

        $spreadsheet = $this->load($result);
        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $cartera->setCellValue('B2', 400000);

        $this->assertEqualsWithDelta(300000, $cartera->getCell('D2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(30000, $cartera->getCell('E2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(270000, $cartera->getCell('F2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1150000, $cartera->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(800000, $cartera->getCell('D5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(80000, $cartera->getCell('E5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(720000, $cartera->getCell('F5')->getCalculatedValue(), 0.001);

        $this->assertEqualsWithDelta(1150000, $resumen->getCell('B2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(800000, $resumen->getCell('B4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(80000, $resumen->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(720000, $resumen->getCell('B6')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(2, $resumen->getCell('B7')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(350000 / 1150000, $resumen->getCell('B8')->getCalculatedValue(), 0.000001);

        $temp = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';

        try {
            (new Xlsx($spreadsheet))->save($temp);
            $reopened = IOFactory::load($temp);
        } finally {
            @unlink($temp);
        }

        $this->assertSame('=B2-C2', $reopened->getSheetByName('Cartera')->getCell('D2')->getValue());
        $this->assertSame('=SUM(B2:B4)', $reopened->getSheetByName('Cartera')->getCell('B5')->getValue());
        $this->assertSame(
            '=IF(Cartera!B5=0,0,Cartera!C5/Cartera!B5)',
            $reopened->getSheetByName('Resumen')->getCell('B8')->getValue()
        );
        $this->assertEqualsWithDelta(
            300000,
            $reopened->getSheetByName('Cartera')->getCell('D2')->getCalculatedValue(),
            0.001
        );
        $this->assertEqualsWithDelta(
            350000 / 1150000,
            $reopened->getSheetByName('Resumen')->getCell('B8')->getCalculatedValue(),
            0.000001
        );
    }

    public function test_the_legacy_row_array_still_produces_one_datos_sheet(): void
    {
        $result = $this->create([
            'format' => 'xlsx',
            'filename' => 'cartera-simple',
            'content' => json_encode([
                ['Cliente', 'Obligación', 'Pagado', 'Saldo'],
                ['Prueba Alfa', 300000, 100000, 200000],
                ['Prueba Beta', 250000, 250000, 0],
            ]),
        ]);

        $spreadsheet = $this->load($result);

        $this->assertSame(['Datos'], $spreadsheet->getSheetNames());
        $this->assertSame(1, $spreadsheet->getSheetCount());

        $datos = $spreadsheet->getActiveSheet();
        $this->assertSame('Cliente', $datos->getCell('A1')->getValue());
        $this->assertSame(300000, $datos->getCell('B2')->getValue());
        $this->assertSame(200000, $datos->getCell('D2')->getValue());
    }

    public function test_csv_keeps_its_legacy_contract_and_still_neutralises_formula_text(): void
    {
        $result = $this->create([
            'format' => 'csv',
            'filename' => 'cartera',
            'content' => json_encode([
                ['Cliente', 'Saldo'],
                ['Prueba Alfa', '=HYPERLINK("http://evil.test","x")'],
            ]),
        ]);

        $artifact = AiArtifact::query()->findOrFail($result['artifact_id']);
        $bytes = Storage::disk('local')->get($artifact->storage_path);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $bytes);
        $this->assertStringContainsString('Cliente,Saldo', $bytes);
        $this->assertStringContainsString("'=HYPERLINK", $bytes);
    }

    public function test_a_genuine_csv_or_markdown_body_still_produces_a_legacy_xlsx(): void
    {
        $markdown = $this->create([
            'format' => 'xlsx',
            'filename' => 'tabla-markdown',
            'content' => "| Cliente | Saldo |\n| --- | --- |\n| Prueba Alfa | 200000 |\n",
        ]);

        $spreadsheet = $this->load($markdown);
        $this->assertSame(['Datos'], $spreadsheet->getSheetNames());
        $this->assertSame('Cliente', $spreadsheet->getActiveSheet()->getCell('A1')->getValue());
        $this->assertSame(200000, $spreadsheet->getActiveSheet()->getCell('B2')->getValue());

        $csv = $this->create([
            'format' => 'csv',
            'filename' => 'tabla-csv',
            'content' => "Cliente,Saldo\nPrueba Alfa,200000\n",
        ]);

        $artifact = AiArtifact::query()->findOrFail($csv['artifact_id']);
        $bytes = Storage::disk('local')->get($artifact->storage_path);

        $this->assertStringContainsString('Cliente,Saldo', $bytes);
        $this->assertStringContainsString('"Prueba Alfa",200000', $bytes);
    }

    public function test_malformed_or_incompatible_json_is_rejected_instead_of_becoming_csv(): void
    {
        $this->assertRejected('{"version":2,"sheets":[', 'mal formado');
        $this->assertRejected('{"version":2}', 'no vacía');
        $this->assertRejected('{"foo":"bar"}', 'version');
        $this->assertRejected(json_encode([['Cliente' => 'Prueba Alfa']]), 'no una lista');
    }

    public function test_malformed_json_is_also_rejected_for_csv(): void
    {
        try {
            $this->create([
                'format' => 'csv',
                'filename' => 'csv-invalido',
                'content' => '{"version":2,"sheets":[',
            ]);

            $this->fail('Malformed JSON must not become a CSV file.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('mal formado', $exception->getMessage());
        }

        $this->assertSame(0, AiArtifact::query()->count());
    }

    public function test_invalid_or_duplicate_sheet_names_are_rejected(): void
    {
        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [
                ['name' => 'Cartera/Resumen', 'rows' => [['Cliente']]],
            ],
        ]), 'no permitidos');

        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [
                ['name' => 'Cartera', 'rows' => [['Cliente']]],
                ['name' => 'cartera', 'rows' => [['Cliente']]],
            ],
        ]), 'duplicadas');
    }

    public function test_unexpected_cell_objects_are_rejected_not_stringified(): void
    {
        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [['Cliente', ['foo' => 'bar']]],
            ]],
        ]), 'objeto no admitido');
    }

    public function test_dangerous_functions_and_external_references_are_rejected(): void
    {
        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [[
                    ['type' => 'formula', 'expression' => '=HYPERLINK("http://evil.test","x")'],
                ]],
            ]],
        ]), 'HYPERLINK no está admitida');

        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [[
                    ['type' => 'formula', 'expression' => "='[otro-libro.xlsx]Hoja1'!A1"],
                ]],
            ]],
        ]), 'no existe en el libro');

        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [[
                    ['type' => 'formula', 'expression' => "=cmd|' /C calc'!A0"],
                ]],
            ]],
        ]), 'carácter no admitido');
    }

    public function test_formula_text_from_untrusted_data_stays_text(): void
    {
        $result = $this->create([
            'format' => 'xlsx',
            'filename' => 'datos-seguros',
            'content' => json_encode([
                ['Dato'],
                ['=HYPERLINK("http://evil.test","x")'],
            ]),
        ]);

        $spreadsheet = $this->load($result);
        $cell = $spreadsheet->getSheetByName('Datos')->getCell('A2');

        $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
        $this->assertStringContainsString("'=HYPERLINK", (string) $cell->getValue());
    }

    public function test_a_v2_request_is_deduplicated_within_the_same_turn(): void
    {
        $conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Cartera',
        ]);
        app(AiTurnContext::class)->set($conversation->id, 'turn-xlsx-v2');

        $arguments = [
            'format' => 'xlsx',
            'filename' => 'cartera-dedupe',
            'content' => json_encode($this->carteraBook(), JSON_UNESCAPED_UNICODE),
        ];

        $first = $this->create($arguments);
        $second = $this->create($arguments);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertTrue($second['deduplicated']);
        $this->assertSame($first['artifact_id'], $second['artifact_id']);
        $this->assertSame(1, AiArtifact::query()->count());
    }

    public function test_a_failed_generation_leaves_no_file_and_no_artifact_row(): void
    {
        Storage::fake('local');

        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [[
                    ['type' => 'formula', 'expression' => '=NO_EXISTE(1)'],
                ]],
            ]],
        ]), 'no está admitida');

        $this->assertRejected(json_encode([
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => [[
                    ['type' => 'formula', 'expression' => '=1/0'],
                ]],
            ]],
        ]), 'produce #DIV/0!');

        $this->assertSame(0, AiArtifact::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

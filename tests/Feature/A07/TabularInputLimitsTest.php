<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\DocumentIngestor;
use App\Models\AiArtifact;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\TabularAttachmentReader;
use App\Services\Ai\TabularReportPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-XLSX-02 — untrusted input is bounded, and an ambiguous request is not
 * answered with a report for a different question.
 *
 * The four defects these tests pin down:
 *
 *  R01 a spreadsheet or CSV was loaded whole, with no byte, row, column, cell or
 *      archive ceiling, so a small or malicious file could exhaust the request;
 *  R02 a request that asked for grouping, filtering, sorting, a date range or a
 *      different shape was answered by the fixed report anyway;
 *  R03 "descuento de 10.000 COP" (an amount) was read as a 10 % rate;
 *  R04 a multi-sheet workbook was read from its active sheet without saying so.
 *
 * A refusal is the expected outcome in each case. The deterministic three-client
 * baseline is asserted again at the end so the hardening cannot change the
 * numbers the certified report already produces.
 */
final class TabularInputLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiConversation $conversation;

    /** @var list<array<string, mixed>> */
    private array $providerCalls = [];

    private const QA_TABLE = [
        ['Cliente', 'Obligacion', 'Pagado'],
        ['Prueba Alfa', 300000, 100000],
        ['Prueba Beta', 250000, 250000],
        ['Prueba Gamma', 500000, 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'artifacts.create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'artifacts.create']);

        Storage::fake('local');

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Input limits QA',
        ]);

        config([
            'ai.enabled' => true,
            'ai.suggestions.reasoning.enabled' => false,
        ]);

        $this->providerCalls = [];

        Http::fake(function (ClientRequest $request): mixed {
            $this->providerCalls[] = $request->data();

            return Http::response([], 500);
        });
    }

    private function attachCsv(string $csv, string $name = 'cartera.csv'): AiAttachment
    {
        $path = tempnam(sys_get_temp_dir(), 'cdh-limits').'.csv';
        file_put_contents($path, $csv);

        try {
            $attachment = app(DocumentIngestor::class)->ingest(
                new UploadedFile($path, $name, 'text/csv', null, true),
                $this->user,
                $this->conversation->id,
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(AiAttachment::STATUS_PROCESSED, $attachment->status);

        return $attachment;
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    private function attachWorkbook(array $sheets, string $name = 'libro.xlsx'): AiAttachment
    {
        $book = new Spreadsheet;
        $first = true;

        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
            $first = false;
            $sheet->setTitle($title);
            $sheet->fromArray($rows, null, 'A1', true);
        }

        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'cdh-limits').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        try {
            $attachment = app(DocumentIngestor::class)->ingest(
                new UploadedFile(
                    $path,
                    $name,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true,
                ),
                $this->user,
                $this->conversation->id,
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(AiAttachment::STATUS_PROCESSED, $attachment->status);

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $question): array
    {
        $response = $this->actingAs($this->user)->postJson(
            "/api/ai/conversations/{$this->conversation->id}/messages",
            ['message' => $question],
        );

        $response->assertOk();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function loadWorkbook(array $result): Spreadsheet
    {
        $this->assertNotEmpty($result['artifacts'] ?? []);
        $artifact = AiArtifact::query()->findOrFail((int) $result['artifacts'][0]['id']);

        return IOFactory::load(Storage::disk('local')->path($artifact->storage_path));
    }

    private function planner(): TabularReportPlanner
    {
        return app(TabularReportPlanner::class);
    }

    // ---------------------------------------------------------------- R03

    public function test_a_monetary_discount_is_never_read_as_a_percentage(): void
    {
        $this->attachCsv($this->csvFromRows(self::QA_TABLE));

        $base = 'Genera un libro de cartera desde el adjunto';

        $this->assertNull(
            $this->planner()->plan($this->conversation, $this->user, $base.' con descuento de 10.000 COP.'),
            'A monetary discount amount is not a rate.',
        );

        $this->assertNull(
            $this->planner()->plan($this->conversation, $this->user, $base.' con un descuento de 100.000.'),
            'A bare amount after "descuento" is ambiguous.',
        );

        $this->assertNull(
            $this->planner()->plan($this->conversation, $this->user, $base.' con descuento del 10.000 %.'),
            'A dot followed by three digits is a thousands separator, not a rate.',
        );

        $percent = $this->planner()->plan(
            $this->conversation,
            $this->user,
            $base.' con descuento del 10 %.',
        );

        $this->assertNotNull($percent);
        $this->assertSame(10.0, $percent->discountPercent);

        $worded = $this->planner()->plan(
            $this->conversation,
            $this->user,
            $base.' con descuento del 7,5 por ciento.',
        );

        $this->assertNotNull($worded);
        $this->assertSame(7.5, $worded->discountPercent);
    }

    // ---------------------------------------------------------------- R02

    public function test_requests_for_a_shape_the_fixed_report_does_not_produce_are_declined(): void
    {
        $this->attachCsv($this->csvFromRows(self::QA_TABLE));

        $base = 'Genera un libro de cartera desde el adjunto con descuento del 10 %';

        $unsupported = [
            'agrupado por cliente',
            'con los clientes agrupados por saldo',
            'filtrando solo los clientes morosos',
            'solo los clientes con deuda',
            'ordenado de mayor a menor',
            'top 5 deudores',
            'con saldo mayor a 100000',
            'con saldo entre 100000 y 500000',
            'del mes de octubre',
            'desde el 1 de octubre',
            'con una columna adicional',
            'como tabla dinamica',
            'con un grafico de barras',
            'con subtotal por cliente',
        ];

        foreach ($unsupported as $suffix) {
            $this->assertNull(
                $this->planner()->plan($this->conversation, $this->user, $base.', '.$suffix.'.'),
                "The fixed report cannot honour: {$suffix}",
            );
        }

        $this->assertNotNull(
            $this->planner()->plan(
                $this->conversation,
                $this->user,
                $base.' con fórmulas reales y los siete indicadores.',
            ),
            'A plain request for the standard report must still be recognised.',
        );
    }

    public function test_an_unsupported_request_falls_through_instead_of_building_the_wrong_report(): void
    {
        $this->attachCsv($this->csvFromRows(self::QA_TABLE));

        $response = $this->actingAs($this->user)->postJson(
            "/api/ai/conversations/{$this->conversation->id}/messages",
            [
                'message' => 'Genera un libro de cartera desde el adjunto agrupado por cliente '
                    .'con descuento del 10 %.',
            ],
        );

        // The request reaches the normal assistant (the fake provider fails),
        // and no deterministic artifact is produced for an unasked-for shape.
        $response->assertStatus(503);
        $this->assertNotSame('tabular_report_rejected', $response->json('code'));
        $this->assertSame(0, AiArtifact::query()->count());
    }

    // ---------------------------------------------------------------- R04

    public function test_a_multi_sheet_workbook_is_refused_until_a_sheet_is_named(): void
    {
        $attachment = $this->attachWorkbook([
            'Ventas' => self::QA_TABLE,
            'Compras' => [
                ['Cliente', 'Obligacion', 'Pagado'],
                ['Compras Uno', 200000, 50000],
            ],
        ]);

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A multi-sheet workbook must not silently use its active sheet.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('varias hojas', $exception->getMessage());
            $this->assertStringContainsString('Ventas', $exception->getMessage());
            $this->assertStringContainsString('Compras', $exception->getMessage());
        }

        $this->assertNull(
            $this->planner()->plan(
                $this->conversation,
                $this->user,
                'Genera un libro de cartera desde el adjunto con descuento del 10 %.',
            ),
            'With several sheets and no sheet named, the planner must decline.',
        );
    }

    public function test_a_named_sheet_is_the_one_that_feeds_the_report(): void
    {
        $this->attachWorkbook([
            'Ventas' => self::QA_TABLE,
            'Compras' => [
                ['Cliente', 'Obligacion', 'Pagado'],
                ['Compras Uno', 200000, 50000],
                ['Compras Dos', 100000, 100000],
            ],
        ]);

        $plan = $this->planner()->plan(
            $this->conversation,
            $this->user,
            'Genera un libro de cartera desde el adjunto usando la hoja Compras, '
                .'con resumen y descuento del 10 %.',
        );

        $this->assertNotNull($plan);
        $this->assertSame('Compras', $plan->sheetName);

        $result = $this->ask(
            'Genera un libro de cartera desde el adjunto usando la hoja Compras, '
                .'con resumen y descuento del 10 %.',
        );

        $this->assertSame('tabular_report_v1', $result['model']);
        $this->assertSame([], $this->providerCalls);

        $spreadsheet = $this->loadWorkbook($result);
        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertNotNull($cartera);
        $this->assertNotNull($resumen);
        $this->assertSame('Compras Uno', $cartera->getCell('A2')->getValue());
        $this->assertSame('Compras Dos', $cartera->getCell('A3')->getValue());
        $this->assertEqualsWithDelta(300000, $cartera->getCell('B4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(150000, $cartera->getCell('C4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(150000, $resumen->getCell('B4')->getCalculatedValue(), 0.001);
    }

    public function test_a_single_sheet_workbook_needs_no_sheet_name(): void
    {
        $this->attachWorkbook(['Datos QA' => self::QA_TABLE]);

        $result = $this->ask(
            'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
        );

        $this->assertSame('tabular_report_v1', $result['model']);
        $this->assertSame([], $this->providerCalls);

        $spreadsheet = $this->loadWorkbook($result);
        $cartera = $spreadsheet->getSheetByName('Cartera');

        $this->assertSame('Prueba Alfa', $cartera->getCell('A2')->getValue());
        $this->assertEqualsWithDelta(1050000, $cartera->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(700000, $cartera->getCell('D5')->getCalculatedValue(), 0.001);
    }

    // ---------------------------------------------------------------- R01

    public function test_a_delimited_file_over_the_row_limit_is_refused(): void
    {
        $csv = "Cliente,Obligacion,Pagado\n";

        for ($index = 1; $index <= TabularAttachmentReader::MAX_ROWS + 1; $index++) {
            $csv .= "Cliente {$index},100000,0\n";
        }

        $attachment = $this->attachCsv($csv, 'demasiadas-filas.csv');

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A file above the row ceiling must be refused, not truncated.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('5000 filas', $exception->getMessage());
        }
    }

    public function test_a_delimited_file_over_the_column_limit_is_refused(): void
    {
        $header = implode(',', array_map(static fn (int $i): string => "Columna{$i}", range(1, TabularAttachmentReader::MAX_COLUMNS + 1)));
        $row = implode(',', array_fill(0, TabularAttachmentReader::MAX_COLUMNS + 1, '1'));

        $attachment = $this->attachCsv($header."\n".$row."\n", 'demasiadas-columnas.csv');

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A file above the column ceiling must be refused.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('100 columnas', $exception->getMessage());
        }
    }

    public function test_a_delimited_file_over_the_cell_limit_is_refused(): void
    {
        $columns = 90;
        $rows = intdiv(TabularAttachmentReader::MAX_CELLS, $columns) + 1;

        $header = implode(',', array_map(static fn (int $i): string => "Columna{$i}", range(1, $columns)));
        $line = implode(',', array_fill(0, $columns, '1'));

        $csv = $header."\n";
        $csv .= str_repeat($line."\n", $rows);

        $attachment = $this->attachCsv($csv, 'demasiadas-celdas.csv');

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A file above the cell ceiling must be refused.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('50000 celdas', $exception->getMessage());
        }
    }

    public function test_a_file_over_the_byte_limit_is_refused_before_it_is_read(): void
    {
        $path = 'ai-attachments/'.$this->user->id.'/demasiado-grande.csv';
        Storage::disk('local')->put($path, str_repeat('a', TabularAttachmentReader::MAX_FILE_BYTES + 1));

        $attachment = AiAttachment::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $this->conversation->id,
            'storage_path' => $path,
            'original_name' => 'demasiado-grande.csv',
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'size_bytes' => TabularAttachmentReader::MAX_FILE_BYTES + 1,
            'detected_kind' => 'csv',
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A file above the byte ceiling must be refused.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('25 MB', $exception->getMessage());
        }
    }

    public function test_a_workbook_that_declares_too_many_rows_is_refused_before_loading(): void
    {
        $rows = [['Cliente', 'Obligacion', 'Pagado']];

        for ($index = 1; $index <= TabularAttachmentReader::MAX_ROWS + 1; $index++) {
            $rows[] = ["Cliente {$index}", 100000, 0];
        }

        $attachment = $this->attachWorkbook(['Cartera' => $rows], 'demasiadas-filas.xlsx');

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A workbook above the row ceiling must be refused.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('5000 filas', $exception->getMessage());
        }
    }

    public function test_an_unreadable_spreadsheet_is_refused_with_an_actionable_message(): void
    {
        $path = 'ai-attachments/'.$this->user->id.'/roto.xlsx';
        Storage::disk('local')->put($path, 'not a zip archive');

        $attachment = AiAttachment::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $this->conversation->id,
            'storage_path' => $path,
            'original_name' => 'roto.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'extension' => 'xlsx',
            'size_bytes' => 17,
            'detected_kind' => 'xlsx',
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);

        try {
            app(TabularAttachmentReader::class)->rows($attachment);
            $this->fail('A file that is not a real XLSX must be refused.');
        } catch (AiToolRejected $exception) {
            $this->assertStringContainsString('no es un archivo comprimido válido', $exception->getMessage());
        }
    }

    // ------------------------------------------------------ baseline intact

    public function test_the_certified_three_client_baseline_is_unchanged(): void
    {
        $this->attachCsv($this->csvFromRows(self::QA_TABLE));

        $result = $this->ask(
            'Analiza el Excel adjunto y genera un libro con Cartera y Resumen, '
                .'descuento del 10 %, fórmulas reales y los siete indicadores.',
        );

        $this->assertSame('tabular_report_v1', $result['model']);
        $this->assertSame([], $this->providerCalls);

        $spreadsheet = $this->loadWorkbook($result);
        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertEqualsWithDelta(1050000, $cartera->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(350000, $cartera->getCell('C5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(700000, $cartera->getCell('D5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(70000, $cartera->getCell('E5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(630000, $cartera->getCell('F5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(2, $resumen->getCell('B7')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(1 / 3, $resumen->getCell('B8')->getCalculatedValue(), 0.000001);
        $this->assertStringContainsString('33.33%', $resumen->getCell('B8')->getFormattedValue());
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function csvFromRows(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(static fn (mixed $value): string => (string) $value, $row));
        }

        return implode("\n", $lines)."\n";
    }
}

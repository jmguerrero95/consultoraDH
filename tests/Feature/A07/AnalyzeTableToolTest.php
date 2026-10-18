<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\DocumentIngestor;
use App\Models\AiArtifact;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\Tools\AnalyzeTableTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R2 (bloque B/C3) — an attached table is computed, not restated.
 *
 * The defect was a `cartera.csv` turned into an Excel file where B's payment was
 * `25000` and the totals did not add up. The fix is that the numbers come from
 * the file the person attached, parsed and summed in PHP, so what the model says
 * and what the file contains cannot drift apart.
 */
final class AnalyzeTableToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'artifacts.create', 'tasks.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'artifacts.create', 'tasks.view']);

        Storage::fake('local');

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Cartera',
        ]);
    }

    private function attachCsv(string $contents, string $name = 'cartera.csv'): AiAttachment
    {
        $path = tempnam(sys_get_temp_dir(), 'cdh-csv').'.csv';
        file_put_contents($path, $contents);

        $attachment = app(DocumentIngestor::class)->ingest(
            new UploadedFile($path, $name, 'text/csv', null, true),
            $this->user,
            $this->conversation->id,
        );

        @unlink($path);

        return $attachment;
    }

    private const CARTERA = "Categoria,Obligacion,Pagado\nA,300000,100000\nB,250000,250000\nC,500000,0\n";

    /**
     * Run the tool the way the registry does: validate, then execute.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runTool(array $arguments): array
    {
        $tool = app(AnalyzeTableTool::class);

        return $tool->execute($tool->validate($arguments), $this->user);
    }

    public function test_the_exported_xlsx_carries_the_files_own_numbers_and_computed_totals(): void
    {
        $this->attachCsv(self::CARTERA);

        app(AiTurnContext::class)->set($this->conversation->id, 'turn-1');

        $result = $this->runTool(['export' => 'xlsx', 'filename' => 'gestion-de-deudas']);

        // The verified totals, computed from the file.
        $this->assertSame(1050000, $result['computed']['totals']['Obligacion']);
        $this->assertSame(350000, $result['computed']['totals']['Pagado']);
        $this->assertSame(700000, $result['computed']['totals']['Saldo pendiente (calculado)']);
        $this->assertSame([], $result['computed']['inconsistencies']);

        // A real artifact was produced.
        $this->assertTrue($result['artifact']['created']);
        $artifact = AiArtifact::query()->findOrFail($result['artifact']['artifact_id']);
        $this->assertSame('xlsx', $artifact->format);

        // Read the actual bytes back — the file is a real spreadsheet.
        $absolute = Storage::disk('local')->path($artifact->storage_path);
        $sheet = SpreadsheetFactory::load($absolute)->getActiveSheet();

        $this->assertSame('Categoria', $sheet->getCell('A1')->getValue());
        $this->assertSame(300000, $sheet->getCell('B2')->getValue());
        $this->assertSame(100000, $sheet->getCell('C2')->getValue());
        $this->assertSame(200000, $sheet->getCell('D2')->getValue(), 'A: 300000 - 100000');

        // B is the row the model got wrong: 250000 paid, 0 pending.
        $this->assertSame(250000, $sheet->getCell('C3')->getValue());
        $this->assertSame(0, $sheet->getCell('D3')->getValue());

        $this->assertSame(500000, $sheet->getCell('B4')->getValue());
        $this->assertSame(500000, $sheet->getCell('D4')->getValue());

        // Totals row.
        $this->assertSame('TOTAL', $sheet->getCell('A5')->getValue());
        $this->assertSame(1050000, $sheet->getCell('B5')->getValue());
        $this->assertSame(350000, $sheet->getCell('C5')->getValue());
        $this->assertSame(700000, $sheet->getCell('D5')->getValue());
    }

    public function test_analysis_without_export_returns_data_and_writes_no_file(): void
    {
        $this->attachCsv(self::CARTERA);
        app(AiTurnContext::class)->set($this->conversation->id, 'turn-1');

        $result = $this->runTool([]);

        $this->assertSame(700000, $result['computed']['totals']['Saldo pendiente (calculado)']);
        $this->assertArrayNotHasKey('artifact', $result);
        $this->assertSame(0, AiArtifact::query()->count());
    }

    public function test_it_reads_semicolon_delimited_and_colombian_formatted_numbers(): void
    {
        $this->attachCsv("Categoria;Obligacion;Pagado\nA;300.000;100.000\nB;250.000;250.000\nC;500.000;0\n");

        app(AiTurnContext::class)->set($this->conversation->id, 'turn-1');

        $result = $this->runTool([]);

        $this->assertSame(1050000, $result['computed']['totals']['Obligacion']);
        $this->assertSame(350000, $result['computed']['totals']['Pagado']);
        $this->assertSame(700000, $result['computed']['totals']['Saldo pendiente (calculado)']);
    }

    public function test_it_refuses_to_reach_an_attachment_from_another_conversation(): void
    {
        $other = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Otra']);
        $attachment = $this->attachCsv(self::CARTERA);

        app(AiTurnContext::class)->set($other->id, 'turn-2');

        $this->expectException(AiToolRejected::class);

        // The attachment belongs to `$this->conversation`; asking from `$other`
        // must not find it.
        $this->runTool(['attachment_id' => $attachment->id]);
    }

    public function test_the_tool_is_registered_and_read_only(): void
    {
        $tool = app(AiToolRegistry::class)->get('analyze_table');

        $this->assertFalse($tool->isWrite());
        $this->assertArrayHasKey('analyze_table', app(AiToolRegistry::class)->all());
    }
}

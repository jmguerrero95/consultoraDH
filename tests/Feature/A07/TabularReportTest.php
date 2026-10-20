<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\DocumentIngestor;
use App\Models\AiArtifact;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\TabularReportPlanner;
use App\Services\Ai\TabularReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-XLSX-04 — the attached table is the source, not a model's memory.
 *
 * These tests drive the normal assistant endpoint. The deterministic planner
 * must complete a clear receivables request before any provider is called, and
 * the resulting file must be a real workbook with dynamic ranges, formulas,
 * seven indicators and a working recalculation.
 */
final class TabularReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiConversation $conversation;

    /** @var list<array<string, mixed>> */
    private array $providerCalls = [];

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
            'title' => 'Cartera QA',
        ]);

        config([
            'ai.enabled' => true,
            'ai.suggestions.reasoning.enabled' => false,
        ]);

        $this->providerCalls = [];

        // The deterministic route must make no provider call. If it accidentally
        // falls through, this fake makes that visible without reaching a network.
        Http::fake(function (ClientRequest $request): mixed {
            $this->providerCalls[] = $request->data();

            return Http::response([], 500);
        });
    }

    private function conversationFor(User $user, string $title = 'Cartera'): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function attach(
        string $csv,
        string $name = 'cartera.csv',
        ?AiConversation $conversation = null,
        ?User $user = null,
    ): AiAttachment {
        $conversation ??= $this->conversation;
        $user ??= $this->user;

        $path = tempnam(sys_get_temp_dir(), 'cdh-report').'.csv';
        file_put_contents($path, $csv);

        try {
            $attachment = app(DocumentIngestor::class)->ingest(
                new UploadedFile($path, $name, 'text/csv', null, true),
                $user,
                $conversation->id,
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
    private function ask(string $question, ?AiConversation $conversation = null, ?string $clientTurnId = null): array
    {
        $conversation ??= $this->conversation;

        $response = $this->actingAs($this->user)->postJson(
            "/api/ai/conversations/{$conversation->id}/messages",
            array_filter([
                'message' => $question,
                'client_turn_id' => $clientTurnId,
            ], static fn ($value): bool => $value !== null),
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

    private const QA_TABLE = "Cliente,Obligacion,Pagado\n"
        ."Prueba Alfa,300000,100000\n"
        ."Prueba Beta,250000,250000\n"
        ."Prueba Gamma,500000,0\n";

    public function test_the_qa_contract_is_built_from_the_attachment_without_a_provider_call(): void
    {
        $this->attach(self::QA_TABLE);

        $result = $this->ask(
            'Analiza el Excel adjunto y genera un libro con Cartera y Resumen, '
            .'descuento del 10 %, fórmulas reales y los siete indicadores.',
            clientTurnId: 'qa-r3-02-a',
        );

        $this->assertSame([], $this->providerCalls, 'The deterministic report must not call an LLM.');
        $this->assertSame('server', $result['provider']);
        $this->assertSame('tabular_report_v1', $result['model']);
        $this->assertSame(1, count($result['artifacts']));
        $this->assertStringContainsString('Cartera', $result['reply']);

        $spreadsheet = $this->loadWorkbook($result);
        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertNotNull($cartera);
        $this->assertNotNull($resumen);
        $this->assertSame(['Cartera', 'Resumen'], $spreadsheet->getSheetNames());
        $this->assertSame(2, $spreadsheet->getSheetCount());

        // Real formulas, dynamic to the three data rows, not values posing as formulas.
        $this->assertSame('=B2-C2', $cartera->getCell('D2')->getValue());
        $this->assertSame('=D2*0.1', $cartera->getCell('E2')->getValue());
        $this->assertSame('=D2-E2', $cartera->getCell('F2')->getValue());
        $this->assertSame('=SUM(B2:B4)', $cartera->getCell('B5')->getValue());
        $this->assertSame('=Cartera!B5', $resumen->getCell('B2')->getValue());
        $this->assertSame('=COUNTIF(Cartera!D2:D4,">0")', $resumen->getCell('B7')->getValue());
        $this->assertSame('=IFERROR(Cartera!C5/Cartera!B5,0)', $resumen->getCell('B8')->getValue());

        // The required initial figures.
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

        // Formatting: amounts are numerical COP, count is integer, rate is a fraction.
        $this->assertStringContainsString('$', $cartera->getCell('B2')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString('$', $cartera->getCell('F5')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('0', $resumen->getCell('B7')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00%', $resumen->getCell('B8')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString('33.33%', $resumen->getCell('B8')->getFormattedValue());

        // Recalculate after changing Cartera!B2 from 300000 to 400000. The
        // preceding reads populated PhpSpreadsheet's calculation cache, so clear
        // it exactly as a spreadsheet application would invalidate its results.
        $cartera->setCellValue('B2', 400000);
        $spreadsheet->getCalculationEngine()->clearCalculationCache();

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

        // The download is authenticated and returns real XLSX bytes.
        $download = $this->actingAs($this->user)->get((string) $result['artifacts'][0]['download_url']);
        $download->assertOk();
        $download->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringStartsWith('PK', (string) $download->streamedContent());

        // Turn, message and artifact are linked exactly once.
        $turn = AiMessage::query()->where('client_turn_id', 'qa-r3-02-a')->firstOrFail();
        $this->assertSame(AiMessage::STATUS_COMPLETED, $turn->status);
        $assistant = AiMessage::query()
            ->where('ai_conversation_id', $this->conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->firstOrFail();
        $artifact = AiArtifact::query()->findOrFail((int) $result['artifacts'][0]['id']);
        $this->assertSame($assistant->id, $artifact->message_id);
        $this->assertSame($this->conversation->id, $artifact->conversation_id);
        $this->assertTrue((bool) $this->conversation->fresh()->contains_client_data);
    }

    public function test_the_ranges_follow_ten_rows(): void
    {
        $csv = "Cliente,Obligacion,Pagado\n";

        for ($i = 1; $i <= 10; $i++) {
            $csv .= sprintf("Cliente %02d,100000,10000\n", $i);
        }

        $this->attach($csv);

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Genera un libro de cartera desde el Excel adjunto, con resumen y descuento del 10 %.',
            clientTurnId: 'ten-rows',
        ));

        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertSame('=SUM(B2:B11)', $cartera->getCell('B12')->getValue());
        $this->assertSame('=SUM(D2:D11)', $cartera->getCell('D12')->getValue());
        $this->assertSame('=Cartera!B12', $resumen->getCell('B2')->getValue());
        $this->assertSame('=COUNTIF(Cartera!D2:D11,">0")', $resumen->getCell('B7')->getValue());
        $this->assertEqualsWithDelta(1000000, $resumen->getCell('B2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(900000, $resumen->getCell('B4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(10, $resumen->getCell('B7')->getCalculatedValue(), 0.001);
    }

    public function test_the_ranges_follow_one_hundred_rows(): void
    {
        $csv = "Cliente,Obligacion,Pagado\n";

        for ($i = 1; $i <= 100; $i++) {
            $csv .= sprintf("Cliente %03d,100000,10000\n", $i);
        }

        $this->attach($csv);

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Genera un libro de cartera desde el adjunto, con Cartera y Resumen y descuento del 10 %.',
            clientTurnId: 'hundred-rows',
        ));

        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertSame('=SUM(B2:B101)', $cartera->getCell('B102')->getValue());
        $this->assertSame('=Cartera!B102', $resumen->getCell('B2')->getValue());
        $this->assertSame('=COUNTIF(Cartera!D2:D101,">0")', $resumen->getCell('B7')->getValue());
        $this->assertEqualsWithDelta(10000000, $resumen->getCell('B2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(9000000, $resumen->getCell('B4')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(100, $resumen->getCell('B7')->getCalculatedValue(), 0.001);
    }

    public function test_the_discount_can_be_five_ten_or_fifteen_percent_without_code_changes(): void
    {
        $expected = [
            5 => [0.05, 10000, 35000, 665000],
            10 => [0.1, 20000, 70000, 630000],
            15 => [0.15, 30000, 105000, 595000],
        ];

        foreach ($expected as $percent => [$rate, $firstDiscount, $totalDiscount, $adjusted]) {
            $conversation = $this->conversationFor($this->user, "Cartera {$percent}");
            $this->attach(self::QA_TABLE, "cartera-{$percent}.csv", $conversation);

            $spreadsheet = $this->loadWorkbook($this->ask(
                "Analiza el Excel adjunto y genera un libro de cartera con resumen y descuento del {$percent} %.",
                $conversation,
                "discount-{$percent}",
            ));

            $cartera = $spreadsheet->getSheetByName('Cartera');
            $resumen = $spreadsheet->getSheetByName('Resumen');

            $this->assertSame('=D2*'.rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'), $cartera->getCell('E2')->getValue());
            $this->assertEqualsWithDelta($firstDiscount, $cartera->getCell('E2')->getCalculatedValue(), 0.001);
            $this->assertEqualsWithDelta($totalDiscount, $resumen->getCell('B5')->getCalculatedValue(), 0.001);
            $this->assertEqualsWithDelta($adjusted, $resumen->getCell('B6')->getCalculatedValue(), 0.001);
        }

        $this->assertSame([], $this->providerCalls);
    }

    public function test_without_a_requested_discount_no_discount_is_invented(): void
    {
        $this->attach(self::QA_TABLE);

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Analiza el Excel adjunto y genera un libro de cartera con Cartera y Resumen y fórmulas reales.',
            clientTurnId: 'no-discount',
        ));

        $cartera = $spreadsheet->getSheetByName('Cartera');
        $resumen = $spreadsheet->getSheetByName('Resumen');

        $this->assertSame('=D2*0', $cartera->getCell('E2')->getValue());
        $this->assertEqualsWithDelta(0, $cartera->getCell('E2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(200000, $cartera->getCell('F2')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(0, $resumen->getCell('B5')->getCalculatedValue(), 0.001);
        $this->assertEqualsWithDelta(700000, $resumen->getCell('B6')->getCalculatedValue(), 0.001);
    }

    public function test_reordered_columns_are_mapped_by_header_not_position(): void
    {
        $this->attach(
            "Pagado,Cliente,Obligacion\n"
            ."100000,Prueba Alfa,300000\n"
            ."250000,Prueba Beta,250000\n"
            ."0,Prueba Gamma,500000\n"
        );

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Genera un libro de cartera desde la tabla adjunta con resumen y descuento del 10 %.',
            clientTurnId: 'reordered',
        ));

        $cartera = $spreadsheet->getSheetByName('Cartera');

        $this->assertSame('Prueba Alfa', $cartera->getCell('A2')->getValue());
        $this->assertSame(300000, $cartera->getCell('B2')->getValue());
        $this->assertSame(100000, $cartera->getCell('C2')->getValue());
        $this->assertEqualsWithDelta(200000, $cartera->getCell('D2')->getCalculatedValue(), 0.001);
    }

    public function test_ambiguous_headers_are_rejected_instead_of_guessed(): void
    {
        $this->attach(
            "Cliente,Cliente,Obligacion,Pagado\n"
            ."Prueba Alfa,Prueba Alfa Dos,300000,100000\n"
        );

        $response = $this->actingAs($this->user)->postJson(
            "/api/ai/conversations/{$this->conversation->id}/messages",
            [
                'message' => 'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
                'client_turn_id' => 'ambiguous',
            ],
        );

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'tabular_report_rejected');
        $this->assertSame(0, AiArtifact::query()->count());
        $this->assertSame([], $this->providerCalls);
    }

    public function test_unreadable_amounts_are_rejected_instead_of_becoming_zero(): void
    {
        $this->attach(
            "Cliente,Obligacion,Pagado\n"
            ."Prueba Alfa,no-es-un-numero,100000\n"
        );

        $response = $this->actingAs($this->user)->postJson(
            "/api/ai/conversations/{$this->conversation->id}/messages",
            [
                'message' => 'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
                'client_turn_id' => 'invalid-amount',
            ],
        );

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'tabular_report_rejected');
        $this->assertSame(0, AiArtifact::query()->count());
    }

    public function test_a_client_name_that_looks_like_a_formula_stays_text(): void
    {
        $this->attach(
            "Cliente,Obligacion,Pagado\n"
            ."=1+1,300000,100000\n"
        );

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
            clientTurnId: 'injection',
        ));

        $cell = $spreadsheet->getSheetByName('Cartera')->getCell('A2');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertStringContainsString('=1+1', (string) $cell->getValue());
        $this->assertNotSame('=1+1', $cell->getValue());
    }

    public function test_another_conversations_attachment_is_never_used(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);
        $stranger->givePermissionTo(['ai.use', 'artifacts.create']);

        $otherConversation = $this->conversationFor($stranger, 'Otra cartera');
        $otherAttachment = $this->attach(
            "Cliente,Obligacion,Pagado\nSecreto Ajeno,999999,0\n",
            'secreto.csv',
            $otherConversation,
            $stranger,
        );

        $this->attach(self::QA_TABLE);

        $spreadsheet = $this->loadWorkbook($this->ask(
            'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
            clientTurnId: 'isolation',
        ));

        $cartera = $spreadsheet->getSheetByName('Cartera');

        $this->assertSame('Prueba Alfa', $cartera->getCell('A2')->getValue());
        $this->assertStringNotContainsString('Secreto Ajeno', (string) $cartera->getCell('A3')->getValue());

        // A direct call with somebody else's attachment is refused by ownership.
        app(AiTurnContext::class)->set($this->conversation->id, 'isolation-direct');

        $this->expectException(AiToolRejected::class);
        app(TabularReportService::class)->generateForAttachment(
            $otherAttachment,
            $this->user,
            10,
            true,
            'robado',
        );
    }

    public function test_a_generic_excel_request_is_not_silently_treated_as_a_receivables_report(): void
    {
        $this->attach(self::QA_TABLE);

        $planner = app(TabularReportPlanner::class);

        $this->assertNull(
            $planner->plan($this->conversation, $this->user, 'Genera un Excel con la tabla adjunta.'),
        );

        $this->assertNull(
            $planner->plan(
                $this->conversation,
                $this->user,
                'Genera un libro de cartera desde el adjunto con descuento.',
            ),
            'A discount without a percentage is ambiguous and must be left to the assistant.',
        );
    }

    public function test_multiple_attachments_are_only_used_when_the_question_names_one(): void
    {
        $this->attach(
            "Cliente,Obligacion,Pagado\nAlfa,100000,0\n",
            'cartera-alfa.csv',
        );
        $this->attach(
            "Cliente,Obligacion,Pagado\nBeta,200000,0\n",
            'cartera-beta.csv',
        );

        $planner = app(TabularReportPlanner::class);
        $base = 'Genera un libro de cartera desde el adjunto con descuento del 10 %.';

        $this->assertNull($planner->plan($this->conversation, $this->user, $base));

        $plan = $planner->plan(
            $this->conversation,
            $this->user,
            'Genera un libro de cartera desde el adjunto cartera-beta.csv con descuento del 10 %.',
        );

        $this->assertNotNull($plan);
        $this->assertSame('cartera-beta.csv', $plan->sourceName);
    }

    public function test_the_same_client_turn_is_replayed_without_a_second_artifact(): void
    {
        $this->attach(self::QA_TABLE);

        $first = $this->ask(
            'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
            clientTurnId: 'idempotent-turn',
        );

        $second = $this->ask(
            'Genera un libro de cartera desde el adjunto con resumen y descuento del 10 %.',
            clientTurnId: 'idempotent-turn',
        );

        $this->assertSame($first['artifacts'][0]['id'], $second['artifacts'][0]['id']);
        $this->assertTrue((bool) ($second['replayed'] ?? false));
        $this->assertSame(1, AiArtifact::query()->count());
        $this->assertSame(1, AiMessage::query()->where('client_turn_id', 'idempotent-turn')->count());
        $this->assertSame([], $this->providerCalls);
    }
}

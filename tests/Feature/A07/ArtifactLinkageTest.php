<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Documents\DocumentIngestor;
use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R3 (prioridad 0) — a file any tool produces belongs to its thread.
 *
 * ## The defect
 *
 * `analyze_table` exports an XLSX by calling `create_artifact` internally, and
 * returns the resulting ids under `artifact`. `AiAssistantService` linked files
 * only when the tool's *name* was `create_artifact`, so the export was written,
 * downloaded correctly from the response — and never attached to the
 * conversation. On reload, the file was gone from the thread.
 *
 * These tests drive the real service over a faked provider and assert the
 * linkage, its visibility in the history endpoint, its ownership boundary, and
 * that a repeated operation does not draw the same card twice.
 */
final class ArtifactLinkageTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $owner;

    private User $stranger;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'documents.view', 'artifacts.create', 'web_search'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->owner = User::factory()->create(['status' => 'active']);
        $this->owner->givePermissionTo(['ai.use', 'documents.view', 'artifacts.create', 'web_search']);

        $this->stranger = User::factory()->create(['status' => 'active']);
        $this->stranger->givePermissionTo(['ai.use', 'documents.view', 'artifacts.create']);

        Storage::fake('local');

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.suggestions.reasoning.enabled' => false,
        ]);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->owner->id,
            'title' => 'Cartera',
        ]);
    }

    private function attachCsv(string $contents, string $name = 'cartera.csv'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cdh-csv').'.csv';
        file_put_contents($path, $contents);

        app(DocumentIngestor::class)->ingest(
            new UploadedFile($path, $name, 'text/csv', null, true),
            $this->owner,
            $this->conversation->id,
        );

        @unlink($path);
    }

    private const CARTERA = "cliente,empresa,obligacion_cop,pagado_cop\n"
        ."Persona Demo A,Empresa Ficticia,300000,100000\n"
        ."Persona Demo B,Empresa Ficticia,250000,250000\n"
        ."Persona Demo C,Empresa Modelo,500000,0\n";

    /**
     * @return array<string, mixed>
     */
    private function toolCallBody(string $name, array $arguments, string $callId): array
    {
        return [
            'id' => 'resp_'.$callId,
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $callId,
                    'type' => 'function',
                    'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                ]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(string $content): array
    {
        return [
            'id' => 'resp_text',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ];
    }

    private function ask(): array
    {
        return app(AiAssistantService::class)->ask(
            $this->conversation,
            'Analiza el CSV que adjunté y conviértelo a Excel',
            $this->owner,
        );
    }

    public function test_an_export_from_analyze_table_is_linked_to_its_conversation(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('analyze_table', [
                    'export' => 'xlsx',
                    'filename' => 'gestion-de-deudas',
                ], 'c1'))
                ->push($this->textBody('Listo, generé el archivo con los saldos calculados.')),
        ]);

        $result = $this->ask();

        // The file is in the turn's own result.
        $this->assertCount(1, $result['artifacts'], 'The export must be part of the turn.');

        $artifactId = (int) $result['artifacts'][0]['id'];
        $artifact = AiArtifact::query()->findOrFail($artifactId);

        // And it belongs to this conversation and this owner — which is what
        // makes "the file I asked for" a member of the thread.
        $this->assertSame($this->conversation->id, (int) $artifact->conversation_id);
        $this->assertSame($this->owner->id, (int) $artifact->user_id);
        $this->assertSame('xlsx', $artifact->format);

        // The bytes are a real workbook carrying the verified totals, not a
        // placeholder: the defect was about linkage, not about the file itself.
        $sheet = SpreadsheetFactory::load(Storage::disk('local')->path($artifact->storage_path))->getActiveSheet();
        $this->assertSame('TOTAL', $sheet->getCell('A5')->getValue());
        $this->assertSame(700000, $sheet->getCell('E5')->getValue(), 'The pending balance is the computed one.');
    }

    public function test_the_history_endpoint_returns_the_export_and_reloading_keeps_it(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('analyze_table', ['export' => 'csv', 'filename' => 'cartera-verificada'], 'c1'))
                ->push($this->textBody('Aquí está tu archivo.')),
        ]);

        $result = $this->ask();
        $artifactId = (int) $result['artifacts'][0]['id'];

        // Reload the conversation the way the widget does after F5: the file the
        // turn produced must still be there, tied to the thread by the server.
        $response = $this->actingAs($this->owner)->getJson("/api/ai/conversations/{$this->conversation->id}");

        $response->assertOk();

        $ids = array_column($response->json('artifacts'), 'id');
        $this->assertContains($artifactId, array_map('intval', $ids), 'The export survives a reload.');

        // And it downloads.
        $this->actingAs($this->owner)
            ->get(route('api.ai.artifacts.download', ['artifact' => $artifactId]))
            ->assertOk();
    }

    public function test_the_export_is_tied_to_the_reply_it_was_generated_in(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('analyze_table', ['export' => 'xlsx', 'filename' => 'informe'], 'c1'))
                ->push($this->textBody('Listo, aquí está el informe.')),
        ]);

        $result = $this->ask();
        $artifactId = (int) $result['artifacts'][0]['id'];
        $artifact = AiArtifact::query()->findOrFail($artifactId);

        $reply = AiMessage::query()
            ->where('ai_conversation_id', $this->conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->firstOrFail();

        // The file points at the reply that produced it, which is what lets the
        // interface put it beside that answer instead of at the end of the
        // transcript.
        $this->assertSame((int) $reply->id, (int) $artifact->message_id);

        // And the history endpoint tells the interface the same thing.
        $history = $this->actingAs($this->owner)->getJson("/api/ai/conversations/{$this->conversation->id}");
        $history->assertOk();

        $row = collect($history->json('artifacts'))->firstWhere('id', $artifactId);
        $this->assertSame((int) $reply->id, (int) $row['message_id']);

        $messageIds = array_column($history->json('messages'), 'id');
        $this->assertContains((int) $reply->id, array_map('intval', $messageIds), 'Messages carry their id so files can be placed beside them.');
    }

    public function test_another_person_cannot_download_the_export(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('analyze_table', ['export' => 'xlsx', 'filename' => 'privado'], 'c1'))
                ->push($this->textBody('Listo.')),
        ]);

        $result = $this->ask();
        $artifactId = (int) $result['artifacts'][0]['id'];

        // Answered exactly like a missing artifact: an id that is real and not
        // theirs must not be distinguishable from one that does not exist.
        $this->actingAs($this->stranger)
            ->get(route('api.ai.artifacts.download', ['artifact' => $artifactId]))
            ->assertNotFound();
    }

    public function test_another_conversation_does_not_absorb_the_export(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('analyze_table', ['export' => 'xlsx', 'filename' => 'solo-aqui'], 'c1'))
                ->push($this->textBody('Listo.')),
        ]);

        $this->ask();
        $artifactId = (int) AiArtifact::query()->value('id');

        $other = AiConversation::query()->create([
            'user_id' => $this->owner->id,
            'title' => 'Otra conversación',
        ]);

        $history = $this->actingAs($this->owner)->getJson("/api/ai/conversations/{$other->id}");
        $history->assertOk();

        $this->assertNotContains(
            $artifactId,
            array_map('intval', array_column($history->json('artifacts'), 'id')),
            'A file belongs to the thread that produced it, not to every thread.',
        );
    }

    public function test_a_repeated_export_draws_one_card(): void
    {
        $this->attachCsv(self::CARTERA);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // The model asks for the same export twice in one turn.
                ->push($this->toolCallBody('analyze_table', [
                    'export' => 'xlsx',
                    'filename' => 'cartera',
                    'title' => 'Cartera',
                ], 'c1'))
                ->push($this->toolCallBody('analyze_table', [
                    'export' => 'xlsx',
                    'filename' => 'cartera',
                    'title' => 'Cartera',
                ], 'c2'))
                ->push($this->textBody('Listo.')),
        ]);

        $result = $this->ask();

        // Idempotency returns the same underlying artifact, so the turn lists it
        // once — a document appears once in a thread, however many times it was
        // asked for inside the same turn.
        $this->assertCount(1, $result['artifacts']);
    }
}

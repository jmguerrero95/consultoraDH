<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiArtifact;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiTurnFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R3: a file request that produced no file is a contract failure.
 *
 * The observed live defect: a model answered "el archivo se generó" with a
 * filename, while `artifacts` was empty. Delivering that reply would invent a
 * download that does not exist. The turn now asks once for the real tool call
 * (preserving the model's own text and all prior tool results), and fails
 * explicitly if the file still does not exist.
 */
final class MissingArtifactRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'artifacts.create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'artifacts.create']);

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
            'user_id' => $this->user->id,
            'title' => 'Saldo sintético',
        ]);
    }

    /** @return array<string, mixed> */
    private function text(string $content): array
    {
        return [
            'id' => 'resp_text',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ];
    }

    /** @return array<string, mixed> */
    private function artifactCall(): array
    {
        return [
            'id' => 'resp_tool',
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_art_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'create_artifact',
                        'arguments' => json_encode([
                            'format' => 'xlsx',
                            'filename' => 'saldo_prueba',
                            'content' => json_encode([
                                ['Cliente', 'Obligación', 'Pagado', 'Saldo'],
                                ['Prueba Alfa', 300000, 100000, 200000],
                                ['Prueba Beta', 250000, 250000, 0],
                                ['Prueba Gamma', 500000, 0, 500000],
                                ['TOTALES', 1050000, 350000, 700000],
                            ]),
                        ]),
                    ],
                ]],
            ]]],
        ];
    }

    private function ask(): array
    {
        return app(AiAssistantService::class)->ask(
            $this->conversation,
            'Genera un archivo XLSX descargable con el detalle de estas tres obligaciones ficticias.',
            $this->user,
        );
    }

    public function test_a_claimed_file_is_generated_by_one_bounded_recovery(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->text('He generado el archivo saldo_prueba.xlsx. Descárgalo aquí.'))
                ->push($this->artifactCall())
                ->push($this->text('Listo: saldo_prueba.xlsx está disponible para descargar.')),
        ]);

        $result = $this->ask();

        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('saldo_prueba.xlsx', $result['artifacts'][0]['filename']);

        $artifact = AiArtifact::query()->sole();
        $this->assertSame('xlsx', $artifact->format);
        Storage::disk('local')->assertExists($artifact->storage_path);

        // The recovery asked for the tool call; it did not re-ask the person.
        $this->assertCount(3, Http::recorded());
    }

    public function test_a_second_answer_without_a_file_fails_instead_of_claiming_one(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->text('He generado el archivo saldo_prueba.xlsx.'))
                ->push($this->text('El archivo saldo_prueba.xlsx está listo para descargar.')),
        ]);

        try {
            $this->ask();
            $this->fail('The turn must not deliver a file that was never generated.');
        } catch (AiTurnFailed $e) {
            $this->assertStringContainsString('artifact', $e->getMessage());
        }

        $this->assertSame(0, AiArtifact::query()->count());
        $this->assertSame(
            0,
            AiMessageModel::query()
                ->where('ai_conversation_id', $this->conversation->id)
                ->where('role', 'assistant')
                ->count(),
            'A failed turn must not be stored as something the assistant said.',
        );
    }

    public function test_a_question_about_a_document_does_not_force_file_generation(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response(
                $this->text('El documento tiene tres secciones y un total de 1.050.000 COP.')
            ),
        ]);

        $result = $this->ask2('Resume este documento y dime el total.');

        $this->assertSame([], $result['artifacts']);
        $this->assertCount(1, Http::recorded(), 'No recovery round should be spent on a verbal question.');
    }

    /** @return array<string, mixed> */
    private function ask2(string $question): array
    {
        return app(AiAssistantService::class)->ask($this->conversation, $question, $this->user);
    }
}

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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * QA-R3-02 runtime failure — a rejected artifact must not become a tool loop.
 *
 * The failed turn left four executed tool batches (`analyze_table`,
 * `create_artifact`), no artifact row and a final tool-free synthesis with no
 * text. The model kept asking for tools until the round limit. This test drives
 * the real service over the real provider client with the HTTP call faked; it
 * asserts that one rejected artifact gets one targeted repair and that a second
 * rejection ends with a safe, specific code instead of another round.
 */
final class ArtifactValidationRecoveryTest extends TestCase
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
        Cache::forget('ai.diagnostics.recent');

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.suggestions.reasoning.enabled' => false,
            'ai.tool_max_rounds' => 4,
        ]);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'QA-R3-02 sintético',
        ]);
    }

    /**
     * A valid two-sheet workbook with two real cross-sheet indicators.
     *
     * @return array<string, mixed>
     */
    private function workbook(bool $withRequiredIndicators = true): array
    {
        $formula = static fn (string $expression): array => [
            'type' => 'formula',
            'expression' => $expression,
        ];

        $book = [
            'version' => 2,
            'required_indicators' => ['total_obligations', 'total_paid'],
            'indicators' => [
                [
                    'id' => 'total_obligations',
                    'sheet' => 'Resumen',
                    'cell' => 'B2',
                    'kind' => 'formula',
                    'format' => 'currency_cop',
                ],
                [
                    'id' => 'total_paid',
                    'sheet' => 'Resumen',
                    'cell' => 'B3',
                    'kind' => 'formula',
                    'format' => 'currency_cop',
                ],
            ],
            'sheets' => [
                [
                    'name' => 'Cartera',
                    'rows' => [
                        ['Obligación', 'Pagado'],
                        [1000, 400],
                        [2000, 600],
                        [3000, 0],
                        ['Totales', $formula('=SUM(A2:A4)'), $formula('=SUM(B2:B4)')],
                    ],
                    'column_formats' => ['A' => 'currency_cop', 'B' => 'currency_cop'],
                    'freeze_pane' => 'A2',
                ],
                [
                    'name' => 'Resumen',
                    'rows' => [
                        ['Concepto', 'Valor'],
                        ['Obligaciones totales', $formula('=Cartera!A5')],
                        ['Pagado total', $formula('=Cartera!B5')],
                    ],
                    'column_formats' => ['B' => 'currency_cop'],
                    'freeze_pane' => 'A2',
                ],
            ],
        ];

        if (! $withRequiredIndicators) {
            unset($book['required_indicators']);
        }

        return $book;
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function artifactCall(array $book, string $callId): array
    {
        return $this->toolCall('create_artifact', [
            'format' => 'xlsx',
            'filename' => 'cartera_resumen',
            'content' => json_encode($book, JSON_UNESCAPED_UNICODE),
        ], $callId);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments, string $callId): array
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
    private function text(string $content): array
    {
        return [
            'id' => 'resp_text',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function assertOnlyTools(Request $request, array $names): void
    {
        $offered = array_values(array_map(
            static fn (array $tool): string => (string) ($tool['function']['name'] ?? ''),
            $request->data()['tools'] ?? [],
        ));

        sort($offered);
        sort($names);

        $this->assertSame($names, $offered);
    }

    public function test_a_rejected_artifact_gets_one_targeted_repair_and_is_delivered(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->artifactCall($this->workbook(false), 'c1'))
                ->push($this->artifactCall($this->workbook(), 'c2'))
                ->push($this->text('Listo: cartera_resumen.xlsx está disponible para descargar.')),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation,
            'Genera un archivo XLSX descargable con dos hojas y fórmulas reales.',
            $this->user,
        );

        $this->assertCount(1, $result['artifacts']);
        $this->assertSame('cartera_resumen.xlsx', $result['artifacts'][0]['filename']);
        $this->assertSame(1, AiArtifact::query()->count());

        $providerCalls = array_values(array_filter(
            Http::recorded()->all(),
            static fn (array $pair): bool => $pair[0]->url() === self::PROVIDER_URL,
        ));

        $this->assertCount(3, $providerCalls, 'rejected artifact -> one repair -> final answer');

        $repairRequest = $providerCalls[1][0];
        $this->assertOnlyTools($repairRequest, ['create_artifact']);
        $this->assertSame(
            [0],
            array_keys($repairRequest->data()['tools'] ?? []),
            'The tools payload must be a JSON list, not a name-keyed object.',
        );
        $this->assertSame(
            ['type' => 'function', 'function' => ['name' => 'create_artifact']],
            $repairRequest->data()['tool_choice'] ?? null,
            'The repair round must force create_artifact instead of leaving it to auto.',
        );

        $messages = $repairRequest->data()['messages'] ?? [];
        $directive = null;

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'user'
                && str_contains((string) ($message['content'] ?? ''), 'create_artifact')
            ) {
                $directive = (string) $message['content'];
            }
        }

        $this->assertNotNull($directive, 'The repair round must carry an explicit artifact directive.');
        $this->assertStringContainsString('required_indicators', $directive);

        // The failed turn must not be persisted as an assistant answer.
        $this->assertSame(
            1,
            AiMessageModel::query()
                ->where('ai_conversation_id', $this->conversation->id)
                ->where('role', 'assistant')
                ->count(),
        );
    }

    public function test_a_second_rejected_artifact_stops_with_a_safe_specific_code(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->artifactCall($this->workbook(false), 'c1'))
                ->push($this->artifactCall($this->workbook(false), 'c2'))
                ->push($this->artifactCall($this->workbook(false), 'c3'))
                ->push($this->artifactCall($this->workbook(false), 'c4'))
                ->push($this->artifactCall($this->workbook(false), 'c5'))
                ->push($this->text('No debería llegar aquí.')),
        ]);

        try {
            app(AiAssistantService::class)->ask(
                $this->conversation,
                'Genera un archivo XLSX descargable con dos hojas y fórmulas reales.',
                $this->user,
            );

            $this->fail('A turn whose artifact was rejected twice must fail, not loop.');
        } catch (AiTurnFailed $exception) {
            $this->assertSame('artifact_indicators_pair_missing', $exception->code());
            $this->assertStringNotContainsString('required_indicators', $exception->getMessage());
        }

        $providerCalls = array_values(array_filter(
            Http::recorded()->all(),
            static fn (array $pair): bool => $pair[0]->url() === self::PROVIDER_URL,
        ));

        $this->assertCount(2, $providerCalls, 'The second rejection must end the turn, not ask for a fifth round.');
        $this->assertSame(0, AiArtifact::query()->count());
        $this->assertSame(
            0,
            AiMessageModel::query()
                ->where('ai_conversation_id', $this->conversation->id)
                ->where('role', 'assistant')
                ->count(),
            'A failed turn must not be stored as an assistant answer.',
        );

        $turnRow = null;

        foreach (Cache::get('ai.diagnostics.recent', []) as $row) {
            if (($row['outcome'] ?? null) === 'turn') {
                $turnRow = $row;
                break;
            }
        }

        $this->assertNotNull($turnRow);
        $this->assertSame('failed', $turnRow['result']);
        $this->assertSame('artifact_validation_failed', $turnRow['ended_by']);
        $this->assertContains('artifact_indicators_pair_missing', $turnRow['tool_errors'] ?? []);
    }

    public function test_the_endpoint_returns_the_safe_code_without_internal_detail(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->artifactCall($this->workbook(false), 'c1'))
                ->push($this->artifactCall($this->workbook(false), 'c2')),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Genera un archivo XLSX descargable con dos hojas y fórmulas reales.',
            'client_turn_id' => 'qa-r3-02-runtime-failure',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('recoverable', true)
            ->assertJsonPath('code', 'artifact_indicators_pair_missing');

        $payload = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('required_indicators', $payload);
        $this->assertStringNotContainsString('Trace', $payload);
        $this->assertStringNotContainsString('create_artifact', $payload);
    }
}

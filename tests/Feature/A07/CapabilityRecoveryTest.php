<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R2 (bloque C1) — "no puedo" is not accepted while the tool exists.
 *
 * The reported defect was a turn that answered "no puedo crear archivos PDF ni
 * Excel" while `create_artifact` was authorised and offered. The correction is
 * bounded: the turn is asked once more with the tool named, and a second refusal
 * is delivered rather than looped on.
 */
final class CapabilityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'artifacts.create', 'clients.view', 'receivables.view', 'companies.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'artifacts.create', 'clients.view', 'receivables.view', 'companies.view']);

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
        ]);
    }

    private function conversation(): AiConversation
    {
        return AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Test']);
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(string $content): array
    {
        return ['id' => 'resp', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /**
     * @param  array<string, mixed>  $arguments
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

    public function test_a_capability_refusal_is_corrected_once_and_the_tool_runs(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('No puedo crear archivos PDF ni Excel, lo siento.'))
                ->push($this->toolCallBody('create_artifact', [
                    'format' => 'pdf',
                    'filename' => 'resumen',
                    'title' => 'Resumen',
                    'content' => "# Resumen\n\nContenido de prueba.",
                ], 'c1'))
                ->push($this->textBody('Listo: aquí está el enlace de descarga.')),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            'Genera un PDF con el resumen de cartera',
            $this->user,
        );

        // refusal -> corrected request -> tool call -> answer
        $this->assertCount(3, Http::recorded());
        $this->assertSame('Listo: aquí está el enlace de descarga.', $result['reply']);
        $this->assertNotEmpty($result['artifacts'], 'The corrected turn must actually produce the file.');
    }

    public function test_a_second_refusal_is_delivered_without_looping(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('No puedo crear archivos.'))
                ->push($this->textBody('Lo siento, no tengo capacidad de generar documentos.')),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            'Genera un PDF con el resumen',
            $this->user,
        );

        $this->assertCount(2, Http::recorded(), 'The correction happens once, never in a loop.');
        $this->assertSame('Lo siento, no tengo capacidad de generar documentos.', $result['reply']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderException;
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
 *
 * A07-RUNTIME-RELIABILITY (T04) extends this to the five outcomes that must be
 * told apart. The live run answered a PDF request with the *generic* "Lo siento,
 * pero no puedo cumplir con esa solicitud.", which the old check missed because it
 * required the refusal to name a file. Now: a policy refusal is delivered and
 * never recovered; a capability or generic refusal is recovered once when the
 * tool is authorised; a refusal with no authorised tool is delivered; and a
 * refusal whose recovery has no quota fails cleanly instead of silently.
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

    private function conversation(?User $user = null): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => ($user ?? $this->user)->id,
            'title' => 'Test',
        ]);
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

    // ------------------------------------------------------------------- T04

    // C: the exact live shape — a generic refusal for a PDF request, recovered.
    public function test_a_generic_refusal_for_a_file_request_is_corrected_once_and_the_document_is_produced(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('Lo siento, pero no puedo cumplir con esa solicitud.'))
                ->push($this->toolCallBody('create_artifact', [
                    'format' => 'pdf',
                    'filename' => 'guia',
                    'title' => 'Guía',
                    'content' => "# Guía\n\nContenido.",
                ], 'c1'))
                ->push($this->textBody('Listo: aquí está el enlace de descarga.')),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            "Crea un PDF profesional titulado 'Guía básica de seguridad informática'.",
            $this->user,
        );

        $this->assertCount(3, Http::recorded(), 'Generic refusal -> correction -> tool -> answer.');
        $this->assertSame('Listo: aquí está el enlace de descarga.', $result['reply']);
        $this->assertNotEmpty($result['artifacts'], 'The generic refusal must not stand in for the file.');
    }

    // A: an explicit safety refusal is delivered, never recovered.
    public function test_a_content_policy_refusal_is_delivered_and_never_recovered(): void
    {
        $refusal = 'No puedo ayudarte con eso; va contra mis normas de seguridad.';

        Http::fake([
            self::PROVIDER_URL => Http::response($this->textBody($refusal)),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            'Crea un PDF con instrucciones para vulnerar un sistema ajeno.',
            $this->user,
        );

        $this->assertCount(1, Http::recorded(), 'A policy refusal is not evaded by another provider or round.');
        $this->assertSame($refusal, $result['reply']);
        $this->assertEmpty($result['artifacts']);
    }

    // C': a generic refusal without a deliverable is left as the answer.
    public function test_a_generic_refusal_is_not_corrected_when_no_file_was_asked_for(): void
    {
        $refusal = 'Lo siento, pero no puedo cumplir con esa solicitud.';

        Http::fake([
            self::PROVIDER_URL => Http::response($this->textBody($refusal)),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            '¿Cuánto vendimos este trimestre?',
            $this->user,
        );

        $this->assertCount(1, Http::recorded());
        $this->assertSame($refusal, $result['reply']);
    }

    // D: no authorised tool — the person may not create artifacts, so no recovery.
    public function test_a_refusal_without_the_authorised_tool_is_delivered_without_recovery(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo(['ai.use']);

        $refusal = 'No puedo crear archivos PDF.';

        Http::fake([
            self::PROVIDER_URL => Http::response($this->textBody($refusal)),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation($limited),
            'Genera un PDF con el resumen',
            $limited,
        );

        $this->assertCount(1, Http::recorded(), 'Nothing to recover with: no correction is issued.');
        $this->assertSame($refusal, $result['reply']);
    }

    // The bounded recovery does not re-ask the model that just declined: it
    // moves to a candidate with tool-use evidence when one exists.
    public function test_the_bounded_recovery_moves_past_the_model_that_refused(): void
    {
        config([
            'ai.primary.model' => 'refuser',
            'ai.fallback' => [
                'base_url' => 'https://provider.test/v1',
                'api_key' => 'test-key',
                'model' => 'tooler',
            ],
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // The first candidate declines the summarised PDF.
                ->push($this->textBody('Lo siento, pero no puedo cumplir con esa solicitud.'))
                // The recovery reaches the second candidate, which uses the tool.
                ->push($this->toolCallBody('create_artifact', [
                    'format' => 'pdf',
                    'filename' => 'resumen',
                    'title' => 'Resumen',
                    'content' => "# Resumen\n\nContenido.",
                ], 'c1'))
                ->push($this->textBody('Listo: aquí está el enlace de descarga.')),
        ]);

        $result = app(AiAssistantService::class)->ask(
            $this->conversation(),
            'Genera un PDF con el resumen',
            $this->user,
        );

        $requests = Http::recorded();
        $this->assertCount(3, $requests);
        $this->assertSame('refuser', $requests[0][0]->data()['model']);
        $this->assertSame('tooler', $requests[1][0]->data()['model'], 'The recovery does not re-ask the model that refused.');
        $this->assertNotEmpty($result['artifacts']);
    }

    // E: no quota for the recovery — a clean failure, not a silent refusal.
    public function test_a_refusal_whose_recovery_has_no_quota_fails_cleanly(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody('Lo siento, pero no puedo cumplir con esa solicitud.'))
                ->push(['error' => ['message' => 'rate limited']], 429),
        ]);

        $conversation = $this->conversation();

        try {
            app(AiAssistantService::class)->ask(
                $conversation,
                'Genera un PDF con el resumen',
                $this->user,
            );

            $this->fail('A turn whose recovery has no quota must fail, not answer with the refusal.');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable, 'A quota refusal is a temporary, retryable failure.');
        }

        // Nothing was stored as an answer for the failed turn. Scoped to this
        // conversation, so a row another test committed cannot be mistaken for it.
        $this->assertSame(
            0,
            AiMessageModel::query()
                ->where('ai_conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->count(),
            'A failed turn persists no fabricated assistant message.',
        );
    }
}

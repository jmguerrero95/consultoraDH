<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiMessage;
use App\Services\Ai\OpenAiCompatibleProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemini 3 requires the thought signature of a function call to be replayed on
 * the continuation. The signature is opaque provider state, so it is preserved
 * on the tool call and echoed only to the endpoint that issued it; a failover to
 * another vendor must not receive a field it does not understand.
 */
final class GeminiContinuationTest extends TestCase
{
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';

    private const OTHER_URL = 'https://provider.test/v1/chat/completions';

    /** @return array<string, mixed> */
    private function toolCallResponse(): array
    {
        return [
            'id' => 'resp_1',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'sumar', 'arguments' => '{"a":300000,"b":250000}'],
                        'extra_content' => ['google' => ['thought_signature' => 'sig-opaca']],
                    ]],
                ],
            ]],
        ];
    }

    /** @return list<AiMessage> */
    private function continuation(): array
    {
        return [
            AiMessage::user('Suma 300000 y 250000.'),
            AiMessage::assistant('', [[
                'id' => 'call_1',
                'name' => 'sumar',
                'arguments' => ['a' => 300000, 'b' => 250000],
                'extra_content' => ['google' => ['thought_signature' => 'sig-opaca']],
            ]]),
            AiMessage::toolResult('sumar', ['resultado' => 550000], 'call_1'),
        ];
    }

    private function provider(string $baseUrl): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider([
            'base_url' => $baseUrl,
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);
    }

    public function test_the_provider_preserves_the_thought_signature_returned_by_gemini(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->toolCallResponse())]);

        $response = $this->provider('https://generativelanguage.googleapis.com/v1beta/openai')
            ->complete([AiMessage::user('Suma 300000 y 250000.')]);

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame(
            ['google' => ['thought_signature' => 'sig-opaca']],
            $response->toolCalls[0]->providerState,
        );
    }

    public function test_the_signature_is_echoed_back_to_gemini_on_the_continuation(): void
    {
        Http::fake([self::GEMINI_URL => Http::response([
            'id' => 'resp_2',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'El total es 550.000.']]],
        ])]);

        $this->provider('https://generativelanguage.googleapis.com/v1beta/openai')
            ->complete($this->continuation());

        Http::assertSent(function (Request $request): bool {
            $call = $request['messages'][1]['tool_calls'][0] ?? [];

            return ($call['extra_content']['google']['thought_signature'] ?? null) === 'sig-opaca';
        });
    }

    public function test_a_different_vendor_never_receives_the_gemini_signature(): void
    {
        Http::fake([self::OTHER_URL => Http::response([
            'id' => 'resp_2',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'El total es 550.000.']]],
        ])]);

        $this->provider('https://provider.test/v1')->complete($this->continuation());

        Http::assertSent(function (Request $request): bool {
            $call = $request['messages'][1]['tool_calls'][0] ?? [];

            return ! array_key_exists('extra_content', $call);
        });
    }
}

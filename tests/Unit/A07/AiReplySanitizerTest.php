<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Services\Ai\AiAssistantService;
use Tests\TestCase;

/**
 * VERIFY: a function-call envelope a model printed as text never reaches the
 * person or the stored history, while ordinary prose and requested JSON are
 * left intact.
 *
 * The live acceptance run exposed this: the small model answered the weather
 * follow-up with real data and then appended
 * `{"name": "weather_current", "parameters": {...}}` as plain text. The answer
 * was correct, but the raw envelope is not part of it.
 */
final class AiReplySanitizerTest extends TestCase
{
    public function test_a_trailing_tool_call_envelope_is_removed(): void
    {
        $reply = 'La temperatura de mañana será de 30.2 °C.'
            .' {"name": "weather_current", "parameters": {"location": "Girón", "days": "2"}}';

        $clean = AiAssistantService::stripToolCallLeak($reply);

        $this->assertSame('La temperatura de mañana será de 30.2 °C.', $clean);
        $this->assertStringNotContainsString('weather_current', $clean);
    }

    public function test_a_fenced_tool_call_envelope_is_removed(): void
    {
        $reply = "Aquí está la cifra.\n```json\n{\"name\": \"system_stats\", \"arguments\": {}}\n```";

        $clean = AiAssistantService::stripToolCallLeak($reply);

        $this->assertSame('Aquí está la cifra.', $clean);
    }

    public function test_ordinary_prose_ending_in_a_brace_is_untouched(): void
    {
        $reply = 'El conjunto se escribe {1, 2, 3}.';

        $this->assertSame($reply, AiAssistantService::stripToolCallLeak($reply));
    }

    public function test_requested_json_is_untouched(): void
    {
        // A person who asked for JSON gets JSON: no `name` + `parameters` shape.
        $reply = '{"clientes": 325, "inactivos": 3}';

        $this->assertSame($reply, AiAssistantService::stripToolCallLeak($reply));
    }

    public function test_a_reply_without_any_envelope_is_untouched(): void
    {
        $reply = 'No hay datos suficientes para responder.';

        $this->assertSame($reply, AiAssistantService::stripToolCallLeak($reply));
    }
}

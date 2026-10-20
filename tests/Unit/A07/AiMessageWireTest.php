<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Domain\Ai\AiMessage;
use PHPUnit\Framework\TestCase;

/**
 * The provider wire shape after a tool batch.
 *
 * Conversation 69 showed a turn where every candidate answered 400 after a
 * tool call. The wire shape of the assistant call is pinned here: string
 * content, a JSON-object `arguments` string and no `false` when a byte is
 * invalid. The tool result keeps the tool name for continuity, next to the
 * call id providers match on.
 */
final class AiMessageWireTest extends TestCase
{
    public function test_an_assistant_message_with_tool_calls_and_no_prose_keeps_a_string_content(): void
    {
        $message = AiMessage::assistant('', [[
            'id' => 'call_1',
            'name' => 'create_artifact',
            'arguments' => ['format' => 'xlsx'],
        ]]);

        $wire = $message->toWire();

        $this->assertSame('', $wire['content']);
        $this->assertSame('function', $wire['tool_calls'][0]['type']);
        $this->assertSame('create_artifact', $wire['tool_calls'][0]['function']['name']);
        $this->assertSame('{"format":"xlsx"}', $wire['tool_calls'][0]['function']['arguments']);
        $this->assertIsArray(json_decode($wire['tool_calls'][0]['function']['arguments'], true));
    }

    public function test_a_tool_result_keeps_its_tool_name_and_a_string_content(): void
    {
        $wire = AiMessage::toolResult('analyze_table', ['rows' => [[1, 2]]], 'call_1')->toWire();

        $this->assertSame('tool', $wire['role']);
        $this->assertSame('call_1', $wire['tool_call_id']);
        $this->assertSame('analyze_table', $wire['name']);
        $this->assertIsString($wire['content']);
    }

    public function test_invalid_utf8_in_tool_arguments_never_produces_false_json(): void
    {
        $wire = AiMessage::assistant('', [[
            'id' => 'call_1',
            'name' => 'create_artifact',
            'arguments' => ['filename' => "cartera\xB1"],
        ]])->toWire();

        $this->assertIsString($wire['tool_calls'][0]['function']['arguments']);
        $this->assertNotFalse(json_decode($wire['tool_calls'][0]['function']['arguments'], true));
    }

    public function test_prose_before_a_tool_call_is_preserved(): void
    {
        $wire = AiMessage::assistant('Voy a generar el libro.', [[
            'id' => 'call_1',
            'name' => 'create_artifact',
            'arguments' => [],
        ]])->toWire();

        $this->assertSame('Voy a generar el libro.', $wire['content']);
        $this->assertSame('{}', $wire['tool_calls'][0]['function']['arguments']);
    }
}

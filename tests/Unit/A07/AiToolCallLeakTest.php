<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Services\Ai\AiToolCallLeak;
use Tests\TestCase;

/**
 * A07-RUNTIME-RELIABILITY — the textual tool-call envelope, recognised safely.
 *
 * The T02 live run ended with the model's own `<tool_call>` envelope delivered as
 * the answer. These pin the recognition: a complete, unambiguous envelope becomes
 * a structured call so the existing registry path can validate and run it; an
 * incomplete one, one naming a tool the turn did not offer, or one with a
 * non-parameter body is reported as malformed and is never an answer; and
 * ordinary prose that merely mentions the tag is left untouched.
 */
final class AiToolCallLeakTest extends TestCase
{
    /** @var list<string> */
    private const OFFERED = ['web_search', 'web_fetch', 'create_artifact'];

    private const COMPLETE = <<<'TEXT'
    <tool_call>
    <function=web_fetch>
    <parameter=max_chars>5000</parameter>
    <parameter=url>https://energia.test/sodio</parameter>
    </function>
    </tool_call>
    TEXT;

    public function test_a_complete_envelope_becomes_a_structured_call(): void
    {
        $leak = AiToolCallLeak::extract(self::COMPLETE, self::OFFERED);

        $this->assertSame(AiToolCallLeak::COMPLETE, $leak['status']);
        $this->assertCount(1, $leak['calls']);
        $this->assertSame('web_fetch', $leak['calls'][0]->name);
        $this->assertSame(5000, $leak['calls'][0]->arguments['max_chars']);
        $this->assertSame('https://energia.test/sodio', $leak['calls'][0]->arguments['url']);
        $this->assertSame('', $leak['prose']);
    }

    public function test_an_incomplete_envelope_is_malformed_and_never_the_answer(): void
    {
        $content = "<tool_call>\n<function=web_fetch>\n<parameter=url>https://energia.test/sodio</parameter>";

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::MALFORMED, $leak['status']);
        $this->assertSame([], $leak['calls']);
        $this->assertStringNotContainsString('<tool_call', $leak['prose']);
    }

    public function test_a_call_to_a_tool_the_turn_did_not_offer_is_malformed(): void
    {
        $content = '<tool_call><function=delete_everything><parameter=id>1</parameter></function></tool_call>';

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::MALFORMED, $leak['status']);
        $this->assertSame([], $leak['calls']);
    }

    public function test_a_function_body_that_is_not_parameters_is_malformed(): void
    {
        $content = '<tool_call><function=web_search>busca esto por favor</function></tool_call>';

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::MALFORMED, $leak['status']);
        $this->assertSame([], $leak['calls']);
    }

    public function test_ordinary_prose_that_mentions_the_tag_is_left_alone(): void
    {
        $content = 'El bloque <tool_call> es la forma en que un modelo pide una herramienta.';

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::NONE, $leak['status']);
        $this->assertSame([], $leak['calls']);
        $this->assertSame($content, $leak['prose']);
    }

    public function test_a_bare_function_envelope_without_the_wrapper_is_recognised(): void
    {
        $content = '<function=web_search><parameter=query>sodio litio</parameter></function>';

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::COMPLETE, $leak['status']);
        $this->assertSame('sodio litio', $leak['calls'][0]->arguments['query']);
    }

    public function test_prose_around_a_complete_envelope_is_kept_as_the_answer_text(): void
    {
        $content = "Voy a buscar.\n\n".self::COMPLETE;

        $leak = AiToolCallLeak::extract($content, self::OFFERED);

        $this->assertSame(AiToolCallLeak::COMPLETE, $leak['status']);
        $this->assertSame('Voy a buscar.', $leak['prose']);
    }

    public function test_strip_removes_the_envelope_but_leaves_a_mention_and_prose(): void
    {
        $this->assertSame(
            'Voy a buscar.',
            AiToolCallLeak::strip('Voy a buscar.'.self::COMPLETE)
        );

        $mention = 'El bloque <tool_call> es la forma en que un modelo pide una herramienta.';
        $this->assertSame($mention, AiToolCallLeak::strip($mention));

        $this->assertSame(
            'Aquí está el resumen.',
            AiToolCallLeak::strip("Aquí está el resumen.\n".self::COMPLETE)
        );
    }

    public function test_the_correction_directive_names_no_internal_detail(): void
    {
        $directive = AiToolCallLeak::correctionDirective();

        $this->assertStringNotContainsString('<tool_call', $directive);
        $this->assertStringNotContainsString('web_fetch', $directive);
        $this->assertStringNotContainsString('parameter', $directive);
    }
}

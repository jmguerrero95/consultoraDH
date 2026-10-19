<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolCall;

/**
 * Recognises a tool call a model printed as plain text instead of returning it
 * as a structured call.
 *
 * ## The defect this exists for
 *
 * The T02 live run ended with the whole answer being the model's own function
 * envelope written as text:
 *
 * ```
 * <tool_call>
 * <function=web_fetch>
 * <parameter=max_chars>5000</parameter>
 * <parameter=url>https://…</parameter>
 * </function>
 * </tool_call>
 * ```
 *
 * It reached the person verbatim because the loop reads any response without a
 * structured call as the answer, and the JSON-only sanitiser did not know this
 * shape. The result was neither a tool call nor an answer.
 *
 * ## What this does, and what it deliberately does not
 *
 * Recognition is deliberately strict: the envelope must be **the whole
 * message**. A model that returns a tool call returns *only* that call, so the
 * entire content — after surrounding whitespace — has to be one `<tool_call>`
 * wrapper around a single `<function=NAME>` element whose body is nothing but
 * `<parameter=KEY>value</parameter>` children, or a bare `<function=…>` element
 * of that same shape. Nothing looser counts.
 *
 * That strictness is the whole security property. A textual call that arrives
 * wrapped in prose, inside a fenced block, quoted from a document, shown as an
 * example, or mixed with other content is indistinguishable from text the model
 * merely *wrote about* a call, and executing it would be executing a quotation.
 * Those cases are reported as {@see self::MALFORMED}: never executed, never
 * shown as an answer. Ordinary prose that merely mentions `<tool_call>` has no
 * function element at all and is left untouched, so a question about the syntax
 * is never mistaken for a call.
 *
 * A pure envelope is returned as a structured {@see AiToolCall} so the
 * *existing* registry path validates and runs it: the tool must be one the turn
 * actually offered, its arguments go through the tool's own validator, and SSRF,
 * RBAC, deduplication and the turn budget all apply exactly as they do to a call
 * the provider returned properly. Nothing here executes anything, and a
 * structured call from the provider is always preferred.
 */
final class AiToolCallLeak
{
    /** No tool-call envelope is present: the text is an ordinary answer. */
    public const NONE = 'none';

    /** A complete, unambiguous envelope was found and parsed into calls. */
    public const COMPLETE = 'complete';

    /** An invocation was attempted but cannot be normalised safely. */
    public const MALFORMED = 'malformed';

    /**
     * The whole message is one `<tool_call>` wrapper. Anchored on purpose: a
     * wrapper with anything outside it is not an invocation this turn may run.
     */
    private const PURE_WRAPPER = '/^<\s*tool_call\b[^>]*>(.*)<\s*\/\s*tool_call\s*>$/is';

    /**
     * The whole message is one bare `<function=NAME>` element. Anchored for the
     * same reason: a function element with text around it is prose about a call.
     */
    private const PURE_FUNCTION = '/^<\s*function\s*=\s*([^\s>\/]+)\s*>(.*)<\s*\/\s*function\s*>$/is';

    /**
     * Text that still carries an invocation somewhere. Combined with the pure
     * checks above, a match here means the call is present but cannot be
     * vouched for, so it is neither an answer nor a call.
     */
    private const INVOCATION = '/<\s*function\s*=/i';

    private const BLOCK = '/<\s*tool_call\b[^>]*>(.*?)<\s*\/\s*tool_call\s*>/is';

    private const FUNCTION = '/<\s*function\s*=\s*([^\s>\/]+)\s*>(.*)<\s*\/\s*function\s*>/is';

    private const PARAMETER = '/<\s*parameter\s*=\s*([^\s>\/]+)\s*>(.*?)<\s*\/\s*parameter\s*>/is';

    private const FUNCTION_IN_A_WRAPPER = '/<\s*tool_call\b.*<\s*function\s*=/is';

    /**
     * Parse a model's prose for a textual tool call.
     *
     * @param  list<string>  $offeredNames  the tools this turn actually offered; a
     *                                      call to anything else is not normalisable
     * @return array{status: string, calls: list<AiToolCall>, prose: string}
     */
    public static function extract(string $content, array $offeredNames): array
    {
        $offered = array_fill_keys($offeredNames, true);

        $trimmed = trim($content);

        if ($trimmed === '') {
            return self::none($content);
        }

        // The pure envelope: the whole message is one call and nothing else.
        $body = null;

        if (preg_match(self::PURE_WRAPPER, $trimmed, $wrapper) === 1
            && preg_match(self::PURE_FUNCTION, trim($wrapper[1])) === 1) {
            $body = trim($wrapper[1]);
        } elseif (preg_match(self::PURE_FUNCTION, $trimmed) === 1) {
            $body = $trimmed;
        }

        if ($body !== null) {
            $call = self::parseFunction($body, $offered);

            return $call === null
                ? self::malformed(self::strip($content))
                : self::complete([$call], '');
        }

        // An invocation signal anywhere else — prose around the envelope, a
        // fenced block, a quotation, an incomplete or mixed envelope — is an
        // invalid model result: never an answer, and never executed.
        if (preg_match(self::INVOCATION, $content) === 1) {
            return self::malformed(self::strip($content));
        }

        return self::none($content);
    }

    /**
     * Remove a textual tool-call envelope from a reply, leaving the prose.
     *
     * Only a region that actually holds a function element is removed, so a
     * sentence that merely names `<tool_call>` is returned unchanged.
     */
    public static function strip(string $content): string
    {
        $removed = false;

        $text = preg_replace_callback(self::BLOCK, static function (array $match) use (&$removed): string {
            if (preg_match('/<\s*function\s*=/i', $match[1]) !== 1) {
                return $match[0];
            }

            $removed = true;

            return '';
        }, $content) ?? $content;

        if (preg_match(self::FUNCTION_IN_A_WRAPPER, $text) === 1) {
            $text = preg_replace('/<\s*tool_call\b.*$/is', '', $text) ?? $text;
            $removed = true;
        } elseif (self::looksLikeBareInvocation($text)) {
            $text = preg_replace('/<\s*function\s*=.*$/is', '', $text) ?? $text;
            $removed = true;
        }

        return $removed ? trim($text) : $content;
    }

    /**
     * The instruction a turn appends when it cannot normalise a leaked call.
     *
     * It names no internal detail — no tool output, no parameter error — so a
     * person reading the transcript learns nothing about the internals, and it
     * tells the model what to do instead of printing the envelope again.
     */
    public static function correctionDirective(): string
    {
        return 'Tu mensaje anterior no llegó como una llamada de herramienta válida y por eso no se ejecutó. '
            .'Si necesitas una herramienta, invócala por el mecanismo estructurado y con argumentos válidos; '
            .'no escribas el bloque de llamada como texto. Si ya tienes información suficiente, responde '
            .'directamente sin volver a imprimir ninguna llamada.';
    }

    /**
     * Whether the text contains the bare function envelope (no wrapper).
     *
     * A function element with no closing tag and no parameter is a mention, not
     * an invocation, and is not treated as one.
     */
    private static function looksLikeBareInvocation(string $content): bool
    {
        if (preg_match('/<\s*function\s*=/i', $content) !== 1) {
            return false;
        }

        return str_contains(mb_strtolower($content), '</function>')
            || preg_match('/<\s*parameter\s*=/i', $content) === 1;
    }

    /**
     * @param  array<string, bool>  $offered
     */
    private static function parseFunction(string $body, array $offered): ?AiToolCall
    {
        if (preg_match(self::FUNCTION, trim($body), $match) !== 1) {
            return null;
        }

        $name = $match[1];

        // A call to a tool this turn never offered is not a call this turn may
        // make: the registry is not asked to run it, and it is not an answer.
        if (! isset($offered[$name])) {
            return null;
        }

        $arguments = self::parseParameters($match[2] ?? '');

        if ($arguments === null) {
            return null;
        }

        return new AiToolCall($name, $arguments);
    }

    /**
     * @return array<string, mixed>|null null when the body holds anything that
     *                                   is not a parameter element
     */
    private static function parseParameters(string $inner): ?array
    {
        $residual = preg_replace(self::PARAMETER, '', $inner) ?? $inner;

        if (trim($residual) !== '') {
            return null;
        }

        $arguments = [];

        if (preg_match_all(self::PARAMETER, $inner, $matches) && $matches[1] !== []) {
            foreach ($matches[1] as $index => $key) {
                $value = html_entity_decode(trim($matches[2][$index]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $arguments[$key] = self::coerce($value);
            }
        }

        return $arguments;
    }

    /**
     * Give a textual parameter the type a JSON argument would have carried.
     *
     * The tool's own validator remains the authority; this only spares the turn
     * a spurious type rejection for the common `"5000"` that means the integer
     * 5000. Anything that is not clearly a number or a boolean stays a string.
     */
    private static function coerce(string $value): mixed
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        return $value;
    }

    /**
     * @return array{status: string, calls: list<AiToolCall>, prose: string}
     */
    private static function none(string $prose): array
    {
        return ['status' => self::NONE, 'calls' => [], 'prose' => $prose];
    }

    /**
     * @param  list<AiToolCall>  $calls
     * @return array{status: string, calls: list<AiToolCall>, prose: string}
     */
    private static function complete(array $calls, string $prose): array
    {
        return ['status' => self::COMPLETE, 'calls' => $calls, 'prose' => $prose];
    }

    /**
     * @return array{status: string, calls: list<AiToolCall>, prose: string}
     */
    private static function malformed(string $prose): array
    {
        return ['status' => self::MALFORMED, 'calls' => [], 'prose' => $prose];
    }
}

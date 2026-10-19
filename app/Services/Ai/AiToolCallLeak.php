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
 * It recognises exactly this structural envelope — a `<tool_call>` wrapper
 * around a single `<function=NAME>` element whose body is nothing but
 * `<parameter=KEY>value</parameter>` children — and nothing looser. Ordinary
 * prose that merely mentions `<tool_call>` has no function element and is left
 * untouched, so a question about the syntax is never mistaken for a call.
 *
 * A fully formed envelope is returned as a structured {@see AiToolCall} so the
 * *existing* registry path validates and runs it: the tool must be one the turn
 * actually offered, its arguments go through the tool's own validator, and SSRF,
 * RBAC, deduplication and the turn budget all apply exactly as they do to a call
 * the provider returned properly. Nothing here executes anything.
 *
 * An envelope that is incomplete, mixes other content into the function body,
 * or names a tool the turn did not offer cannot be normalised safely and is
 * reported as {@see self::MALFORMED}: the caller must not show it as an answer
 * and must not run it.
 */
final class AiToolCallLeak
{
    /** No tool-call envelope is present: the text is an ordinary answer. */
    public const NONE = 'none';

    /** A complete, unambiguous envelope was found and parsed into calls. */
    public const COMPLETE = 'complete';

    /** An invocation was attempted but cannot be normalised safely. */
    public const MALFORMED = 'malformed';

    private const BLOCK = '/<\s*tool_call\b[^>]*>(.*?)<\s*\/\s*tool_call\s*>/is';

    private const OPENER = '/<\s*tool_call\b/i';

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

        if (trim($content) === '') {
            return self::none($content);
        }

        // Complete wrappers. A wrapper whose body has no function element is a
        // mention, not a call, so it is kept as prose rather than treated as a
        // candidate.
        $candidates = [];

        $prose = preg_replace_callback(self::BLOCK, static function (array $match) use (&$candidates): string {
            if (preg_match('/<\s*function\s*=/i', $match[1]) !== 1) {
                return $match[0];
            }

            $candidates[] = $match[1];

            return '';
        }, $content) ?? $content;

        $prose = trim($prose);

        if ($candidates === []) {
            // An opener followed by a function but never closed: an attempted
            // invocation that cannot be read unambiguously.
            if (preg_match(self::FUNCTION_IN_A_WRAPPER, $prose) === 1) {
                return self::malformed(self::strip($content));
            }

            // A bare envelope, with no wrapper at all.
            if (self::looksLikeBareInvocation($content)) {
                $call = self::parseFunction(trim($content), $offered);

                return $call === null
                    ? self::malformed(self::strip($content))
                    : self::complete([$call], '');
            }

            return self::none($content);
        }

        // A complete envelope plus a second, incomplete one is ambiguous.
        if (preg_match(self::FUNCTION_IN_A_WRAPPER, $prose) === 1 || self::looksLikeBareInvocation($prose)) {
            return self::malformed(self::strip($content));
        }

        $calls = [];

        foreach ($candidates as $body) {
            $call = self::parseFunction($body, $offered);

            if ($call === null) {
                return self::malformed(self::strip($content));
            }

            $calls[] = $call;
        }

        return self::complete($calls, $prose);
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

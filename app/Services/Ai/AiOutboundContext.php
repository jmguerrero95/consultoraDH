<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * What the current turn knows about where its text came from.
 *
 * The pattern boundary in {@see AiPrivacyGate} can spot an email or a document
 * number, but a business name, an internal clause or a client's balance have no
 * shape to match. The only honest signal for those is *provenance*, and
 * provenance lives with the turn, not with the tool: this holder carries it from
 * the assistant (which retrieved the passages and ran the tools) to the outbound
 * tool (which is about to send a query).
 *
 * One list — `privateSources`: the text the turn holds that must not leave.
 * Passages from a document the person attached, results of tools that answer
 * from our records, and — when the thread is known to hold private data — the
 * prior turns themselves. A model that turns any of these into a search query is
 * copying private material outward.
 *
 * The person's own current question is deliberately *not* listed as an
 * authorisation for anything private: a term that appears in private material is
 * refused wherever it came from, because typing an identifier is not by itself a
 * decision to publish it. A genuinely independent public query carries terms that
 * appear in no private source, so it is still allowed — that is what keeps public
 * research working inside a private conversation.
 *
 * It is bound as a singleton because it is per-request state, and it is reset
 * when the turn ends so a later caller does not inherit stale provenance. An
 * empty holder is the truthful "nothing private is in play" state.
 */
final class AiOutboundContext
{
    /** Longest single source kept, to bound memory on a long document. */
    private const MAX_SOURCE_CHARS = 20_000;

    /** Most sources kept, so a turn cannot grow without bound. */
    private const MAX_SOURCES = 40;

    /** @var list<string> */
    private array $privateSources = [];

    /**
     * Start a turn's provenance.
     *
     * @param  list<string>  $privateSources
     */
    public function set(array $privateSources = []): void
    {
        $this->privateSources = [];

        foreach ($privateSources as $source) {
            $this->addPrivateSource($source);
        }
    }

    /**
     * Record a piece of private text, e.g. a passage or a tool result.
     */
    public function addPrivateSource(mixed $text): void
    {
        if (! is_string($text) || trim($text) === '') {
            return;
        }

        if (count($this->privateSources) >= self::MAX_SOURCES) {
            return;
        }

        $this->privateSources[] = mb_substr($text, 0, self::MAX_SOURCE_CHARS);
    }

    /**
     * @return list<string>
     */
    public function privateSources(): array
    {
        return $this->privateSources;
    }

    /**
     * Clear at the end of a turn, so the next caller inherits nothing.
     */
    public function reset(): void
    {
        $this->privateSources = [];
    }
}

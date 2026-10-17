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
 * Two lists, and the difference between them is the whole policy:
 *
 *   - `privateSources` — text the turn holds that must not leave: passages from a
 *     document the person attached, results of tools that answer from our
 *     records. A model that turns these into a search query is copying private
 *     material outward.
 *   - `authorizedSources` — text the person supplied themselves (their current
 *     question). Their own words are theirs to search with; a term they typed is
 *     allowed even when it also appears in private data.
 *
 * It is bound as a singleton because it is per-request state, and it is reset
 * when the turn ends so a later caller does not inherit stale provenance. An
 * empty holder is the truthful "nothing private is in play" state, and the search
 * boundary then allows a public query, which is what keeps public research
 * working inside a private conversation.
 */
final class AiOutboundContext
{
    /** Longest single source kept, to bound memory on a long document. */
    private const MAX_SOURCE_CHARS = 20_000;

    /** Most sources kept, so a turn cannot grow without bound. */
    private const MAX_SOURCES = 40;

    /** @var list<string> */
    private array $privateSources = [];

    /** @var list<string> */
    private array $authorizedSources = [];

    /**
     * Start a turn's provenance.
     *
     * @param  list<string>  $privateSources
     * @param  list<string>  $authorizedSources
     */
    public function set(array $privateSources = [], array $authorizedSources = []): void
    {
        $this->privateSources = [];
        $this->authorizedSources = [];

        foreach ($privateSources as $source) {
            $this->addPrivateSource($source);
        }

        foreach ($authorizedSources as $source) {
            $this->addAuthorizedSource($source);
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
     * Record a text the person supplied themselves.
     */
    public function addAuthorizedSource(mixed $text): void
    {
        if (! is_string($text) || trim($text) === '') {
            return;
        }

        if (count($this->authorizedSources) >= self::MAX_SOURCES) {
            return;
        }

        $this->authorizedSources[] = mb_substr($text, 0, self::MAX_SOURCE_CHARS);
    }

    /**
     * @return list<string>
     */
    public function privateSources(): array
    {
        return $this->privateSources;
    }

    /**
     * @return list<string>
     */
    public function authorizedSources(): array
    {
        return $this->authorizedSources;
    }

    /**
     * Clear at the end of a turn, so the next caller inherits nothing.
     */
    public function reset(): void
    {
        $this->privateSources = [];
        $this->authorizedSources = [];
    }
}

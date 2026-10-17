<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The one place that decides whether a payload may leave the server, and what
 * an outbound search query is allowed to say.
 *
 * ## Why a single boundary
 *
 * A07-R4 classified privacy inside the chat turn. That left the async workers
 * (research synthesis, document drafting, suggestion reasoning) calling the
 * provider resolver with no classification at all, which defaulted to "public",
 * and left every web search free to carry whatever identifier the model or the
 * prefetch happened to put in the query. Two divergent policies are one policy
 * too many: the leak is always the path that forgot to ask.
 *
 * This class owns both questions so every caller asks the same one:
 *
 *   - {@see payloadRequiresClientData()} — may this payload go to a provider
 *     whose free tier may train on the content?
 *   - {@see sanitizeQuery()} — what may this outbound search query actually
 *     contain?
 *
 * It has no dependencies and does no I/O, so it can be used from a tool, a job,
 * a controller and a service without wiring anything.
 *
 * ## Provenance first, patterns second
 *
 * The decision is driven by *where the data came from* — a conversation already
 * marked private, a retrieved document, a non-public task, a tool that reads our
 * records — because a business name or a client's balance has no shape a regular
 * expression can recognise. Patterns only add a conservative floor: an email or
 * a long digit run is treated as private even when nothing else says so. A regex
 * match can promote a payload to private; a missing match never demotes it.
 */
final class AiPrivacyGate
{
    /** What a redacted identifier is replaced with in an outbound query. */
    public const REDACTION = '[dato omitido]';

    /**
     * Whether a payload about to be sent to a model requires a provider
     * authorised for private data.
     *
     * @param  bool  $conversationFlagged  the conversation already held private data
     * @param  bool  $hasPrivateDocuments  retrieved passages from an attachment are in the payload
     * @param  bool  $nonPublicTask  the turn routed to a task that is not general/web
     * @param  bool  $privateToolRan  a tool that reads our records (or is unclassified) ran
     * @param  list<string>  $texts  the exact outgoing text: question, history, tool results, briefs
     */
    public function payloadRequiresClientData(
        bool $conversationFlagged = false,
        bool $hasPrivateDocuments = false,
        bool $nonPublicTask = false,
        bool $privateToolRan = false,
        array $texts = [],
    ): bool {
        if ($conversationFlagged || $hasPrivateDocuments || $nonPublicTask || $privateToolRan) {
            return true;
        }

        foreach ($texts as $text) {
            if ($this->looksPrivate((string) $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a piece of text carries a private identifier.
     *
     * Deliberately blunt: an email address, a run of six or more digits (the
     * shape of a document number, a NIT or an account), or a grouped phone
     * number. The only cost of a false positive is that a public answer uses a
     * provider eligible for private data.
     */
    public function looksPrivate(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text) === 1) {
            return true;
        }

        if (preg_match('/\d{6,}/', $text) === 1) {
            return true;
        }

        // The 3-3-4 grouping a person types for a phone number. Kept apart from
        // `\d{6,}` so a date such as 2024-01-31 is not mistaken for one.
        return preg_match('/\b\d{3}[\s.\-]\d{3}[\s.\-]\d{4}\b/', $text) === 1;
    }

    /**
     * Turn an outbound search query into one that may leave the server.
     *
     * The rule the mission states is the rule implemented: a public question
     * asked inside a private conversation is still a public question, so it is
     * not blocked; but an identifier that found its way into the query is
     * removed, and a query that is *only* an identifier is refused rather than
     * sent with its meaning stripped.
     *
     * The caller must use the returned `query` and never the original.
     *
     * @return array{query: string, redacted: int, blocked: bool, reason: string|null}
     */
    public function sanitizeQuery(string $query): array
    {
        $redacted = 0;

        $clean = preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            self::REDACTION,
            $query,
            -1,
            $count,
        ) ?? $query;
        $redacted += $count;

        $clean = preg_replace('/\d{6,}/', self::REDACTION, $clean, -1, $count) ?? $clean;
        $redacted += $count;

        $clean = preg_replace('/\b\d{3}[\s.\-]\d{3}[\s.\-]\d{4}\b/', self::REDACTION, $clean, -1, $count) ?? $clean;
        $redacted += $count;

        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);

        if (! $this->hasUsableTerms($clean)) {
            return [
                'query' => $clean,
                'redacted' => $redacted,
                'blocked' => true,
                'reason' => $redacted > 0
                    ? 'La consulta contenía únicamente datos privados; reformúlala con términos públicos.'
                    : 'La consulta está vacía.',
            ];
        }

        return ['query' => $clean, 'redacted' => $redacted, 'blocked' => false, 'reason' => null];
    }

    /**
     * Whether a sanitised query still carries a term worth searching for.
     *
     * One run of letters or of at least two digits is enough. The redaction
     * placeholder itself does not count, so "[dato omitido]" alone is refused.
     */
    private function hasUsableTerms(string $clean): bool
    {
        $withoutPlaceholder = str_replace(self::REDACTION, ' ', $clean);

        return preg_match('/[\p{L}]{2,}|\d{2,}/u', $withoutPlaceholder) === 1;
    }
}

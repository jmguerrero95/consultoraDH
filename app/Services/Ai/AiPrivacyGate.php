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
     * Query-string parameter names whose mere presence means the URL is carrying
     * a secret or a session token to a third party.
     */
    private const SENSITIVE_URL_PARAMS = [
        'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'apikey',
        'api_secret', 'client_secret', 'secret', 'password', 'passwd', 'pwd',
        'auth', 'authorization', 'credential', 'credentials', 'private_key',
        'session', 'sessionid', 'session_id', 'sid', 'jwt', 'signature', 'sig',
    ];

    /** Shortest run of letters or digits treated as a distinctive term. */
    private const TOKEN_MIN = 5;

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
     * Whether an outbound search query may leave, judged by shape *and*
     * provenance.
     *
     * `sanitizeQuery()` answers the shape question: no identifier in the query.
     * This answers the provenance question the pattern check cannot: even a query
     * with no recognisable identifier must not leave when its terms are drawn
     * from private material — a document the person attached, a record a private
     * tool returned, a prior turn of a conversation known to hold private data. A
     * business name or an internal agreement has no shape a regular expression
     * recognises; where it *came from* is the only honest signal.
     *
     * The rule is deliberately narrow so public research keeps working:
     *
     *   - no private sources in the turn → allowed (a public question is public);
     *   - a query whose terms appear in no private source → allowed, even inside a
     *     private conversation;
     *   - a query term that appears in private material → refused, wherever it
     *     came from. The person's own words are not an authorisation: typing an
     *     identifier into the current question does not make it public, so a term
     *     the private material holds is refused even when the person repeated it.
     *
     * The model cannot declare its own query public: it proposes terms, and the
     * decision is made here, from the turn's metadata, never from anything the
     * model said.
     *
     * The caller must use the returned `query` and never the original.
     *
     * @param  list<string>  $privateSources  exact private texts in the turn's context
     * @return array{query: string, redacted: int, blocked: bool, reason: string|null, provenance: string}
     */
    public function authorizeOutboundQuery(
        string $query,
        array $privateSources = [],
    ): array {
        $clean = $this->sanitizeQuery($query);

        if ($clean['blocked']) {
            return $clean + ['provenance' => 'identifier'];
        }

        if ($privateSources !== [] && $this->derivesFromPrivate($clean['query'], $privateSources)) {
            return [
                'query' => $clean['query'],
                'redacted' => $clean['redacted'],
                'blocked' => true,
                'reason' => 'La consulta parece provenir de información privada del contexto. Reformúlala con términos públicos y genéricos, sin nombres ni datos internos.',
                'provenance' => 'private_context',
            ];
        }

        return $clean + ['provenance' => 'public'];
    }

    /**
     * Why a URL must not be requested, or null when it may.
     *
     * A URL is an outbound message too. The SSRF layer already decides *where* it
     * may go; this decides whether the string may carry what it carries: an
     * embedded credential, an email, a token in a query parameter, or a value
     * taken from private material. Redirect targets are checked with the same
     * method, so a hop cannot smuggle past the first check.
     *
     * The provenance check covers *every* component a request transmits to the
     * destination — user info, path, query string and fragment — not only the
     * query string. A private value in the path ("/documentos/ProyectoAlondra")
     * travels exactly as a query parameter would, and percent-encoding is decoded
     * once before the comparison, so "%41londra" is checked as "Alondra". The host
     * is deliberately excluded: it names the public site, not the record on it.
     *
     * A bare number in a public URL is not refused: article and document ids are
     * ordinary. Only identifiers in the shape of a person's data, named
     * credentials and provenance-derived values are.
     *
     * The returned reason never contains the URL or the offending value.
     *
     * @param  list<string>  $privateSources  exact private texts in the turn's context
     */
    public function sensitiveUrlReason(
        string $url,
        array $privateSources = [],
    ): ?string {
        if ($url === '') {
            return null;
        }

        // Percent-encoding is a trivial veil: decode once and check both.
        $decoded = rawurldecode($url);

        if (preg_match('#^https?://[^/@]+@#i', $url) === 1 || preg_match('#^https?://[^/@]+@#i', $decoded) === 1) {
            return 'La URL incluye credenciales y no puede solicitarse.';
        }

        $email = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

        if (preg_match($email, $url) === 1 || preg_match($email, $decoded) === 1) {
            return 'La URL contiene un dato personal (correo) y no puede solicitarse.';
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $query = is_string($parts['query'] ?? null) ? rawurldecode((string) $parts['query']) : '';

        foreach ($this->queryParameterNames($query) as $name) {
            if (in_array($name, self::SENSITIVE_URL_PARAMS, true)) {
                return 'La URL incluye un parámetro sensible y no puede solicitarse.';
            }
        }

        if ($privateSources === []) {
            return null;
        }

        // Everything the request carries to the destination: user info, path,
        // query and fragment, decoded once. A fragment is included even though an
        // ordinary HTTP request does not transmit it, because the tool reports it
        // back as the page that was read, and a private fragment is still text
        // the model chose out of private material.
        $carried = [];

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $component) {
            if (is_string($parts[$component] ?? null) && $parts[$component] !== '') {
                $carried[] = rawurldecode((string) $parts[$component]);
            }
        }

        if ($carried !== [] && $this->derivesFromPrivate(implode(' ', $carried), $privateSources)) {
            return 'La URL contiene información privada y no puede consultarse.';
        }

        return null;
    }

    /**
     * Whether the given text's distinctive terms are drawn from private material.
     *
     * A term that exists in any private source and has surfaced in the outbound
     * text is the model composing from private context, which is the leak this
     * closes. The person's own words do not authorise a private term: a term the
     * private material holds is refused wherever it came from, because repeating
     * an identifier is not by itself a decision to publish it.
     *
     * @param  list<string>  $privateSources
     */
    private function derivesFromPrivate(string $text, array $privateSources): bool
    {
        $tokens = $this->significantTokens($text);

        if ($tokens === []) {
            return false;
        }

        foreach ($privateSources as $source) {
            foreach (array_keys($this->significantTokens($source)) as $token) {
                if (isset($tokens[$token])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The distinctive terms of a text: accented letters and digits in runs long
     * enough to carry meaning, keyed for set membership.
     *
     * Runs are split on camelCase and letter/digit transitions before the length
     * filter, so a value that arrived glued together — "ProyectoAlondra",
     * "articulo123456", a path segment — yields the same terms as the separated
     * text. Without that, a private name joined into a single path segment would
     * match nothing and leave unblocked.
     *
     * @return array<string, true>
     */
    private function significantTokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/(?<=[\p{Ll}])(?=\p{Lu})/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{L}])(?=\p{N})/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{N}])(?=\p{L})/u', ' ', $text) ?? $text;

        preg_match_all('/[\p{L}\p{N}]{'.self::TOKEN_MIN.',}/u', mb_strtolower($text), $matches);

        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            $tokens[$token] = true;
        }

        return $tokens;
    }

    /**
     * The lower-cased parameter names of a query string.
     *
     * @return list<string>
     */
    private function queryParameterNames(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $names = [];

        foreach (preg_split('/[&;]/', $query) ?: [] as $pair) {
            $name = explode('=', $pair, 2)[0];
            $name = strtolower(trim($name));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
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

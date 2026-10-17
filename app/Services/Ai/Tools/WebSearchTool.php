<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\AiPrivacyGate;
use App\Services\Ai\AiToolDeadline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Informative web search tool using a configurable SearXNG-compatible provider.
 *
 * Configuration through env/config:
 * - AI_WEB_SEARCH_ENABLED: bool
 * - AI_WEB_SEARCH_BASE_URL: SearXNG instance URL (e.g., https://searx.example.com)
 * - AI_WEB_SEARCH_TIMEOUT_SECONDS: int
 *
 * Does not hard-code one commercial vendor.
 *
 * Output: title, url, domain, snippet, published_at when available.
 */
final class WebSearchTool extends AbstractAiTool
{
    /**
     * @param  AiPrivacyGate|null  $privacy  redacts private identifiers before a
     *                                       query leaves for a public engine
     * @param  AiToolDeadline|null  $deadline  the turn's clock, so a search never
     *                                         outlives the budget that asked for it
     */
    public function __construct(
        private readonly ?AiPrivacyGate $privacy = null,
        private readonly ?AiToolDeadline $deadline = null,
    ) {}

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Búsqueda web informativa con fuentes citadas. Usa un proveedor SearXNG configurable. Devuelve título, URL, dominio, fragmento y fecha cuando está disponible.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Términos de búsqueda.'],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Máximo de resultados a devolver (1 a 10).',
                    'default' => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $query = $this->requireString($arguments, 'query', 500);
        $maxResults = $arguments['max_results'] ?? 5;

        if (! is_int($maxResults) || $maxResults < 1 || $maxResults > 10) {
            throw new AiToolRejected("'max_results' debe ser un entero entre 1 y 10.");
        }

        return [
            'query' => $query,
            'max_results' => $maxResults,
        ];
    }

    public function describe(array $arguments): string
    {
        return sprintf(
            'Buscar en la web: %s (máx. %d resultado%s).',
            $arguments['query'],
            $arguments['max_results'],
            $arguments['max_results'] === 1 ? '' : 's'
        );
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'web_search');

        if (! config('ai.web_search.enabled', false)) {
            throw new AiToolRejected('La búsqueda web no está habilitada. Configure AI_WEB_SEARCH_ENABLED=true y AI_WEB_SEARCH_BASE_URL.');
        }

        $query = $arguments['query'];
        $maxResults = $arguments['max_results'];

        // The outbound-query boundary. A search goes to a third party (the
        // engines behind the SearXNG instance), so an identifier that reached the
        // query must not leave with it. A public query asked inside a private
        // conversation is still a public query and is not blocked; a query that
        // is *only* an identifier is refused rather than emptied. The sanitised
        // query is what is sent and what is reported — never the original.
        $guarded = ($this->privacy ?? app(AiPrivacyGate::class))->sanitizeQuery($query);

        if ($guarded['blocked']) {
            throw new AiToolRejected($guarded['reason'] ?? 'La consulta no puede enviarse a un buscador público.');
        }

        $query = $guarded['query'];

        $baseUrl = config('ai.web_search.base_url');
        if (empty($baseUrl)) {
            throw new AiToolRejected('No hay proveedor de búsqueda web configurado. Establezca AI_WEB_SEARCH_BASE_URL.');
        }

        $timeout = ($this->deadline ?? app(AiToolDeadline::class))
            ->cap((int) config('ai.web_search.timeout_seconds', 10));

        $url = rtrim($baseUrl, '/').'/search';
        $params = [
            'q' => $query,
            'format' => 'json',
            'categories' => 'general',
            'language' => 'es',
            'safesearch' => 1,
        ];

        try {
            // `withQueryParameters` is what puts these in the URL. Passing them
            // as `['query' => ...]` to `get()` does not: that key is Guzzle's
            // *body* option, so the request went out with no `q` at all and the
            // provider answered 403 for a query it had never received. The tool
            // therefore never returned a real result against a real provider.
            $response = Http::timeout($timeout)
                ->withQueryParameters($params)
                ->withHeaders([
                    'User-Agent' => 'Consultora-DH-Assistant/1.0',
                    'Accept' => 'application/json',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            throw new AiToolRejected('Error de conexión con el proveedor de búsqueda: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw new AiToolRejected('Error en la búsqueda web: '.$response->status().' '.$response->body());
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['results'])) {
            throw new AiToolRejected('Respuesta inválida del proveedor de búsqueda.');
        }

        $results = [];
        foreach ($data['results'] as $result) {
            if (! is_array($result)) {
                continue;
            }

            $title = $result['title'] ?? '';
            $url = $result['url'] ?? '';
            $snippet = $result['content'] ?? $result['snippet'] ?? '';
            $publishedAt = null;

            // Extract domain from URL
            $domain = '';
            if ($url) {
                $parsed = parse_url($url);
                $domain = $parsed['host'] ?? '';
                $domain = preg_replace('/^www\./', '', $domain);
            }

            // Parse published date if available
            if (isset($result['publishedDate']) && is_string($result['publishedDate'])) {
                try {
                    $publishedAt = Carbon::parse($result['publishedDate'])->toIso8601String();
                } catch (\Throwable) {
                    // Ignore invalid dates
                }
            }

            if ($title && $url) {
                $results[] = [
                    'title' => $title,
                    'url' => $url,
                    'domain' => $domain,
                    'snippet' => $snippet,
                    'published_at' => $publishedAt,
                ];
            }

            if (count($results) >= $maxResults) {
                break;
            }
        }

        // An empty result set is reported as an empty result set.
        //
        // This used to be padded with a placeholder row carrying `'url' => '#'`,
        // which is indistinguishable from a real citation: the assistant was
        // told it had one source whose title was "No se encontraron
        // resultados.", and any UI that rendered a link list would draw an
        // entry for a page that does not exist. A model reading that has no way
        // to tell a fabricated citation from a real one, which is the failure
        // mode this whole tool exists to prevent.
        return [
            'query' => $query,
            'results' => array_slice($results, 0, $maxResults),
            'source' => 'searxng',
            'total_results' => count($results),
            'found_any' => $results !== [],
        ];
    }
}

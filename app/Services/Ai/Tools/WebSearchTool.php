<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
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

        $baseUrl = config('ai.web_search.base_url');
        if (empty($baseUrl)) {
            throw new AiToolRejected('No hay proveedor de búsqueda web configurado. Establezca AI_WEB_SEARCH_BASE_URL.');
        }

        $timeout = config('ai.web_search.timeout_seconds', 10);

        $url = rtrim($baseUrl, '/').'/search';
        $params = [
            'q' => $query,
            'format' => 'json',
            'categories' => 'general',
            'language' => 'es',
            'safesearch' => 1,
        ];

        try {
            $response = Http::timeout($timeout)
                ->get($url, [
                    'query' => $params,
                    'headers' => [
                        'User-Agent' => 'Consultora-DH-Assistant/1.0',
                        'Accept' => 'application/json',
                    ],
                ]);
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

        if (empty($results)) {
            $results[] = [
                'title' => 'No se encontraron resultados.',
                'url' => '#',
                'domain' => '',
                'snippet' => 'La búsqueda web no devolvió resultados para: '.$query,
                'published_at' => null,
            ];
        }

        return [
            'query' => $query,
            'results' => array_slice($results, 0, $maxResults),
            'source' => 'searxng',
            'total_results' => count($results),
        ];
    }
}

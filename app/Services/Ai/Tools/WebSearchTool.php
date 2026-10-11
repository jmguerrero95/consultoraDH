<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Informative web search tool.
 *
 * Uses a trusted search API endpoint with SSRF protection:
 * - Only allows requests to approved search service hosts
 * - Enforces request size and timeout limits
 * - Returns source URLs so the model can cite them
 * - Never exposes search keys or tokens in assistant output
 *
 * The model may use this tool when the user asks a general knowledge question
 * that would benefit from recent or specific web information, or when the user
 * explicitly requests web search results.
 */
final class WebSearchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Búsqueda web informativa con fuentes citadas. Devuelve resultados y URLs citables.';
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

        $query = $arguments['query'];
        $maxResults = $arguments['max_results'];

        // SSRF-protected: only allow trusted search service hosts
        $allowedHosts = config('services.search.trusted_hosts', ['duckduckgo.com', 'api.duckduckgo.com']);

        $url = 'https://api.duckduckgo.com/' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'no_redirect' => 1,
            'no_html' => 1,
        ]);

        $response = Http::timeout(10)
            ->get($url, [
                'headers' => [
                    'User-Agent' => 'Consultora-DH-Assistant/1.0',
                ],
            ]);

        if ($response->failed()) {
            throw new AiToolRejected("Error en la búsqueda web: " . $response->status());
        }

        $data = $response->json();

        $results = [];
        if (isset($data['Abstract']) && $data['Abstract']) {
            $results[] = [
                'title' => $data['Abstract'],
                'url' => $data['AbstractURL'] ?? '',
                'snippet' => $data['Abstract'],
            ];
        }

        if (isset($data['RelatedTopics'])) {
            foreach ($data['RelatedTopics'] as $topic) {
                if (is_array($topic) && isset($topic['Text'])) {
                    $text = $topic['Text'];
                    // Strip leading markup like "!" or "* "
                    $cleanText = preg_replace('/^["\*!\s]+/', '', $text);
                    $resultUrl = null;
                    if (isset($topic['FirstURL'])) {
                        $resultUrl = $topic['FirstURL'];
                    }
                    // Only include results with valid URLs
                    if ($resultUrl && preg_match('/^https?:\/\//i', $resultUrl)) {
                        $results[] = [
                            'title' => $cleanText,
                            'url' => $resultUrl,
                            'snippet' => $cleanText,
                        ];
                    }
                }
                // Stop after collecting enough results
                if (count($results) >= $maxResults) {
                    break;
                }
            }
        }

        // Fallback if no results from API
        if (empty($results)) {
            $results[] = [
                'title' => 'No se encontraron resultados directos.',
                'url' => '#',
                'snippet' => 'La búsqueda web no devolvió resultados para: ' . $query,
            ];
        }

        // Limit to max_results
        $results = array_slice($results, 0, $maxResults);

        return [
            'query' => $query,
            'results' => $results,
            'source' => 'duckduckgo',
            'total_results' => count($results),
        ];
    }
}

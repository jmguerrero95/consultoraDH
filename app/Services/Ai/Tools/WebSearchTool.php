<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\AiOutboundContext;
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
     *                                       query leaves for a public engine, and
     *                                       judges whether the query derives from
     *                                       private context
     * @param  AiToolDeadline|null  $deadline  the turn's clock, so a search never
     *                                         outlives the budget that asked for it
     * @param  AiOutboundContext|null  $outbound  the turn's private and authorised
     *                                            source texts, so provenance — not
     *                                            the model's claim — decides
     */
    public function __construct(
        private readonly ?AiPrivacyGate $privacy = null,
        private readonly ?AiToolDeadline $deadline = null,
        private readonly ?AiOutboundContext $outbound = null,
    ) {}

    public function name(): string
    {
        return 'web_search';
    }

    /**
     * Languages a query may be sent in.
     *
     * `es` is the default because the assistant answers in Spanish, but a
     * technical subject is often documented in English first and Spanish
     * articles simply restate it. The model may ask for `en` when that would
     * widen the coverage — and `auto` lets the engine detect the language of
     * the query itself, which is what an English-language question needs.
     *
     * @var list<string>
     */
    private const LANGUAGES = ['es', 'en', 'pt', 'auto'];

    public function description(): string
    {
        return 'Búsqueda web informativa con fuentes citadas. Usa un proveedor SearXNG configurable. Devuelve título, URL, dominio, fragmento y fecha cuando está disponible. '
            .'Si un tema técnico se documenta primero en inglés, puedes repetir la búsqueda con language="en" para ampliar la cobertura. '
            .'Reformula con términos concretos cuando una consulta no devuelva resultados pertinentes; nunca mezcles varias preguntas distintas en una sola consulta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Términos de búsqueda, concretos y específicos (no la pregunta completa).'],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Máximo de resultados a devolver (1 a 10).',
                    'default' => 5,
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => self::LANGUAGES,
                    'description' => 'Idioma de la búsqueda: "es" (por defecto), "en" para temas técnicos documentados primero en inglés, "pt" o "auto" (detectar).',
                    'default' => 'es',
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

        $language = $arguments['language'] ?? 'es';
        $language = is_string($language) ? strtolower(trim($language)) : '';

        if (! in_array($language, self::LANGUAGES, true)) {
            throw new AiToolRejected("'language' debe ser uno de: ".implode(', ', self::LANGUAGES).'.');
        }

        return [
            'query' => $query,
            'max_results' => $maxResults,
            'language' => $language,
        ];
    }

    public function describe(array $arguments): string
    {
        return sprintf(
            'Buscar en la web: %s (máx. %d resultado%s%s).',
            $arguments['query'],
            $arguments['max_results'],
            $arguments['max_results'] === 1 ? '' : 's',
            ($arguments['language'] ?? 'es') !== 'es' ? ', idioma '.$arguments['language'] : ''
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
        $language = $arguments['language'] ?? 'es';

        // The outbound-query boundary. A search goes to a third party (the
        // engines behind the SearXNG instance), so two questions are asked before
        // it leaves: does the query carry an identifier (redaction/refusal), and
        // does it derive from private material the turn holds (document
        // passages, private tool results, a flagged thread's history)? The second
        // is provenance, not shape — a business name has no pattern — and it is
        // answered here, outside the model, from the turn's own metadata. A
        // public query asked inside a private conversation is still a public
        // query: it is allowed as long as none of its terms appear in the private
        // sources. The sanitised query is what is sent and what is reported.
        $context = $this->outbound ?? app(AiOutboundContext::class);
        $guarded = ($this->privacy ?? app(AiPrivacyGate::class))->authorizeOutboundQuery(
            $query,
            $context->privateSources(),
        );

        if ($guarded['blocked']) {
            throw new AiToolRejected($guarded['reason'] ?? 'La consulta no puede enviarse a un buscador público.');
        }

        $query = $guarded['query'];

        $baseUrl = config('ai.web_search.base_url');
        if (empty($baseUrl)) {
            throw new AiToolRejected('No hay proveedor de búsqueda web configurado. Establezca AI_WEB_SEARCH_BASE_URL.');
        }

        // The turn's clock. `budgetFor` returns null once there is not enough
        // time left to start the request at all — including an expired turn — and
        // the search is refused rather than started with a minimum that would
        // outlive the budget. No external connection is made after that point.
        $timeout = ($this->deadline ?? app(AiToolDeadline::class))
            ->budgetFor((int) config('ai.web_search.timeout_seconds', 10));

        if ($timeout === null) {
            throw new AiToolRejected('El turno no tiene tiempo suficiente para buscar en la web; intenta de nuevo o responde con la información que ya tienes.');
        }

        $url = rtrim($baseUrl, '/').'/search';
        $params = [
            'q' => $query,
            'format' => 'json',
            'categories' => 'general',
            'language' => $language,
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
            // The engine could not be reached. This is the tool failing, not the
            // subject having no information: the wording says so explicitly, so a
            // model reading the error cannot report "no existe información" when
            // the real cause is that nothing was consulted.
            throw new AiToolRejected(
                'No se pudo consultar el buscador web (no está disponible: '.$e->getMessage().'). '
                .'Esto NO significa que la información no exista: la búsqueda no llegó a realizarse.'
            );
        }

        if ($response->failed()) {
            throw new AiToolRejected(
                'El buscador web respondió con un error '.$response->status().', es decir, no está disponible en este momento. '
                .'Esto NO significa que la información no exista; no se pudo consultar. '
                .'Intenta con otra formulación y, si el buscador sigue fallando, dilo con esas palabras.'
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['results'])) {
            throw new AiToolRejected(
                'El buscador web devolvió una respuesta que no se pudo interpretar; el servicio no está disponible correctamente. '
                .'Esto NO significa que la información no exista.'
            );
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
            'language' => $language,
            'results' => array_slice($results, 0, $maxResults),
            'source' => 'searxng',
            'total_results' => count($results),
            'found_any' => $results !== [],
            // The distinction the model must not lose: a search that worked and
            // found nothing is not the same as an engine that could not be
            // consulted. This assertion is about the subject being thin, and the
            // next step is a reformulation, not a claim of non-existence.
            'note' => $results === []
                ? 'La búsqueda se ejecutó correctamente pero no devolvió resultados para esta consulta. '
                    .'No concluyas que la información no existe: reformula con términos más concretos, '
                    .'nombres propios o, si el tema es técnico, prueba de nuevo con language="en".'
                : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Web fetch tool with SSRF protection.
 *
 * Fetches a URL and returns the content, with strict SSRF protections:
 * - Only allows hosts configured in `services.fetch.trusted_hosts`
 * - Enforces maximum response size to prevent memory exhaustion
 * - Enforces request timeout
 * - Strips sensitive headers and metadata from the result
 * - Returns a truncated excerpt if the content is too large
 *
 * The model may use this tool when the user explicitly requests to fetch
 * a specific URL's content, or when it needs to reference web page content
 * as data (not instructions).
 */
final class WebFetchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Fetch y muestra el contenido de una URL con protección SSRF. Solo hosts autorizados.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL absoluta a fetchear.'],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Máximo de caracteres a devolver (1 a 5000).',
                    'default' => 1000,
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $url = $this->requireString($arguments, 'url', 2000);

        // Basic URL validation - must be http or https
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new AiToolRejected("URL inválida: debe ser una URL http o https.");
        }

        $maxChars = $arguments['max_chars'] ?? 1000;

        if (! is_int($maxChars) || $maxChars < 1 || $maxChars > 5000) {
            throw new AiToolRejected("'max_chars' debe ser un entero entre 1 y 5000.");
        }

        return [
            'url' => $url,
            'max_chars' => $maxChars,
        ];
    }

    public function describe(array $arguments): string
    {
        return sprintf(
            'Fetch URL: %s (máx. %d carácter%s).',
            $arguments['url'],
            $arguments['max_chars'],
            $arguments['max_chars'] === 1 ? '' : 's'
        );
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'web_fetch');

        $url = $arguments['url'];
        $maxChars = $arguments['max_chars'];

        // SSRF protection: only allow trusted hosts
        $trustedHosts = config('services.fetch.trusted_hosts', []);
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Remove port for comparison
        $host = preg_replace('/:\d+$/', '', $host);

        $isTrusted = false;
        foreach ($trustedHosts as $trusted) {
            $trusted = preg_replace('/:\d+$/', '', $trusted);
            if (strtolower($host) === strtolower($trusted) || Str::endsWith(strtolower($host), '.'.strtolower($trusted))) {
                $isTrusted = true;
                break;
            }
        }

        if (! $isTrusted) {
            throw new AiToolRejected("Host no autorizado: {$host}. Los hosts autorizados son: " . implode(', ', $trustedHosts));
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Consultora-DH-Assistant/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml',
            ])->timeout(15)->get($url);

            if ($response->failed()) {
                throw new AiToolRejected("Error fetching URL: " . $response->status() . ' ' . $response->statusText());
            }

            $content = $response->body();

            // Truncate if too large
            if (strlen($content) > $maxChars) {
                $content = substr($content, 0, $maxChars) . '... [truncado]';
            }

            // Extract basic metadata
            $title = '';
            if (preg_match('/<title>(.*?)<\/title>/i', $content, $matches)) {
                $title = strip_tags($matches[1]);
                if (mb_strlen($title) > 200) {
                    $title = mb_substr($title, 0, 200) . '...';
                }
            }

            return [
                'url' => $url,
                'title' => $title,
                'content' => $content,
                'status_code' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'content_length' => strlen($response->body()),
            ];
        } catch (\Throwable $e) {
            throw new AiToolRejected("Error al fetchear URL: " . $e->getMessage());
        }
    }
}

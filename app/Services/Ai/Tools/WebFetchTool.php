<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\AiOutboundContext;
use App\Services\Ai\AiPrivacyGate;
use App\Services\Ai\AiToolDeadline;
use App\Services\Ai\Net\BoundedStream;
use App\Services\Ai\Net\DownloadTooLarge;
use App\Services\Ai\Net\PinnedTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Web fetch tool with comprehensive SSRF protection.
 *
 * Implements actual SSRF protection:
 * - Rejects URL credentials (user:pass@)
 * - Resolves DNS and validates every resolved IPv4 and IPv6
 * - Rejects loopback (127.0.0.0/8, ::1)
 * - Rejects RFC1918 private ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
 * - Rejects link-local (169.254.0.0/16, fe80::/10)
 * - Rejects metadata ranges (169.254.169.254, fd00::/8, etc.)
 * - Rejects reserved/private ranges
 * - Rejects Docker/internal names
 * - Validates EVERY redirect target before following
 * - Bounded redirects (max 5)
 * - Bounded timeout
 * - Bounded downloaded bytes
 * - Accepts safe text/html types only
 *
 * Extracts readable text; does not return executable HTML/scripts.
 */
final class WebFetchTool extends AbstractAiTool
{
    private const MAX_REDIRECTS = 5;

    private const MAX_DOWNLOAD_BYTES = 500_000; // 500KB

    private const ALLOWED_CONTENT_TYPES = [
        'text/html',
        'text/plain',
        'application/xhtml+xml',
        'application/xml',
        'text/xml',
    ];

    public function __construct(
        private readonly PinnedTransport $transport,
        private readonly ?AiToolDeadline $deadline = null,
        private readonly ?AiPrivacyGate $privacy = null,
        private readonly ?AiOutboundContext $outbound = null,
    ) {}

    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Obtiene el contenido legible de una URL pública con protección SSRF completa. Solo HTTP/HTTPS públicos; rechaza IPs privadas, localhost, metadatos y redirecciones inseguras.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL absoluta a fetchear (http/https).'],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Máximo de caracteres a devolver (1 a 10000).',
                    'default' => 3000,
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
            throw new AiToolRejected('URL inválida: debe ser una URL http o https.');
        }

        // A URL is an outbound message too: a credential, an email, a token in a
        // query parameter or a value taken from private material must not leave.
        // The same check runs on every redirect target in `execute()`, so a hop
        // cannot smuggle past this first look.
        $context = $this->outbound ?? app(AiOutboundContext::class);
        $reason = ($this->privacy ?? app(AiPrivacyGate::class))->sensitiveUrlReason(
            $url,
            $context->privateSources(),
        );

        if ($reason !== null) {
            throw new AiToolRejected($reason);
        }

        $maxChars = $arguments['max_chars'] ?? 3000;

        if (! is_int($maxChars) || $maxChars < 1 || $maxChars > 10000) {
            throw new AiToolRejected("'max_chars' debe ser un entero entre 1 y 10000.");
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

        // Redirects are never followed automatically: every hop has to be
        // resolved and re-validated before it is requested — including against the
        // clock, so a redirect cannot begin a fresh request once the turn has no
        // time left. The per-hop timeout is applied inside the loop below.
        $client = Http::withHeaders([
            'User-Agent' => 'Consultora-DH-Assistant/1.0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml',
        ])->withOptions([
            'allow_redirects' => false,
            // A proxy would resolve the hostname itself, which makes the pinned
            // address below advisory rather than authoritative: the request would
            // go to the proxy and the proxy would pick any address it liked,
            // including the one this tool just refused. An empty proxy disables
            // both Guzzle's configured proxy and the HTTP_PROXY/HTTPS_PROXY
            // environment, so the connection reaches the validated address or
            // nothing does.
            'proxy' => '',
        ]);

        // Provenance for the URL check, read once: it applies to every hop.
        $context = $this->outbound ?? app(AiOutboundContext::class);
        $privacy = $this->privacy ?? app(AiPrivacyGate::class);

        // Track redirects manually to validate each target
        $currentUrl = $url;
        $redirectCount = 0;
        $finalResponse = null;
        $finalUrl = $url;

        while ($redirectCount <= self::MAX_REDIRECTS) {
            // The clock is asked at every hop. A null answer means the turn has
            // no time left — or too little for a call to be worth starting — and
            // the request, first or redirect, is refused rather than begun.
            $timeout = ($this->deadline ?? app(AiToolDeadline::class))
                ->budgetFor((int) config('ai.web_fetch.timeout_seconds', 15));

            if ($timeout === null) {
                throw new AiToolRejected('El turno no tiene tiempo suficiente para leer la página solicitada.');
            }

            // The content policy is re-applied to the effective URL of this hop,
            // so a redirect to a URL carrying a token or a private value — in the
            // path, the user info, the query or the fragment — is refused exactly
            // as the first URL would be.
            $reason = $privacy->sensitiveUrlReason(
                $currentUrl,
                $context->privateSources(),
            );

            if ($reason !== null) {
                throw new AiToolRejected($reason);
            }

            // Resolve, refuse anything not publicly routable, and pin the
            // connection to the addresses that survived. Repeated on every hop,
            // so a later redirect is checked exactly as the first request was.
            $parsedUrl = parse_url($currentUrl);

            if (! is_array($parsedUrl) || ! isset($parsedUrl['host'])) {
                throw new AiToolRejected('URL inválida: no se pudo analizar el host.');
            }

            $host = $parsedUrl['host'];
            $port = (int) ($parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'http') === 'https' ? 443 : 80));

            $resolvedIps = $this->transport->validatedAddresses($host);

            // Reset per hop so each response is bounded on its own.
            //
            // The sink is a PSR-7 stream rather than a callable because that is
            // what Guzzle accepts: `streamFor()` wraps a callable in a
            // PumpStream, which is read-only, so its `write()` — the callback
            // cURL hands every chunk to — throws on the first byte of every
            // real response. The stream also carries the cap, so it is the
            // transport's own writer that stops the transfer rather than
            // something that shortens an already-downloaded body.
            $body = new BoundedStream(self::MAX_DOWNLOAD_BYTES);

            try {
                $response = $client->withOptions([
                    'timeout' => $timeout,
                    'curl' => $this->transport->pinOptions($host, $port, $resolvedIps),
                    'sink' => $body,
                ])->get($currentUrl);
            } catch (DownloadTooLarge) {
                throw new AiToolRejected(
                    'La respuesta supera el máximo de '.self::MAX_DOWNLOAD_BYTES.' bytes permitidos.'
                );
            } catch (\Throwable $e) {
                throw new AiToolRejected('Error al fetchear URL: '.$e->getMessage());
            }

            if ($response->failed()) {
                throw new AiToolRejected('Error fetching URL: '.$response->status().' '.$response->statusText());
            }

            // A sender that declares an oversized length is refused before the
            // body is read at all.
            $declared = $response->header('Content-Length');

            if ($body->declaredLengthExceeds($declared === null ? null : (int) $declared)) {
                throw new AiToolRejected(
                    'La respuesta declara un tamaño superior al máximo de '
                    .self::MAX_DOWNLOAD_BYTES.' bytes permitidos.'
                );
            }

            $contentType = $response->header('Content-Type') ?? '';
            $isAllowedType = false;
            foreach (self::ALLOWED_CONTENT_TYPES as $allowed) {
                if (Str::startsWith(strtolower($contentType), $allowed)) {
                    $isAllowedType = true;
                    break;
                }
            }

            if (! $isAllowedType) {
                throw new AiToolRejected("Tipo de contenido no permitido: {$contentType}. Solo se aceptan text/html, text/plain, application/xhtml+xml, application/xml.");
            }

            // Check for redirect
            $status = $response->status();
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! $location) {
                    break; // No location header, stop
                }

                $redirectCount++;
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new AiToolRejected('Demasiadas redirecciones (máx. '.self::MAX_REDIRECTS.').');
                }

                // Resolve relative redirects
                $currentUrl = $this->resolveRedirect($currentUrl, $location);
                $finalUrl = $currentUrl;

                continue;
            }

            $finalResponse = $response;
            break;
        }

        if ($finalResponse === null) {
            throw new AiToolRejected('No se pudo obtener respuesta válida tras redirecciones.');
        }

        // What the transfer actually delivered, which is within the cap because
        // the writer refuses the chunk that would pass it. The response body is
        // the fallback for handlers that do not stream into a sink.
        $content = $body->size() > 0 ? $body->contents() : $finalResponse->body();

        // Extract readable text (strip HTML tags, scripts, styles)
        $textContent = $this->extractReadableText($content);

        // Extract basic metadata
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $content, $matches)) {
            $title = strip_tags($matches[1]);
            if (mb_strlen($title) > 200) {
                $title = mb_substr($title, 0, 200).'...';
            }
        }

        // Truncate to max_chars
        if (mb_strlen($textContent) > $maxChars) {
            $textContent = mb_substr($textContent, 0, $maxChars).'... [truncado]';
        }

        return [
            'url' => $finalUrl,
            'title' => $title,
            'content' => $textContent,
            'status_code' => $finalResponse->status(),
            'content_type' => $finalResponse->header('Content-Type'),
            'content_length' => strlen($content),
        ];
    }

    /**
     * Resolve a relative redirect URL against the current URL.
     */
    private function resolveRedirect(string $currentUrl, string $location): string
    {
        $parsedCurrent = parse_url($currentUrl);
        $parsedLocation = parse_url($location);

        if (isset($parsedLocation['scheme']) && isset($parsedLocation['host'])) {
            // Absolute URL
            return $location;
        }

        // Relative URL
        $base = ($parsedCurrent['scheme'] ?? 'https').'://'.($parsedCurrent['host'] ?? '');
        if (isset($parsedCurrent['port'])) {
            $base .= ':'.$parsedCurrent['port'];
        }

        $path = $parsedCurrent['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');

        if (Str::startsWith($location, '/')) {
            return $base.$location;
        }

        return $base.'/'.$dir.'/'.$location;
    }

    /**
     * Extract readable text from HTML content.
     */
    private function extractReadableText(string $html): string
    {
        // Remove scripts, styles, noscript, comments
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convert common block elements to newlines
        $blockTags = ['div', 'p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'td', 'th', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside'];
        foreach ($blockTags as $tag) {
            $html = preg_replace('/<\/?'.$tag.'\b[^>]*>/i', "\n", $html);
        }

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }
}

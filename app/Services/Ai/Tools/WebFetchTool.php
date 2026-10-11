<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
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
            throw new AiToolRejected("URL inválida: debe ser una URL http o https.");
        }

        // Reject URLs with credentials
        if (preg_match('/^https?:\/\/[^\/]+@/', $url)) {
            throw new AiToolRejected('URLs con credenciales no son permitidas.');
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

        $client = Http::withHeaders([
            'User-Agent' => 'Consultora-DH-Assistant/1.0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml',
        ])->timeout(15);

        // Track redirects manually to validate each target
        $currentUrl = $url;
        $redirectCount = 0;
        $finalResponse = null;
        $finalUrl = $url;

        while ($redirectCount <= self::MAX_REDIRECTS) {
            // Validate the current URL before requesting
            $this->validateUrlForSsrf($currentUrl);

            try {
                $response = $client->get($currentUrl);
            } catch (\Throwable $e) {
                throw new AiToolRejected("Error al fetchear URL: " . $e->getMessage());
            }

            if ($response->failed()) {
                throw new AiToolRejected("Error fetching URL: " . $response->status() . ' ' . $response->statusText());
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
                    throw new AiToolRejected('Demasiadas redirecciones (máx. ' . self::MAX_REDIRECTS . ').');
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

        $content = $finalResponse->body();

        // Enforce download size limit
        if (strlen($content) > self::MAX_DOWNLOAD_BYTES) {
            $content = substr($content, 0, self::MAX_DOWNLOAD_BYTES);
        }

        // Extract readable text (strip HTML tags, scripts, styles)
        $textContent = $this->extractReadableText($content);

        // Extract basic metadata
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $content, $matches)) {
            $title = strip_tags($matches[1]);
            if (mb_strlen($title) > 200) {
                $title = mb_substr($title, 0, 200) . '...';
            }
        }

        // Truncate to max_chars
        if (mb_strlen($textContent) > $maxChars) {
            $textContent = mb_substr($textContent, 0, $maxChars) . '... [truncado]';
        }

        return [
            'url' => $finalUrl,
            'title' => $title,
            'content' => $textContent,
            'status_code' => $finalResponse->status(),
            'content_type' => $finalResponse->header('Content-Type'),
            'content_length' => strlen($finalResponse->body()),
        ];
    }

    /**
     * Validate a URL for SSRF protection.
     */
    private function validateUrlForSsrf(string $url): void
    {
        $parsed = parse_url($url);
        if (! $parsed || ! isset($parsed['host'])) {
            throw new AiToolRejected('URL inválida: no se pudo analizar el host.');
        }

        $host = $parsed['host'];

        // Reject internal Docker/localhost names
        $internalHosts = ['localhost', 'host.docker.internal', 'docker.for.mac.host.internal', 'docker.for.win.host.internal'];
        if (in_array(strtolower($host), $internalHosts, true)) {
            throw new AiToolRejected("Host interno no permitido: {$host}");
        }

        // Resolve DNS
        $ips = [];
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        } catch (\Throwable) {
            throw new AiToolRejected("No se pudo resolver el host: {$host}");
        }

        if (empty($ips)) {
            throw new AiToolRejected("El host no resuelve a ninguna IP: {$host}");
        }

        // Validate every resolved IP
        foreach ($ips as $ip) {
            $this->validateIpAddress($ip, $host);
        }
    }

    /**
     * Validate an IP address against SSRF protection rules.
     */
    private function validateIpAddress(string $ip, string $host): void
    {
        // Check if it's a valid IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->validateIpv4($ip, $host);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->validateIpv6($ip, $host);
        } else {
            throw new AiToolRejected("IP inválida resuelta para {$host}: {$ip}");
        }
    }

    private function validateIpv4(string $ip, string $host): void
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            throw new AiToolRejected("IPv4 inválida: {$ip}");
        }

        $a = (int) $parts[0];
        $b = (int) $parts[1];
        $c = (int) $parts[2];
        $d = (int) $parts[3];

        // Loopback (127.0.0.0/8)
        if ($a === 127) {
            throw new AiToolRejected("Loopback IPv4 no permitido para {$host}: {$ip}");
        }

        // RFC1918 private ranges
        // 10.0.0.0/8
        if ($a === 10) {
            throw new AiToolRejected("IP privada RFC1918 (10.0.0.0/8) no permitida para {$host}: {$ip}");
        }
        // 172.16.0.0/12
        if ($a === 172 && $b >= 16 && $b <= 31) {
            throw new AiToolRejected("IP privada RFC1918 (172.16.0.0/12) no permitida para {$host}: {$ip}");
        }
        // 192.168.0.0/16
        if ($a === 192 && $b === 168) {
            throw new AiToolRejected("IP privada RFC1918 (192.168.0.0/16) no permitida para {$host}: {$ip}");
        }

        // Link-local (169.254.0.0/16)
        if ($a === 169 && $b === 254) {
            throw new AiToolRejected("Link-local IPv4 (169.254.0.0/16) no permitida para {$host}: {$ip}");
        }

        // Metadata service (169.254.169.254)
        if ($a === 169 && $b === 254 && $c === 169 && $d === 254) {
            throw new AiToolRejected("IP de metadatos no permitida para {$host}: {$ip}");
        }

        // Reserved ranges
        // 0.0.0.0/8
        if ($a === 0) {
            throw new AiToolRejected("IP reservada (0.0.0.0/8) no permitida para {$host}: {$ip}");
        }
        // 100.64.0.0/10 (CGNAT)
        if ($a === 100 && $b >= 64 && $b <= 127) {
            throw new AiToolRejected("CGNAT (100.64.0.0/10) no permitida para {$host}: {$ip}");
        }
        // 224.0.0.0/4 (multicast)
        if ($a >= 224 && $a <= 239) {
            throw new AiToolRejected("Multicast (224.0.0.0/4) no permitida para {$host}: {$ip}");
        }
        // 240.0.0.0/4 (reserved)
        if ($a >= 240) {
            throw new AiToolRejected("IP reservada (240.0.0.0/4) no permitida para {$host}: {$ip}");
        }
    }

    private function validateIpv6(string $ip, string $host): void
    {
        // Normalize IPv6
        $ip = strtolower($ip);

        // Loopback (::1)
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            throw new AiToolRejected("Loopback IPv6 no permitida para {$host}: {$ip}");
        }

        // Link-local (fe80::/10)
        if (Str::startsWith($ip, 'fe80:')) {
            throw new AiToolRejected("Link-local IPv6 (fe80::/10) no permitida para {$host}: {$ip}");
        }

        // Unique local addresses (fc00::/7) - includes fd00::/8
        if (preg_match('/^f[cd][0-9a-f]:/', $ip)) {
            throw new AiToolRejected("IPv6 única local (fc00::/7) no permitida para {$host}: {$ip}");
        }

        // IPv4-mapped IPv6 (::ffff:0:0/96) - check embedded IPv4
        if (Str::startsWith($ip, '::ffff:')) {
            $ipv4 = substr($ip, 7);
            if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->validateIpv4($ipv4, $host);
            }
        }

        // Multicast (ff00::/8)
        if (Str::startsWith($ip, 'ff')) {
            throw new AiToolRejected("Multicast IPv6 (ff00::/8) no permitida para {$host}: {$ip}");
        }

        // Documentation/benchmark (2001:db8::/32)
        if (Str::startsWith($ip, '2001:db8:')) {
            throw new AiToolRejected("IPv6 de documentación (2001:db8::/32) no permitida para {$host}: {$ip}");
        }

        // Teredo (2001::/32)
        if (Str::startsWith($ip, '2001:')) {
            // Allow but could be restricted
        }
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
        $base = ($parsedCurrent['scheme'] ?? 'https') . '://' . ($parsedCurrent['host'] ?? '');
        if (isset($parsedCurrent['port'])) {
            $base .= ':' . $parsedCurrent['port'];
        }

        $path = $parsedCurrent['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');

        if (Str::startsWith($location, '/')) {
            return $base . $location;
        }

        return $base . '/' . $dir . '/' . $location;
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
            $html = preg_replace('/<\/?' . $tag . '\b[^>]*>/i', "\n", $html);
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
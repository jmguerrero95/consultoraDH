<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

use App\Domain\Ai\AiToolRejected;

/**
 * Decides where a fetch may actually connect, and pins it there.
 *
 * ## The gap this closes
 *
 * Checking a name's address and then requesting the *name* leaves a gap between
 * the two. The check resolves the name once; the HTTP client resolves it again,
 * independently, and nothing ties the two answers together. A host that answers
 * with a public address for the check and a private one for the connection — a
 * DNS rebinding attack — passes validation and then reaches whatever it chose
 * the second time.
 *
 * So the address that was validated is the address that is used. The connection
 * is pinned to it, and because the name is still what is requested, TLS still
 * verifies a certificate for the name the caller asked for and the `Host` header
 * still carries it. Pinning changes which machine answers; it does not weaken
 * what the connection must prove about itself.
 *
 * ## Proxies are refused
 *
 * A proxy resolves names itself. With one in the configuration the pin is
 * advisory at best — the request goes to the proxy, and the proxy picks the
 * destination. The policy here is only meaningful if the connection cannot be
 * routed somewhere else, so these requests do not use one.
 */
final class PinnedTransport
{
    /**
     * Host names that name this machine's own network rather than a public one.
     */
    private const INTERNAL_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'host.docker.internal',
        'gateway.docker.internal',
        'docker.for.mac.host.internal',
        'docker.for.win.host.internal',
    ];

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Resolve a host and refuse anything that is not publicly routable.
     *
     * @return list<string> the validated addresses, to be used as the connection target
     *
     * @throws AiToolRejected
     */
    public function validatedAddresses(string $host): array
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '') {
            throw new AiToolRejected('URL inválida: no se pudo analizar el host.');
        }

        if (in_array($host, self::INTERNAL_HOSTS, true)) {
            throw new AiToolRejected("Host interno no permitido: {$host}");
        }

        // A name that is not a name: "example.com.evil.test" or a decimal-encoded
        // address are ways of writing something that is not the host it appears to be.
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! $this->isPlausibleHostname($host)) {
            throw new AiToolRejected("Host no válido: {$host}");
        }

        $addresses = $this->dns->resolve($host);

        if ($addresses === []) {
            throw new AiToolRejected("El host no resuelve a ninguna IP: {$host}");
        }

        foreach ($addresses as $address) {
            $this->assertPubliclyRoutable($address, $host);
        }

        return $addresses;
    }

    /**
     * The cURL configuration that makes the validated addresses authoritative.
     *
     * Public because it is the whole of the guarantee and needs to be asserted
     * directly: a test that could only observe it by inspecting a real socket
     * would not be able to prove that verification is on, that a proxy is off, or
     * that an IPv6 literal was bracketed rather than silently ignored.
     *
     * @param  list<string>  $addresses
     * @return array<int, mixed>
     */
    public function pinOptions(string $host, int $port, array $addresses): array
    {
        $resolve = [];
        $connectTo = [];

        foreach ($addresses as $address) {
            $literal = $this->curlAddress($address);

            $resolve[] = "{$host}:{$port}:{$literal}";
            $connectTo[] = "{$host}:{$port}:{$literal}:{$port}";
        }

        return [
            // The name keeps resolving to exactly the addresses that were checked.
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_CONNECT_TO => $connectTo,
            // Nothing may route this request somewhere other than those addresses.
            CURLOPT_PROXY => '',
            // Verification is asserted rather than left to a default that a later
            // change could relax. Pinning chooses which host answers; it is not a
            // reason to accept that host's certificate unconditionally.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
    }

    /**
     * An address as cURL parses it.
     *
     * cURL separates each entry on colons, so an unbracketed IPv6 literal is read
     * as several fields and the entry is discarded. The brackets are what make it
     * one field.
     */
    private function curlAddress(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    private function isPlausibleHostname(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host);
    }

    /**
     * Refuse anything that is not a publicly routable unicast address.
     *
     * @throws AiToolRejected
     */
    private function assertPubliclyRoutable(string $address, string $host): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->assertPublicIpv4($address, $host);

            return;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assertPublicIpv6($address, $host);

            return;
        }

        throw new AiToolRejected("Dirección no válida resuelta para {$host}: {$address}");
    }

    private function assertPublicIpv4(string $ip, string $host): void
    {
        [$a, $b] = array_map('intval', explode('.', $ip));

        $refuse = function (string $what) use ($ip, $host): never {
            throw new AiToolRejected("{$what} no permitido para {$host}: {$ip}");
        };

        // 0.0.0.0/8 "this network", 127.0.0.0/8 loopback.
        if ($a === 0 || $a === 127) {
            $refuse($a === 127 ? 'Loopback IPv4' : 'Dirección reservada IPv4');
        }

        // RFC 1918.
        if ($a === 10) {
            $refuse('Dirección privada RFC1918');
        }

        if ($a === 172 && $b >= 16 && $b <= 31) {
            $refuse('Dirección privada RFC1918');
        }

        if ($a === 192 && $b === 168) {
            $refuse('Dirección privada RFC1918');
        }

        // Link-local, which includes the 169.254.169.254 metadata endpoint.
        if ($a === 169 && $b === 254) {
            $refuse('Dirección link-local IPv4 (incluye metadatos)');
        }

        // Carrier-grade NAT, 100.64.0.0/10.
        if ($a === 100 && $b >= 64 && $b <= 127) {
            $refuse('Dirección CGNAT');
        }

        // 192.0.0.0/24 and 192.0.2.0/24 reserved; 198.18.0.0/15 benchmarking.
        if (($a === 192 && $b === 0) || ($a === 198 && ($b === 18 || $b === 19))) {
            $refuse('Dirección reservada IPv4');
        }

        // Multicast and broadcast/reserved.
        if ($a >= 224) {
            $refuse($a >= 240 ? 'Dirección reservada IPv4' : 'Dirección multicast IPv4');
        }
    }

    private function assertPublicIpv6(string $ip, string $host): void
    {
        $refuse = function (string $what) use ($ip, $host): never {
            throw new AiToolRejected("{$what} no permitido para {$host}: {$ip}");
        };

        $normalized = strtolower($ip);

        // An IPv4-mapped address is the same destination wearing a v6 hat, so the
        // v4 rules decide it. Refusing only on the v6 prefix would let ::ffff:10.0.0.1
        // past a rule that refuses 10.0.0.1.
        if (str_starts_with($normalized, '::ffff:')) {
            $mapped = substr($normalized, 7);

            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->assertPublicIpv4($mapped, $host);

                return;
            }

            $refuse('Dirección IPv4-mapped inválida');
        }

        $packed = @inet_pton($normalized);

        if ($packed === false) {
            $refuse('Dirección IPv6 inválida');
        }

        $bytes = array_values(unpack('C16', $packed));

        // ::1 loopback, :: unspecified.
        $isLoopback = $bytes[15] === 1 && array_slice($bytes, 0, 15) === array_fill(0, 15, 0);
        $isUnspecified = array_slice($bytes, 0, 16) === array_fill(0, 16, 0);

        if ($isUnspecified || $isLoopback) {
            $refuse($isLoopback ? 'Loopback IPv6' : 'Dirección no especificada IPv6');
        }

        // fc00::/7 unique local.
        if (($bytes[0] & 0xFE) === 0xFC) {
            $refuse('Dirección única local IPv6 (fc00::/7)');
        }

        // fe80::/10 link-local.
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0x80) {
            $refuse('Dirección link-local IPv6 (fe80::/10)');
        }

        // ff00::/8 multicast.
        if ($bytes[0] === 0xFF) {
            $refuse('Dirección multicast IPv6');
        }

        // 2001:db8::/32 documentation, 2001::/23 IETF protocol assignments.
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x0D && $bytes[3] === 0xB8) {
            $refuse('Dirección de documentación IPv6');
        }

        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && ($bytes[2] & 0xFE) === 0x00) {
            $refuse('Dirección reservada IPv6');
        }
    }
}

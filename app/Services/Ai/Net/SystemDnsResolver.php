<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

/**
 * The system resolver.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        // An address written into the URL needs no lookup: it is already the
        // destination, so it is reported as resolving to itself. Returning empty
        // here instead would mean a literal address could never be requested,
        // which is a limitation rather than a protection.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

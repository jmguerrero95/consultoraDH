<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

/**
 * Name resolution, as the SSRF guard consumes it.
 *
 * Extracted so the guard can be exercised against controlled answers. A test that
 * had to reach a real resolver to prove "a private address is refused" would
 * either need a real host that resolves to a private address — which does not
 * exist on demand — or would prove nothing at all.
 */
interface DnsResolver
{
    /**
     * Every address a name currently resolves to.
     *
     * Returning several is normal and is not itself suspicious; the guard checks
     * each of them, because a name that answers with one public and one private
     * address is exactly the shape a rebinding attempt takes.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}

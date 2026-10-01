<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Security\TrustedHostConfiguration;
use App\Support\Security\TrustedHostsNotConfigured;
use Closure;
use Illuminate\Http\Middleware\TrustHosts as FrameworkTrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests whose `Host` header is not a host Consultora DH serves.
 *
 * This is Laravel's own `TrustHosts` middleware with the enforcement moved into
 * `handle()` for one reason: the framework answers no request when its list is
 * empty, because an empty list means "nothing to check". Consultora DH treats an
 * empty list as "no host may be trusted" and refuses to serve, so that a
 * production deployment which forgot `TRUSTED_HOSTS` fails closed instead of
 * quietly accepting whatever `Host` it is sent.
 *
 * The host list itself is built by TrustedHostConfiguration, which decides
 * between development and production hosts and escapes every configured entry
 * into a literal, anchored pattern.
 */
final class TrustHosts extends FrameworkTrustHosts
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, $next): Response
    {
        $hosts = $this->hosts();

        if ($hosts === null) {
            // Never reaches Symfony with an empty list: that would accept every
            // host. The application stops instead.
            throw TrustedHostsNotConfigured::for(
                config('security.environment'),
                config('security.trusted_hosts_rejected', []),
            );
        }

        // Symfony wraps each entry in its own `{}i` delimiters and recognises
        // the `^escaped$` shape, serving it from a hash lookup.
        Request::setTrustedHosts($hosts);

        return $next($request);
    }

    /**
     * The accepted host patterns, or null when none may be trusted.
     *
     * @return list<string>|null
     */
    #[\Override]
    public function hosts(): ?array
    {
        $hosts = config('security.trusted_hosts');

        return is_array($hosts) && $hosts !== [] ? array_values($hosts) : null;
    }

    /**
     * Exposed for documentation and tests: are the development hosts in force?
     */
    public function trustsDevelopmentHosts(): bool
    {
        return (bool) config('security.uses_development_hosts')
            && TrustedHostConfiguration::usesDevelopmentHosts(config('security.environment'));
    }
}

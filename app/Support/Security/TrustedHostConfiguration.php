<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Builds the list of `Host` header values the application answers to.
 *
 * Two decisions are encoded here.
 *
 * 1. Which hosts exist depends on the environment. `localhost`, the loopback
 *    addresses and the Docker service names only exist while developing, so
 *    they are added for `local` and `testing` and for nothing else. Every other
 *    environment, `production` and `staging` included, trusts exactly what the
 *    operator configured, or nothing at all.
 *
 * 2. `TRUSTED_HOSTS` holds literal host names, not regular expressions. Each
 *    entry is escaped with `preg_quote()` and anchored, so a dot in
 *    `portal.consultoradh.com` matches a dot and nothing else.
 *
 *    This is not only a convention, it is what Symfony recognises: the
 *    framework detects patterns of the shape `^escaped$` and serves them from a
 *    hash lookup instead of the regular expression engine. A configuration that
 *    produces those patterns cannot reach the engine at all, which is why an
 *    environment value cannot inject expression syntax even if it tries. An
 *    entry that could be read as an expression (`.*`, `^`, `a|b`) is not a
 *    hostname, so it is rejected before it becomes a pattern.
 *
 * Wildcard subdomains are deliberately not supported here. If they are ever
 * needed they must arrive through a separate, explicit setting, never by
 * weakening this one.
 */
final class TrustedHostConfiguration
{
    /**
     * Environments for which the development hosts below are added.
     *
     * Anything outside this list, `production` and `staging` included, is
     * required to configure `TRUSTED_HOSTS`.
     */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * The hosts a local checkout needs: the loopback addresses the browser and
     * `php artisan serve` use, plus the two Docker service names the end to end
     * suite calls from inside the network.
     *
     * Ports are deliberately absent. Symfony strips `:port` from the header
     * before matching, so `localhost:8080` is compared as `localhost`.
     *
     * @var list<string>
     */
    public const DEVELOPMENT_HOSTS = [
        'localhost',
        '127.0.0.1',
        '[::1]',
        'nginx',
        'app',
    ];

    /**
     * Whether this environment gets the development hosts.
     */
    public static function usesDevelopmentHosts(?string $environment): bool
    {
        return in_array((string) $environment, self::DEVELOPMENT_ENVIRONMENTS, true);
    }

    /**
     * The patterns in force for an environment.
     *
     * @return list<string>
     */
    public static function patterns(?string $environment, ?string $configured): array
    {
        $configured = self::configuredPatterns($configured);

        if (! self::usesDevelopmentHosts($environment)) {
            return $configured;
        }

        $patterns = array_merge(self::developmentPatterns(), $configured);

        return array_values(array_unique($patterns));
    }

    /**
     * @return list<string>
     */
    public static function developmentPatterns(): array
    {
        return array_values(array_filter(array_map(
            self::pattern(...),
            self::DEVELOPMENT_HOSTS,
        )));
    }

    /**
     * The operator's entries, escaped and anchored.
     *
     * @return list<string>
     */
    public static function configuredPatterns(?string $configured): array
    {
        return array_values(array_filter(array_map(
            self::pattern(...),
            self::split($configured),
        )));
    }

    /**
     * Entries that were discarded because they are not host names.
     *
     * They are reported to the log rather than to the client: an operator has to
     * find out why their domain was ignored, but that is not information to hand
     * to whoever is probing the application.
     *
     * @return list<string>
     */
    public static function rejectedEntries(?string $configured): array
    {
        $rejected = [];

        foreach (self::split($configured) as $entry) {
            if (self::pattern($entry) === null) {
                $rejected[] = $entry;
            }
        }

        return $rejected;
    }

    /**
     * The exact pattern for one host name, or null when it is not one.
     */
    public static function pattern(?string $host): ?string
    {
        $host = mb_strtolower(trim((string) $host));

        // preg_quote is the escaping that matters: it makes every character in
        // the name literal. `:` and the brackets of an IPv6 literal are escaped
        // too, which is harmless, and Symfony strips the port before matching,
        // so a pattern can never be widened by one.
        return self::isHostName($host) ? '^'.preg_quote($host).'$' : null;
    }

    /**
     * @return list<string>
     */
    private static function split(?string $configured): array
    {
        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        $entries = array_map(trim(...), explode(',', $configured));

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * A DNS name, or an IPv6 literal in brackets.
     *
     * The character set is the point: a name is letters, digits, dots and
     * hyphens in non empty labels. Everything a regular expression would treat as
     * syntax is outside it.
     */
    private static function isHostName(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']')
                && strlen($host) > 2
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match(
            '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/',
            $host,
        ) === 1;
    }
}

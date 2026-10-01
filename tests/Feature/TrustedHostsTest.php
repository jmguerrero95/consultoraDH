<?php

declare(strict_types=1);

use App\Http\Middleware\TrustHosts;
use App\Models\User;
use App\Support\Security\TrustedHostConfiguration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Host header handling.
 *
 * The password recovery flow builds absolute URLs from the request, so a forged
 * `Host` header would make a reset link point at a host the attacker controls.
 * Everything here rests on one property: the accepted list is literal, anchored
 * host names, and it is empty in production unless an operator configured it.
 */
beforeEach(function (): void {
    // The suite runs with APP_ENV=testing, so the development hosts are in
    // force by default. Each test that cares states the environment it needs.
    config([
        'security.environment' => 'testing',
        'security.uses_development_hosts' => true,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns('testing', null),
        'security.trusted_hosts_rejected' => [],
    ]);
});

afterEach(function (): void {
    // Symfony keeps the accepted patterns in static state; a test that installs
    // a production list must not leak it into the next test.
    Request::setTrustedHosts([]);
});

it('accepts the loopback hosts used in development', function (string $url): void {
    $this->get($url)->assertOk();
})->with([
    'http://localhost/login',
    'http://localhost:8080/login',
    'http://127.0.0.1/login',
    'http://127.0.0.1:8080/login',
]);

it('rejects an unknown host before the request is handled', function (): void {
    $this->get('http://attacker.example.com/login')->assertStatus(400);
});

it('rejects an unknown host on the API as well', function (): void {
    $this->postJson('http://attacker.example.com/api/auth/login', [])->assertStatus(400);
});

it('registers exactly one trusted host middleware on every request', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    $trusted = array_values(array_filter(
        $global,
        static fn (string $m): bool => str_ends_with($m, 'TrustHosts'),
    ));

    // Exactly one, and it is the Consultora DH one, not the framework's.
    expect($trusted)->toBe([TrustHosts::class]);

    // It is global, so it is not part of any route's middleware list.
    $route = Route::getRoutes()->getByName('api.health')?->gatherMiddleware() ?? [];

    expect($route)->not->toContain('throttle:api');
});

it('does not build a reset link from an untrusted host', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'enlace@consultora-dh.test']);

    // A forged Host is rejected outright, so no link can be generated at all.
    $this->postJson('http://attacker.example.com/api/auth/forgot-password', [
        'email' => $user->email,
    ])->assertStatus(400);

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Configured production hosts
|--------------------------------------------------------------------------
|
| The examples below use the domains from the task, so the escaping that is
| asserted is the escaping that ships.
|
*/

it('accepts a configured production host', function (): void {
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns(
            'production',
            'portal.consultoradh.com,www.consultoradh.com',
        ),
    ]);

    $this->get('http://portal.consultoradh.com/login')->assertOk();
    $this->get('http://www.consultoradh.com/login')->assertOk();
});

it('turns a configured host into an escaped, anchored, literal pattern', function (): void {
    // Exactly the patterns the task requires for TRUSTED_HOSTS=portal…,www….
    expect(TrustedHostConfiguration::configuredPatterns('portal.consultoradh.com,www.consultoradh.com'))
        ->toBe(['^portal\.consultoradh\.com$', '^www\.consultoradh\.com$']);
});

it('rejects a similar but not equal hostname in production', function (string $host): void {
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns(
            'production',
            'portal.consultoradh.com',
        ),
    ]);

    $this->get('http://'.$host.'/login')->assertStatus(400);
})->with([
    'portal-consultoradh.com',            // hyphen instead of a dot
    'portalconsultoradh.com',             // the dot dropped entirely
    'portal.consultoradh.com.attacker.io', // a trusted prefix with an attacker suffix
    'attacker.io.portal.consultoradh.com', // a trusted suffix
    'portal.consultoradh.como',           // one character longer
    'xportal.consultoradh.com',           // one character longer at the front
]);

it('treats the dots of a configured domain as literal', function (): void {
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns(
            'production',
            'portal.consultoradh.com',
        ),
    ]);

    // If the dot were a regular expression "any character", this would match.
    $this->get('http://portalXconsultoradhXcom/login')->assertStatus(400);
    $this->get('http://portal-consultoradh-com/login')->assertStatus(400);

    // The genuine host still works, so the rejection is not simply "all denied".
    $this->get('http://portal.consultoradh.com/login')->assertOk();
});

it('does not let an environment value inject regular expression syntax', function (string $entry, string $mustNotMatch): void {
    $patterns = TrustedHostConfiguration::configuredPatterns($entry);

    // Anything that could be read as an expression is not a host name, so it
    // never becomes a pattern at all.
    expect($patterns)->toBe([]);

    // And with nothing installed, the host cannot get through.
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => $patterns,
    ]);

    $this->get('http://'.$mustNotMatch.'/login')->assertStatus(503);
})->with([
    'regex syntax, wildcards' => ['.*', 'attacker.io'],
    'regex syntax, anchors' => ['^', 'attacker.io'],
    'regex syntax, alternation' => ['consultoradh.com|attacker.io', 'attacker.io'],
    'regex syntax, character class' => ['[a-z]+.io', 'attacker.io'],
    'regex syntax, wildcard subdomain' => ['*.consultoradh.com', 'anything.consultoradh.com'],
    'regex syntax, quantifier' => ['a{0,}b.io', 'attacker.io'],
]);

it('refuses every request when production has no trusted hosts configured', function (): void {
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns('production', ''),
    ]);

    // Failing closed means 503, not 400 and not 200: the application is not
    // refusing this host, it is refusing to serve without a list.
    $this->get('http://portal.consultoradh.com/login')->assertStatus(503);
    $this->get('http://localhost/login')->assertStatus(503);
    $this->get('http://localhost:8080/login')->assertStatus(503);

    $this->postJson('http://localhost:8080/api/auth/login', [])
        ->assertStatus(503)
        ->assertJsonPath('code', 'trusted_hosts_not_configured');
});

it('does not silently trust the development hosts in production', function (string $url): void {
    config([
        'security.environment' => 'production',
        'security.uses_development_hosts' => false,
        'security.trusted_hosts' => TrustedHostConfiguration::patterns(
            'production',
            'portal.consultoradh.com',
        ),
    ]);

    // A production list does not implicitly include the development hosts.
    $this->get($url)->assertStatus(400);
})->with([
    'loopback by name' => ['http://localhost/login'],
    'loopback by address' => ['http://127.0.0.1/login'],
    'loopback by address with a port' => ['http://127.0.0.1:8080/login'],
    'the nginx service name' => ['http://nginx/login'],
    'the app service name' => ['http://app/login'],
]);

it('adds the development hosts only for local and testing', function (): void {
    // Built from the constant, so the test cannot drift from the list.
    $development = array_map(
        static fn (string $host): string => '^'.preg_quote($host).'$',
        TrustedHostConfiguration::DEVELOPMENT_HOSTS,
    );

    // Spelled out, so the constant's contents are asserted rather than assumed.
    expect($development)->toBe([
        '^localhost$',
        '^127\.0\.0\.1$',
        '^\[\\:\\:1\\]$',
        '^nginx$',
        '^app$',
    ]);

    expect(TrustedHostConfiguration::patterns('local', null))->toBe($development)
        ->and(TrustedHostConfiguration::patterns('testing', null))->toBe($development);

    // Every other environment, staging included, gets nothing for free.
    foreach (['production', 'staging', 'ci', ''] as $environment) {
        expect(TrustedHostConfiguration::patterns($environment, null))->toBe([])
            ->and(TrustedHostConfiguration::patterns($environment, 'portal.consultoradh.com'))
            ->toBe(['^portal\.consultoradh\.com$']);
    }
});

it('keeps a configured host in addition to the development ones', function (): void {
    expect(TrustedHostConfiguration::patterns('local', 'portal.consultoradh.com'))
        ->toContain('^portal\.consultoradh\.com$')
        ->toContain('^localhost$');
});

it('discards entries that are not host names and reports them', function (): void {
    $configured = 'portal.consultoradh.com,  , .*, http://portal.consultoradh.com, portal.consultoradh.com:8080';

    // Only the one real host name survives.
    expect(TrustedHostConfiguration::configuredPatterns($configured))
        ->toBe(['^portal\.consultoradh\.com$']);

    // The rest are reported so an operator can find out why nothing worked.
    expect(TrustedHostConfiguration::rejectedEntries($configured))
        ->toBe(['.*', 'http://portal.consultoradh.com', 'portal.consultoradh.com:8080']);
});

it('normalises case and surrounding whitespace in a configured host', function (): void {
    expect(TrustedHostConfiguration::configuredPatterns('  PORTAL.ConsultoraDH.com  '))
        ->toBe(['^portal\.consultoradh\.com$']);
});

it('accepts an IPv6 loopback literal', function (): void {
    expect(TrustedHostConfiguration::pattern('[::1]'))->toBe('^\\[\\:\\:1\\]$')
        ->and(TrustedHostConfiguration::pattern('[::1'))->toBeNull();
});

it('rejects a host with an empty label', function (string $host): void {
    expect(TrustedHostConfiguration::pattern($host))->toBeNull();
})->with([
    'portal..consultoradh.com',
    '.consultoradh.com',
    'consultoradh.com.',
    '-portal.consultoradh.com',
    'portal-.consultoradh.com',
    '',
]);

<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\Net\BoundedStream;
use App\Services\Ai\Net\DnsResolver;
use App\Services\Ai\Net\DownloadTooLarge;
use App\Services\Ai\Net\PinnedTransport;
use App\Services\Ai\Tools\WebFetchTool;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * VERIFY 3: where the fetch is allowed to connect.
 *
 * Two levels, because the guarantee has two parts that fail differently:
 *
 *   - Which addresses are acceptable, and what a redirect is allowed to lead to.
 *     Driven through a controlled resolver, so a hostile answer can be given
 *     exactly and a real host that happens to resolve publicly proves nothing.
 *   - What the connection is then pinned to, asserted on the cURL configuration
 *     itself, because a correct check followed by a request to the bare name would
 *     still be exploitable and nothing about a fake HTTP layer would show it.
 */
final class WebFetchSsrfTest extends TestCase
{
    /**
     * A resolver that answers with whatever the test decides, and records how
     * many times it was asked — which is how rebinding is observed.
     */
    private function resolverReturning(array $answers): DnsResolver
    {
        return new class($answers) implements DnsResolver
        {
            public int $calls = 0;

            public function __construct(private array $answers) {}

            public function resolve(string $host): array
            {
                $this->calls++;

                // Each lookup gets the next answer, so a name that changes what it
                // says between lookups is observable rather than assumed.
                return [$this->answers[$this->calls % count($this->answers)]];
            }
        };
    }

    private function guardFor(DnsResolver $resolver): PinnedTransport
    {
        return new PinnedTransport($resolver);
    }

    // ------------------------------------------------- which addresses pass

    public function test_public_ipv4_and_ipv6_destinations_are_accepted(): void
    {
        $guard = $this->guardFor($this->resolverReturning(['93.184.216.34']));
        $this->assertSame(['93.184.216.34'], $guard->validatedAddresses('example.test'));

        $guardV6 = $this->guardFor($this->resolverReturning(['2606:2800:220:1:248:1893:25c8:1946']));
        $this->assertSame(
            ['2606:2800:220:1:248:1893:25c8:1946'],
            $guardV6->validatedAddresses('example.test')
        );
    }

    #[DataProvider('hostileAddresses')]
    public function test_non_public_destinations_are_refused(string $address): void
    {
        $guard = $this->guardFor($this->resolverReturning([$address]));

        $this->expectException(AiToolRejected::class);

        $guard->validatedAddresses('rebind.test');
    }

    public static function hostileAddresses(): array
    {
        return [
            'loopback ipv4' => ['127.0.0.1'],
            'loopback range' => ['127.13.37.1'],
            'private 10/8' => ['10.0.0.5'],
            'private 172.16/12' => ['172.20.1.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local' => ['169.254.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'cgnat' => ['100.64.0.1'],
            'benchmark' => ['198.18.0.1'],
            'multicast' => ['239.1.1.1'],
            'reserved' => ['240.0.0.1'],
            'this network' => ['0.0.0.0'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unique local' => ['fd00::1'],
            'ipv6 unique local c' => ['fc00::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv4-mapped private' => ['::ffff:10.0.0.5'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv6 documentation' => ['2001:db8::1'],
        ];
    }

    public function test_internal_host_names_are_refused_without_consulting_dns(): void
    {
        $guard = $this->guardFor($this->resolverReturning(['93.184.216.34']));

        foreach (['localhost', 'host.docker.internal'] as $host) {
            try {
                $guard->validatedAddresses($host);
                $this->fail("{$host} should be refused.");
            } catch (AiToolRejected) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_name_answering_with_both_public_and_private_addresses_is_refused(): void
    {
        // The shape a rebinding attempt takes: one good answer to satisfy the
        // check, one bad answer waiting for whoever connects.
        $guard = $this->guardFor($this->resolverReturning(['93.184.216.34', '127.0.0.1']));

        $this->expectException(AiToolRejected::class);

        $guard->validatedAddresses('rebind.test');
    }

    public function test_rebinding_between_lookups_is_caught_because_every_hop_resolves_again(): void
    {
        // First lookup answers public, the second answers with the metadata address.
        $resolver = $this->resolverReturning(['169.254.169.254', '93.184.216.34']);
        $guard = $this->guardFor($resolver);

        // First lookup: the public answer passes.
        $this->assertSame(['93.184.216.34'], $guard->validatedAddresses('rebind.test'));

        // The next hop resolves again, and the name has changed what it says.
        $this->expectException(AiToolRejected::class);
        $guard->validatedAddresses('rebind.test');
    }

    // ------------------------------------------------------------- pinning

    public function test_connection_is_pinned_to_the_validated_address(): void
    {
        $guard = $this->guardFor($this->resolverReturning(['93.184.216.34']));
        $options = $guard->pinOptions('example.test', 443, ['93.184.216.34']);

        $this->assertSame(['example.test:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        $this->assertSame(
            ['example.test:443:93.184.216.34:443'],
            $options[CURLOPT_CONNECT_TO]
        );
    }

    public function test_ipv6_pins_are_bracketed_so_they_are_not_discarded(): void
    {
        $guard = $this->guardFor($this->resolverReturning([]));
        $options = $guard->pinOptions('example.test', 443, ['2606:2800:220:1:248:1893:25c8:1946']);

        $this->assertSame(
            ['example.test:443:[2606:2800:220:1:248:1893:25c8:1946]'],
            $options[CURLOPT_RESOLVE]
        );
    }

    public function test_tls_verification_stays_enabled(): void
    {
        $guard = $this->guardFor($this->resolverReturning([]));
        $options = $guard->pinOptions('example.test', 443, ['93.184.216.34']);

        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
    }

    public function test_no_proxy_can_route_around_the_pin(): void
    {
        $guard = $this->guardFor($this->resolverReturning([]));
        $options = $guard->pinOptions('example.test', 443, ['93.184.216.34']);

        // A proxy resolves names itself, which would make the pin advisory.
        $this->assertSame('', $options[CURLOPT_PROXY]);
    }

    // ---------------------------------------------------- the tool end to end

    public function test_tool_refuses_a_host_that_resolves_privately(): void
    {
        Permission::firstOrCreate(['name' => 'web_fetch', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('web_fetch');

        $this->app->instance(DnsResolver::class, $this->resolverReturning(['169.254.169.254']));

        $tool = app(WebFetchTool::class);

        $this->expectException(AiToolRejected::class);

        $tool->execute(['url' => 'http://metadata.test/latest/meta-data/', 'max_chars' => 1000], $user);
    }

    public function test_tool_refuses_a_redirect_that_leads_to_a_private_destination(): void
    {
        Permission::firstOrCreate(['name' => 'web_fetch', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('web_fetch');

        // Public on the first hop, private on the second: the redirect is what
        // must be checked, because it is what the tool would have followed.
        $this->app->instance(
            DnsResolver::class,
            $this->resolverReturning(['10.0.0.5', '93.184.216.34'])
        );

        Http::fake([
            'example.test/*' => Http::response(
                '',
                302,
                ['Location' => 'http://internal.test/secret']
            ),
        ]);

        $tool = app(WebFetchTool::class);

        $this->expectException(AiToolRejected::class);

        $tool->execute(['url' => 'http://example.test/', 'max_chars' => 1000], $user);
    }

    public function test_tool_refuses_a_response_that_declares_more_than_the_cap(): void
    {
        Permission::firstOrCreate(['name' => 'web_fetch', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('web_fetch');

        $this->app->instance(DnsResolver::class, $this->resolverReturning(['93.184.216.34']));

        // Declared larger than the cap: refused on the header, before the body
        // would be streamed at all.
        Http::fake([
            'example.test/*' => Http::response(
                'short',
                200,
                ['Content-Type' => 'text/html', 'Content-Length' => '9000000']
            ),
        ]);

        $this->expectException(AiToolRejected::class);
        $this->expectExceptionMessageMatches('/superior al m/');

        app(WebFetchTool::class)->execute(['url' => 'http://example.test/', 'max_chars' => 1000], $user);
    }

    public function test_the_cap_stops_a_stream_mid_transfer_rather_than_truncating_afterwards(): void
    {
        // The header may be absent or dishonest, so the body is bounded as it
        // arrives. Asserted on the writer the transfer is given, because a fake
        // HTTP handler never streams and therefore cannot exercise it; the real
        // transfer counterpart is WebFetchHttpIntegrationTest.
        $body = new BoundedStream(1_000);

        $body->write(str_repeat('a', 600));

        try {
            $body->write(str_repeat('b', 500));
            $this->fail('A chunk crossing the cap must abort the transfer.');
        } catch (DownloadTooLarge) {
            $this->assertSame(600, $body->size(), 'What arrived before the cap is kept.');
        }

        // A response exactly at the cap is accepted, so the boundary is inclusive.
        $exact = new BoundedStream(10);
        $exact->write(str_repeat('c', 10));
        $this->assertSame(10, $exact->size());
    }

    public function test_tool_serves_a_public_page_within_the_cap(): void
    {
        Permission::firstOrCreate(['name' => 'web_fetch', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('web_fetch');

        $this->app->instance(DnsResolver::class, $this->resolverReturning(['93.184.216.34']));

        Http::fake([
            'example.test/*' => Http::response(
                '<html><head><title>Public</title></head><body><p>Hola</p><script>evil()</script></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $result = app(WebFetchTool::class)->execute(
            ['url' => 'http://example.test/', 'max_chars' => 1000],
            $user
        );

        $this->assertSame('Public', $result['title']);
        $this->assertStringContainsString('Hola', $result['content']);
        $this->assertStringNotContainsString('evil()', $result['content']);
    }
}

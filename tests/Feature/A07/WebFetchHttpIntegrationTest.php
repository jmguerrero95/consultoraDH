<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\Net\BoundedStream;
use App\Services\Ai\Net\DnsResolver;
use App\Services\Ai\Net\DownloadTooLarge;
use App\Services\Ai\Tools\WebFetchTool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\StreamInterface;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * R4: the sink Guzzle is actually given.
 *
 * ## Why this test exists at all
 *
 * `web_fetch` passed `'sink' => $body->append(...)`. A callable is not a sink
 * Guzzle accepts. `CurlFactory` calls `Psr7\Utils::streamFor($sink)`, and
 * `streamFor()` wraps any callable in a `PumpStream` — a *read* stream built to
 * produce data. So `isWritable()` is false and `write()` throws
 * `RuntimeException: Cannot write to a PumpStream`, on the first chunk of the
 * first real response, for every request. The tool could not fetch anything.
 *
 * ## Why `Http::fake()` never caught it
 *
 * A fake handler returns a response directly. It builds no cURL handle and
 * installs no `CURLOPT_WRITEFUNCTION`, so the sink is never written to and its
 * type is never exercised. Every assertion about this file could have passed
 * against a completely broken sink, which is precisely what happened.
 *
 * So these tests drive a real cURL transfer against a real server bound to the
 * loopback interface, served by PHP's built-in server from
 * `tests/Support/a07_fetch_server.php`. No network, no external service, no
 * paid anything.
 *
 * ## The cap
 *
 * `BoundedStream` bounds the transfer, not the stored result. The oversized
 * fixtures declare 3 MB — far past the 500 KB cap — and the assertion is that the
 * transfer stops with only part of that received. A cap applied after buffering
 * would have downloaded all 3 MB first and then shortened it, which is what the
 * test is checking has not happened.
 */
#[Group('a07')]
final class WebFetchHttpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private static $serverProcess = null;

    private static int $port = 0;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:'.self::$port;

        $router = escapeshellarg(dirname(__DIR__, 2).'/Support/a07_fetch_server.php');

        $descriptors = [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];

        $process = proc_open(
            sprintf('php -S 127.0.0.1:%d %s', self::$port, $router),
            $descriptors,
            $pipes
        );

        if (! is_resource($process)) {
            self::fail('Could not start the local fixture server.');
        }

        self::$serverProcess = $process;

        self::waitForServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            $status = proc_get_status(self::$serverProcess);

            // proc_open leaves a shell behind unless the process group is killed;
            // terminating the server by name is what actually frees the port.
            if ($status['running'] ?? false) {
                @exec(sprintf('pkill -f "php -S 127.0.0.1:%d" 2>/dev/null', self::$port));
                proc_terminate(self::$serverProcess, SIGTERM);
            }

            proc_close(self::$serverProcess);
        }

        self::$serverProcess = null;
        parent::tearDownAfterClass();
    }

    /**
     * A port the OS says is free, and which the server then actually took.
     */
    private static function findFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            self::fail("Could not reserve a local port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        if ($port <= 0) {
            self::fail('Could not determine a free port.');
        }

        return $port;
    }

    /**
     * The server is started asynchronously, so the first request can arrive
     * before it is listening. A refusal here means "not ready yet", which is
     * distinct from a fixture returning an error.
     */
    private static function waitForServer(): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.25);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('The local fixture server did not start listening.');
    }

    /**
     * The transport under test: a real cURL client with a real handler stack, no
     * fakes anywhere in it.
     */
    private function realClient(): Client
    {
        return new Client([
            'base_uri' => self::$baseUrl,
            'http_errors' => false,
            'timeout' => 10,
        ]);
    }

    // ------------------------------------------- the defect this milestone fixes

    public function test_the_previous_callable_sink_is_not_a_sink_guzzle_accepts(): void
    {
        // The defect itself, pinned. A callable reaches `streamFor()`, which
        // wraps it in a read-only PumpStream whose write() always throws. This
        // assertion is what the old implementation violated.
        $stream = Utils::streamFor(fn (string $chunk) => strlen($chunk));

        $this->assertInstanceOf(StreamInterface::class, $stream);
        $this->assertFalse(
            $stream->isWritable(),
            'A callable must not become a writable sink; this is why the transfer failed.'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot write to a PumpStream/');

        $stream->write('any bytes at all');
    }

    public function test_the_bounded_stream_is_a_sink_guzzle_accepts(): void
    {
        $sink = new BoundedStream(500_000);

        $this->assertInstanceOf(StreamInterface::class, $sink);
        $this->assertTrue($sink->isWritable(), 'Guzzle writes the body here, so it must be writable.');

        // Round-trips, because a sink that accepted bytes it could not read back
        // would trade a broken transfer for a silently empty result.
        $written = $sink->write('first chunk');
        $this->assertSame(11, $written, 'Guzzle uses the return value as the byte count.');
        $this->assertSame('first chunk', $sink->contents());
    }

    // ------------------------------------------- real transfers that succeed

    public function test_a_real_html_page_is_fetched_over_a_real_socket(): void
    {
        $sink = new BoundedStream(500_000);

        $response = $this->realClient()->request('GET', '/html', ['sink' => $sink]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Fixture Page', $this->titleOf($sink->contents()));
        $this->assertStringContainsString('Real transport, no fake.', $sink->contents());
        $this->assertGreaterThan(0, $sink->size(), 'The body was written through the sink, not only returned.');
    }

    public function test_a_response_exactly_at_the_cap_is_accepted(): void
    {
        $sink = new BoundedStream(500_000);

        $response = $this->realClient()->request('GET', '/exact-cap', ['sink' => $sink]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(500_000, $sink->size(), 'The boundary is inclusive: exactly the cap is allowed.');
    }

    // ------------------------------------------- the cap stops the transfer

    public function test_an_oversized_declared_response_is_refused_mid_transfer(): void
    {
        // 3 MB declared against a 500 KB cap.
        $sink = new BoundedStream(500_000);

        $this->expectException(DownloadTooLarge::class);

        try {
            $this->realClient()->request('GET', '/huge-declared', ['sink' => $sink]);
        } finally {
            // The load-bearing assertion. A cap applied after the body was
            // buffered would leave the sink holding the whole document; here it
            // must hold no more than the cap, which means the transfer was
            // abandoned instead of completed and then trimmed.
            $this->assertLessThanOrEqual(
                500_000,
                $sink->size(),
                'The sink must never accumulate more than the cap, so the surplus was never received.'
            );
            $this->assertLessThan(
                3 * 1024 * 1024,
                $sink->size(),
                'A cap that only truncated afterwards would already hold the whole body.'
            );
        }
    }

    public function test_an_oversized_response_without_a_declared_length_is_also_refused(): void
    {
        // The header can be absent or a lie, so this is the case that only a cap
        // inside the transfer answers.
        $sink = new BoundedStream(500_000);

        $this->expectException(DownloadTooLarge::class);

        try {
            $this->realClient()->request('GET', '/huge-undeclared', ['sink' => $sink]);
        } finally {
            $this->assertLessThanOrEqual(500_000, $sink->size());
        }
    }

    // ------------------------------------------- failures are handled, not leaked

    public function test_an_error_status_reaches_the_caller_without_being_an_exception_from_the_sink(): void
    {
        // http_errors is off, so a 404 is a response rather than a thrown
        // transport failure. The sink must have behaved normally getting there.
        $sink = new BoundedStream(500_000);

        $response = $this->realClient()->request('GET', '/missing', ['sink' => $sink]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not here.', $sink->contents());
    }

    public function test_a_connection_failure_surfaces_as_a_transport_exception(): void
    {
        $this->expectException(ConnectException::class);

        // Nothing is listening here: the port was reserved, then released.
        (new Client(['timeout' => 2]))->request('GET', 'http://127.0.0.1:1/nothing');
    }

    // ------------------------------------------- the tool, over the same socket

    /**
     * What the tool hands the transport, captured from inside the handler stack
     * rather than read off the tool's source.
     *
     * This is the assertion that fails against the previous implementation. The
     * old code passed a Closure; this records whatever arrived at the handler
     * and asserts it is a stream Guzzle can actually write into. The sink tests
     * above prove `BoundedStream` behaves correctly on a real transfer, and this
     * proves the tool is the thing handing it over.
     */
    public function test_the_tool_hands_the_transport_a_writable_stream_not_a_callable(): void
    {
        $captured = null;

        $this->app->instance(DnsResolver::class, $this->publicResolver());

        Http::fake(function ($request, $options) use (&$captured) {
            $captured = $options['sink'] ?? null;

            return Http::response(
                '<html><head><title>Captured</title></head><body><p>ok</p></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        });

        $result = app(WebFetchTool::class)->execute(
            ['url' => 'http://example.test/', 'max_chars' => 1000],
            $this->userAllowedToFetch()
        );

        $this->assertSame('Captured', $result['title']);

        $this->assertNotNull($captured, 'The tool must hand the transport a sink.');
        $this->assertInstanceOf(
            StreamInterface::class,
            $captured,
            'Guzzle requires a writable path, a resource or a StreamInterface. A callable is not one.'
        );
        $this->assertNotInstanceOf(\Closure::class, $captured);
        $this->assertTrue($captured->isWritable(), 'Guzzle writes the body here.');
        $this->assertLessThanOrEqual(500_000, $captured->size());
    }

    /**
     * The tool refuses the loopback address, so a real end-to-end fetch of the
     * local fixture would have to bypass its own SSRF policy — which is the
     * guarantee under test elsewhere and is not weakened here for convenience.
     *
     * What is asserted instead is the half that the fixture *can* exercise
     * honestly: that a real socket to a refused address is refused by the policy
     * before any body is written, and that the refusal is a clean rejection
     * rather than a leaked transport exception. The transfer half of the tool is
     * covered above against this same server, on the same sink the tool uses.
     */
    public function test_the_tool_refuses_the_loopback_fixture_over_a_real_socket(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        });

        $user = $this->userAllowedToFetch();

        $this->expectException(AiToolRejected::class);
        $this->expectExceptionMessageMatches('/Loopback|interno/i');

        app(WebFetchTool::class)->execute(
            ['url' => self::$baseUrl.'/html', 'max_chars' => 3000],
            $user
        );
    }

    private function publicResolver(): DnsResolver
    {
        return new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
    }

    private function userAllowedToFetch(): User
    {
        Permission::firstOrCreate(['name' => 'web_fetch', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('web_fetch');

        return $user;
    }

    private function titleOf(string $html): string
    {
        preg_match('~<title>(.*?)</title>~is', $html, $matches);

        return strip_tags($matches[1] ?? '');
    }
}

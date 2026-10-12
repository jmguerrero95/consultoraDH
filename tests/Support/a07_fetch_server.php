<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Local fixture server for the web fetch integration test
|--------------------------------------------------------------------------
|
| Served by PHP's built-in server on the loopback interface, and reached over a
| real socket by a real cURL transfer.
|
| It exists because `Http::fake()` cannot see the defect this milestone closes:
| a fake handler never builds a cURL handle and never installs a write callback,
| so a sink Guzzle would reject in production passes every faked test. The only
| way to observe the sink contract is to let the transport actually consume it.
|
| Routes are deliberately small and explicit. Nothing here reaches the network:
| the server binds to 127.0.0.1 and every response is generated inline.
|
*/

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/** A response the tool should accept. */
if ($path === '/html') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><head><title>Fixture Page</title></head>'
        .'<body><h1>Local fixture</h1><p>Real transport, no fake.</p>'
        .'<script>shouldBeStripped()</script></body></html>';

    return true;
}

/** The same content as plain text. */
if ($path === '/text') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Local fixture as plain text.';

    return true;
}

/** A content type the tool must refuse. */
if ($path === '/binary') {
    header('Content-Type: application/octet-stream');
    echo str_repeat("\x00", 64);

    return true;
}

/**
 * More than the tool's cap, declared up front.
 *
 * On a real transport the sink aborts before this whole body is consumed, which
 * is the point: the cap bounds the transfer, not just the stored result.
 */
if ($path === '/huge-declared') {
    $total = 3 * 1024 * 1024;
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: '.$total);

    $sent = 0;
    while ($sent < $total) {
        $chunk = str_repeat('a', 16 * 1024);
        echo $chunk;
        $sent += strlen($chunk);
        flush();

        if (connection_aborted()) {
            break;
        }
    }

    return true;
}

/**
 * More than the cap with no declared length at all.
 *
 * The header can be absent or dishonest, so this is the case where only a cap
 * inside the transfer protects anything.
 */
if ($path === '/huge-undeclared') {
    $total = 3 * 1024 * 1024;
    header('Content-Type: text/html; charset=utf-8');

    $sent = 0;
    while ($sent < $total) {
        $chunk = str_repeat('b', 16 * 1024);
        echo $chunk;
        $sent += strlen($chunk);
        flush();

        if (connection_aborted()) {
            break;
        }
    }

    return true;
}

/** Exactly the cap, so the boundary can be shown to be inclusive. */
if ($path === '/exact-cap') {
    $total = 500_000;
    header('Content-Type: text/html; charset=utf-8');

    $sent = 0;
    while ($sent < $total) {
        $chunk = str_repeat('c', min(8192, $total - $sent));
        echo $chunk;
        $sent += strlen($chunk);
        flush();

        if (connection_aborted()) {
            break;
        }
    }

    return true;
}

/** A redirect that points at the loopback interface. */
if ($path === '/redirect-loopback') {
    header('Location: http://127.0.0.1:'.$_SERVER['SERVER_PORT'].'/html', true, 302);

    return true;
}

/** An error status, for the failure path. */
if ($path === '/missing') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body>Not here.</body></html>';

    return true;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<html><body>Unknown fixture route.</body></html>';

return true;

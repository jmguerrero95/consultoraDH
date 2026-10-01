#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Consultora DH - application container health check
#
# PHP-FPM speaks the FastCGI binary protocol and exposes no HTTP endpoint, so
# this script sends a real FastCGI request for the pool's configured
# `ping.path`. The pool answers it directly, without invoking PHP or touching
# the database, which makes the check cheap enough to poll.
# ---------------------------------------------------------------------------
set -euo pipefail

php -r '
const FPM_HOST = "127.0.0.1";
const FPM_PORT = 9000;
const PING_PATH = "/fpm-ping";

/** Encode one FastCGI name/value pair. */
function encodePair(string $name, string $value): string
{
    $nameLength = strlen($name);
    $valueLength = strlen($value);

    if ($nameLength > 127 || $valueLength > 127) {
        // Only short values are ever sent, so the long form is unnecessary.
        throw new RuntimeException("unsupported long parameter");
    }

    return chr($nameLength).chr($valueLength).$name.$value;
}

/**
 * Build a FastCGI record: 8 byte header (version, type, id, length,
 * padding, reserved) followed by the content.
 */
function record(int $type, int $id, string $content): string
{
    $length = strlen($content);

    return chr(1).chr($type).pack("n", $id).pack("n", $length).chr(0).chr(0).$content;
}

$socket = @stream_socket_client(
    "tcp://".FPM_HOST.":".FPM_PORT,
    $errno,
    $errstr,
    3,
);

if ($socket === false) {
    fwrite(STDERR, "php-fpm is not accepting connections: {$errstr}\n");
    exit(1);
}

stream_set_timeout($socket, 3);

$params = "";
foreach ([
    "SCRIPT_FILENAME" => PING_PATH,
    "SCRIPT_NAME" => PING_PATH,
    "REQUEST_URI" => PING_PATH,
    "REQUEST_METHOD" => "GET",
    "QUERY_STRING" => "",
    "SERVER_PROTOCOL" => "HTTP/1.1",
] as $name => $value) {
    $params .= encodePair((string) $name, $value);
}

// BEGIN_REQUEST body: role (2 bytes, big endian) + flags (1) + reserved (5).
// Role 1 is FPM_RESPONDER.
$request = record(1, 1, pack("n", 1).chr(0).str_repeat(chr(0), 5))
    .record(4, 1, $params)                      // PARAMS
    .record(4, 1, "")                           // PARAMS, empty -> end of list
    .record(5, 1, "");                          // STDIN, empty -> no body

fwrite($socket, $request);

$body = "";
$finished = false;

while (! $finished) {
    $header = fread($socket, 8);

    if (strlen($header) !== 8) {
        break;
    }

    $type = ord($header[1]);
    $length = (ord($header[4]) << 8) | ord($header[5]);
    $content = $length > 0 ? fread($socket, $length) : "";

    // 3 = END_REQUEST, 6 = STDOUT
    if ($type === 3) {
        $finished = true;
    } elseif ($type === 6) {
        $body .= $content;
    }
}

fclose($socket);

if (! str_contains($body, "pong")) {
    fwrite(STDERR, "php-fpm did not answer the ping\n");
    exit(1);
}

exit(0);
'

<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

use RuntimeException;

/**
 * The response exceeded the download cap while it was still arriving.
 *
 * Thrown from inside the transfer sink rather than after the response has been
 * buffered, so the bytes past the limit are never read off the socket. A cap
 * applied after the fact bounds the answer but not the transfer, which is the
 * difference between refusing a large document and accepting whatever a
 * remote host chooses to send.
 */
final class DownloadTooLarge extends RuntimeException {}

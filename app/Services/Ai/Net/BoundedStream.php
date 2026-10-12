<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

use GuzzleHttp\Psr7;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A writable PSR-7 stream that refuses to accumulate more than a cap.
 *
 * ## Why a stream and not a callable
 *
 * Guzzle's `sink` request option accepts a writable path, a PHP resource, or a
 * PSR-7 {@see StreamInterface}. It does not accept a callable.
 *
 * A callable is not merely rejected, it is silently misread. `CurlFactory` does
 * `Psr7\Utils::streamFor($sink)`, and `streamFor()` wraps any callable in a
 * {@see Psr7\PumpStream}. A PumpStream is a *read* stream — it produces data
 * from the callable — so `isWritable()` is false and `write()` unconditionally
 * throws `RuntimeException: Cannot write to a PumpStream`. Because Guzzle
 * installs `$sink->write()` as `CURLOPT_WRITEFUNCTION`, that throw happens on
 * the first chunk of the first real response: every fetch fails.
 *
 * The failure is invisible to a faked HTTP client, because a fake never builds
 * a cURL handle and never installs a write callback. It only appears against a
 * real transport, which is why this class is exercised over a real local server
 * rather than through `Http::fake()`.
 *
 * ## Why the cap belongs here
 *
 * Truncating a finished body bounds the answer but not the transfer: the bytes
 * have already been read off the socket, so the cap protects nothing a remote
 * host controls. Enforcing it inside `write()` means the chunk that would pass
 * the limit is refused while it is still being handed to us, and the rest of
 * the document is never requested from the peer.
 */
final class BoundedStream implements StreamInterface
{
    /** @var resource|null */
    private $handle;

    private bool $detached = false;

    public function __construct(private readonly int $limit)
    {
        if ($limit < 0) {
            throw new RuntimeException('The body limit cannot be negative.');
        }

        // php://temp rather than a plain string: it gives correct seek/tell/eof
        // semantics for free, and it spills to disk past its memory threshold.
        // The cap is far below that threshold, so a conforming transfer never
        // reaches the disk.
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the download.');
        }

        $this->handle = $handle;
    }

    /**
     * The cap, exposed so a caller can refuse an oversized declared length
     * before spending the transfer on it.
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Whether the sender declared a length that is already too large.
     *
     * Refusing on the declared length stops a response that would blow the cap
     * before spending the transfer at all, which `write()` cannot do: the header
     * arrives first and the body would otherwise be streamed in full.
     */
    public function declaredLengthExceeds(?int $declared): bool
    {
        return $declared !== null && $declared > $this->limit;
    }

    /**
     * Everything written so far, whatever the read position.
     *
     * The position is deliberately left alone: the tool reads the finished body
     * after the transfer has advanced the cursor to the end, so rewinding here
     * would be guessing at what the caller wants.
     */
    public function contents(): string
    {
        if ($this->handle === null) {
            return '';
        }

        $current = ftell($this->handle);
        rewind($this->handle);
        $contents = stream_get_contents($this->handle);

        if ($current !== false) {
            fseek($this->handle, $current);
        }

        return $contents === false ? '' : $contents;
    }

    public function size(): int
    {
        $size = $this->getSize();

        return $size ?? 0;
    }

    public function __toString(): string
    {
        try {
            return $this->contents();
        } catch (\Throwable) {
            // __toString must not propagate; a failed read yields an empty body.
            return '';
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;
        $this->detached = true;

        return $handle;
    }

    public function getSize(): ?int
    {
        if ($this->handle === null) {
            return null;
        }

        $stats = fstat($this->handle);

        return $stats === false ? null : $stats['size'];
    }

    public function tell(): int
    {
        if ($this->handle === null) {
            throw new RuntimeException('Stream is detached.');
        }

        $position = ftell($this->handle);

        if ($position === false) {
            throw new RuntimeException('Could not determine the stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        if (fseek($this->handle, $offset, $whence) !== 0) {
            throw new RuntimeException('Could not seek within the stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Accept one chunk of the transfer.
     *
     * Guzzle installs this as `CURLOPT_WRITEFUNCTION` and uses the return value
     * as the number of bytes cURL may consider written, so it must be accurate.
     * The chunk that would pass the limit is refused instead of written, which
     * aborts the transfer with the remaining bytes never requested.
     *
     * @throws DownloadTooLarge when this chunk would pass the limit
     */
    public function write(string $string): int
    {
        $this->assertAttached();

        $size = $this->size();

        if ($size + strlen($string) > $this->limit) {
            throw new DownloadTooLarge;
        }

        $written = fwrite($this->handle, $string);

        if ($written === false) {
            throw new RuntimeException('Could not write to the download stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function read(int $length): string
    {
        $this->assertAttached();

        if ($length < 0) {
            throw new RuntimeException('Cannot read a negative number of bytes.');
        }

        if ($length === 0) {
            return '';
        }

        $chunk = fread($this->handle, $length);

        return $chunk === false ? '' : $chunk;
    }

    public function getContents(): string
    {
        $this->assertAttached();

        $contents = stream_get_contents($this->handle);

        return $contents === false ? '' : $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->handle === null) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->handle);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * Whether this stream has been detached or closed, after which Guzzle can no
     * longer write to it.
     */
    public function isDetached(): bool
    {
        return $this->detached || $this->handle === null;
    }

    private function assertAttached(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}

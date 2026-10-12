<?php

declare(strict_types=1);

namespace App\Services\Ai\Net;

/**
 * Accumulates a response body, and refuses to accumulate more than a cap.
 *
 * ## Why this exists rather than truncating a finished body
 *
 * Truncating after the fact bounds the answer but not the transfer: the bytes
 * have already been read off the socket and into memory, so the cap protects
 * nothing that a remote host controls. This is written into the transfer's own
 * sink, so the moment the limit would be passed the read stops — the remaining
 * bytes are never received.
 */
final class BoundedBody
{
    private string $buffer = '';

    public function __construct(private readonly int $limit) {}

    /**
     * Accept one chunk of the body.
     *
     * Called by the HTTP transfer for each chunk as it arrives, so exceeding the
     * cap aborts here rather than after the whole response has been buffered.
     *
     * @throws DownloadTooLarge when this chunk would pass the limit
     */
    public function append(string $chunk): void
    {
        if (strlen($this->buffer) + strlen($chunk) > $this->limit) {
            throw new DownloadTooLarge;
        }

        $this->buffer .= $chunk;
    }

    /**
     * Whether the sender declared a length that is already too large.
     *
     * Refusing on the declared length stops a response that would blow the cap
     * before spending the transfer at all, which the sink cannot do: the header
     * arrives first and the body would otherwise be streamed in full.
     */
    public function declaredLengthExceeds(?int $declared): bool
    {
        return $declared !== null && $declared > $this->limit;
    }

    public function contents(): string
    {
        return $this->buffer;
    }

    public function size(): int
    {
        return strlen($this->buffer);
    }
}

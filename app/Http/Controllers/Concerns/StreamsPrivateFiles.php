<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a private file without materialising it in PHP memory.
 *
 * `file_get_contents()` inside `streamDownload` looks like streaming but is
 * not: the whole file is read into a string before the first byte reaches the
 * client. With several concurrent downloads that is one copy per request.
 *
 * These responses are always private and non-cacheable. Ownership and
 * visibility checks stay in the controller; this trait only handles bytes.
 */
trait StreamsPrivateFiles
{
    /**
     * @param  array<string, string>  $headers
     */
    protected function streamPrivateFile(
        string $path,
        string $name,
        array $headers = [],
        bool $deleteAfterSend = false,
    ): StreamedResponse {
        if (! is_file($path) || ! is_readable($path)) {
            abort(404);
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            abort(404);
        }

        return $this->streamPrivateResource(
            $stream,
            $name,
            $headers,
            $deleteAfterSend ? $path : null,
        );
    }

    /**
     * @param  resource  $stream
     * @param  array<string, string>  $headers
     */
    protected function streamPrivateResource(
        mixed $stream,
        string $name,
        array $headers = [],
        ?string $deleteAfterSendPath = null,
    ): StreamedResponse {
        $headers = array_merge([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], $headers);

        return response()->streamDownload(function () use ($stream, $deleteAfterSendPath): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($deleteAfterSendPath !== null) {
                    @unlink($deleteAfterSendPath);
                }
            }
        }, $name, $headers);
    }
}

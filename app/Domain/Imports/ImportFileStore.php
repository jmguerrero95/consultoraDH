<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\LegacyImport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Every read and write of an import's private copy, on one disk, in one place.
 *
 * ## What was wrong
 *
 * The audit found the disk disagreeing with itself across three call sites:
 *
 * ```php
 * // upload — the method-injected default disk
 * public function store(Request $request, Filesystem $storage) { $storage->put(…); }
 *
 * // parse — the configured disk
 * public function absolutePath(): ?string { Storage::disk(config('imports.disk'))->path(…); }
 *
 * // cancel — the default disk again
 * Storage::delete($import->stored_path);
 * ```
 *
 * With `FILESYSTEM_DISK` and `IMPORT_DISK` both set to `local` this happened to work, which is
 * why it shipped. Set `IMPORT_DISK=s3` and the upload went to S3 while the parse looked on
 * local — so every import failed with "the private copy is no longer on the server" for a file
 * that had just arrived. And `cancel()` deleted from the default disk with a path built from a
 * different disk's root, so cancellation silently left the file behind.
 *
 * The mismatch is a configuration trap rather than a visible bug, which is the worst kind:
 * nothing fails until someone sets the variable the config file documents.
 *
 * ## Why this class exists rather than a helper on the model
 *
 * `LegacyImport::absolutePath()` was the right idea in the wrong place. Path construction is
 * the model's (`storedRelativePath()` — it is the row's own address, and the UUID rule is
 * §5.1's), but *disk selection* is a policy that has to be applied identically by the endpoint,
 * the job and the cancellation path. Putting it here makes the three agree by construction.
 *
 * ## Nothing here ever produces a public URL
 *
 * §4.3 and the disk's `serve => false`. There is deliberately no `url()` method.
 */
final class ImportFileStore
{
    public function __construct(private readonly ?string $diskName = null) {}

    /** Resolve the disk from configuration every time, so a test may change it. */
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName ?? (string) config('imports.disk', 'local'));
    }

    /** The name, for a log line or an audit record that must not carry a path. */
    public function diskName(): string
    {
        return $this->diskName ?? (string) config('imports.disk', 'local');
    }

    /**
     * Write the uploaded bytes to the import's address.
     *
     * `putFileAs`-style streaming is not used because the source is already a temporary file
     * on the same machine; what matters is that the destination is built by the model and the
     * disk by this class, and that a partial write is not left behind on failure.
     *
     * @throws \RuntimeException when the bytes cannot be stored
     */
    public function store(LegacyImport $import, string $contents): void
    {
        $relative = $import->storedRelativePath();

        if (! $this->disk()->put($relative, $contents)) {
            throw new \RuntimeException('No se pudo guardar la copia privada del archivo.');
        }
    }

    /**
     * The absolute path for the parser, or NULL when the copy is gone.
     *
     * NULL rather than a fabricated path: a caller handed a path that does not exist fails
     * somewhere deeper with a worse message, and `ParseLegacyImport` already treats NULL as
     * "the source is missing".
     */
    public function absolutePath(LegacyImport $import): ?string
    {
        $relative = $import->storedRelativePath();

        return $this->disk()->exists($relative) ? $this->disk()->path($relative) : null;
    }

    /**
     * Remove the private copy. Used by cancel and by a re-upload that supersedes one.
     *
     * Idempotent: deleting an absent file is a success, because both callers are expressing
     * "this copy must not remain" rather than "a file was deleted".
     */
    public function delete(LegacyImport $import): void
    {
        $this->disk()->delete($import->storedRelativePath());
    }

    /** The stored size in bytes, for a sanity check after a write. */
    public function size(LegacyImport $import): int
    {
        $relative = $import->storedRelativePath();

        return $this->disk()->exists($relative) ? (int) $this->disk()->size($relative) : 0;
    }
}

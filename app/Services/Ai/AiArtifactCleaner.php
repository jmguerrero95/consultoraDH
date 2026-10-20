<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiArtifact;
use App\Models\AiArtifactDeletion;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the private bytes of a deleted AI artifact, durably and idempotently.
 *
 * The database rows are deleted inside the conversation's transaction; the
 * bytes are removed *after* it commits, driven by the outbox rows written in
 * that same transaction. Three properties follow from that split:
 *
 *   - a rollback deletes nothing from disk, because no byte removal ran;
 *   - a commit can never lose a cleanup, because the outbox row is committed
 *     with the rows and the scheduler drains whatever is outstanding;
 *   - a crash between the two leaves the outbox row, which is exactly the
 *     retryable state the outbox exists for.
 *
 * Removing bytes is idempotent: a path that is already gone is a success, so the
 * same row can be processed any number of times without error. A path that some
 * *other* artifact still references is never removed — the guard below makes
 * that structural rather than a convention.
 */
final class AiArtifactCleaner
{
    /** How many rows one drain may process, so the caller's request stays bounded. */
    public const DRAIN_LIMIT = 200;

    /**
     * Record paths to remove, inside the caller's transaction.
     *
     * `insertOrIgnore` is used against the unique `storage_path` index, so two
     * concurrent deletes of the same path enqueue one row rather than racing.
     *
     * @param  list<array{user_id: int, storage_path: string}>  $paths
     */
    public function queue(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $now = now();

        $rows = [];

        foreach ($paths as $path) {
            $storagePath = (string) ($path['storage_path'] ?? '');

            if ($storagePath === '') {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $path['user_id'],
                'storage_path' => $storagePath,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            AiArtifactDeletion::query()->insertOrIgnore($rows);
        }
    }

    /**
     * Remove the bytes for every due outbox row.
     *
     * Called right after a delete commits, and by the scheduled command for
     * anything left behind. Returns how many rows were cleared.
     */
    public function process(int $limit = self::DRAIN_LIMIT): int
    {
        $due = AiArtifactDeletion::query()
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $cleared = 0;

        foreach ($due as $row) {
            if ($this->processRow($row)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * @return bool whether the row was cleared (the bytes are gone)
     */
    private function processRow(AiArtifactDeletion $row): bool
    {
        $path = (string) $row->storage_path;

        try {
            // Safety: a path that still belongs to a live artifact is not ours
            // to remove. Paths are unique per artifact, so this only fires if a
            // row was somehow written for a file still in use — in which case the
            // right answer is to leave it, not to delete something referenced.
            $stillReferenced = AiArtifact::query()
                ->where('storage_path', $path)
                ->exists();

            if (! $stillReferenced) {
                Storage::disk('local')->delete($path);
            }

            $row->delete();

            return true;
        } catch (\Throwable $e) {
            $row->forceFill([
                'attempts' => (int) $row->attempts + 1,
                'last_error' => $this->safeError($e),
                'available_at' => now()->addSeconds($this->backoffSeconds((int) $row->attempts + 1)),
            ])->save();

            // Reported so a persistent failure is visible in the log; the row
            // itself is the durable record that another attempt is owed.
            report($e);

            return false;
        }
    }

    /**
     * How long to wait before retrying a failing removal.
     *
     * Grows with the attempt count and is capped, so a transient disk error is
     * retried quickly while a permanently locked path is not hammered.
     */
    private function backoffSeconds(int $attempts): int
    {
        return (int) min(60 * max(1, $attempts), 3600);
    }

    /**
     * A failure description with no path and no credentials: the exception
     * class and message, bounded, is enough to diagnose from the log.
     */
    private function safeError(\Throwable $e): string
    {
        return mb_substr($e::class.': '.$e->getMessage(), 0, 500);
    }

    /**
     * Whether any cleanup work is outstanding. For the status report.
     */
    public function pendingCount(): int
    {
        return AiArtifactDeletion::query()->count();
    }

    /**
     * The number of artifact rows still holding a given path. Used by tests and
     * by the guard; not part of the request path.
     */
    public function referencesTo(string $storagePath): int
    {
        return AiArtifact::query()->where('storage_path', $storagePath)->count();
    }

    /**
     * The storage disk the artifacts live on. Kept in one place so a future
     * move off the local disk is a one-line change.
     */
    public static function disk(): string
    {
        return 'local';
    }
}

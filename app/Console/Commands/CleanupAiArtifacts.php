<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiArtifactCleaner;
use Illuminate\Console\Command;

/**
 * Drain the AI artifact deletion outbox.
 *
 * A conversation delete commits its file-removal intents and then tries to
 * satisfy them immediately; this command is the safety net that finishes any
 * that were left behind by a crash, a restart or a transient disk error. It is
 * idempotent and bounded, so running it on a healthy system does nothing and
 * running it twice never removes the wrong file.
 */
final class CleanupAiArtifacts extends Command
{
    protected $signature = 'ai:cleanup-artifacts';

    protected $description = 'Remove the bytes owed by the AI artifact deletion outbox.';

    public function handle(AiArtifactCleaner $cleaner): int
    {
        $cleared = $cleaner->process();

        $this->info("{$cleared} artifact file(s) removed.".($cleaner->pendingCount() > 0 ? ' Outstanding work remains.' : ''));

        return self::SUCCESS;
    }
}

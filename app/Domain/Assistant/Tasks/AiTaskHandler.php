<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tasks;

use App\Models\AiTask;

/**
 * One kind of work the assistant can be asked to do in the background.
 *
 * ## Why handlers rather than a switch
 *
 * Each kind has its own inputs, its own stages, its own failure messages and its
 * own idea of what "done" means. A `switch` in one growing method is how three
 * of them end up sharing a progress percentage that means three different
 * things.
 *
 * A handler is told what to do and keeps its own bookkeeping; the worker in
 * between owns retries, idempotency and the status transitions, so no handler
 * can get those wrong independently.
 *
 * @see IngestDocumentTask
 */
interface AiTaskHandler
{
    /**
     * The `AiTask::KIND_*` this handler serves.
     */
    public function kind(): string;

    /**
     * Run the work.
     *
     * @return array<string, mixed> stored as the task's `result`
     *
     * @throws \Throwable to fail the task; the worker records the reason
     */
    public function handle(AiTask $task): array;
}

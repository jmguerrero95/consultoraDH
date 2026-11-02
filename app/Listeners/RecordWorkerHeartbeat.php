<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Ops\WorkerHeartbeat;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\Looping;
use Throwable;

/**
 * Records what a real worker process is doing.
 *
 * The group comes from `QUEUE_WORKER_GROUP`, set explicitly by each compose
 * service. Nothing here inspects the operating-system command line, so a
 * renamed container or a different supervisor cannot silently change which
 * group a heartbeat belongs to.
 *
 * The listener is registered for every queue process. In a web request the
 * group is empty, so the recorder does nothing and cannot impersonate a worker.
 */
final class RecordWorkerHeartbeat
{
    public function __construct(private readonly WorkerHeartbeat $heartbeat) {}

    public function looping(Looping $event): void
    {
        $this->heartbeat->recordIdle();
    }

    public function processing(JobProcessing $event): void
    {
        try {
            $this->heartbeat->recordBusy($event->job->resolveName());
        } catch (Throwable) {
            // The job must run even if its name cannot be read.
        }
    }

    public function processed(JobProcessed $event): void
    {
        $this->heartbeat->recordIdle(true);
    }

    public function failed(JobFailed $event): void
    {
        $this->heartbeat->recordIdle(true);
    }

    public function released(JobReleased $event): void
    {
        $this->heartbeat->recordIdle(true);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->heartbeat->recordIdle(true);
    }
}

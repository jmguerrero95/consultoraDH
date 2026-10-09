<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Support\Events\SupportSlaEventEmitted;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\SupportSlaEvent;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanSupportSla implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function handle(): void
    {
        $now = now();

        // Scan first_response breaches
        $this->scanMetric('first_response', $now);

        // Scan next_response breaches
        $this->scanMetric('next_response', $now);

        // Scan resolution breaches
        $this->scanMetric('resolution', $now);

        // Scan warnings
        $this->scanWarnings($now);
    }

    private function scanMetric(string $metric, Carbon $now): void
    {
        $field = match ($metric) {
            'first_response' => 'first_response_due_at',
            'next_response' => 'next_staff_response_due_at',
            'resolution' => 'resolution_due_at',
        };

        $conversations = SupportConversation::query()
            ->where($field, '<=', $now)
            ->where('status', '!=', 'closed')
            ->where('status', '!=', 'resolved')
            ->with(['queue', 'assignee', 'client.user'])
            ->chunkById(100, function ($conversations) use ($metric, $now) {
                foreach ($conversations as $conversation) {
                    $this->processBreach($conversation, $metric, $now);
                }
            });
    }

    private function scanWarnings(Carbon $now): void
    {
        $queues = SupportQueue::where('active', true)
            ->whereNotNull('warning_minutes_before')
            ->get();

        foreach ($queues as $queue) {
            $warningThreshold = $now->copy()->addMinutes($queue->warning_minutes_before);

            // First response warnings
            if ($queue->first_response_minutes) {
                $metric = 'first_response';
                SupportConversation::query()
                    ->where('queue_id', $queue->id)
                    ->where('status', '!=', 'closed')
                    ->where('status', '!=', 'resolved')
                    ->where('first_response_due_at', '>', $now)
                    ->where('first_response_due_at', '<=', $warningThreshold)
                    ->whereDoesntHave('slaEvents', function ($q) use ($metric) {
                        $q->where('metric', $metric)
                            ->where('level', 'warning');
                    })
                    ->chunkById(100, function ($conversations) {
                        foreach ($conversations as $conversation) {
                            $this->emitSlaEvent($conversation, 'first_response', 'warning');
                        }
                    });
            }

            // Next response warnings
            if ($queue->next_response_minutes) {
                $metric = 'next_response';
                SupportConversation::query()
                    ->where('queue_id', $queue->id)
                    ->where('status', '!=', 'closed')
                    ->where('status', '!=', 'resolved')
                    ->where('next_staff_response_due_at', '>', $now)
                    ->where('next_staff_response_due_at', '<=', $warningThreshold)
                    ->whereDoesntHave('slaEvents', function ($q) use ($metric) {
                        $q->where('metric', $metric)
                            ->where('level', 'warning');
                    })
                    ->chunkById(100, function ($conversations) {
                        foreach ($conversations as $conversation) {
                            $this->emitSlaEvent($conversation, 'next_response', 'warning');
                        }
                    });
            }

            // Resolution warnings
            if ($queue->resolution_minutes) {
                $metric = 'resolution';
                SupportConversation::query()
                    ->where('queue_id', $queue->id)
                    ->where('status', '!=', 'closed')
                    ->where('status', '!=', 'resolved')
                    ->where('resolution_due_at', '>', $now)
                    ->where('resolution_due_at', '<=', $warningThreshold)
                    ->whereDoesntHave('slaEvents', function ($q) use ($metric) {
                        $q->where('metric', $metric)
                            ->where('level', 'warning');
                    })
                    ->chunkById(100, function ($conversations) {
                        foreach ($conversations as $conversation) {
                            $this->emitSlaEvent($conversation, 'resolution', 'warning');
                        }
                    });
            }
        }
    }

    private function processBreach(SupportConversation $conversation, string $metric, Carbon $now): void
    {
        // Check if breach already recorded
        $existing = SupportSlaEvent::where('conversation_id', $conversation->id)
            ->where('metric', $metric)
            ->where('level', 'breach')
            ->exists();

        if (! $existing) {
            $this->emitSlaEvent($conversation, $metric, 'breach');
        }
    }

    private function emitSlaEvent(SupportConversation $conversation, string $metric, string $level): void
    {
        try {
            $slaEvent = SupportSlaEvent::create([
                'conversation_id' => $conversation->id,
                'metric' => $metric,
                'due_at' => $conversation->{$this->getDueField($metric)},
                'level' => $level,
                'emitted_at' => now(),
            ]);

            // Dispatch event for escalation/notification
            event(new SupportSlaEventEmitted($slaEvent));

        } catch (QueryException $e) {
            // Handle unique constraint violation (duplicate)
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    private function getDueField(string $metric): string
    {
        return match ($metric) {
            'first_response' => 'first_response_due_at',
            'next_response' => 'next_staff_response_due_at',
            'resolution' => 'resolution_due_at',
        };
    }
}

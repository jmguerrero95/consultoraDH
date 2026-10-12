<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One unit of long-running assistant work.
 *
 * ## The row is the whole contract with the browser
 *
 * A tab can be closed, a phone locked, a connection dropped. This row is what
 * the interface re-reads on reopen, and it is deliberately shaped so that
 * reading it needs no other table: status, progress, stage and the reason for a
 * failure are all here, because the moment somebody needs them is when they
 * cannot go and ask.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $ai_conversation_id
 * @property string $kind
 * @property string $status
 * @property int $progress
 * @property string|null $stage
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $attempts
 * @property string $dedupe_key
 */
class AiTask extends Model
{
    public const KIND_DOCUMENT_INGEST = 'document_ingest';

    public const KIND_DOCUMENT_GENERATE = 'document_generate';

    public const KIND_RESEARCH = 'research';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'ai_tasks';

    protected $fillable = [
        'user_id',
        'ai_conversation_id',
        'kind',
        'status',
        'progress',
        'stage',
        'input',
        'result',
        'error',
        'queued_at',
        'started_at',
        'finished_at',
        'attempts',
        'dedupe_key',
    ];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'progress' => 'integer',
        'attempts' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * The document this task produced, when it produced one.
     */
    public function attachment(): HasOne
    {
        return $this->hasOne(AiAttachment::class, 'ai_task_id');
    }

    /**
     * Whether a worker may still pick this up.
     *
     * The check is on the row and not on the queue, so a job that was somehow
     * dispatched twice - a visibility timeout on Redis, a worker restarted
     * mid-flight - re-reads the task and finds it finished and stops, instead of
     * doing the work twice.
     */
    public function isClaimable(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Whether it failed in a way a person could act on.
     */
    public function isRecoverable(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}

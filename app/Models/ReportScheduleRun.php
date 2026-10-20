<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due occurrence of a report schedule, and what happened to it.
 *
 * `occurrence_key` is the schedule's due timestamp. The unique index on
 * (schedule, occurrence) is the claim: two scheduler ticks cannot both own the
 * same occurrence, and a failed one stays recoverable instead of disappearing
 * behind an advanced `next_run_at`.
 */
class ReportScheduleRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'report_schedule_id',
        'occurrence_key',
        'status',
        'attempts',
        'error_code',
        'generated_report_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function generatedReport(): BelongsTo
    {
        return $this->belongsTo(GeneratedReport::class, 'generated_report_id');
    }
}

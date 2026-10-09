<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Reports\ReportCadence;
use App\Domain\Reports\ReportFormat;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'report_type',
        'filters',
        'format',
        'cadence',
        'run_time',
        'day_of_week',
        'day_of_month',
        'owner_user_id',
        'active',
        'next_run_at',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'format' => ReportFormat::class,
            'cadence' => ReportCadence::class,
            'run_time' => 'datetime:H:i',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}

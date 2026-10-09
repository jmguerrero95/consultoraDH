<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use HasFactory;

    protected $table = 'automation_runs';

    protected $fillable = [
        'automation_rule_id',
        'occurrence_key',
        'status',
        'trigger_snapshot',
        'started_at',
        'finished_at',
        'error_code',
    ];

    protected $casts = [
        'trigger_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function actionRuns(): HasMany
    {
        return $this->hasMany(AutomationActionRun::class, 'automation_run_id');
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['succeeded', 'partial', 'failed', 'blocked']);
    }
}

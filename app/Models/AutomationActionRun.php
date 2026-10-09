<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationActionRun extends Model
{
    use HasFactory;

    protected $table = 'automation_action_runs';

    protected $fillable = [
        'automation_run_id',
        'automation_action_id',
        'status',
        'result_snapshot',
        'error_code',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'result_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AutomationAction::class, 'automation_action_id');
    }
}

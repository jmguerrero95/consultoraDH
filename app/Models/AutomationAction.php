<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationAction extends Model
{
    use HasFactory;

    protected $table = 'automation_actions';

    protected $fillable = [
        'automation_rule_id',
        'position',
        'action_type',
        'config',
        'active',
    ];

    protected $casts = [
        'position' => 'integer',
        'config' => 'array',
        'active' => 'boolean',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function actionRuns(): HasMany
    {
        return $this->hasMany(AutomationActionRun::class, 'automation_action_id');
    }
}

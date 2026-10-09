<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    use HasFactory;

    protected $table = 'automation_rules';

    protected $fillable = [
        'name',
        'description',
        'active',
        'trigger_type',
        'trigger_config',
        'condition_config',
        'owner_user_id',
        'revision',
        'activated_at',
        'next_run_at',
        'last_run_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'trigger_config' => 'array',
        'condition_config' => 'array',
        'revision' => 'integer',
        'activated_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class, 'automation_rule_id')->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'automation_rule_id');
    }

    public function cursors(): HasMany
    {
        return $this->hasMany(AutomationRuleCursor::class, 'automation_rule_id');
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
        $this->activated_at = now();
        $this->save();
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->save();
    }
}

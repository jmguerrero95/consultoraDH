<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRuleCursor extends Model
{
    use HasFactory;

    protected $table = 'automation_rule_cursors';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'automation_rule_id',
        'source',
        'last_source_id',
    ];

    protected $casts = [
        'last_source_id' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}

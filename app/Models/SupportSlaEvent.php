<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSlaEvent extends Model
{
    use HasFactory;

    protected $table = 'support_sla_events';

    /**
     * An SLA event is an immutable record of a measurement taken at a moment
     * in time; `emitted_at` already carries that moment, so the table carries
     * no bookkeeping timestamps.
     */
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'metric',
        'due_at',
        'level',
        'emitted_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'emitted_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }
}

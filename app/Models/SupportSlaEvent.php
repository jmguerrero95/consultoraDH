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

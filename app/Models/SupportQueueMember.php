<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportQueueMember extends Model
{
    use HasFactory;

    protected $table = 'support_queue_members';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'queue_id',
        'user_id',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function queue(): BelongsTo
    {
        return $this->belongsTo(SupportQueue::class, 'queue_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

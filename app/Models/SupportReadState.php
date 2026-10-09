<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportReadState extends Model
{
    use HasFactory;

    protected $table = 'support_read_states';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_message_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'last_read_message_id');
    }
}

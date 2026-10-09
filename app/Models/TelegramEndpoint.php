<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramEndpoint extends Model
{
    use HasFactory;

    protected $table = 'telegram_endpoints';

    protected $fillable = [
        'label',
        'chat_id',
        'enabled',
        'event_preferences',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'event_preferences' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportReplyToken extends Model
{
    use HasFactory;

    protected $table = 'support_reply_tokens';

    protected $fillable = [
        'conversation_id',
        'participant_kind',
        'user_id',
        'client_id',
        'token_hash',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at->isFuture();
    }

    /**
     * Generate a new raw token and store its hash
     */
    public static function generateToken(): array
    {
        $rawToken = Str::random(64); // 64 chars = 512 bits of entropy
        $tokenHash = hash('sha256', $rawToken);

        return [
            'raw_token' => $rawToken,
            'token_hash' => $tokenHash,
        ];
    }

    /**
     * Verify a raw token against the stored hash
     */
    public function verifyRawToken(string $rawToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $rawToken));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at->isFuture();
    }
}
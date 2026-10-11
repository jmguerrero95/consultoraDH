<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MCP (Model Context Protocol) authentication token.
 *
 * Raw token is returned only once when issued.
 * Only the secure hash (SHA-256) is stored.
 * Authentication: Bearer raw_token -> hash -> match stored hash.
 */
class McpToken extends Model
{
    protected $table = 'mcp_tokens';

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new raw token and its hash.
     *
     * @return array{raw: string, hash: string}
     */
    public static function generate(): array
    {
        $raw = bin2hex(random_bytes(32)); // 64 hex chars
        $hash = hash('sha256', $raw);

        return ['raw' => $raw, 'hash' => $hash];
    }

    /**
     * Verify a raw token against stored hash.
     */
    public function verifyToken(string $rawToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $rawToken));
    }

    /**
     * Check if token is valid (exists, not expired, not revoked, user active).
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if (! $this->user->isActive()) {
            return false;
        }

        return true;
    }

    /**
     * Record usage.
     */
    public function recordUsage(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Revoke the token.
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }
}
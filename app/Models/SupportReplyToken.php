<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A capability that lets somebody reply to a conversation by email without an
 * account.
 *
 * ## Why only the hash is stored
 *
 * The raw token is a bearer credential: whoever holds it may post into the
 * conversation. Storing it would mean a database disclosure — a backup, a
 * replica, a read-only console — silently conferred that authority, and the
 * `token_hash` column exists precisely so that it does not. Only the SHA-256 of
 * the token is persisted.
 *
 * ## The consequence, stated plainly
 *
 * The raw token is therefore **not recoverable** after issuance. Nothing can
 * read it back: not a later request, not a retry, not an operator. It exists
 * only in the memory of the request that created it.
 *
 * So a token is issued exactly once, for exactly one purpose — building the
 * reply-to address of the message being sent now — and if a second reply-to
 * address is needed, a *new* token is issued and the old one revoked. Reusing
 * an existing row is not possible, by construction, and the code that tried
 * (`$token->raw_token` on a row loaded from the database) could only ever have
 * failed.
 *
 * See `SupportFallbackEmail`, which is the only issuer.
 *
 * ## Why the token is hex
 *
 * The token travels inside an email address. Base64 contains `+` and `/` and
 * pads with `=`, all of which are awkward or ambiguous in that position and in
 * the mail providers that parse it. Hex is unambiguous and needs no quoting.
 */
class SupportReplyToken extends Model
{
    use HasFactory;

    /** Tokens are written once and never edited; a revision column would invite it. */
    public $timestamps = false;

    /** How long a freshly issued token stays usable. */
    public const DEFAULT_TTL_DAYS = 30;

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

    /**
     * Hide the hash from serialised output.
     *
     * A model that is ever dumped — a log line, an API response, a queued job's
     * payload — must not carry the stored hash. It is not the credential, but it
     * is one half of the pair that verifies one, and there is no reason to
     * publish it.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /* --------------------------------------------------------------------- */
    /* Relationships                                                           */
    /* --------------------------------------------------------------------- */

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

    /* --------------------------------------------------------------------- */
    /* State                                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Whether this token may still be redeemed.
     *
     * Revocation wins over expiry, and expiry wins over everything else, so a
     * revoked token can never be revived by having its `revoked_at` cleared and
     * its `expires_at` extended.
     */
    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isFuture();
    }

    /**
     * Whether this token belongs to the given conversation.
     *
     * Checked on every redemption so a token minted for one conversation cannot
     * be replayed against another, which is the whole of what the token is for.
     */
    public function belongsToConversation(int $conversationId): bool
    {
        return $this->conversation_id === $conversationId;
    }

    /* --------------------------------------------------------------------- */
    /* Issuance                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Issue a token and return the raw value alongside the persisted row.
     *
     * The raw value is returned rather than stored, and it is returned exactly
     * once: this is the only moment at which it exists. A caller that loses it
     * must issue another token rather than go looking for this one.
     *
     * Any token previously outstanding for the same conversation and
     * participant is revoked as part of the same transaction, so exactly one is
     * ever live. Leaving the old one in force would mean two valid addresses for
     * one conversation and no way to tell which is the current one.
     *
     * @return array{token: self, raw_token: string}
     */
    public static function issue(
        SupportConversation $conversation,
        string $participantKind,
        ?User $user = null,
        ?Client $client = null,
        int $ttlDays = self::DEFAULT_TTL_DAYS,
    ): array {
        // 32 bytes of CSPRNG, hex encoded. Explicitly `random_bytes` rather than
        // a helper so the source of entropy is visible at the point where the
        // security of a bearer credential is decided.
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $token = DB::transaction(function () use ($conversation, $participantKind, $user, $client, $ttlDays, $tokenHash): self {
            // Revoke whatever was live before, so there is never a second valid
            // address for this conversation.
            static::query()
                ->where('conversation_id', $conversation->id)
                ->where('participant_kind', $participantKind)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return static::create([
                'conversation_id' => $conversation->id,
                'participant_kind' => $participantKind,
                'user_id' => $user?->id,
                'client_id' => $client?->id,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addDays($ttlDays),
                'revoked_at' => null,
                'created_at' => now(),
            ]);
        });

        return ['token' => $token, 'raw_token' => $rawToken];
    }

    /**
     * Redeem a raw token: find the row whose hash matches, and return it.
     *
     * The lookup is by hash, so the raw value is never compared in the
     * application and never has to be stored to be checked. A token that does
     * not resolve is reported as `null` rather than as a distinct failure, so a
     * caller cannot use the difference to learn whether a token existed.
     */
    public static function redeem(string $rawToken): ?self
    {
        $token = static::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        return $token?->isValid() === true ? $token : null;
    }

    /**
     * Revoke a token, so it stops working immediately.
     *
     * Used when a conversation is closed or a participant is removed: the reply
     * address should stop working at the same moment the thing it referred to
     * does.
     */
    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /* --------------------------------------------------------------------- */
    /* Queries                                                                 */
    /* --------------------------------------------------------------------- */

    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}

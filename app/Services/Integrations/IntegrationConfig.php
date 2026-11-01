<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One resolver for every credential that can be changed from the interface.
 *
 * ## Precedence, stated once
 *
 * A non-empty value stored in `integration_settings` wins. When there is no
 * row, the environment variable is used. The rule is the same for the bot
 * token, the webhook secret, the bot username and the VAPID pair, so an
 * operator never has to remember which half is authoritative for which key.
 *
 * ## Why the database, and not `.env`
 *
 * A web request cannot rewrite the environment of the worker processes that
 * already started, and writing `.env` from HTTP is a file write with the
 * application's secrets in it. The database is visible to HTTP workers and
 * queue workers alike, and the value is encrypted at rest by the model cast.
 * The environment remains the bootstrap and the fallback, which is what makes
 * this change safe for a deployment that never opens the screen.
 *
 * ## No stale copy in memory
 *
 * Nothing is cached in a static property. A worker that sends a message and a
 * controller that stores a new token are different processes, and a resolver
 * with a long-lived copy would let one keep using the old credential after the
 * other changed it. One indexed lookup per resolve is cheap enough for that.
 */
final class IntegrationConfig
{
    public const TELEGRAM_BOT_TOKEN = 'telegram.bot_token';

    public const TELEGRAM_WEBHOOK_SECRET = 'telegram.webhook_secret';

    public const TELEGRAM_WEBHOOK_SECRET_PREVIOUS = 'telegram.webhook_secret_previous';

    public const TELEGRAM_WEBHOOK_SECRET_ROTATED_AT = 'telegram.webhook_secret_rotated_at';

    public const TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT = 'telegram.webhook_secret_confirmed_at';

    public const TELEGRAM_BOT_USERNAME = 'telegram.bot_username';

    public const WEBPUSH_PUBLIC_KEY = 'webpush.public_key';

    public const WEBPUSH_PRIVATE_KEY = 'webpush.private_key';

    /** How long the secret being replaced keeps being accepted. */
    public const WEBHOOK_SECRET_GRACE_HOURS = 24;

    public function __construct(private readonly AuditRecorder $audit) {}

    public function telegramBotToken(): ?string
    {
        return $this->value(self::TELEGRAM_BOT_TOKEN, 'services.telegram.bot_token');
    }

    public function telegramBotUsername(): ?string
    {
        return $this->value(self::TELEGRAM_BOT_USERNAME, 'services.telegram.bot_username');
    }

    public function telegramWebhookSecret(): ?string
    {
        return $this->value(self::TELEGRAM_WEBHOOK_SECRET, 'services.telegram.webhook_secret');
    }

    /**
     * Every secret the webhook should currently accept.
     *
     * During a rotation the previous secret is still valid for a grace window.
     * Telegram keeps delivering updates signed with the old value until
     * `setWebhook` reaches it, and a local configuration that rejected those
     * would silently drop every message in the interval.
     *
     * ## An unconfirmed rotation never expires
     *
     * The grace window starts when Telegram confirms the new value, not when
     * the operator asks for it. While `setWebhook` has not been confirmed
     * (rejected, timed out, or never retried), the previous secret is still
     * the one Telegram is signing with. Letting it expire on a clock while the
     * provider keeps using it is exactly how a bot goes silent, so it stays
     * accepted until a rotation is confirmed or the operator discards it.
     *
     * @return list<string>
     */
    public function acceptedTelegramWebhookSecrets(): array
    {
        $secrets = [];

        $current = $this->telegramWebhookSecret();

        if ($current !== null) {
            $secrets[] = $current;
        }

        $previous = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS);

        if (is_string($previous) && $previous !== '') {
            $confirmedAt = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT);
            $rotatedAt = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT);

            if ($confirmedAt === null) {
                // The rotation is still pending: Telegram may be using this
                // value right now, so there is no safe moment to drop it.
                $secrets[] = $previous;
            } else {
                $expiresAt = is_string($rotatedAt)
                    ? Carbon::parse($rotatedAt)->addHours(self::WEBHOOK_SECRET_GRACE_HOURS)
                    : null;

                if ($expiresAt === null || $expiresAt->isFuture()) {
                    $secrets[] = $previous;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Whether a rotation is still waiting for Telegram to confirm it.
     *
     * Reports only state and timestamps: the secrets themselves never leave
     * the server through this shape.
     *
     * @return array{
     *     pending: bool,
     *     confirmed: bool,
     *     previous_accepted: bool,
     *     previous_accepted_until: string|null,
     *     grace_hours: int
     * }
     */
    public function telegramWebhookSecretRotation(): array
    {
        $previous = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS);
        $confirmedAt = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT);
        $rotatedAt = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT);

        $hasPrevious = is_string($previous) && $previous !== '';
        $confirmed = $confirmedAt !== null;
        $expiresAt = $hasPrevious && $confirmed && is_string($rotatedAt)
            ? Carbon::parse($rotatedAt)->addHours(self::WEBHOOK_SECRET_GRACE_HOURS)
            : null;

        $previousAccepted = $hasPrevious && ($expiresAt === null || $expiresAt->isFuture());

        return [
            'pending' => $hasPrevious && ! $confirmed,
            'confirmed' => $confirmed,
            'previous_accepted' => $previousAccepted,
            'previous_accepted_until' => $previousAccepted ? $expiresAt?->toIso8601String() : null,
            'grace_hours' => self::WEBHOOK_SECRET_GRACE_HOURS,
        ];
    }

    public function telegramConfigured(): bool
    {
        return $this->telegramBotToken() !== null;
    }

    public function telegramSource(): string
    {
        return $this->source(self::TELEGRAM_BOT_TOKEN, 'services.telegram.bot_token');
    }

    public function webhookSecretSource(): string
    {
        return $this->source(self::TELEGRAM_WEBHOOK_SECRET, 'services.telegram.webhook_secret');
    }

    public function vapidPublicKey(): ?string
    {
        return $this->value(self::WEBPUSH_PUBLIC_KEY, 'services.webpush.public_key');
    }

    public function vapidPrivateKey(): ?string
    {
        return $this->value(self::WEBPUSH_PRIVATE_KEY, 'services.webpush.private_key');
    }

    public function vapidConfigured(): bool
    {
        return $this->vapidPublicKey() !== null && $this->vapidPrivateKey() !== null;
    }

    public function vapidSource(): string
    {
        return $this->source(self::WEBPUSH_PUBLIC_KEY, 'services.webpush.public_key');
    }

    /**
     * Store the bot credentials an administrator typed into the panel.
     *
     * An empty token or secret is refused instead of stored: saving one would
     * silence a working bot, and the operator asked to configure it.
     *
     * @param  array{bot_token?: string|null, webhook_secret?: string|null, bot_username?: string|null}  $values
     */
    public function saveTelegramCredentials(array $values, ?User $actor = null): void
    {
        foreach (['bot_token', 'webhook_secret'] as $required) {
            if (array_key_exists($required, $values) && trim((string) $values[$required]) === '') {
                throw new InvalidArgumentException('Un token o secreto vacío dejaría el bot inoperante.');
            }
        }

        $map = [
            'bot_token' => self::TELEGRAM_BOT_TOKEN,
            'webhook_secret' => self::TELEGRAM_WEBHOOK_SECRET,
            'bot_username' => self::TELEGRAM_BOT_USERNAME,
        ];

        DB::transaction(function () use ($values, $map, $actor): void {
            if (array_key_exists('webhook_secret', $values)) {
                // A hand-edited secret is a rotation from Telegram's point of
                // view: it keeps signing with the old value until the webhook
                // is registered again. Keep that value accepted, and mark the
                // new one as unconfirmed until `setWebhook` succeeds.
                $this->rememberDisplacedSecret($this->telegramWebhookSecret(), $actor);
                $this->put(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT, null, $actor);
            }

            foreach ($map as $input => $key) {
                if (! array_key_exists($input, $values)) {
                    continue;
                }

                $this->put($key, $this->clean($values[$input]), $actor);
            }
        });

        $this->audit->record(AuditAction::TelegramCredentialsUpdated, $actor, [
            'keys' => array_keys(array_intersect_key($values, $map)),
        ]);
    }

    /**
     * Rotate the webhook secret and keep the old one accepted for the grace
     * window.
     *
     * The new value is returned so the caller can register it with Telegram. It
     * is never written to a log or an audit row.
     *
     * ## Repeated rotations while Telegram never confirmed the last one
     *
     * If `setWebhook` was rejected or timed out, Telegram is still using the
     * secret from the last *confirmed* rotation, not the newest value. Moving
     * the newest value into `previous` would drop the one Telegram actually
     * signs with, so a pending rotation keeps the stored previous secret in
     * place. Only a confirmed rotation replaces it.
     */
    public function rotateTelegramWebhookSecret(?User $actor = null): string
    {
        $next = bin2hex(random_bytes(32));

        DB::transaction(function () use ($next, $actor): void {
            $this->rememberDisplacedSecret($this->telegramWebhookSecret(), $actor);
            $this->put(self::TELEGRAM_WEBHOOK_SECRET, $next, $actor);
            $this->put(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT, null, $actor);
        });

        $this->audit->record(AuditAction::TelegramWebhookSecretRotated, $actor, [
            'previous_was_configured' => $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS) !== null,
            'grace_hours' => self::WEBHOOK_SECRET_GRACE_HOURS,
        ]);

        return $next;
    }

    /**
     * Record that Telegram accepted the current secret.
     *
     * The grace window for the displaced value starts here: the previous secret
     * has to stay accepted while Telegram finishes switching to the new one,
     * and that interval begins with the provider's acknowledgement — not with
     * the operator's click.
     */
    public function confirmTelegramWebhookSecret(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            $this->put(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT, now()->toIso8601String(), $actor);

            if ($this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS) !== null) {
                $this->put(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT, now()->toIso8601String(), $actor);
            }
        });
    }

    /**
     * Forget the old secret after the grace window, or when the operator says
     * the rotation is done.
     */
    public function discardPreviousWebhookSecret(?User $actor = null): void
    {
        DB::transaction(function () use ($actor): void {
            $this->put(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS, null, $actor);
            $this->put(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT, null, $actor);
        });
    }

    /**
     * @param  array{public_key?: string|null, private_key?: string|null}  $values
     *
     * ## The pair is written as a pair
     *
     * The public and private half are only usable together: a browser
     * subscription is created against one public key and the push service only
     * accepts messages signed with its matching private key. Accepting one half
     * would leave `vapidConfigured()` true over a pair that cannot deliver
     * anything, so a partial update is refused instead of stored.
     */
    public function saveVapidKeys(array $values, ?User $actor = null): void
    {
        $hasPublic = array_key_exists('public_key', $values);
        $hasPrivate = array_key_exists('private_key', $values);

        if (! $hasPublic && ! $hasPrivate) {
            throw new InvalidArgumentException('No hay claves VAPID que guardar.');
        }

        if ($hasPublic !== $hasPrivate) {
            throw new InvalidArgumentException(
                'Las claves VAPID se guardan como par: una mitad nueva con la otra anterior no forma un par válido.'
            );
        }

        $publicKey = $this->clean($values['public_key']);
        $privateKey = $this->clean($values['private_key']);

        if ($publicKey === null || $privateKey === null) {
            throw new InvalidArgumentException('Una clave VAPID vacía dejaría Push inoperante.');
        }

        if (! $this->isVapidPublicKey($publicKey) || ! $this->isVapidPrivateKey($privateKey)) {
            throw new InvalidArgumentException(
                'Las claves VAPID no tienen el formato esperado (clave pública P-256 de 65 bytes y privada de 32 bytes, en base64url).'
            );
        }

        DB::transaction(function () use ($publicKey, $privateKey, $actor): void {
            $this->put(self::WEBPUSH_PUBLIC_KEY, $publicKey, $actor);
            $this->put(self::WEBPUSH_PRIVATE_KEY, $privateKey, $actor);
        });

        $this->audit->record(AuditAction::PushSettingsUpdated, $actor, [
            'rotated_public_key' => true,
            'rotated_private_key' => true,
        ]);
    }

    private function value(string $key, string $configKey): ?string
    {
        $stored = $this->storedValue($key);

        if ($stored !== null) {
            return $stored;
        }

        $configured = config($configKey);

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * Keep the secret Telegram may still be using accepted after a change.
     *
     * The value is not displaced when a previous rotation is still pending and
     * the stored previous differs from the current one: in that state Telegram
     * never adopted `current`, and the stored previous is the secret it is
     * actually signing with.
     */
    private function rememberDisplacedSecret(?string $current, ?User $actor): void
    {
        if ($current === null) {
            return;
        }

        $previous = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS);
        $confirmedAt = $this->storedValue(self::TELEGRAM_WEBHOOK_SECRET_CONFIRMED_AT);

        if ($confirmedAt === null && $previous !== null && $previous !== $current) {
            return;
        }

        $this->put(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS, $current, $actor);
        $this->put(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT, now()->toIso8601String(), $actor);
    }

    private function storedValue(string $key): ?string
    {
        $stored = IntegrationSetting::query()->where('key', $key)->first()?->value;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private function source(string $key, string $configKey): string
    {
        $stored = IntegrationSetting::query()->where('key', $key)->first()?->value;

        if (is_string($stored) && $stored !== '') {
            return 'database';
        }

        $configured = config($configKey);

        return is_string($configured) && $configured !== '' ? 'environment' : 'missing';
    }

    private function put(string $key, ?string $value, ?User $actor): void
    {
        if ($value === null) {
            IntegrationSetting::query()->where('key', $key)->delete();

            return;
        }

        IntegrationSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $actor?->id],
        );
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A VAPID public key is an uncompressed P-256 point: 65 bytes starting
     * with 0x04, encoded as base64url by every key generator this project uses.
     */
    private function isVapidPublicKey(string $key): bool
    {
        $decoded = $this->base64UrlDecode($key);

        return $decoded !== null
            && strlen($decoded) === 65
            && ord($decoded[0]) === 4;
    }

    /** A VAPID private key is the 32-byte scalar, base64url encoded. */
    private function isVapidPrivateKey(string $key): bool
    {
        $decoded = $this->base64UrlDecode($key);

        return $decoded !== null && strlen($decoded) === 32;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr(rtrim($value, '='), '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? null : $decoded;
    }
}

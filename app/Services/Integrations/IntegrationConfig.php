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
     * @return list<string>
     */
    public function acceptedTelegramWebhookSecrets(): array
    {
        $secrets = [];

        $current = $this->telegramWebhookSecret();

        if ($current !== null) {
            $secrets[] = $current;
        }

        $previous = IntegrationSetting::query()
            ->where('key', self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS)
            ->first()?->value;

        $rotatedAt = IntegrationSetting::query()
            ->where('key', self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT)
            ->first()?->value;

        if (is_string($previous) && $previous !== '') {
            $expiresAt = is_string($rotatedAt)
                ? Carbon::parse($rotatedAt)->addHours(self::WEBHOOK_SECRET_GRACE_HOURS)
                : null;

            if ($expiresAt === null || $expiresAt->isFuture()) {
                $secrets[] = $previous;
            }
        }

        return array_values(array_unique($secrets));
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
     */
    public function rotateTelegramWebhookSecret(?User $actor = null): string
    {
        $previous = $this->telegramWebhookSecret();
        $next = bin2hex(random_bytes(32));

        DB::transaction(function () use ($previous, $next, $actor): void {
            if ($previous !== null) {
                $this->put(self::TELEGRAM_WEBHOOK_SECRET_PREVIOUS, $previous, $actor);
                $this->put(self::TELEGRAM_WEBHOOK_SECRET_ROTATED_AT, now()->toIso8601String(), $actor);
            }

            $this->put(self::TELEGRAM_WEBHOOK_SECRET, $next, $actor);
        });

        $this->audit->record(AuditAction::TelegramWebhookSecretRotated, $actor, [
            'previous_was_configured' => $previous !== null,
            'grace_hours' => self::WEBHOOK_SECRET_GRACE_HOURS,
        ]);

        return $next;
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
     */
    public function saveVapidKeys(array $values, ?User $actor = null): void
    {
        foreach (['public_key', 'private_key'] as $required) {
            if (array_key_exists($required, $values) && trim((string) $values[$required]) === '') {
                throw new InvalidArgumentException('Una clave VAPID vacía dejaría Push inoperante.');
            }
        }

        DB::transaction(function () use ($values, $actor): void {
            if (array_key_exists('public_key', $values)) {
                $this->put(self::WEBPUSH_PUBLIC_KEY, $this->clean($values['public_key']), $actor);
            }

            if (array_key_exists('private_key', $values)) {
                $this->put(self::WEBPUSH_PRIVATE_KEY, $this->clean($values['private_key']), $actor);
            }
        });

        $this->audit->record(AuditAction::PushSettingsUpdated, $actor, [
            'rotated_public_key' => array_key_exists('public_key', $values),
            'rotated_private_key' => array_key_exists('private_key', $values),
        ]);
    }

    private function value(string $key, string $configKey): ?string
    {
        $stored = IntegrationSetting::query()->where('key', $key)->first()?->value;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $configured = config($configKey);

        return is_string($configured) && $configured !== '' ? $configured : null;
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
}

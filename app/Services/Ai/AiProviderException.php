<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A provider call that did not succeed.
 *
 * ## Why `retryable` alone was not enough
 *
 * A single boolean conflated two different questions: *is this model broken* and
 * *may this application ask a different provider instead*. A rejected credential
 * on Groq is not the same kind of event as a 400 from the application's own
 * middleware, yet both used to end a turn — so a denied Groq key, whose models
 * sit at the top of the pool, blocked Gemini and OpenRouter from ever being
 * asked. That is the defect this class now prevents.
 *
 * Each failure therefore carries an explicit `scope`, which says where the fault
 * lies and what the resolver may do next:
 *
 *   - `model`          the model itself is the problem (404, empty answer).
 *                      Another model may answer. The model is cooled down.
 *   - `credential`     the provider rejected the credential or the account's
 *                      access (401/403). Another *provider* may answer; no
 *                      sibling model behind the same key is tried, and the whole
 *                      endpoint is quarantined.
 *   - `transport`      timeout, refused connection or 5xx. Another model may
 *                      answer now.
 *   - `quota_model`    a 429 scoped to one model. Another model may answer.
 *   - `quota_account`  a 429 that belongs to the whole account. Another provider
 *                      may answer; siblings behind the same key are not tried.
 *   - `capability`     the request was refused as malformed (400/422). Free
 *                      models differ in what they accept, so another may serve;
 *                      if the refusal names tools, that model is downgraded to
 *                      conversation-only.
 *   - `policy`         the provider refused the *content* under its safety
 *                      policy. Terminal on purpose: hopping providers to dodge a
 *                      content policy is the exact evasion the privacy rules
 *                      forbid, so the turn stops and reports it honestly.
 *   - `internal`       an application or configuration fault that no other
 *                      provider can make better. Terminal.
 *
 * `retryable` is derived from `scope` and kept as the historical alias of
 * "another candidate may answer this turn" (`allowsFailover()`), so existing
 * callers keep working while new code reasons about `scope`.
 *
 * `kind` is unchanged and still decides *how* the failure is remembered: a
 * rejected credential disables an endpoint, a missing model is parked for hours,
 * a timeout only cools its own model down.
 */
class AiProviderException extends RuntimeException
{
    public const SCOPE_MODEL = 'model';

    public const SCOPE_CREDENTIAL = 'credential';

    public const SCOPE_TRANSPORT = 'transport';

    public const SCOPE_QUOTA_MODEL = 'quota_model';

    public const SCOPE_QUOTA_ACCOUNT = 'quota_account';

    public const SCOPE_CAPABILITY = 'capability';

    public const SCOPE_POLICY = 'policy';

    public const SCOPE_INTERNAL = 'internal';

    /**
     * No provider is authorised to receive this payload's private data.
     *
     * Separate from a generic transport failure because the honest message is
     * different: the record did not leave the server, and no retry to another
     * provider can make an unauthorised provider safe.
     */
    public const SCOPE_PRIVACY = 'privacy';

    /**
     * Whether another candidate may be asked. Derived from `scope`, never set
     * independently, so the two can never disagree.
     */
    public readonly bool $retryable;

    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $kind = 'transport',
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $accountWide = false,
        public readonly string $scope = self::SCOPE_TRANSPORT,
        /**
         * When the provider refused the request because of a missing capability,
         * this names it ("tools"). The resolver records the downgrade so the
         * model keeps serving conversation but is not offered that work again.
         */
        public readonly ?string $missingCapability = null,
        /**
         * Whether a provider quota refusal is a *daily* account limit rather than
         * a per-minute one. A per-minute limit clears in seconds; a daily limit
         * does not, and suspending the account for the ordinary cooldown would
         * re-probe a wall that cannot move until the next day (A07-R5).
         */
        public readonly bool $dailyLimit = false,
    ) {
        parent::__construct($message);

        $this->retryable = ! in_array($scope, [self::SCOPE_POLICY, self::SCOPE_INTERNAL, self::SCOPE_PRIVACY], true);
    }

    /**
     * Whether the pool may try the next candidate for this failure.
     */
    public function allowsFailover(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether this failure ends the turn regardless of what else is configured.
     *
     * A content-policy refusal and an application/configuration fault are the
     * two that do: neither is made better by asking somebody else.
     */
    public function isTerminal(): bool
    {
        return ! $this->retryable;
    }

    /**
     * The provider rejected the credential, or denied this account access.
     *
     * Failover is allowed: the turn continues on an *independent* provider. The
     * whole endpoint is disabled so the same bad key is not re-tried model by
     * model, which is the difference between "one refused request" and "the
     * credential gets probed once per model per question".
     */
    public static function authentication(string $message): self
    {
        return new self($message, status: 401, kind: 'auth', scope: self::SCOPE_CREDENTIAL);
    }

    /**
     * The provider refused the request as malformed.
     *
     * Retryable across candidates on purpose: free models differ in which
     * parameters they accept, so a request one model rejects another may serve.
     * The failure cools that model down rather than ending the turn. When the
     * refusal is about tools, `$missingCapability` is `'tools'` and the resolver
     * stops offering that model tool work.
     */
    public static function malformed(string $message, ?string $missingCapability = null): self
    {
        return new self(
            $message,
            status: 400,
            kind: 'malformed',
            scope: self::SCOPE_CAPABILITY,
            missingCapability: $missingCapability,
        );
    }

    /**
     * A 429.
     *
     * `$accountWide` distinguishes a limit that belongs to one model from one
     * that belongs to the whole account. The difference decides whether the next
     * model is tried (a model limit) or whether the endpoint is set aside as a
     * whole (a free-tier account limit), because switching models behind a
     * shared account limit is the same wall, not a recovery.
     */
    public static function rateLimited(string $message, ?int $retryAfterSeconds = null, bool $accountWide = false, bool $dailyLimit = false): self
    {
        return new self(
            $message,
            status: 429,
            kind: 'rate_limited',
            retryAfterSeconds: $retryAfterSeconds,
            accountWide: $accountWide,
            scope: $accountWide ? self::SCOPE_QUOTA_ACCOUNT : self::SCOPE_QUOTA_MODEL,
            dailyLimit: $dailyLimit,
        );
    }

    public static function unavailable(string $message): self
    {
        // A transport failure, a timeout, or a 5xx: the same request may well
        // succeed on another model or a moment later.
        return new self($message, status: 503, kind: 'transport', scope: self::SCOPE_TRANSPORT);
    }

    /**
     * A 200 with neither prose nor a tool call.
     *
     * Failover, but the model's own fault: another model may well answer. Kept
     * as its own kind because a model that keeps returning empty is not the same
     * fault as one that is unreachable.
     */
    public static function emptyAnswer(string $message = 'The AI provider returned an empty answer.'): self
    {
        return new self($message, status: 502, kind: 'empty', scope: self::SCOPE_MODEL);
    }

    /**
     * The provider does not know this model.
     *
     * Failover across candidates — another model will work — but parked for a
     * long time, because until the catalogue or the configuration is corrected
     * this model will always 404.
     */
    public static function modelMissing(string $message): self
    {
        return new self($message, status: 404, kind: 'not_found', scope: self::SCOPE_MODEL);
    }

    /**
     * The provider refused the content under its own safety policy.
     *
     * Terminal: the resolver does not hop to another provider, because using
     * failover to get a policy refusal answered elsewhere is exactly the evasion
     * the privacy rules forbid. Nothing is cooled down — the model is fine.
     */
    public static function contentPolicy(string $message): self
    {
        return new self($message, status: 400, kind: 'content_policy', scope: self::SCOPE_POLICY);
    }

    /**
     * No provider is configured, or the application itself cannot proceed.
     *
     * Terminal: another candidate cannot fix a missing configuration.
     */
    public static function notConfigured(): self
    {
        return new self('No AI provider is configured.', status: 503, kind: 'not_configured', scope: self::SCOPE_INTERNAL);
    }

    /**
     * A provider call arrived with no privacy classification.
     *
     * Terminal, and deliberately so: a missing classification is an application
     * fault, and asking a different provider cannot make an unclassified payload
     * safe. The call is refused before any HTTP request, so nothing left the
     * server.
     */
    public static function misclassified(string $message): self
    {
        return new self($message, status: 500, kind: 'privacy_contract', scope: self::SCOPE_INTERNAL);
    }

    /**
     * No provider authorised for private data is available right now.
     *
     * Kept distinct from a plain transport failure so the public message is the
     * privacy one: the record stayed on the server and retrying elsewhere is not a
     * recovery. Terminal for the same reason.
     */
    public static function privacyUnavailable(string $message): self
    {
        return new self($message, status: 503, kind: 'privacy', scope: self::SCOPE_PRIVACY);
    }

    /**
     * A stable, safe classification for the interface. The provider's own
     * message, HTTP body and commercial text never appear here.
     */
    public function publicCode(): string
    {
        return match (true) {
            $this->scope === self::SCOPE_PRIVACY => 'provider_unavailable_private',
            $this->kind === 'rate_limited',
            $this->scope === self::SCOPE_QUOTA_ACCOUNT,
            $this->scope === self::SCOPE_QUOTA_MODEL => 'quota_limited',
            $this->kind === 'empty' => 'provider_empty',
            $this->kind === 'not_configured' => 'provider_not_configured',
            $this->scope === self::SCOPE_POLICY => 'provider_policy_refusal',
            $this->scope === self::SCOPE_INTERNAL => 'provider_internal_error',
            default => 'provider_unavailable',
        };
    }

    /**
     * The message a person may be shown, in Spanish, carrying no provider text.
     *
     * The exception's own `getMessage()` is written for a log: it can quote the
     * provider's refusal, which may invite a payment ("Add credits to unlock…")
     * or name internals. That prose must never reach the interface, so the
     * user-facing sentence is derived here from the *classification* — kind and
     * scope — and never from the provider's own words. The interface is told what
     * happened and whether retrying is worthwhile, and nothing more.
     */
    public function publicMessage(): string
    {
        if ($this->scope === self::SCOPE_PRIVACY) {
            return 'No hay un proveedor autorizado para procesar esta información de forma segura.';
        }

        if ($this->kind === 'rate_limited' || $this->scope === self::SCOPE_QUOTA_ACCOUNT || $this->scope === self::SCOPE_QUOTA_MODEL) {
            return 'El servicio de inteligencia artificial alcanzó temporalmente su límite de uso. Puedes reintentar cuando vuelva a estar disponible.';
        }

        return match ($this->scope) {
            self::SCOPE_CAPABILITY => 'Esta operación necesita una función que no está disponible temporalmente.',
            self::SCOPE_POLICY => 'El proveedor de inteligencia artificial no pudo procesar esta solicitud.',
            default => 'No hay un proveedor de inteligencia artificial disponible en este momento.',
        };
    }

    /**
     * A short, safe description of the failure for the status diagnostics.
     *
     * Built from the classified shape (HTTP status, scope, missing capability) and
     * never from the provider's own message, so the diagnostics that are exposed
     * through `/api/ai/status` cannot carry a payment prompt, a credential or a
     * record. It is enough to tell "attempted Gemini and it refused the payload"
     * from "Gemini was never tried because the turn was classified private".
     */
    public function safeDetail(): string
    {
        $scope = match ($this->scope) {
            self::SCOPE_CREDENTIAL => 'credencial rechazada',
            self::SCOPE_QUOTA_ACCOUNT => $this->dailyLimit ? 'cuota de cuenta (límite diario)' : 'cuota de cuenta',
            self::SCOPE_QUOTA_MODEL => 'cuota del modelo',
            self::SCOPE_CAPABILITY => $this->missingCapability === 'tools'
                ? 'capacidad de herramientas no admitida'
                : 'petición rechazada (formato)',
            self::SCOPE_POLICY => 'contenido rechazado por política',
            self::SCOPE_MODEL => 'modelo no disponible',
            self::SCOPE_PRIVACY => 'sin proveedor autorizado para datos privados',
            self::SCOPE_INTERNAL => 'fallo interno o de configuración',
            default => 'error de transporte',
        };

        return $this->status === null ? $scope : 'HTTP '.$this->status.' — '.$scope;
    }
}

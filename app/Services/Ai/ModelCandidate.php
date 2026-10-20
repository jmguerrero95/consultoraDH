<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * One model that may answer a turn.
 *
 * A candidate is a *model on an endpoint*, not merely a key: several models can
 * share one base URL and one credential, and a credential that is rejected
 * invalidates all of them at once. The endpoint identity is therefore derived
 * from the URL **and** the key, so the resolver can disable every model of a
 * broken credential in one step rather than discovering the same 401 once per
 * model.
 *
 * ## What is never in here
 *
 * The API key is held so the provider can send it, and it is turned into a hash
 * for every cache key and every diagnostic line. Nothing in this object's public
 * surface — `label()`, `endpointHash()`, `providerHost()` — carries the key or a
 * value from which it could be recovered.
 */
final readonly class ModelCandidate
{
    /**
     * Independent free hosts, so the status says "groq" or "gemini" rather than
     * a bare hostname. Matching is on the URL substring, not an exact host, so a
     * regional or proxied subdomain still maps to its provider.
     *
     * @var array<string, string>
     */
    private const KNOWN_HOSTS = [
        'api.groq.com' => 'groq',
        'api.cloudflare.com' => 'cloudflare',
        'generativelanguage.googleapis.com' => 'gemini',
        'opencode.ai' => 'opencode_zen',
    ];

    /**
     * @param  list<string>  $capabilities  which of 'chat' / 'tools' this model supports
     * @param  string  $dataPolicy  `standard` when the endpoint's terms forbid training on
     *                              the content; `training` when its free tier may use it,
     *                              in which case real business data must not be sent there
     */
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public string $model,
        public int $priority = 100,
        public array $capabilities = ['chat', 'tools'],
        public string $source = 'config',
        public ?int $contextLength = null,
        public string $providerName = '',
        public string $dataPolicy = 'standard',
        /**
         * Output-token ceiling for providers whose own default is too low to
         * finish a tool-call round (Cloudflare Workers AI defaults to 256, which
         * a reasoning model spends before it emits the call). Null leaves the
         * provider's default untouched.
         */
        public ?int $maxTokens = null,
    ) {}

    public function supportsTools(): bool
    {
        return in_array('tools', $this->capabilities, true);
    }

    /**
     * Whether real business data may be sent to this endpoint.
     *
     * A provider whose free tier may train on the content is only used for
     * general questions unless the deployment has explicitly authorised it for
     * client data. The question is about *our* data leaving the building, so the
     * safe default is "no" and the operator opts in per deployment.
     */
    public function supportsClientData(): bool
    {
        // Only an explicit `standard` classification permits real business
        // data. An unknown or misspelled policy is treated like `training`:
        // a configuration typo must fail closed, not silently unlock the gate.
        if ($this->dataPolicy === 'standard') {
            return true;
        }

        return (bool) config('ai.privacy.allow_training_providers', false);
    }

    public function supportsChat(): bool
    {
        return in_array('chat', $this->capabilities, true);
    }

    public function isOpenRouter(): bool
    {
        return str_contains($this->baseUrl, 'openrouter.ai');
    }

    /**
     * A stable, non-reversible identity for the endpoint (URL + credential).
     */
    public function endpointHash(): string
    {
        return substr(sha1($this->baseUrl.'|'.$this->apiKey), 0, 16);
    }

    /**
     * A stable identity for this model on this endpoint.
     */
    public function key(): string
    {
        return $this->endpointHash().':'.$this->model;
    }

    /**
     * A safe display label for diagnostics: host and model, never the key.
     */
    public function label(): string
    {
        return $this->providerHost().':'.$this->model;
    }

    public function providerHost(): string
    {
        if ($this->providerName !== '') {
            return $this->providerName;
        }

        if ($this->isOpenRouter()) {
            return 'openrouter';
        }

        foreach (self::KNOWN_HOSTS as $host => $name) {
            if (str_contains($this->baseUrl, $host)) {
                return $name;
            }
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'provider';
    }

    /**
     * The provider configuration shape `OpenAiCompatibleProvider` already reads.
     *
     * @return array{base_url: string, api_key: string, model: string}
     */
    public function toConfig(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey,
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
        ];
    }
}

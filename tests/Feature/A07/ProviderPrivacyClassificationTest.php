<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Services\Ai\ModelCandidate;
use App\Services\Ai\ModelPool;
use Tests\TestCase;

/**
 * A07-R2: the privacy classification of the independent providers is evidence,
 * not analogy.
 *
 * Groq and Cloudflare are `standard` because their official terms forbid
 * training on customer content; Gemini stays `training` because its free tier
 * may use content to improve its products. This test pins the configuration and
 * the gate that consumes it, so a silent edit cannot make private client data
 * eligible for a provider whose terms do not allow it.
 */
final class ProviderPrivacyClassificationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function provider(string $name): array
    {
        $provider = config("ai.providers.{$name}");

        $this->assertIsArray($provider, "El proveedor {$name} debe estar configurado.");

        return $provider;
    }

    private function candidate(string $name): ModelCandidate
    {
        return new ModelCandidate(
            baseUrl: 'https://provider.test/v1',
            apiKey: 'test-key',
            model: 'test-model',
            providerName: $name,
            dataPolicy: (string) $this->provider($name)['data_policy'],
        );
    }

    public function test_groq_and_cloudflare_are_classified_as_not_training(): void
    {
        $this->assertSame('standard', $this->provider('groq')['data_policy']);
        $this->assertSame('standard', $this->provider('cloudflare')['data_policy']);
    }

    public function test_gemini_keeps_the_training_classification(): void
    {
        // Deliberately not inferred from the other two providers.
        $this->assertSame('training', $this->provider('gemini')['data_policy']);
    }

    public function test_openrouter_is_training_by_default_and_keeps_that_policy_in_the_pool(): void
    {
        config([
            'ai.privacy.allow_training_providers' => false,
            'ai.openrouter.data_policy' => 'training',
            'ai.primary' => [
                'base_url' => 'https://openrouter.ai/api/v1',
                'api_key' => 'test-key',
                'model' => 'vendor/model:free',
            ],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.pool' => [],
            'ai.extra_models' => [],
            'ai.providers' => [],
            'ai.openrouter.include_free_router' => false,
            'ai.openrouter.dynamic_pool' => false,
        ]);

        $candidate = null;

        foreach (app(ModelPool::class)->all() as $entry) {
            if ($entry->model === 'vendor/model:free') {
                $candidate = $entry;
            }
        }

        $this->assertInstanceOf(ModelCandidate::class, $candidate);
        // The catalogue refinement rebuilds the candidate; the classification
        // must survive that rebuild or the gate silently reopens.
        $this->assertSame('training', $candidate->dataPolicy);
        $this->assertFalse($candidate->supportsClientData());
        $this->assertTrue($candidate->supportsChat());
    }

    public function test_an_unknown_privacy_classification_fails_closed(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $candidate = new ModelCandidate(
            baseUrl: 'https://provider.test/v1',
            apiKey: 'test-key',
            model: 'test-model',
            dataPolicy: 'standrad',
        );

        $this->assertFalse($candidate->supportsClientData());
    }

    public function test_standard_providers_may_serve_client_data_without_an_opt_in(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->assertTrue($this->candidate('groq')->supportsClientData());
        $this->assertTrue($this->candidate('cloudflare')->supportsClientData());
    }

    public function test_the_training_provider_is_excluded_from_client_data_by_default(): void
    {
        config(['ai.privacy.allow_training_providers' => false]);

        $this->assertFalse($this->candidate('gemini')->supportsClientData());
    }

    public function test_the_training_provider_requires_an_explicit_operator_opt_in(): void
    {
        config(['ai.privacy.allow_training_providers' => true]);

        $this->assertTrue($this->candidate('gemini')->supportsClientData());
    }

    public function test_the_shipped_default_is_not_to_authorise_training_providers(): void
    {
        $default = require config_path('ai.php');

        $this->assertFalse(
            $default['privacy']['allow_training_providers'],
            'AI_ALLOW_TRAINING_PROVIDERS must stay off in the shipped configuration.',
        );
    }
}

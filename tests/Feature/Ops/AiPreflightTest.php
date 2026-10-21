<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * G10: the preflight reads the configuration the deployment actually runs with.
 *
 * The acceptance criteria are written in terms of three independent providers
 * able to serve private data. Whether that holds depends on the effective
 * configuration: a key that was never filled in, a provider disabled in
 * `.env`, or a provider whose data policy is `training` while
 * `AI_ALLOW_TRAINING_PROVIDERS` is off. The preflight reports that state
 * instead of leaving it to be discovered as a mysterious loss of availability.
 *
 * It is a configuration report, not a certification: a listed provider is not
 * a provider that answered a real request.
 */
beforeEach(function (): void {
    config([
        'ai.enabled' => true,
        'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
        'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
        'ai.pool' => [],
        'ai.extra_models' => [],
        'ai.openrouter.dynamic_pool' => false,
        'ai.openrouter.include_free_router' => false,
        'ai.privacy.allow_training_providers' => false,
        'ai.providers' => [
            'groq' => [
                'base_url' => 'https://groq.test/v1',
                'api_key' => 'groq-key',
                'models' => ['groq-model'],
                'priority' => 1,
                'capabilities' => ['chat', 'tools'],
                'data_policy' => 'standard',
                'enabled' => true,
            ],
            'gemini' => [
                'base_url' => 'https://gemini.test/v1',
                'api_key' => 'gemini-key',
                'models' => ['gemini-model'],
                'priority' => 2,
                'capabilities' => ['chat', 'tools'],
                'data_policy' => 'training',
                'enabled' => true,
            ],
        ],
    ]);
});

/**
 * @return array<string, mixed>
 */
function preflightReport(): array
{
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray();

    return $report;
}

it('reports the effective privacy policy and who it excludes', function (): void {
    expect(Artisan::call('ai:preflight', ['--json' => true]))->toBe(0);

    $report = preflightReport();

    expect($report['privacy']['allow_training_providers'])->toBeFalse()
        ->and($report['privacy']['documented_default'])->toBeFalse()
        ->and($report['privacy']['differs_from_documented_default'])->toBeFalse()
        // Only the "standard" provider may carry client data.
        ->and($report['independent_private_providers'])->toBe(1)
        ->and($report['required_independent_private_providers'])->toBe(3)
        ->and($report['meets_independence_requirement'])->toBeFalse();

    $excluded = array_column($report['excluded_from_private_data'], 'provider');

    expect($excluded)->toContain('gemini')
        ->and($excluded)->not->toContain('groq');
});

it('counts a training-classified provider once the operator allows it', function (): void {
    config(['ai.privacy.allow_training_providers' => true]);

    expect(Artisan::call('ai:preflight', ['--json' => true]))->toBe(0);

    $report = preflightReport();

    expect($report['privacy']['allow_training_providers'])->toBeTrue()
        ->and($report['privacy']['differs_from_documented_default'])->toBeTrue()
        ->and($report['independent_private_providers'])->toBe(2)
        ->and($report['excluded_from_private_data'])->toBe([]);
});

it('fails in strict mode while fewer than three independent private providers are configured', function (): void {
    expect(Artisan::call('ai:preflight', ['--json' => true, '--strict' => true]))->toBe(1);

    config(['ai.privacy.allow_training_providers' => true]);

    // Three independent providers, all authorised for private data.
    config(['ai.providers.groq.data_policy' => 'standard']);
    config(['ai.providers.cloudflare' => [
        'base_url' => 'https://cloudflare.test/v1',
        'api_key' => 'cloudflare-key',
        'models' => ['cloudflare-model'],
        'priority' => 3,
        'capabilities' => ['chat', 'tools'],
        'data_policy' => 'standard',
        'enabled' => true,
    ]]);
    config(['ai.providers.gemini.data_policy' => 'standard']);

    expect(Artisan::call('ai:preflight', ['--json' => true, '--strict' => true]))->toBe(0);
});

it('says plainly that a configuration report is not a live certification', function (): void {
    expect(Artisan::call('ai:preflight', ['--json' => true]))->toBe(0);

    $report = preflightReport();

    expect($report['certification']['live_certified'])->toBeNull()
        ->and($report['certification']['note'])->toContain('Certificar');
});

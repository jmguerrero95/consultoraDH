<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\ModelCandidate;
use Illuminate\Console\Command;

/**
 * Reports the AI configuration this deployment actually runs with.
 *
 * ## Why this exists
 *
 * The acceptance criteria for the assistant are written in terms of *three
 * independent providers* able to serve private data. Whether that is true
 * depends on the effective configuration, not on what the repository says:
 * a key that was never filled in, a provider disabled in `.env`, a model
 * whose data policy is `training` while `AI_ALLOW_TRAINING_PROVIDERS` is
 * off — each of them quietly removes a provider from the private pool, and
 * the interface then reports a reduced availability that nobody can explain.
 *
 * So the preflight reads the *effective* configuration: which providers are
 * configured, how their data policy classifies them, how many independent
 * ones remain eligible for client data, and when the blocked ones come back.
 *
 * ## What it does not do
 *
 * It does not certify anything. A configuration that lists three independent
 * providers proves that three are configured, not that three answered a
 * real request with real credentials. Certification is a LIVE exercise, with
 * evidence per provider, and the report says so in as many words.
 */
final class AiPreflightCommand extends Command
{
    protected $signature = 'ai:preflight
        {--json : Print a machine readable report}
        {--strict : Exit non-zero when the private pool does not meet the required independence}';

    protected $description = 'Report the effective AI provider configuration, private-data eligibility and recovery times';

    /**
     * The acceptance criterion: three independent providers able to serve
     * private data. Overridable for a deployment that has been told
     * otherwise, but the default is the number the criteria name.
     */
    private const REQUIRED_INDEPENDENT_PRIVATE_PROVIDERS = 3;

    public function handle(AiProviderResolver $resolver): int
    {
        $allowTraining = (bool) config('ai.privacy.allow_training_providers', false);

        $private = $resolver->candidatesFor(['chat', 'client_data']);
        $all = $resolver->pool();

        $hosts = [];

        foreach ($private as $candidate) {
            $hosts[$candidate->providerHost()] = true;
        }

        $independent = count($hosts);
        $required = self::REQUIRED_INDEPENDENT_PRIVATE_PROVIDERS;

        $recoveries = [];
        $excluded = [];

        foreach ($all as $candidate) {
            if ($resolver->blocked($candidate)) {
                $seconds = max(0, $resolver->blockedFor($candidate));
                $account = $candidate->endpointHash();

                $recoveries[$account] = max($recoveries[$account] ?? 0, $seconds);

                continue;
            }

            if (! $candidate->supportsClientData()) {
                $excluded[] = [
                    'provider' => $candidate->providerHost(),
                    'model' => $candidate->model,
                    'data_policy' => $candidate->dataPolicy,
                    'reason' => 'La política de datos de este proveedor no permite datos de clientes.',
                ];
            }
        }

        $timers = array_values(array_filter($recoveries, static fn (int $seconds): bool => $seconds > 0));

        $report = [
            'checked_at' => now()->toIso8601String(),
            'ai_enabled' => (bool) config('ai.enabled', false),
            'privacy' => [
                'allow_training_providers' => $allowTraining,
                'documented_default' => false,
                'differs_from_documented_default' => $allowTraining !== false,
                'decision' => $allowTraining
                    ? 'AI_ALLOW_TRAINING_PROVIDERS está activo: es una decisión explícita del operador, no un valor por defecto. Los proveedores clasificados como «training» pueden recibir datos de clientes.'
                    : 'AI_ALLOW_TRAINING_PROVIDERS está en su valor por defecto: sólo los proveedores clasificados como «standard» pueden recibir datos de clientes.',
            ],
            'private_pool' => $this->privatePool($private, $resolver),
            'independent_private_providers' => $independent,
            'required_independent_private_providers' => $required,
            'meets_independence_requirement' => $independent >= $required,
            'excluded_from_private_data' => $excluded,
            'recovery' => [
                'earliest_seconds' => $timers === [] ? null : min($timers),
                'latest_seconds' => $timers === [] ? null : max($timers),
                'note' => 'El primer valor es el momento en que algún proveedor vuelve a estar disponible; el segundo, el peor caso.',
            ],
            'certification' => [
                'live_certified' => null,
                'note' => 'Este informe describe la configuración efectiva, no una certificación LIVE. Certificar exige una petición real a cada proveedor, con credenciales reales y datos sintéticos, y su evidencia.',
            ],
            'rate_limits' => config('ai.rate_limits', []),
        ];

        $this->render($report, (bool) $this->option('json'));

        if ($this->option('strict') && ! $report['meets_independence_requirement']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<ModelCandidate>  $candidates
     * @return list<array<string, mixed>>
     */
    private function privatePool(array $candidates, AiProviderResolver $resolver): array
    {
        $rows = [];

        foreach ($candidates as $candidate) {
            $rows[] = [
                'provider' => $candidate->providerHost(),
                'model' => $candidate->model,
                'data_policy' => $candidate->dataPolicy,
                'blocked' => $resolver->blocked($candidate),
                'retry_after_seconds' => $resolver->blocked($candidate)
                    ? max(0, $resolver->blockedFor($candidate))
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('AI preflight (configuración efectiva)');
        $this->line('IA habilitada: '.($report['ai_enabled'] ? 'sí' : 'no'));
        $this->line('Privacidad: '.$report['privacy']['decision']);

        $rows = [];

        foreach ($report['private_pool'] as $entry) {
            $rows[] = [
                $entry['provider'],
                $entry['model'],
                $entry['data_policy'],
                $entry['blocked'] ? 'en espera' : 'listo',
                $entry['retry_after_seconds'] ?? '-',
            ];
        }

        $this->table(['proveedor', 'modelo', 'política', 'estado', 'recupera (s)'], $rows);

        $this->line(sprintf(
            'Proveedores independientes para datos privados: %d (mínimo requerido: %d).',
            $report['independent_private_providers'],
            $report['required_independent_private_providers'],
        ));

        if ($report['excluded_from_private_data'] !== []) {
            $this->line('Excluidos de datos privados:');

            foreach ($report['excluded_from_private_data'] as $entry) {
                $this->line('  - '.$entry['provider'].' / '.$entry['model'].' ('.$entry['data_policy'].')');
            }
        }

        $this->line('Recuperación: '.($report['recovery']['earliest_seconds'] ?? 'sin tiempos conocidos')
            .' s (más temprano) / '.($report['recovery']['latest_seconds'] ?? 'sin tiempos conocidos').' s (peor caso)');

        $this->warn($report['certification']['note']);
    }
}

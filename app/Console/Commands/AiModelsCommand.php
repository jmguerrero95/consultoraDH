<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiModelHealth;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\ModelCatalog;
use Illuminate\Console\Command;

/**
 * Inspect and refresh the AI model pool.
 *
 * ## Why a command and not a request
 *
 * The pool is derived from OpenRouter's catalogue, and downloading a catalogue
 * is exactly the kind of work that must not happen inside a question: it is a
 * third-party call that can be slow or unavailable, and no user should wait for
 * it. So the catalogue is refreshed here, cached, and read by the request path
 * without a network call.
 *
 * `ai:models`           shows the pool the resolver would use, and its health.
 * `ai:models --refresh` refreshes the catalogue first.
 * `ai:models --reset-health` clears per-model cooldowns, for when a provider
 *                       outage has passed and the pool should try again at once.
 */
final class AiModelsCommand extends Command
{
    protected $signature = 'ai:models
        {--refresh : Fetch the live OpenRouter catalogue and cache it}
        {--reset-health : Clear per-model cooldowns and failure counters}';

    protected $description = 'Show the AI model pool and its health, optionally refreshing the catalogue.';

    public function handle(ModelCatalog $catalog, AiProviderResolver $resolver, AiModelHealth $health): int
    {
        if ($this->option('reset-health')) {
            $this->resetHealth($health, $resolver);
        }

        if ($this->option('refresh')) {
            $this->refresh($catalog);
        } else {
            $age = $catalog->ageSeconds();

            $this->line($age === null
                ? '<fg=yellow>Catalogue: not cached.</> Run with --refresh to fetch it.'
                : sprintf('<fg=green>Catalogue: cached %s ago.</>', $this->human($age)));
        }

        $this->newLine();
        $this->line('Model pool (ordered by the resolver\'s live health, first is tried first):');

        $report = $resolver->snapshot();
        $homepage = $catalog->available() ? null : '<fg=yellow>no catalogue</>';

        if ($report === []) {
            $this->components->warn('No model is configured. Set AI_PRIMARY_* or AI_EXTRA_MODELS.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($report as $row) {
            $rows[] = [
                $row['priority'],
                $row['provider'],
                $row['model'],
                $row['tools'] ? 'sí' : 'no',
                $row['state'],
                $row['cooldown_seconds'] > 0 ? $this->human((int) $row['cooldown_seconds']) : '—',
                $row['last_latency_ms'] === null ? '—' : $row['last_latency_ms'].'ms',
            ];
        }

        $this->table(
            ['prio', 'proveedor', 'modelo', 'tools', 'estado', 'enfriando', 'última latencia'],
            $rows,
        );

        if ($homepage !== null) {
            $this->components->warn('The catalogue is not cached, so paid-model exclusion falls back to the :free naming rule only.');
        }

        $this->newLine();
        $this->renderRecent($catalog);

        return self::SUCCESS;
    }

    private function refresh(ModelCatalog $catalog): void
    {
        $this->line('Fetching the OpenRouter catalogue…');

        $count = $catalog->refresh();

        if ($count === null) {
            $this->components->error('The catalogue could not be fetched; the cached copy, if any, is unchanged.');
        } else {
            $this->components->info(sprintf('Catalogue refreshed: %d models. Free models with tool support are now pool candidates.', $count));
        }
    }

    private function resetHealth(AiModelHealth $health, AiProviderResolver $resolver): void
    {
        foreach ($resolver->pool() as $candidate) {
            $health->reset($candidate);
        }

        $this->components->info('Per-model health and cooldowns cleared.');
    }

    private function renderRecent(ModelCatalog $catalog): void
    {
        $free = $catalog->freeToolCapable();

        if ($free === []) {
            $this->components->warn('No free tool-capable model is known. Run with --refresh once the network allows it.');

            return;
        }

        $this->line(sprintf('<fg=green>%d free tool-capable models</> in the cached catalogue.', count($free)));
        $this->line('  '.implode("\n  ", array_slice($free, 0, 12)));
    }

    private function human(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }
}

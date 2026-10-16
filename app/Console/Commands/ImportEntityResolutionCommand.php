<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Affiliations\ImportEntityResolutionReport;
use App\Models\LegacyImport;
use Illuminate\Console\Command;

/**
 * Prints what a historical import actually resolved, per institutional type.
 *
 * Read-only. It reports the state the import is in; it does not answer findings, rebuild a plan or
 * write anything, so it is safe to run against the production database while a batch is under review.
 */
final class ImportEntityResolutionCommand extends Command
{
    protected $signature = 'imports:entity-resolution
        {import : Import id}';

    protected $description = 'Report, per type (EPS/CCF/AFP/ARL), what an import resolved, refused, left ambiguous or left pending.';

    public function handle(): int
    {
        $id = (int) $this->argument('import');
        $import = LegacyImport::query()->find($id);

        if ($import === null) {
            $this->error(sprintf('No existe ninguna importación con id «%d».', $id));

            return self::FAILURE;
        }

        $report = ImportEntityResolutionReport::forImport($import);

        $this->line(sprintf('Importación #%d · %s · %s', $import->id, $import->status->value, (string) $import->plan_revision));
        $this->newLine();

        $rows = [];

        foreach (['EPS', 'CCF', 'AFP', 'ARL'] as $type) {
            foreach (['unique_tokens', 'occurrences', 'resolved', 'negative', 'refused', 'ambiguous', 'inactive', 'pending'] as $metric) {
                $rows[] = [$type, $metric, $report[$type][$metric]];
            }
        }

        $this->table(['Tipo', 'Métrica', 'Cantidad'], $rows);

        $this->newLine();
        $this->line('Pendientes, con el motivo de cada uno:');

        foreach (['EPS', 'CCF', 'AFP', 'ARL'] as $type) {
            $reasons = $report[$type]['pending_reasons'];

            if ($reasons === []) {
                continue;
            }

            $this->line(sprintf('  %s:', $type));

            foreach ($reasons as $token => $reason) {
                $this->line(sprintf('    - %-26s %s', $token, $reason));
            }
        }

        $this->newLine();

        if ($report['blocking_issues'] === []) {
            $this->info('Sin incidencias bloqueantes abiertas.');
        } else {
            $this->warn('Incidencias bloqueantes abiertas:');

            foreach ($report['blocking_issues'] as $code => $count) {
                $this->line(sprintf('  - %-42s %d', $code, $count));
            }
        }

        return self::SUCCESS;
    }
}

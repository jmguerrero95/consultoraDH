<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Affiliations\ActivateNationalCatalogues;
use App\Domain\Affiliations\CatalogueActivationAuthorisation;
use App\Domain\Affiliations\CatalogueActivationRefused;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Activates the national catalogues, atomically, on somebody's explicit word.
 *
 * ## Dry-run is not a mode you choose, it is the only default
 *
 * Nothing is written unless both `--apply` and the exact fingerprint from the
 * preview are given. The fingerprint is what ties the confirmation to the thing
 * that was actually reviewed: between the preview and the confirmation somebody
 * could have changed a catalogue, and applying then would extend an approval to
 * content nobody saw.
 *
 * ## The actor must be named, and must exist, and must be permitted
 *
 * `--actor` takes a **user id**, and that id alone is not authorisation: it is
 * resolved against the users table, its status is read, and the Gate and the two
 * permissions are checked. A console argument is a claim, not a credential.
 * `User::first()` was removed from this codebase for the same reason.
 *
 * ## The database is named before anything else happens
 *
 * `--expect-database` is required to match `config('database.connections...database')`. Without it an
 * operator can point this at production having meant the staging copy, and the fingerprint would be
 * perfectly valid — for the wrong database.
 */
final class ActivateNationalCataloguesCommand extends Command
{
    protected $signature = 'catalogues:activate
        {--actor= : User id of the person authorising the activation}
        {--expect-database= : The database this must run against; refuses on any other}
        {--apply : Actually write. Without this nothing is written.}
        {--fingerprint= : The fingerprint printed by the preview being confirmed}';

    protected $description = 'Load the national EPS/CCF/AFP/ARL catalogues and their sigla mappings, atomically.';

    public function handle(ActivateNationalCatalogues $activation): int
    {
        $expected = (string) $this->option('expect-database');
        $actual = (string) config('database.connections.'.config('database.default').'.database');

        if ($expected === '') {
            $this->error('Falta --expect-database. Sin él no se sabe sobre qué base se va a escribir.');

            return self::FAILURE;
        }

        if ($expected !== $actual) {
            $this->error(sprintf(
                'La base indicada no es la base conectada. Indicada: «%s». Conectada: «%s».',
                $expected,
                $actual,
            ));

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $wantsWrite = (bool) $this->option('apply');
        $fingerprint = (string) $this->option('fingerprint');

        if ($wantsWrite && $fingerprint === '') {
            $this->error('Con --apply hace falta --fingerprint con el valor que imprimió la previsualización.');

            return self::FAILURE;
        }

        if (! $wantsWrite) {
            return $this->preview($activation, $actor);
        }

        return $this->write($activation, $actor, $fingerprint);
    }

    private function resolveActor(): ?User
    {
        $raw = $this->option('actor');

        if (! is_string($raw) || $raw === '') {
            $this->error('Falta --actor. Una carga de catálogo es la decisión de una persona y queda auditada a su nombre.');

            return null;
        }

        $actor = User::query()->find($raw);

        if ($actor === null) {
            $this->error(sprintf('No existe ningún usuario con id «%s».', $raw));

            return null;
        }

        if (! CatalogueActivationAuthorisation::allows($actor, writesMappings: true)) {
            $this->error(sprintf(
                '«%s» no puede activar los catálogos. %s',
                $actor->name,
                CatalogueActivationAuthorisation::refuseReason($actor, writesMappings: true),
            ));

            return null;
        }

        return $actor;
    }

    private function preview(ActivateNationalCatalogues $activation, User $actor): int
    {
        $result = $activation->run($actor, apply: false);

        $this->table(
            ['Medida', 'Cantidad'],
            [
                ['Entidades nuevas', $result['preflight']['entities_new']],
                ['Entidades ya existentes', $result['preflight']['entities_existing']],
                ['Entidades inactivas omitidas', $result['preflight']['entities_inactive_skipped']],
                ['Mapeos nuevos', $result['preflight']['mappings_new']],
                ['Mapeos en conflicto', $result['preflight']['mappings_conflicting']],
                ['Mapeos pendientes de aprobación', $result['preflight']['mappings_pending_approval']],
                ['Mapeos sin entidad destino', $result['preflight']['mappings_missing_target']],
            ],
        );

        $this->line('Base: '.$actor->name.' · '.(string) config('database.connections.'.config('database.default').'.database'));
        $this->line('Huella de esta previsualización: '.$result['fingerprint']);
        $this->comment('Previsualización. No se escribió nada. Para escribir, repita con --apply y esa huella.');

        return self::SUCCESS;
    }

    private function write(ActivateNationalCatalogues $activation, User $actor, string $fingerprint): int
    {
        $current = $activation->run($actor, apply: false);

        if ($current['fingerprint'] !== $fingerprint) {
            $this->error(sprintf(
                'La previsualización no coincide con el estado actual. Confirmada: %s. Actual: %s. '
                .'Algo cambió entre medio; revise de nuevo antes de escribir.',
                $fingerprint,
                $current['fingerprint'],
            ));

            return self::FAILURE;
        }

        try {
            $result = $activation->run($actor, apply: true);
        } catch (CatalogueActivationRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Catálogos activados: %d entidades, %d mapeos. Ya existentes: %d.',
            $result['entities'],
            $result['mappings'],
            $result['already_present'],
        ));

        return self::SUCCESS;
    }
}

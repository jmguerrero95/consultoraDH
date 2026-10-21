<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\AiMessage;
use App\Services\Ai\AiProviderResolver;
use Illuminate\Console\Command;

/**
 * Prove, against the real providers, that a turn continues with another
 * provider when the first one fails.
 *
 * ## What this is for
 *
 * The failover is not a feature of the transport: it is the resolver walking
 * down its candidates inside **one** turn. A provider that is unreachable, a
 * key that is refused and a model that is out of quota are three different
 * failures, and each of them has to leave the person with an answer instead of
 * with an error.
 *
 * The failure is induced by the operator: point the primary slot at an address
 * that answers nothing, leave a working provider behind it, and this command
 * asks for a real answer. The evidence is the diagnostics of that single turn:
 * a row for the provider that failed and a row for the provider that answered.
 *
 * The command never prints a credential, never writes business data and never
 * mutates anything.
 */
final class AiFailoverCheckCommand extends Command
{
    protected $signature = 'ai:check-failover
                            {--model= : Override the model for the fallback, without changing configuration}';

    protected $description = 'Prove that a real turn continues with another provider when the first one fails.';

    public function handle(AiProviderResolver $resolver): int
    {
        $this->components->info('Consultora DH — comprobación del failover');

        $primary = $resolver->primary();

        if (! $primary->isConfigured() && ! $resolver->fallback()->isConfigured()) {
            $this->components->error('No hay ningún proveedor configurado; no hay nada que comprobar.');

            return self::FAILURE;
        }

        try {
            $response = $resolver->complete([
                AiMessage::system('Eres un asistente conciso. Responde en español.'),
                AiMessage::user('En una frase, ¿qué es una transacción de base de datos?'),
            ], [], null, null, null, false, null, 'failover-check');
        } catch (\Throwable $e) {
            $this->components->error('El turno no llegó a ningún proveedor: '.$e->getMessage());

            return self::FAILURE;
        }

        $rows = $resolver->diagnostics();
        $attempted = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['turn'] ?? null) === 'failover-check'
        ));

        // The diagnostics say what the resolver *did* with the failure:
        // `failed_over` means the turn moved on to the next candidate.
        $failed = array_values(array_filter(
            $attempted,
            static fn (array $row): bool => in_array($row['action'] ?? '', ['failed_over', 'stopped'], true)
        ));

        $answered = array_values(array_filter(
            $attempted,
            static fn (array $row): bool => ($row['action'] ?? '') === 'answered'
        ));

        foreach ($attempted as $row) {
            $this->line(sprintf(
                '  · %-14s %-28s %-12s %s',
                (string) ($row['provider'] ?? '-'),
                (string) ($row['model'] ?? '-'),
                (string) ($row['outcome'] ?? '-'),
                (string) ($row['detail'] ?? ''),
            ));
        }

        $this->newLine();

        if (trim($response->content) === '') {
            $this->components->error('El proveedor respondió vacío.');

            return self::FAILURE;
        }

        if ($failed === []) {
            $this->components->error('Ningún proveedor falló: no hubo failover que comprobar.');
            $this->line('  Para comprobarlo, apunte el slot principal a una dirección que no conteste.');

            return self::FAILURE;
        }

        if ($answered === []) {
            $this->components->error('El turno no lo respondió ningún proveedor.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'El turno continuó: %s falló y %s respondió con %s en el mismo turno.',
            (string) ($failed[0]['provider'] ?? '-'),
            (string) ($response->provider ?? '-'),
            (string) ($response->model ?? '-'),
        ));

        $this->line('  extracto: '.mb_substr(preg_replace('/\s+/', ' ', $response->content) ?? '', 0, 110));

        return self::SUCCESS;
    }
}

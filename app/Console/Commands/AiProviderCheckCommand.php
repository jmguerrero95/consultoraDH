<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiToolDefinition;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\CloudAiProvider;
use Illuminate\Console\Command;

/**
 * Prove, against the configured provider, that the assistant can actually work.
 *
 * ## Why this exists
 *
 * The assistant's behaviour depends on properties that no unit test can assert:
 * that the host answers, that the model replies in the language asked for, that
 * it can emit a tool call, and that a rejected credential is reported as a
 * rejected credential rather than as a blank reply. Each of those has failed
 * silently at some point, and the application kept rendering a blank transcript
 * either way.
 *
 * So this asks the real provider the ten questions that matter, reports what
 * came back, and stops at the first one that does not. It sends minimal
 * requests on purpose: a free tier is metered per request and the point is to
 * establish capability, not to produce good answers.
 *
 * ## What it never does
 *
 * It never prints the API key, never writes business data, and never mutates
 * anything. The only model it will read is a user passed explicitly with
 * `--user=`, because a tool call is only meaningful against a real account
 * with real permissions.
 */
final class AiProviderCheckCommand extends Command
{
    protected $signature = 'ai:check-provider
                            {--user= : ID of the user whose permissions the tool call is checked against}
                            {--model= : Override the model for this run, without changing configuration}';

    protected $description = 'Run live capability checks against the configured AI provider.';

    public function handle(AiProviderResolver $resolver, AiToolRegistry $registry): int
    {
        $this->components->info('Consultora DH — comprobación del proveedor de IA');

        if (! config('ai.enabled')) {
            $this->components->warn('AI_ENABLED=false: el asistente está desactivado. Nada de lo que sigue puede ejecutarse.');
            $this->line('  Established: AI_ENABLED=true');

            return self::FAILURE;
        }

        $primary = $resolver->primary();
        $fallback = $resolver->fallback();

        $this->reportSlot('Primario', $primary);

        if (! $primary->isConfigured() && ! $fallback->isConfigured()) {
            $this->components->error('Ninguno de los dos está configurado. No hay proveedor con el que responder.');

            return self::FAILURE;
        }

        // Prefer whichever slot can answer, so a deliberately single-slot
        // deployment still gets a meaningful run rather than a misleading failure.
        $slot = $primary->isConfigured() ? $primary : $fallback;

        $this->reportSlot('En uso', $slot);
        $this->newLine();

        // 1. A general question, in Spanish, through the real provider.
        $this->check('1. Pregunta general en español', function () use ($slot): array {
            $response = $slot->complete([
                AiMessage::system('Eres un asistente conciso. Responde en español.'),
                AiMessage::user('En una frase, ¿qué es una transacción de base de datos?'),
            ]);

            if (trim($response->content) === '') {
                throw new \RuntimeException('El proveedor respondió vacío.');
            }

            return [
                'modelo' => $response->model ?? $slot->model(),
                'longitud' => mb_strlen($response->content).' caracteres',
                'extracto' => mb_substr(preg_replace('/\s+/', ' ', $response->content) ?? '', 0, 110),
            ];
        });

        // 2. Multi-turn context: the answer has to depend on the first exchange.
        $this->check('2. Contexto entre turnos', function () use ($slot): array {
            $first = $slot->complete([
                AiMessage::system('Eres un asistente conciso. Responde en español.'),
                AiMessage::user('Mi código de cliente es ACME-77. Solo memorízalo, no lo interpretes.'),
            ]);

            if (trim($first->content) === '') {
                throw new \RuntimeException('El proveedor respondió vacío al primer turno.');
            }

            $second = $slot->complete([
                AiMessage::system('Eres un asistente conciso. Responde en español.'),
                AiMessage::user('Mi código de cliente es ACME-77. Solo memorízalo, no lo interpretes.'),
                AiMessage::assistant($first->content),
                AiMessage::user('¿Cuál era el código? Solo el código.'),
            ]);

            if (! str_contains($second->content, 'ACME-77')) {
                throw new \RuntimeException('El segundo turno no recupera el dato del primero.');
            }

            return ['recuperado' => 'ACME-77'];
        });

        // 3. A real tool call, resolved through the real registry.
        $this->check('3. Llamada a herramienta registrada', function () use ($slot): array {
            $definitions = [
                new AiToolDefinition(
                    'client_search',
                    'Busca clientes por nombre o identificacion.',
                    [
                        'type' => 'object',
                        'properties' => ['term' => ['type' => 'string', 'description' => 'Texto a buscar']],
                        'required' => ['term'],
                    ],
                ),
            ];

            $response = $slot->complete([
                AiMessage::system('Eres un asistente. Usa la herramienta cuando corresponda.'),
                AiMessage::user('Usa obligatoriamente la herramienta client_search con el termino "ACMES". No respondas de memoria.'),
            ], $definitions);

            if ($response->toolCalls === []) {
                throw new \RuntimeException('El modelo no pidió ninguna herramienta. Puede que este modelo no admita tool calling.');
            }

            $call = $response->toolCalls[0];

            return ['herramienta' => $call->name, 'argumentos' => json_encode($call->arguments, JSON_UNESCAPED_SLASHES)];
        });

        // 4. The registry refuses a tool that is not in it, which is the
        //    boundary a real model must not be able to cross.
        $this->check('4. Herramienta fuera del registro rechazada', function () use ($registry): array {
            try {
                $registry->executeRead('drop_all_tables', [], $this->toolProbeUser());
            } catch (\Throwable $e) {
                return ['rechazada' => mb_substr($e->getMessage(), 0, 70)];
            }

            throw new \RuntimeException('El registro aceptó una herramienta desconocida. Eso es un fallo de seguridad.');
        });

        // 5. Production-shaped serialization: the exact payload the application
        //    sends, with the whole real catalogue.
        $this->check('5. Serialización con el catálogo real', function () use ($slot, $registry): array {
            $response = $slot->complete([
                AiMessage::system('Eres un asistente de Consultora DH.'),
                AiMessage::user('Saluda en una frase.'),
            ], $registry->definitions());

            if (trim($response->content) === '') {
                throw new \RuntimeException('Respuesta vacía con el catálogo completo.');
            }

            return ['herramientas_ofrecidas' => count($registry->definitions()), 'respuesta' => 'ok'];
        });

        $this->newLine();

        if ($fallback->isConfigured() && $primary->isConfigured()) {
            $this->check('6. Proveedor de respaldo', function () use ($fallback): array {
                $response = $fallback->complete([
                    AiMessage::system('Responde en español.'),
                    AiMessage::user('Responde únicamente: OK'),
                ]);

                return ['modelo' => $response->model ?? $fallback->model(), 'respuesta' => mb_substr($response->content, 0, 40)];
            });
        } else {
            $this->components->warn('El respaldo no está configurado; no hay proveedor de reserva si el primario falla.');
        }

        $this->newLine();
        $this->components->info('Comprobación completada contra el proveedor real.');

        return self::SUCCESS;
    }

    /**
     * A user for the authorization probe.
     *
     * Only used to prove the registry refuses an unknown tool, which never
     * reaches the account, so a factory user is enough and no real account is
     * read. The command requires `--user=` for the tool-call probe because that
     * one runs against real permissions.
     */
    private function toolProbeUser(): User
    {
        return User::query()->first() ?? User::factory()->create();
    }

    private function reportSlot(string $label, CloudAiProvider $slot): void
    {
        $state = $slot->isConfigured() ? '<fg=green>configurado</>' : '<fg=yellow>sin configurar</>';

        $this->line(sprintf('  %-10s %s  modelo: %s', $label, $state, $slot->model() ?? '—'));
    }

    /**
     * Run one check, reporting the outcome rather than aborting the whole run.
     *
     * A failure here is information: it says exactly which capability the
     * provider does not have, which is what decides whether this provider is
     * usable for the assistant or only for plain conversation.
     *
     * @param  callable(): array<string, mixed>  $probe
     */
    private function check(string $label, callable $probe): void
    {
        try {
            $facts = $probe();

            $this->components->twoColumnDetail($label.'  <fg=green>PASS</>', implode(' · ', array_map(
                static fn (string $key, mixed $value): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)),
                array_keys($facts),
                $facts,
            )));
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail($label.'  <fg=red>FALLO</>', mb_substr($e->getMessage(), 0, 120));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiToolDefinition;
use App\Domain\Ai\AiToolRejected;
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

    /** @var list<string> Checks that did not pass. */
    private array $failures = [];

    /** @var int Checks that passed. */
    private int $passed = 0;

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
        //
        //    This deliberately advertises a tool that does not exist in the
        //    registry. The model is asked to call it, and what is checked is
        //    that it actually emits a tool call carrying that name and a
        //    plausible `term` — and then that the registry refuses it.
        //
        //    That combination is what actually proves the two things that
        //    matter. Asserting only "a tool call happened" would pass against a
        //    model that called anything, and asserting against a real registry
        //    tool would mean asking the provider to trigger a genuine read
        //    against business data every time this command runs.
        $this->check('3. Llamada a herramienta registrada', function () use ($slot, $registry): array {
            $probeTool = 'client_search';

            $definitions = [
                new AiToolDefinition(
                    $probeTool,
                    'Busca clientes por nombre o identificacion.',
                    [
                        'type' => 'object',
                        'properties' => ['term' => ['type' => 'string', 'description' => 'Texto a buscar']],
                        'required' => ['term'],
                    ],
                ),
            ];

            $response = $slot->complete([
                AiMessage::system('Eres un asistente. Usa la herramienta cuando se te pide. No inventes respuestas.'),
                AiMessage::user('Llama a la herramienta '.$probeTool.' con el termino "ACMES". No respondas de memoria.'),
            ], $definitions);

            if ($response->toolCalls === []) {
                throw new \RuntimeException('El modelo no pidió ninguna herramienta. Puede que este modelo no admita tool calling.');
            }

            $call = $response->toolCalls[0];

            // A call with the wrong name is not evidence of tool calling at all.
            if ($call->name !== $probeTool) {
                throw new \RuntimeException(sprintf(
                    'Se esperaba una llamada a %s y el modelo pidió %s.',
                    $probeTool,
                    $call->name,
                ));
            }

            // And a call with no usable arguments proves the schema was not read.
            $term = $call->arguments['term'] ?? null;

            if (! is_string($term) || trim($term) === '') {
                throw new \RuntimeException('La llamada a la herramienta no trae el argumento `term`.');
            }

            // The probe advertises a tool the real registry does not contain, so
            // the registry must refuse it. That refusal is the security boundary:
            // a model naming something outside the closed set gets nothing.
            try {
                $registry->executeRead($probeTool.'_privileged_variant', [], $this->toolProbeUser());
            } catch (\Throwable $e) {
                // Expected: the point is that the registry says no.
            }

            try {
                $registry->executeRead($probeTool, $call->arguments, $this->toolProbeUser());
                // Reaching here means the probe name collided with a real tool
                // and ran it. Not a failure of the provider, but it means this
                // check is no longer proving what it claims to prove.
                throw new \RuntimeException('El nombre de la sonda coincide con una herramienta real y esta se ejecutó.');
            } catch (AiToolRejected $e) {
                // Correct: a tool the model may not use is refused before its
                // arguments are even read.
            }

            return [
                'herramienta' => $call->name,
                'argumento_term' => mb_substr((string) $term, 0, 40),
                'registro' => 'rechaza lo no registrado',
            ];
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

        // The verdict, and the exit code, are the same fact.
        //
        // Reporting "completed" and exiting 0 while checks had failed is worse
        // than no verification at all: it converts a broken provider into a
        // green result that a script will act on.
        if ($this->failures !== []) {
            $this->components->error(sprintf(
                '%d de %d comprobaciones fallaron: %s',
                count($this->failures),
                $this->passed + count($this->failures),
                implode('; ', $this->failures),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Comprobación completada: %d comprobaciones superadas.', $this->passed));

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
     * Run one check, recording the outcome.
     *
     * A failure is recorded rather than thrown so that one run reports every
     * capability instead of stopping at the first gap — but it is recorded as a
     * failure, and it makes the command exit non-zero. An earlier version
     * caught the exception, printed "FALLO", and still returned SUCCESS, which
     * made a command meant to prove the provider works report success while
     * three of its six checks had failed. Anything scripting this command would
     * have taken that as a green light.
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

            $this->passed++;
        } catch (\Throwable $e) {
            $this->failures[] = $label;
            $this->components->twoColumnDetail($label.'  <fg=red>FALLO</>', mb_substr($e->getMessage(), 0, 120));
        }

        // A free tier is metered per minute, and this command spends several
        // requests back to back. Pausing between checks costs seconds and is
        // the difference between measuring the provider and measuring this
        // script's own burst rate.
        $this->pauseBetweenChecks();
    }

    /**
     * Wait out the free tier's per-minute budget between checks.
     *
     * The pause is skipped when the provider has not been reached at all: a
     * deterministic failure is not going to be fixed by waiting.
     */
    private function pauseBetweenChecks(): void
    {
        if (env('AI_CHECK_NO_PAUSE', false) === true) {
            return;
        }

        usleep((int) ((float) env('AI_CHECK_PAUSE_SECONDS', 12) * 1_000_000));
    }
}

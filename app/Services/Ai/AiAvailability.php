<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * What the assistant can actually do *right now*, per kind of operation.
 *
 * ## Why a single `available` flag was wrong
 *
 * The old status answered one question: is any model usable? That can be true
 * while every model that may receive client data is cooling down, or while the
 * only usable model cannot call tools. The interface then said "Disponible" for
 * a document question that could not possibly be answered, which is the defect
 * this class removes.
 *
 * ## Derived from the pool, never from a provider label
 *
 * Each operation declares the capabilities it really needs. The pool is asked
 * for candidates that support exactly those capabilities — including the
 * privacy capability, which is derived from the provider's declared data policy
 * rather than from its marketing. A provider that is eligible on paper but is
 * cooling down, rate-limited or missing a credential is counted as configured
 * but not ready, so "available" means "a model can be tried right now".
 *
 * ## A chat answer does not prove a tool turn
 *
 * An operation that declares the `tools` capability is only "proven" by a model
 * that has *actually emitted a structured tool call* recently
 * (`markToolsDemonstrated`). A model that merely answered a plain chat turn
 * proves the conversation path, not the tool path: it is honest to call a
 * tool-bearing operation "unverified" until tool use has been observed, even
 * when the same model is answering general questions. Operations without the
 * `tools` capability keep using the ordinary recent-answer proof.
 *
 * ## Nothing sensitive leaves here
 *
 * The report carries counts, states, model ids and provider names. It never
 * carries a key, a base URL, a prompt, a reply or a client record.
 */
final class AiAvailability
{
    /**
     * The operations the product promises, each with the capabilities it needs.
     *
     * `requires_web_search` means the operation needs the local SearXNG endpoint
     * as well as a tool-capable model; a model that can call tools is not enough
     * to research the web if the search backend is switched off.
     *
     * @var array<string, array{capabilities: list<string>, requires_web_search?: bool, label: string}>
     */
    private const OPERATIONS = [
        'general_chat' => [
            'capabilities' => ['chat'],
            'label' => 'Conversación general',
        ],
        'private_chat' => [
            'capabilities' => ['chat', 'client_data'],
            'label' => 'Conversación con datos privados',
        ],
        'document_read' => [
            'capabilities' => ['chat', 'tools', 'client_data'],
            'label' => 'Lectura de documentos',
        ],
        'document_generation' => [
            'capabilities' => ['chat', 'tools', 'client_data'],
            'label' => 'Generación de documentos',
        ],
        'internal_data' => [
            'capabilities' => ['chat', 'tools', 'client_data'],
            'label' => 'Consulta de datos internos',
        ],
        'web_research' => [
            'capabilities' => ['chat', 'tools'],
            'requires_web_search' => true,
            'label' => 'Investigación web',
        ],
        'task_proposals' => [
            'capabilities' => ['chat', 'tools', 'client_data'],
            'label' => 'Propuestas de tareas',
        ],
        'tool_synthesis' => [
            'capabilities' => ['chat', 'tools'],
            'label' => 'Síntesis con herramientas',
        ],
    ];

    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly AiModelHealth $health,
    ) {}

    /**
     * The per-operation availability report.
     *
     * @return array<string, array{
     *     label: string,
     *     state: string,
     *     available: bool,
     *     eligible: int,
     *     ready: int,
     *     independent_providers: int,
     *     preferred_model: ?string,
     *     last_success: ?string,
     *     retry_after_seconds: ?int,
     *     retry_after_max_seconds: ?int,
     *     reason: ?string
     * }>
     */
    public function report(): array
    {
        $report = [];

        foreach (self::OPERATIONS as $name => $operation) {
            $report[$name] = $this->evaluate($name, $operation);
        }

        return $report;
    }

    /**
     * The operations that are currently ready, keyed by name.
     *
     * @return list<string>
     */
    public function readyOperations(): array
    {
        return array_keys(array_filter(
            $this->report(),
            static fn (array $row): bool => $row['available'] === true,
        ));
    }

    /**
     * How many genuinely independent providers can serve private tool work now.
     *
     * Models on the same endpoint and credential are one provider, not several:
     * a shared account quota is one wall, not four chances.
     */
    public function independentPrivateProviders(): int
    {
        return $this->report()['internal_data']['independent_providers'] ?? 0;
    }

    /**
     * @param  array{capabilities: list<string>, requires_web_search?: bool, label: string}  $operation
     * @return array{
     *     label: string, state: string, available: bool, eligible: int, ready: int,
     *     independent_providers: int, preferred_model: ?string, last_success: ?string,
     *     retry_after_seconds: ?int, retry_after_max_seconds: ?int, reason: ?string
     * }
     */
    private function evaluate(string $name, array $operation): array
    {
        $candidates = $this->resolver->candidatesFor($operation['capabilities']);
        $eligible = count($candidates);

        // A tool-capable model is not a research service if the search backend is
        // switched off. Report that as blocked by configuration rather than as a
        // provider problem, because no amount of provider failover fixes it.
        if (($operation['requires_web_search'] ?? false) === true
            && ! (bool) config('ai.web_search.enabled', false)
        ) {
            return $this->row(
                $operation['label'],
                'blocked',
                $eligible,
                0,
                null,
                null,
                null,
                'La búsqueda web está desactivada en este entorno.',
            );
        }

        if ($eligible === 0) {
            return $this->row(
                $operation['label'],
                $this->resolver->pool() === [] ? 'unconfigured' : 'blocked',
                $eligible,
                0,
                null,
                null,
                null,
                $this->resolver->pool() === []
                    ? 'No hay ningún proveedor de inteligencia artificial configurado.'
                    : $this->missingCapabilityReason($operation['capabilities']),
            );
        }

        $ready = [];
        $rateLimited = 0;

        // The recovery time of each *account*, not of each model.
        //
        // Two models behind one credential share one quota, so the account
        // becomes usable again when its last blocked model does: taking the
        // minimum per model would promise a recovery that the account's own
        // wall does not allow.
        $blockedByAccount = [];

        foreach ($candidates as $candidate) {
            if ($this->resolver->blocked($candidate)) {
                $seconds = max(0, $this->resolver->blockedFor($candidate));
                $account = $candidate->endpointHash();

                $blockedByAccount[$account] = max($blockedByAccount[$account] ?? 0, $seconds);

                if ($this->health->endpointRateLimited($candidate)) {
                    $rateLimited++;
                }

                continue;
            }

            $ready[] = $candidate;
        }

        // "When will the assistant work again?" is answered by the *earliest*
        // account that comes back, not by the latest one. The worst case is
        // still reported, as the upper end of a range: with two independent
        // providers, one free in a minute and the other tomorrow, the truth is
        // a range and both ends of it are known.
        $timers = array_values(array_filter($blockedByAccount, static fn (int $seconds): bool => $seconds > 0));
        $earliestRetry = $timers === [] ? null : min($timers);
        $latestRetry = $timers === [] ? null : max($timers);

        if ($ready === []) {
            if ($rateLimited > 0) {
                return $this->row(
                    $operation['label'],
                    'quota_limited',
                    $eligible,
                    0,
                    null,
                    null,
                    $earliestRetry,
                    'La cuota de los proveedores elegibles está agotada temporalmente.',
                    0,
                    $latestRetry,
                );
            }

            return $this->row(
                $operation['label'],
                'unavailable',
                $eligible,
                0,
                null,
                null,
                $earliestRetry,
                'Todos los proveedores elegibles están temporalmente en espera.',
                0,
                $latestRetry,
            );
        }

        // A tool-bearing operation is only proven by observed tool use; a plain
        // operation is proven by a recent answer. See the class docblock.
        $requiresTools = in_array('tools', $operation['capabilities'], true);

        $proven = array_values(array_filter(
            $ready,
            fn (ModelCandidate $candidate): bool => $requiresTools
                ? $this->health->toolsDemonstrated($candidate)
                : $this->health->recentSuccess($candidate),
        ));

        $lastSuccess = $this->lastResultFor($ready);
        $providers = $this->independentProviderCount($ready);

        if ($proven !== []) {
            return $this->row(
                $operation['label'],
                $providers > 1 ? 'available' : 'degraded',
                $eligible,
                count($ready),
                $proven[0]->model,
                $lastSuccess,
                null,
                $providers > 1
                    ? null
                    : 'Un solo proveedor independiente puede atender esta operación.',
                $providers,
            );
        }

        // Configured and not blocked, but nothing recent proves it. For a plain
        // operation that means no recent answer; for a tool-bearing one it means
        // no observed tool call, even if chat turns are being answered. Either
        // way it is honest to call this "unverified", never "available".
        return $this->row(
            $operation['label'],
            'unverified',
            $eligible,
            count($ready),
            $ready[0]->model,
            $lastSuccess,
            null,
            $requiresTools
                ? 'Hay proveedores configurados, pero ninguno ha demostrado usar herramientas recientemente.'
                : 'Hay proveedores configurados, pero ninguno ha respondido recientemente.',
            $providers,
        );
    }

    /**
     * @param  list<ModelCandidate>  $candidates
     */
    private function independentProviderCount(array $candidates): int
    {
        $hosts = [];

        foreach ($candidates as $candidate) {
            $hosts[$candidate->providerHost()] = true;
        }

        return count($hosts);
    }

    /**
     * The model that most recently answered among the given candidates.
     *
     * @param  list<ModelCandidate>  $candidates
     */
    private function lastResultFor(array $candidates): ?string
    {
        $best = null;
        $bestAt = 0;

        foreach ($candidates as $candidate) {
            $snapshot = $this->health->snapshot($candidate);
            $at = (int) ($snapshot['last_ok_at'] ?? 0);

            if ($at > $bestAt) {
                $bestAt = $at;
                $best = $candidate->model;
            }
        }

        return $best;
    }

    /**
     * A safe explanation of why no candidate matches, without naming the pool.
     *
     * @param  list<string>  $required
     */
    private function missingCapabilityReason(array $required): string
    {
        if (in_array('client_data', $required, true)) {
            return 'No hay un proveedor autorizado para datos privados con las capacidades necesarias.';
        }

        if (in_array('tools', $required, true)) {
            return 'No hay un proveedor configurado que admita herramientas.';
        }

        return 'No hay un proveedor configurado para esta operación.';
    }

    /**
     * @return array{
     *     label: string, state: string, available: bool, eligible: int, ready: int,
     *     independent_providers: int, preferred_model: ?string, last_success: ?string,
     *     retry_after_seconds: ?int, retry_after_max_seconds: ?int, reason: ?string
     * }
     */
    private function row(
        string $label,
        string $state,
        int $eligible,
        int $ready,
        ?string $preferred,
        ?string $lastSuccess,
        ?int $retryAfter,
        ?string $reason,
        int $providers = 0,
        ?int $retryAfterMax = null,
    ): array {
        return [
            'label' => $label,
            'state' => $state,
            // "available" is reserved for a ready operation with an observed
            // answer and more than one independent provider. A single-provider
            // operation is reported as degraded, because it has no failover.
            'available' => in_array($state, ['available', 'degraded'], true) && $ready > 0,
            'eligible' => $eligible,
            'ready' => $ready,
            'independent_providers' => $providers,
            'preferred_model' => $preferred,
            'last_success' => $lastSuccess,
            // The earliest moment some eligible account is usable again.
            'retry_after_seconds' => $retryAfter,
            // The worst case, when the accounts recover at different times. It
            // is the other end of the same range, not a second promise.
            'retry_after_max_seconds' => $retryAfterMax,
            'reason' => $reason,
        ];
    }
}

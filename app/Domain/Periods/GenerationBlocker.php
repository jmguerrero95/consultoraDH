<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * Something that stops obligations being generated for a candidate.
 *
 * A blocker, never a warning. Every one of these means the system would have to
 * invent a number, a date or a fact in order to continue, and inventing those is
 * precisely what this module refuses to do.
 *
 * `MissingCutoffRule` in particular is the one that will appear the most in the
 * first months of use: nobody has configured the general rule yet. That is the
 * correct answer, and it is a configuration task rather than a default to be
 * guessed.
 */
final readonly class GenerationBlocker
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public string $code,
        public string $message,
        public array $context,
    ) {}

    /**
     * The same finding, reported as something to look at rather than something that
     * stopped the month.
     *
     * @return array<string, mixed>
     */
    public function asWarning(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function missingRate(int $clientId, int $companyId, string $periodKey, ?string $clientName, ?string $companyName): self
    {
        return new self(
            'missing_rate',
            sprintf(
                'No hay valor configurado para %s en %s con vigencia %s.',
                $clientName ?? "cliente #{$clientId}",
                $companyName ?? "empresa #{$companyId}",
                $periodKey,
            ),
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function missingCutoffRule(int $clientId, int $companyId, string $periodKey, ?string $clientName, ?string $companyName): self
    {
        return new self(
            'missing_cutoff_rule',
            sprintf(
                'No hay fecha de corte configurada que aplique a %s en %s para %s.',
                $clientName ?? "cliente #{$clientId}",
                $companyName ?? "empresa #{$companyId}",
                $periodKey,
            ),
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function inactiveReference(int $clientId, int $companyId, string $periodKey, string $detail): self
    {
        return new self(
            'inactive_or_invalid_reference',
            $detail,
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function duplicatePeriodObligation(int $clientId, int $companyId, string $periodKey, int $obligationId): self
    {
        return new self(
            'duplicate_period_obligation',
            sprintf(
                'Ya existe una obligación para el cliente %d y la empresa %d en %s.',
                $clientId,
                $companyId,
                $periodKey,
            ),
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
                'obligation_id' => $obligationId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    /**
     * Two relationship segments for one client and company cover the same days.
     *
     * This is deliberately **not** the old `ambiguous_relationship_state`, which fired
     * whenever a pair had more than one row in the month. That conflated two very
     * different situations:
     *
     *   * a client who worked for a company, left, and was rehired inside the month —
     *     ordinary history, two non-overlapping segments, **one** obligation;
     *   * two rows claiming the same days, which cannot both be true.
     *
     * Only the second is un-billable, because there is no honest answer to which
     * employment the debt belongs to. The first is handled by `BillingCandidate`, which
     * collapses it into a single candidate with full provenance.
     *
     * The message names the offending ids so an operator can look at the rows rather
     * than at a count.
     *
     * @param  list<int>  $assignmentIds
     */
    public static function overlappingRelationshipSegments(
        int $clientId,
        int $companyId,
        string $periodKey,
        array $assignmentIds,
    ): self {
        return new self(
            'overlapping_relationship_segments',
            sprintf(
                'El cliente %d tiene relaciones que se solapan con la empresa %d en %s, '
                .'así que no hay una única relación que justifique la obligación. '
                .'Relaciones implicadas: %s.',
                $clientId,
                $companyId,
                $periodKey,
                implode(', ', $assignmentIds),
            ),
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
                'assignment_ids' => $assignmentIds,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}

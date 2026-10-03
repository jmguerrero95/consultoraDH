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
    public static function ambiguousRelationshipState(int $clientId, int $companyId, string $periodKey, int $openRelationships): self
    {
        return new self(
            'ambiguous_relationship_state',
            sprintf(
                'El cliente %d tiene %d relaciones abiertas con la empresa %d, '
                .'así que no hay una única relación que justifique la obligación de %s.',
                $clientId,
                $openRelationships,
                $companyId,
                $periodKey,
            ),
            [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'period_key' => $periodKey,
                'open_relationships' => $openRelationships,
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

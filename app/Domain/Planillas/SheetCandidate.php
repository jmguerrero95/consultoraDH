<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

/**
 * One person's evidence for one month, as read once from A02 history.
 *
 * This is a value object, not a model: a planilla line is built from one of these and then never
 * re-derived, which is what lets a past month keep saying what it said.
 */
final readonly class SheetCandidate
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public int $clientId,
        public int $assignmentId,
        public string $documentType,
        public string $documentNumber,
        public string $clientName,
        public string $companyTaxId,
        public string $companyName,
        public string $relationshipStartedOn,
        public string $relationshipStartedOnPrecision,
        public ?string $relationshipEndedOn,
        public ?string $relationshipEndedOnPrecision,
        public ?string $epsName,
        public ?string $afpName,
        public ?string $arlName,
        public ?string $ccfName,
        public ?string $arlRiskClass,
        public ?string $jobTitle,
        public array $evidence,
    ) {}

    /** @return array<string, mixed> The columns a planilla line snapshots. */
    public function snapshot(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_company_assignment_id' => $this->assignmentId,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'client_name' => $this->clientName,
            'company_tax_id' => $this->companyTaxId,
            'company_name' => $this->companyName,
            'relationship_started_on' => $this->relationshipStartedOn,
            'relationship_started_on_precision' => $this->relationshipStartedOnPrecision,
            'relationship_ended_on' => $this->relationshipEndedOn,
            'relationship_ended_on_precision' => $this->relationshipEndedOnPrecision,
            'eps_name' => $this->epsName,
            'afp_name' => $this->afpName,
            'arl_name' => $this->arlName,
            'ccf_name' => $this->ccfName,
            'arl_risk_class' => $this->arlRiskClass,
            'job_title' => $this->jobTitle,
            'included' => true,
            'source_evidence' => $this->evidence,
        ];
    }
}

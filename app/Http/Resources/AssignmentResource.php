<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientCompanyAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One period a client worked for a company.
 *
 * @mixin ClientCompanyAssignment
 */
final class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientCompanyAssignment $assignment */
        $assignment = $this->resource;

        return [
            'id' => $assignment->id,
            'client_id' => $assignment->client_id,
            'company_id' => $assignment->company_id,
            'company' => $assignment->relationLoaded('company')
                ? new CompanySummaryResource($assignment->company)
                : null,
            // Present only where the screen is about the client rather than the
            // company: the company's own relationship list shows who works there.
            'client' => $assignment->relationLoaded('client') && $assignment->client !== null
                ? [
                    'id' => $assignment->client->id,
                    'full_name' => $assignment->client->fullName(),
                    // The company's own relationship list names people, so it has to
                    // be able to tell two with the same name apart: the document is
                    // what identifies a person here.
                    'document_type' => $assignment->client->document_type?->value,
                    'document_number' => $assignment->client->document_number,
                    'document_label' => $assignment->client->documentLabel(),
                ]
                : null,
            'started_on' => $assignment->started_on?->toDateString(),
            'ended_on' => $assignment->ended_on?->toDateString(),
            // How exact each end of the interval is. An imported relationship may
            // know only the month it started or ended, and the screen must not
            // present a month as if it were a day.
            'started_on_precision' => $assignment->started_on_precision,
            'ended_on_precision' => $assignment->ended_on_precision,
            'is_active' => $assignment->isActive(),
            'job_title' => $assignment->job_title,
            'notes' => $assignment->notes,
            // Exposed because the interface has to explain why a second
            // relationship is allowed to exist.
            'is_parallel' => $assignment->isAuthorisedParallel(),
            'parallel_reason' => $assignment->parallel_reason,
            'parallel_authorized_at' => $assignment->parallel_authorized_at?->toIso8601String(),
        ];
    }
}

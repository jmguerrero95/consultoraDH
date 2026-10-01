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
                ]
                : null,
            'started_on' => $assignment->started_on?->toDateString(),
            'ended_on' => $assignment->ended_on?->toDateString(),
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

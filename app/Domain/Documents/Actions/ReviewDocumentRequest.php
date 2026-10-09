<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\DocumentRequestStatus;
use App\Models\ClientDocumentRequest;
use App\Models\User;

final class ReviewDocumentRequest
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ClientDocumentRequest $request, string $decision, ?string $note, User $actor): ClientDocumentRequest
    {
        if ($request->status !== DocumentRequestStatus::Received) {
            throw DocumentNotApplicable::wrongState($request->status->value, DocumentRequestStatus::Reviewed->value);
        }

        $newStatus = $decision === 'approve' ? DocumentRequestStatus::Approved : DocumentRequestStatus::Rejected;

        if ($newStatus === DocumentRequestStatus::Rejected && trim((string) $note) === '') {
            throw DocumentNotApplicable::missingReason();
        }

        $request->forceFill([
            'status' => $newStatus->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'decision_note' => $note,
            'approved_at' => $newStatus === DocumentRequestStatus::Approved ? now() : null,
        ])->save();

        $this->audit->record(
            $newStatus === DocumentRequestStatus::Approved ? AuditAction::DocumentRequestApproved : AuditAction::DocumentRequestRejected,
            $actor,
            ['decision' => $decision, 'note' => $note],
            null,
            $request,
        );

        return $request;
    }
}

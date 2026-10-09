<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\DocumentRequestStatus;
use App\Models\ClientDocumentRequest;
use App\Models\User;

final class CancelDocumentRequest
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ClientDocumentRequest $request, string $reason, User $actor): ClientDocumentRequest
    {
        if ($request->status->isTerminal()) {
            throw DocumentNotApplicable::wrongState($request->status->value, DocumentRequestStatus::Cancelled->value);
        }

        if (trim($reason) === '') {
            throw DocumentNotApplicable::missingReason();
        }

        $request->forceFill([
            'status' => DocumentRequestStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'decision_note' => trim($reason),
        ])->save();

        $this->audit->record(AuditAction::DocumentRequestCancelled, $actor, ['reason' => trim($reason)], null, $request);

        return $request;
    }
}

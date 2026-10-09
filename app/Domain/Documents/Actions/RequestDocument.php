<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\DocumentRequestStatus;
use App\Models\ClientDocumentRequest;
use App\Models\User;

final class RequestDocument
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ClientDocumentRequest $request, User $actor): ClientDocumentRequest
    {
        if ($request->status !== DocumentRequestStatus::Requested) {
            throw DocumentNotApplicable::wrongState($request->status->value, DocumentRequestStatus::Received->value);
        }

        $request->forceFill([
            'status' => DocumentRequestStatus::Received->value,
            'received_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::DocumentRequestReceived, $actor, [], null, $request);

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Portal;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\Client;
use App\Models\ClientProfileUpdateRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProfileUpdateHandler
{
    private const ALLOWED_FIELDS = ['first_names', 'last_names', 'email', 'phone', 'address'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function submit(Client $client, array $changes, User $requester): ClientProfileUpdateRequest
    {
        $filtered = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));

        if ($filtered === []) {
            throw new \InvalidArgumentException('No hay cambios válidos para proponer.');
        }

        return ClientProfileUpdateRequest::query()->create([
            'client_id' => $client->id,
            'requested_by_user_id' => $requester->id,
            'proposed_changes' => $filtered,
            'status' => 'pending',
        ]);
    }

    public function approve(ClientProfileUpdateRequest $request, User $reviewer, ?string $note = null): ClientProfileUpdateRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note): ClientProfileUpdateRequest {
            $fresh = ClientProfileUpdateRequest::query()
                ->where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status !== ProfileUpdateRequestStatus::Pending) {
                throw new \RuntimeException('La solicitud ya no está pendiente.');
            }

            $client = Client::query()->findOrFail($fresh->client_id);

            foreach ($fresh->proposed_changes as $field => $value) {
                $client->setAttribute($field, $value);
            }
            $client->save();

            $fresh->forceFill([
                'status' => ProfileUpdateRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'applied_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::ClientProfileUpdateApplied, $reviewer, [
                'request_id' => $fresh->id,
                'client_id' => $client->id,
                'fields' => array_keys($fresh->proposed_changes),
            ], null, $client);

            return $fresh;
        });
    }

    public function reject(ClientProfileUpdateRequest $request, string $reason, User $reviewer): ClientProfileUpdateRequest
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Indique el motivo del rechazo.');
        }

        return DB::transaction(function () use ($request, $reason, $reviewer): ClientProfileUpdateRequest {
            $fresh = ClientProfileUpdateRequest::query()
                ->where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status !== ProfileUpdateRequestStatus::Pending) {
                throw new \RuntimeException('La solicitud ya no está pendiente.');
            }

            $fresh->forceFill([
                'status' => ProfileUpdateRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => trim($reason),
            ])->save();

            $this->audit->record(AuditAction::ClientProfileUpdateRejected, $reviewer, [
                'request_id' => $fresh->id,
                'reason' => trim($reason),
            ], null, $fresh->client);

            return $fresh;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Portal;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clients\Actions\UpdateClient;
use App\Models\Client;
use App\Models\ClientProfileUpdateRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The proposal a client makes about their own data, and the decision staff take on it.
 *
 * ## Why approval goes through A02's action
 *
 * This class used to apply the change with `setAttribute()` and `save()` directly on the
 * client. That wrote the master record correctly and skipped everything that makes a
 * client write legitimate: `UpdateClient`'s allow-list of editable fields, its
 * normalisation, and the `ClientUpdated` event that the audit layer listens to.
 *
 * A client field then had two write paths, one of which produced no audit event — so a
 * change approved by staff would leave no trail of who approved it. Now there is one path
 * to a client field, and it is A02's.
 *
 * ## Why the whole decision is one transaction
 *
 * The request row is claimed, the client is updated and the request is marked applied. If
 * A02 refuses — a field outside its allow-list, a normalisation failure, a duplicate
 * address — the exception propagates and the whole thing rolls back: the request stays
 * `pending`, `applied_at` stays null, and the master is untouched. A request that says
 * "approved" while the change was never written would be the worst outcome available,
 * because it looks like work that was done.
 */
final class ProfileUpdateHandler
{
    private const ALLOWED_FIELDS = ['first_names', 'last_names', 'email', 'phone', 'address'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly UpdateClient $updateClient,
    ) {}

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
            'status' => ProfileUpdateRequestStatus::Pending,
        ]);
    }

    public function approve(ClientProfileUpdateRequest $request, User $reviewer, ?string $note = null): ClientProfileUpdateRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $note): ClientProfileUpdateRequest {
            $fresh = ClientProfileUpdateRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status !== ProfileUpdateRequestStatus::Pending) {
                throw new \RuntimeException('La solicitud ya no está pendiente.');
            }

            $client = Client::query()->findOrFail($fresh->client_id);

            // A02's action, not a direct write. If it throws, everything above rolls back
            // and the request is still pending.
            $this->updateClient->execute($client, $fresh->proposed_changes, $reviewer);

            $fresh->forceFill([
                'status' => ProfileUpdateRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'applied_at' => now(),
            ])->save();

            // The A02 event already records the field-level change; this records the
            // decision about the proposal, which is a different fact.
            $this->audit->record(AuditAction::ClientProfileUpdateApplied, $reviewer, [
                'request_id' => (int) $fresh->id,
                'client_id' => (int) $client->id,
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
                ->whereKey($request->id)
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
                'request_id' => (int) $fresh->id,
                'reason' => trim($reason),
            ], null, $fresh->client);

            return $fresh;
        });
    }
}

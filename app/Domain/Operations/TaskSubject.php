<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\ClientDocumentRequest;
use App\Models\ClientNovelty;
use App\Models\ContributionSheet;

/**
 * The four task references are independent foreign keys in the schema. The
 * database guarantees that each row exists; it cannot guarantee that a client,
 * a novelty, a document request and a planilla describe the same client.
 *
 * A task is an operational instruction ("call this client about this
 * planilla"). If the client is A and the planilla belongs to a company that was
 * never assigned to A, the instruction is contradictory: the person who picks
 * it up will see two different clients and one of them is wrong. The row is
 * formally valid, which is why only the write path can refuse it.
 *
 * Global tasks are still allowed: an explicit client, a description, or a
 * single referenced object are all valid subjects.
 */
final class TaskSubject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertConsistent(array $data): void
    {
        $clientId = self::nullableInt($data['client_id'] ?? null);

        /** @var array<string, int> $references */
        $references = [];

        if (($noveltyId = self::nullableInt($data['novelty_id'] ?? null)) !== null) {
            $novelty = ClientNovelty::query()->find($noveltyId);

            if (! $novelty) {
                throw OperationNotApplicable::subjectNotFound('novedad');
            }

            $references['novedad'] = (int) $novelty->client_id;
        }

        if (($requestId = self::nullableInt($data['document_request_id'] ?? null)) !== null) {
            $request = ClientDocumentRequest::query()->find($requestId);

            if (! $request) {
                throw OperationNotApplicable::subjectNotFound('solicitud de documento');
            }

            $references['solicitud de documento'] = (int) $request->client_id;
        }

        $sheetId = self::nullableInt($data['contribution_sheet_id'] ?? null);

        if ($clientId !== null) {
            foreach ($references as $subject => $referencedClientId) {
                if ($referencedClientId !== $clientId) {
                    throw OperationNotApplicable::subjectClientMismatch($subject);
                }
            }

            if ($sheetId !== null && ! self::sheetBelongsToClient($sheetId, $clientId)) {
                throw OperationNotApplicable::subjectClientMismatch('planilla');
            }

            return;
        }

        $referencedClients = array_values(array_unique(array_values($references)));

        if (count($referencedClients) > 1) {
            throw OperationNotApplicable::subjectReferencesDisagree();
        }

        // A sheet has no client_id of its own: it belongs to a company, and the
        // company belongs to one or more clients over time. If the caller did
        // not state a client, every client named by another reference must have
        // that company in its history.
        if ($sheetId !== null) {
            foreach ($referencedClients as $referencedClientId) {
                if (! self::sheetBelongsToClient($sheetId, $referencedClientId)) {
                    throw OperationNotApplicable::subjectClientMismatch('planilla');
                }
            }
        }
    }

    private static function sheetBelongsToClient(int $sheetId, int $clientId): bool
    {
        return ContributionSheet::query()
            ->whereKey($sheetId)
            ->whereHas('company.assignments', static function ($query) use ($clientId): void {
                $query->where('client_id', $clientId);
            })
            ->exists();
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}

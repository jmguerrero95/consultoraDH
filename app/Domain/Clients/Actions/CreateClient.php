<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\DocumentNumber;
use App\Domain\Clients\DocumentType;
use App\Domain\Clients\Events\ClientCreated;
use App\Domain\Shared\RecordStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the one permanent record for a person.
 *
 * The uniqueness of the document is enforced by the database, not by a check
 * here. That is deliberate: an `exists` query followed by an insert is a race,
 * and two administrators saving the same person at the same moment would both
 * pass the check. The unique index does not negotiate, and the caller turns the
 * resulting violation into a validation message.
 */
final class CreateClient
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    public function execute(array $attributes, User $actor): Client
    {
        $documentType = DocumentType::from($attributes['document_type']);
        $documentNumber = DocumentNumber::normalise($attributes['document_number'], $documentType);

        return DB::transaction(function () use ($attributes, $actor, $documentType, $documentNumber): Client {
            $client = Client::query()->create([
                'document_type' => $documentType->value,
                'document_number' => $documentNumber,
                'first_names' => $attributes['first_names'],
                'last_names' => $attributes['last_names'],
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'address' => $attributes['address'] ?? null,
                'city' => $attributes['city'] ?? null,
                'department' => $attributes['department'] ?? null,
                // A new record is active unless a caller explicitly says otherwise,
                // and the only caller that does is the historical import.
                'status' => $attributes['status'] ?? RecordStatus::Active->value,
            ]);

            event(new ClientCreated($client, $actor));

            return $client;
        });
    }
}

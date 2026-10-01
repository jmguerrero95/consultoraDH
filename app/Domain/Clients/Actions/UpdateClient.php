<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Events\ClientUpdated;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edits a client's editable details.
 *
 * Two things are deliberately not editable here: the document identity and the
 * status. Changing an identity is a different decision, because it is what makes
 * the record unique, and the status has its own operations with their own rules
 * about open relationships. A general purpose "update" that could touch either
 * would eventually be used to do both by accident.
 *
 * Only the names of the fields that really changed reach the audit trail, never
 * their values.
 */
final class UpdateClient
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    public function execute(Client $client, array $attributes, User $actor): Client
    {
        $editable = [
            'first_names',
            'last_names',
            'email',
            'phone',
            'address',
            'city',
            'department',
        ];

        $changed = [];

        return DB::transaction(function () use ($client, $attributes, $actor, $editable, &$changed): Client {
            foreach ($editable as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $incoming = $attributes[$field];
                $incoming = $incoming === null || trim($incoming) === '' ? null : trim($incoming);
                $current = $client->{$field};

                if ($incoming === $current) {
                    continue;
                }

                $client->{$field} = $incoming;
                $changed[] = $field;
            }

            if ($changed !== []) {
                $client->save();

                event(new ClientUpdated($client, $actor, $changed));
            }

            return $client->refresh();
        });
    }
}

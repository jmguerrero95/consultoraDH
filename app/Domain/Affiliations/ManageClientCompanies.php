<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Affiliations\Events\RelationshipClosed;
use App\Domain\Affiliations\Events\RelationshipCreated;
use App\Domain\Affiliations\Events\RelationshipTransferred;
use App\Domain\Shared\LocksRow;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operations that change which companies a client works for.
 *
 * Three rules hold across all of them, and they are the reason this is a domain
 * service rather than something done inline in a controller:
 *
 *  1. History is never rewritten. `company_id` on an existing row is never
 *     updated, and a row is never deleted. A transfer closes one row and opens
 *     another inside one transaction.
 *
 *  2. A second open relationship is never created silently. The source data
 *     contains people listed at more than one company at the same time; some of
 *     that is real and some is an error, and the application cannot tell them
 *     apart. So the caller has to choose explicitly, and choosing "both" requires
 *     a written reason.
 *
 *  3. Everything that touches more than one row runs inside a transaction, so a
 *     failure cannot leave half a transfer behind.
 */
final class ManageClientCompanies
{
    use LocksRow;

    /** Refuse unless the client has no other open relationship. */
    public const RESOLUTION_ONLY_IF_NONE = 'only_if_none';

    /** Close the open relationship and open this one. */
    public const RESOLUTION_TRANSFER = 'transfer';

    /** Keep the open relationship and open this one, with a justification. */
    public const RESOLUTION_PARALLEL = 'parallel';

    /**
     * Close every open relationship, then open this one.
     *
     * Deliberately not one of the public resolutions in `LinkClientCompanyRequest`.
     * A value that can end every open relationship of a client does not belong in
     * a request that means "link this company": an operator who typed it, or who
     * found it in a payload, would get a result nobody explained. The explicit
     * operation that really wants this is deactivating the client, and that calls
     * `closeAllOpen()` below.
     */
    public const RESOLUTION_CLOSE_OTHERS = 'close_others';

    /**
     * Link a client to a company.
     *
     * When another relationship is already open, the caller must decide what to
     * do about it. `$resolution` is one of the three public choices:
     *
     *   'only_if_none'  fail unless no other relationship is open. This is the
     *                   default and the safe one.
     *   'transfer'      close the one open relationship on `$startedOn` and open
     *                   this one. Refused when more than one is open: see
     *                   `TransferSourceRequired`.
     *   'parallel'      keep the open relationship and open this one too, which
     *                   requires `$parallelReason`.
     */
    public function link(
        Client $client,
        Company $company,
        User $actor,
        \DateTimeInterface $startedOn,
        ?string $jobTitle = null,
        ?string $notes = null,
        string $resolution = self::RESOLUTION_ONLY_IF_NONE,
        ?string $parallelReason = null,
    ): ClientCompanyAssignment {
        if (! $client->isActive()) {
            throw new DomainException('No se puede vincular un cliente inactivo a una empresa.');
        }

        if (! $company->isActive()) {
            throw new DomainException('No se puede vincular un cliente a una empresa inactiva.');
        }

        return DB::transaction(function () use (
            $client, $company, $actor, $startedOn, $jobTitle, $notes,
            $resolution, $parallelReason
        ): ClientCompanyAssignment {
            // The client row is the lock, not the relationships. Two concurrent
            // first relationships both used to find zero rows, lock zero rows, and
            // both insert; there is nothing to contend on until the first row
            // exists, and by then both transactions are inside. Locking the master
            // row always contends, because it always exists.
            $this->lockClient($client);

            $open = $this->openAssignments($client);

            return match ($resolution) {
                self::RESOLUTION_ONLY_IF_NONE => $open->isEmpty()
                    ? $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false)
                    : throw ParallelRelationshipNotAllowed::forClient($client, $open),

                self::RESOLUTION_TRANSFER => $this->transferWithin($client, $open, $company, $actor, $startedOn, $jobTitle, $notes),

                self::RESOLUTION_PARALLEL => $this->open(
                    $client, $company, $actor, $startedOn, $jobTitle, $notes, true, $parallelReason
                ),

                default => throw new DomainException("Resolución desconocida: {$resolution}."),
            };
        });
    }

    /**
     * Take the write lock on the client row.
     *
     * Inside the caller's transaction, which is what makes the lock mean anything:
     * taken outside, it would be released before the work it was meant to serialise.
     */
    private function lockClient(Client $client): void
    {
        Client::query()->whereKey($client->id)->lockForUpdate()->first();
    }

    /**
     * Close an open relationship on a given date.
     *
     * The row is kept; only its end date is written. Closing one that is already
     * closed is refused rather than silently moving the date, because that would
     * let a late correction destroy a period that was already reported.
     */
    public function close(
        ClientCompanyAssignment $assignment,
        User $actor,
        \DateTimeInterface $endedOn,
        string $reason = 'Cierre manual',
    ): ClientCompanyAssignment {
        if (! $assignment->isActive()) {
            throw new DomainException('La relación ya estaba cerrada.');
        }

        if ($endedOn->format('Y-m-d') < $assignment->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha de cierre no puede ser anterior al inicio de la relación.');
        }

        DB::transaction(function () use ($assignment, $actor, $endedOn, $reason): void {
            $locked = $this->locked($assignment);

            $locked->forceFill(['ended_on' => $endedOn->format('Y-m-d')])->save();

            event(new RelationshipClosed($locked->refresh(), $actor, $reason));
        });

        return $assignment->refresh();
    }

    /**
     * Move a client from the company of an open relationship to another.
     *
     * The old row is closed and keeps its company. Nothing rewrites history, and
     * the two writes share one transaction.
     */
    public function transfer(
        ClientCompanyAssignment $current,
        Company $to,
        User $actor,
        \DateTimeInterface $effectiveOn,
        ?string $jobTitle = null,
        ?string $notes = null,
    ): ClientCompanyAssignment {
        if (! $current->isActive()) {
            throw new DomainException('Sólo se puede transferir desde una relación abierta.');
        }

        if (! $to->isActive()) {
            throw new DomainException('No se puede transferir a una empresa inactiva.');
        }

        if ($current->company_id === $to->id) {
            throw new DomainException('El cliente ya está vinculado a esa empresa.');
        }

        if ($effectiveOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
            throw new DomainException(
                'La fecha efectiva no puede ser anterior al inicio de la relación actual.'
            );
        }

        return DB::transaction(function () use ($current, $to, $actor, $effectiveOn, $jobTitle, $notes) {
            $closed = $this->locked($current);

            if (! $closed->isActive()) {
                throw new DomainException('La relación de origen ya no está abierta.');
            }

            $closed->forceFill(['ended_on' => $effectiveOn->format('Y-m-d')])->save();

            $opened = $this->createRow(
                $closed->client,
                $to,
                $effectiveOn,
                $jobTitle,
                $notes,
                parallel: false,
                parallelReason: null,
                // The transfer takes effect on the same day the previous one
                // ended, so the client is never shown as belonging to nobody for
                // a day and never to both for one.
                startedOnOverride: $effectiveOn->format('Y-m-d'),
            );

            event(new RelationshipTransferred(
                $closed->refresh(),
                $opened,
                $actor,
                $effectiveOn->format('Y-m-d'),
            ));

            return $opened;
        });
    }

    /**
     * The relationships still open for a client.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ClientCompanyAssignment>
     */
    public function openAssignments(Client $client)
    {
        return $client->companyAssignments()
            ->whereNull('ended_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * The open relationships, read under a write lock.
     *
     * The lock has to be applied to the query, not to the fetched collection.
     * Re-reading inside the transaction is what stops two operators adding a
     * relationship to the same client at the same moment from both succeeding:
     * the second one waits, and then sees the row the first one wrote.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ClientCompanyAssignment>
     */
    private function lockedOpenAssignments(Client $client)
    {
        return ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->whereNull('ended_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Decide what to do about the open relationships and open this one.
     *
     * @param  Collection<int, ClientCompanyAssignment>  $open
     */
    private function transferWithin(
        Client $client,
        $open,
        Company $company,
        User $actor,
        \DateTimeInterface $startedOn,
        ?string $jobTitle,
        ?string $notes,
    ): ClientCompanyAssignment {
        if ($open->isEmpty()) {
            return $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false);
        }

        // With more than one open relationship there is no safe default. The old
        // code took `$open->first()`, which closed whichever row happened to sort
        // first: with two legitimate parallel relationships that ends an
        // employment nobody asked to end.
        if ($open->count() > 1) {
            throw TransferSourceRequired::forClient($client, $open);
        }

        // A single open relationship is the case this flow is for.
        $current = $open->first();

        if ($startedOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha de inicio no puede ser anterior al inicio de la relación abierta.');
        }

        $current->forceFill(['ended_on' => $startedOn->format('Y-m-d')])->save();

        return $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false);
    }

    /**
     * Close every open relationship of a client on one date.
     *
     * Not reachable from the link endpoint on purpose. This is the operation
     * behind deactivating a client, where closing whatever is open is the whole
     * point, and it is named as such so nobody discovers it as a resolution string
     * in a request they thought meant something else.
     *
     * @return list<ClientCompanyAssignment> the rows it closed
     */
    public function closeAllOpen(
        Client $client,
        User $actor,
        \DateTimeInterface $endedOn,
        string $reason = 'Cierre por desactivación del cliente',
    ): array {
        $closed = [];

        foreach ($this->openAssignments($client) as $assignment) {
            $closed[] = $this->close($assignment, $actor, $endedOn, $reason);
        }

        return $closed;
    }

    private function open(
        Client $client,
        Company $company,
        User $actor,
        \DateTimeInterface $startedOn,
        ?string $jobTitle,
        ?string $notes,
        bool $parallel,
        ?string $parallelReason = null,
    ): ClientCompanyAssignment {
        $assignment = $this->createRow(
            $client,
            $company,
            $startedOn,
            $jobTitle,
            $notes,
            $parallel,
            $parallelReason,
        );

        event(new RelationshipCreated($assignment, $actor, $parallel));

        return $assignment;
    }

    private function createRow(
        Client $client,
        Company $company,
        \DateTimeInterface $startedOn,
        ?string $jobTitle,
        ?string $notes,
        bool $parallel,
        ?string $parallelReason,
        ?string $startedOnOverride = null,
    ): ClientCompanyAssignment {
        if ($parallel) {
            // Enforced here as well as by the database CHECK, because a clear
            // exception at the call site is worth more than a constraint error
            // surfacing as a 500.
            if ($parallelReason === null || trim($parallelReason) === '') {
                throw new DomainException(
                    'Una relación en paralelo exige una justificación.'
                );
            }
        }

        return ClientCompanyAssignment::query()->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'started_on' => $startedOnOverride ?? $startedOn->format('Y-m-d'),
            'ended_on' => null,
            'job_title' => $jobTitle,
            'notes' => $notes,
            'parallel_authorized_at' => $parallel ? now() : null,
            'parallel_reason' => $parallel ? trim($parallelReason) : null,
        ]);
    }
}

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

    /** Close every open relationship, then open this one. */
    public const RESOLUTION_CLOSE_OTHERS = 'close_others';

    use LocksRow;

    /**
     * Link a client to a company.
     *
     * When another relationship is already open, the caller must decide what to
     * do about it. `$resolution` is one of:
     *
     *   'only_if_none'  fail unless no other relationship is open. This is the
     *                   default and the safe one.
     *   'transfer'      close the open relationship on `$effectiveDate` and
     *                   open this one.
     *   'parallel'      keep the open relationship and open this one too, which
     *                   requires `$parallelReason`.
     *   'close_others'  close every open relationship on `$effectiveDate` and
     *                   open this one. Used when a client is deactivated.
     */
    public function link(
        Client $client,
        Company $company,
        User $actor,
        \DateTimeInterface $startedOn,
        ?string $jobTitle = null,
        ?string $notes = null,
        string $resolution = self::RESOLUTION_ONLY_IF_NONE,
        ?string $effectiveDate = null,
        ?string $parallelReason = null,
    ): ClientCompanyAssignment {
        if (! $client->isActive()) {
            throw new DomainException('No se puede vincular un cliente inactivo a una empresa.');
        }

        if (! $company->isActive()) {
            throw new DomainException('No se puede vincular un cliente a una empresa inactiva.');
        }

        $open = $this->openAssignments($client);

        return DB::transaction(function () use (
            $client, $company, $actor, $startedOn, $jobTitle, $notes,
            $resolution, $effectiveDate, $parallelReason, $open
        ): ClientCompanyAssignment {
            // Re-read inside the transaction, under a lock: a concurrent request
            // may have opened a relationship between the check above and this
            // write, and both would otherwise succeed.
            $open = $this->lockedOpenAssignments($client);

            return match ($resolution) {
                self::RESOLUTION_ONLY_IF_NONE => $open->isEmpty()
                    ? $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false)
                    : throw ParallelRelationshipNotAllowed::forClient($client, $open),

                self::RESOLUTION_TRANSFER => $this->transferWithin($client, $open, $company, $actor, $startedOn, $jobTitle, $notes),

                self::RESOLUTION_PARALLEL => $this->open(
                    $client, $company, $actor, $startedOn, $jobTitle, $notes, true, $parallelReason
                ),

                self::RESOLUTION_CLOSE_OTHERS => $this->closeOthersAndOpen(
                    $client, $open, $company, $actor, $startedOn, $jobTitle, $notes, $effectiveDate
                ),

                default => throw new DomainException("Resolución desconocida: {$resolution}."),
            };
        });
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

        // Only one can be closed: a transfer moves the client, it does not end
        // every employment a person has ever had.
        $current = $open->first();

        if ($startedOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha de inicio no puede ser anterior al inicio de la relación abierta.');
        }

        $current->forceFill(['ended_on' => $startedOn->format('Y-m-d')])->save();

        return $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false);
    }

    /**
     * @param  Collection<int, ClientCompanyAssignment>  $open
     */
    private function closeOthersAndOpen(
        Client $client,
        $open,
        Company $company,
        User $actor,
        \DateTimeInterface $startedOn,
        ?string $jobTitle,
        ?string $notes,
        ?string $effectiveDate,
    ): ClientCompanyAssignment {
        $endedOn = $effectiveDate ?? $startedOn->format('Y-m-d');

        foreach ($open as $assignment) {
            $this->close($assignment, $actor, new \DateTimeImmutable($endedOn), 'Cierre por desactivación del cliente');
        }

        return $this->open($client, $company, $actor, $startedOn, $jobTitle, $notes, false);
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

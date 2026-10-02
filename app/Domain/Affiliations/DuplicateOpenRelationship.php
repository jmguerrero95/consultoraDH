<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\Client;
use App\Models\Company;

/**
 * Two open relationships between the same client and the same company.
 *
 * One person cannot be employed twice by the same company on overlapping dates.
 * The application allowed it whenever the caller answered `parallel` to a
 * different company and named the target company that was already there, and the
 * consequence was invisible in every summary that mattered: a client showed one
 * company twice, a headcount counted the person twice, and the quality report had
 * no way to explain it because each row looked legitimate on its own.
 *
 * A *closed* relationship with the same company is a different matter entirely and
 * is allowed: that is a client who returned to an employer, which is ordinary and
 * is recorded as its own period. This is only about rows that are open at the same
 * time.
 *
 * It is refused in the domain before the insert, and by a partial unique index in
 * the database, because two concurrent requests both see nothing open and both
 * insert. Neither check is sufficient on its own: the domain rule gives the
 * operator a message, and the index is what actually holds the line.
 */
final class DuplicateOpenRelationship extends \RuntimeException
{
    private function __construct(
        public readonly Client $client,
        public readonly Company $company,
        public readonly int $openAssignmentId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forCompany(Client $client, Company $company, int $openAssignmentId): self
    {
        return new self(
            $client,
            $company,
            $openAssignmentId,
            sprintf(
                'El cliente ya tiene una relación abierta con esta empresa (relación #%d). '
                .'No se pueden abrir dos relaciones con la misma empresa al mismo tiempo: '
                .'si vuelve a la misma empresa después de cerrarla, regístrelo como una relación nueva.',
                $openAssignmentId,
            ),
        );
    }
}

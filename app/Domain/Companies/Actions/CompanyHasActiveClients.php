<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Models\Company;
use RuntimeException;

/**
 * A company cannot be deactivated while clients still have open relationships
 * with it.
 *
 * A conflict the caller has to resolve, with the count attached so the message
 * can say how many people are involved.
 */
final class CompanyHasActiveClients extends RuntimeException
{
    private function __construct(
        public readonly int $companyId,
        public readonly int $activeCount,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forCompany(Company $company, int $activeCount): self
    {
        return new self(
            $company->id,
            $activeCount,
            sprintf(
                'La empresa tiene %d cliente%s con relación abierta. '
                .'Cierre o transfiera esas relaciones antes de desactivarla.',
                $activeCount,
                $activeCount === 1 ? '' : 's',
            ),
        );
    }
}

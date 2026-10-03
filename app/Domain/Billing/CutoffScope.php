<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Which rules a cutoff rule can be attached to.
 *
 * The order of the cases is the resolution order, most specific last, and it is
 * written here once so that neither the resolver nor the interface has to restate
 * the hierarchy.
 *
 * ## Why a client exception also names a company
 *
 * A client may legitimately work for more than one company at the same time, so
 * "this client cuts on the 5th" is not a statement the system can act on: the same
 * person can have two different employers, and each may count the contribution on
 * its own date. The exception is therefore scoped to a client **within** a company,
 * which is what makes it unambiguous.
 *
 * The interface labels it "Excepción por cliente" because that is what an operator
 * recognises; it always requires the company as well.
 */
enum CutoffScope: string
{
    /** Applies when no company or client rule matches. */
    case General = 'general';

    /** Applies to every client of one company. */
    case Company = 'company';

    /** Applies to one client within one company. */
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Company => 'Por empresa',
            self::Client => 'Excepción por cliente',
        };
    }

    /** How specific this scope is. Higher wins. */
    public function specificity(): int
    {
        return match ($this) {
            self::General => 0,
            self::Company => 1,
            self::Client => 2,
        };
    }

    public function requiresCompany(): bool
    {
        return $this !== self::General;
    }

    public function requiresClient(): bool
    {
        return $this === self::Client;
    }

    /**
     * Validate the identifiers a rule of this scope may carry.
     *
     * Refuses the shapes that would make a rule silently unreachable: a client
     * exception with no company is ambiguous by construction, and a general rule
     * with a company would look like it applies to somebody.
     *
     * @throws \InvalidArgumentException
     */
    public function assertIdentifiers(int|string|null $companyId, int|string|null $clientId): void
    {
        if ($this->requiresCompany() && $companyId === null) {
            throw new \InvalidArgumentException(sprintf(
                'Una regla de alcance «%s» necesita la empresa a la que aplica.',
                $this->value,
            ));
        }

        if (! $this->requiresCompany() && $companyId !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Una regla de alcance «%s» no puede llevar empresa.',
                $this->value,
            ));
        }

        if ($this->requiresClient() && $clientId === null) {
            throw new \InvalidArgumentException(sprintf(
                'Una regla de alcance «%s» necesita el cliente al que aplica.',
                $this->value,
            ));
        }

        if (! $this->requiresClient() && $clientId !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Una regla de alcance «%s» no puede llevar cliente.',
                $this->value,
            ));
        }
    }
}

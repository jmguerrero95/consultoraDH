<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

final class OperationNotApplicable extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function wrongState(string $from, string $to): self
    {
        return new self('wrong_state', sprintf('No se puede pasar de «%s» a «%s».', $from, $to));
    }

    public static function missingReason(): self
    {
        return new self('missing_reason', 'Indique el motivo.');
    }

    public static function alreadyDone(): self
    {
        return new self('already_done', 'La tarea ya está completada.');
    }

    /** §R1: an internal task may not be assigned to a portal account. */
    public static function assigneeNotStaff(string $accountType): self
    {
        return new self(
            'assignee_not_staff',
            sprintf(
                'Una tarea interna no puede asignarse a una cuenta de tipo «%s». '
                .'Las tareas se asignan a cuentas del personal.',
                $accountType,
            ),
        );
    }

    /** §R1: a suspended account cannot take new work. */
    public static function assigneeNotActive(): self
    {
        return new self('assignee_not_active', 'La cuenta asignada no está activa.');
    }
}

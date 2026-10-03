<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * A reopen was attempted without a reason.
 *
 * Reopening withdraws a statement that a month is settled. Whoever does that has to
 * say why, because the reason is the first thing asked about a corrected month and
 * it cannot be reconstructed afterwards.
 */
final class ReopenReasonRequired extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Debe indicar el motivo por el que reabre el periodo. '
            .'El motivo queda registrado y no puede modificarse después.'
        );
    }
}

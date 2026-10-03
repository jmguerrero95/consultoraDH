<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * A reopen was attempted on a period that is already open.
 *
 * Refused rather than treated as a no-op, because an operator who asks to reopen an
 * open month has been told something they believe to be untrue, and answering "fine"
 * would leave them believing it.
 */
final class PeriodAlreadyOpen extends \RuntimeException
{
    public function __construct(string $label)
    {
        parent::__construct(sprintf('El periodo %s ya está abierto.', $label));
    }
}

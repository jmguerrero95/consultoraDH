<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use RuntimeException;

/**
 * A tool call that is refused.
 *
 * Two reasons arrive here and both are refusals rather than failures of the
 * assistant: the arguments do not describe anything usable, or the person asking
 * is not allowed to do this. Neither is retried and neither is passed on to the
 * model as though it had been attempted.
 */
final class AiToolRejected extends RuntimeException
{
}

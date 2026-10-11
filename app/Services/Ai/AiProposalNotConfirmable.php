<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A proposal that cannot be confirmed or rejected.
 *
 * Not somebody else's, already spent, or expired. It is raised before anything is
 * executed, so catching it means nothing happened.
 */
final class AiProposalNotConfirmable extends RuntimeException {}

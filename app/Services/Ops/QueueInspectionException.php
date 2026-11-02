<?php

declare(strict_types=1);

namespace App\Services\Ops;

use RuntimeException;

/**
 * The queue exists in configuration but its state could not be read.
 *
 * This is deliberately distinct from "the queue is empty": an empty queue is a
 * valid measurement of zero jobs, while a failed read is the absence of a
 * measurement. Collapsing both into `null` is what let a broken inspection look
 * like a healthy, empty queue.
 */
final class QueueInspectionException extends RuntimeException {}

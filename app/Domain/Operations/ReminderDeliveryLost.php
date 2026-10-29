<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use RuntimeException;

/**
 * The delivery reservation changed hands before its internal marker was written.
 *
 * `markInternal()` is the last local step of a reminder delivery. It updates the
 * task *only* while `reminder_delivery_token` still matches the caller's claim.
 * Zero rows means another worker took the stage over (or the reservation was
 * released), so this worker must not write the marker, must not send the Push,
 * and must not clear the other worker's reservation.
 *
 * Thrown inside the notification transaction, so the in-app notification that
 * was just created is rolled back with it.
 */
final class ReminderDeliveryLost extends RuntimeException
{
    public static function claimChanged(): self
    {
        // The token itself is not echoed: it is a capability, and this message
        // can reach a log.
        return new self('The reminder delivery reservation is no longer owned by this worker.');
    }
}

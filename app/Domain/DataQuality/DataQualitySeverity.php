<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * A problem found in a record, and how serious it is.
 *
 * The severity is what decides whether the record can be saved at all. The rule
 * that shapes the whole layer:
 *
 *   Some of these are errors and some are warnings, and the line between them is
 *   not about how untidy the data looks. It is about whether being wrong would
 *   make the system say something false about the past.
 *
 * A duplicated document number is an error, because two clients sharing an
 * identity means one of them is misfiled and every figure derived from them is
 * wrong. An active client with a company that has been deactivated is a warning:
 * the situation is unusual and probably needs attention, but the record is not
 * lying about anything.
 *
 * Nothing here exists to make historical data look clean. A value that is
 * uncertain is reported as uncertain.
 */
enum DataQualitySeverity: string
{
    /** Blocks the write: doing it would corrupt a fact. */
    case Error = 'error';

    /** Does not block: shown for attention, the record is still saved. */
    case Warning = 'warning';

    /** Informational: unusual, nothing to fix. */
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'Error',
            self::Warning => 'Advertencia',
            self::Notice => 'Aviso',
        };
    }

    /**
     * Whether a finding of this severity prevents the operation.
     */
    public function blocksWrite(): bool
    {
        return $this === self::Error;
    }
}

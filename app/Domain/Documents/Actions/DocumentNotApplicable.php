<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use RuntimeException;

final class DocumentNotApplicable extends RuntimeException
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

    public static function unsafeUpload(): self
    {
        return new self('unsafe_upload', 'El tipo de archivo no está permitido.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * One problem, found in one record.
 *
 * A value object rather than an exception: the inspector returns a list of these
 * and the caller decides what to do, which keeps "what is wrong" separate from
 * "is this allowed".
 */
final readonly class DataQualityFinding
{
    public function __construct(
        public DataQualityCode $code,
        public DataQualitySeverity $severity,
        public string $message,
        public ?string $suggestion = null,
    ) {}

    public static function make(
        DataQualityCode $code,
        DataQualitySeverity $severity,
        string $message,
        ?string $suggestion = null,
    ): self {
        return new self($code, $severity, $message, $suggestion);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'label' => $this->code->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'message' => $this->message,
            'suggestion' => $this->suggestion,
            'blocking' => $this->code->blocking(),
        ];
    }
}

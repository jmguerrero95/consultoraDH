<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

/**
 * The result of validating a planilla.
 *
 * ## Why errors and warnings are separate collections
 *
 * §22 is blunt about it: a missing AFP is not proven by anything in this repository to be a problem,
 * so declaring it an error would be A05 inventing a Colombian rule it was explicitly told not to
 * invent. Errors block `ready`; warnings are visible and do not. Collapsing them into one list would
 * force the validator to guess which is which, and a reviewer would see a sheet refused for a reason
 * the system cannot justify.
 *
 * @see ValidateContributionSheet
 */
final readonly class SheetValidation
{
    /**
     * @param  list<array{code: string, message: string, line_id: int|null}>  $errors
     * @param  list<array{code: string, message: string, line_id: int|null}>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'valid' => $this->passes(),
        ];
    }
}

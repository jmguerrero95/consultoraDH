<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * A period cannot be closed while something about it is unresolved.
 *
 * Closing a month says "this is what we billed", and it should be refused while
 * that claim is known to be wrong or incomplete. Each blocker is a different
 * reason, and they are reported together rather than one at a time: an operator
 * fixing a month needs the whole list, not the first thing the system noticed.
 *
 * The blockers are not arbitrary. "No obligations generated" would refuse every
 * genuinely empty month, so emptiness is not among them; what is refused is a month
 * that was never generated, because that is indistinguishable from a month somebody
 * forgot.
 */
final class ClosePeriodBlocked extends \RuntimeException
{
    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $blockers
     */
    private function __construct(
        public readonly array $blockers,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<array{code: string, message: string, context: array<string, mixed>}>  $blockers
     */
    public static function because(array $blockers): self
    {
        $summary = implode(' ', array_map(
            fn (array $blocker): string => $blocker['message'],
            $blockers,
        ));

        return new self($blockers, sprintf(
            'El periodo no puede cerrarse todavía. %s',
            $summary,
        ));
    }

    public static function notGenerated(string $periodLabel): self
    {
        return self::because([[
            'code' => 'generation_not_performed',
            'message' => sprintf(
                'No consta que se hayan generado las obligaciones de %s. '
                .'Genere las obligaciones, o genere las que falten, antes de cerrarlo.',
                $periodLabel,
            ),
            'context' => [],
        ]]);
    }

    public static function alreadyClosed(string $periodLabel): self
    {
        return self::because([[
            'code' => 'already_closed',
            'message' => sprintf('El periodo %s ya está cerrado.', $periodLabel),
            'context' => [],
        ]]);
    }

    /**
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    public function blockerCodes(): array
    {
        return array_column($this->blockers, 'code');
    }
}

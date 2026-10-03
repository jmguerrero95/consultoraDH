<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Models\MonthlyPeriod;

/**
 * Generation refused: at least one candidate cannot be produced.
 *
 * Carries the whole blocker list rather than the first one, because an operator
 * fixing a month needs the complete set. Reporting "no rate configured" one client
 * at a time, for two hundred clients, is a day of clicking.
 *
 * Nothing was written: the exception is raised inside the transaction, so it rolls
 * back. That is the whole point of computing the blockers before inserting rather
 * than catching a violation halfway through.
 */
final class GenerationBlocked extends \RuntimeException
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
    public static function withBlockers(array $blockers, MonthlyPeriod $period): self
    {
        $count = count($blockers);

        return new self($blockers, sprintf(
            'No se generaron las obligaciones de %s: hay %d problema(s) de configuración por resolver. '
            .'No se escribió nada.',
            $period->label(),
            $count,
        ));
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->blockers, 'code');
    }

    /**
     * The distinct codes present, for a compact message.
     *
     * @return list<string>
     */
    public function distinctCodes(): array
    {
        return array_values(array_unique($this->codes()));
    }
}

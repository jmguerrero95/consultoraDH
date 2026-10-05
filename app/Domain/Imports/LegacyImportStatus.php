<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Where an import is in its life, and — more importantly — which way it may move next.
 *
 * The states are not a status light. Each one answers a different question about who may
 * touch the batch, and the transitions between them are the guarantee that a workbook
 * cannot be applied twice or dragged backwards from `applied` into review:
 *
 *   uploaded    the file is on disk and nothing has read it yet
 *   queued      a parse job is waiting
 *   parsing     a parse job is running
 *   review      read, staged, and waiting for a human to resolve issues
 *   ready       no blocking issue remains and a plan exists
 *   applying    an apply job holds the batch and is writing
 *   applied     written; terminal
 *   failed      something went wrong; recoverable, and never silently resumable
 *   cancelled   abandoned by a person; terminal
 *
 * `isTerminal()` exists because the three places that must refuse a second attempt —
 * `ApplyLegacyImport`, the job and the endpoint — all ask the same question, and three
 * copies of the same list of states would drift.
 */
enum LegacyImportStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Parsing = 'parsing';
    case Review = 'review';
    case Ready = 'ready';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Subido',
            self::Queued => 'En cola',
            self::Parsing => 'Analizando',
            self::Review => 'En revisión',
            self::Ready => 'Listo para aplicar',
            self::Applying => 'Aplicando',
            self::Applied => 'Aplicado',
            self::Failed => 'Falló',
            self::Cancelled => 'Cancelado',
        };
    }

    /** Whether the batch can still move. `applied` and `cancelled` cannot. */
    public function isTerminal(): bool
    {
        return $this === self::Applied || $this === self::Cancelled;
    }

    /** Whether a person is expected to do something about it. */
    public function needsReview(): bool
    {
        return $this === self::Review || $this === self::Failed;
    }

    /**
     * Whether `apply` may start from here.
     *
     * `ready` only, and the list is here rather than at each call site because the three
     * callers are an endpoint, a job and a test, and an allow-list that exists in three
     * places is an allow-list that will disagree in one of them.
     */
    public function allowsApply(): bool
    {
        return $this === self::Ready;
    }

    /**
     * The states reachable from here.
     *
     * Deliberately not a total order. `applied` and `cancelled` have no successors, which
     * is what makes "cannot be dragged back into review" a property of the enum rather
     * than of the code that happens to check for it today.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Uploaded => [self::Queued, self::Cancelled, self::Failed],
            self::Queued => [self::Parsing, self::Failed, self::Cancelled],
            self::Parsing => [self::Review, self::Failed],
            self::Review => [self::Ready, self::Failed, self::Cancelled],
            self::Ready => [self::Applying, self::Review, self::Failed, self::Cancelled],
            self::Applying => [self::Applied, self::Failed],
            self::Applied, self::Cancelled => [],
            self::Failed => [self::Review, self::Cancelled],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports\Exceptions;

/**
 * §12.3's refusal to finalise: some actions did not execute, so the batch cannot be `applied`.
 *
 * ## Why this is not the same as `UnusableImportAction`
 *
 * `UnusableImportAction` is *per action*: one payload that does not say what its action needs.
 * This is *per batch*: the walk finished and some of its actions are still outstanding.
 *
 * The distinction matters because the transaction rolls back in both cases — §12.3's "ningún
 * maestro queda parcialmente escrito" — but the operator's next step is different. A per-action
 * failure names one row and one field. This names how many actions were stranded and why, so the
 * reviewer knows whether to fix one bad row or look at a plan that was wrong about the database.
 *
 * ## The message is sanitised by construction
 *
 * It carries action types, natural keys and reason codes. A natural key is a plan row's own
 * address (`company:900123456`, `rate:CC:12345678:2026-01`) and is already on the review
 * screen; a reason is one of the closed strings in `UnusableImportAction`. Neither can carry a
 * document number, a person's name or a cell from the workbook, which is what §4.3 requires of
 * anything an operator reads.
 */
final class ImportApplyFailed extends \RuntimeException
{
    /**
     * @param  list<array{type: string, key: string, reason: string}>  $stranded
     */
    private function __construct(string $message, public readonly array $stranded)
    {
        parent::__construct($message);
    }

    /** @param list<array{type: string, key: string, reason: string}> $stranded */
    public static function strandedActions(array $stranded): self
    {
        $shown = array_slice($stranded, 0, 5);
        $summary = implode('; ', array_map(
            static fn (array $item): string => sprintf('%s %s (%s)', $item['type'], $item['key'], $item['reason']),
            $shown,
        ));

        $more = count($stranded) > count($shown)
            ? sprintf(' y %d más', count($stranded) - count($shown))
            : '';

        return new self(
            sprintf(
                'No se aplicaron %d de las acciones del plan%s: %s. Nada quedó escrito a medias.',
                count($stranded),
                $more,
                $summary,
            ),
            $stranded,
        );
    }
}

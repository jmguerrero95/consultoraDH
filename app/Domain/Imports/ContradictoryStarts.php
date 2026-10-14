<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One employment whose start date the source contradicts against itself.
 *
 * ## What the contradiction is
 *
 * §8.1 groups rows into episodes by `person + employer + start date`, and every episode carries
 * the months in which that person appeared at that employer. When a file states the same start in
 * every month, the two orderings agree: sorting the episodes by their start and sorting them by
 * the month they were first seen give the same sequence.
 *
 * When they **disagree**, the file has said two things that cannot both be true. The delivered
 * workbook contains one person at one employer across four months with three start dates, and
 * this is the shape:
 *
 * ```text
 *  observed in 2026-06, 2026-07   states start 2026-06-18
 *  observed in 2026-09           states start 2026-09-28
 *  observed in 2026-10           states start 2026-07-28   <-- earlier than the month before
 * ```
 *
 * The third episode was seen **after** the second and starts **before** it. Either the person
 * changed employers without the file saying so, or one of the three dates is a typing slip. Both
 * readings are plausible from the file alone and neither can be chosen from it.
 *
 * ## Why this was invisible, and why it is now a question rather than a refusal
 *
 * §8.5's `findOverlaps()` deliberately skips two episodes at the *same* employer, on the correct
 * reasoning that the shape is two episodes of one employment and not a parallel. That reasoning is
 * right, and it is why nothing ever asked. The consequence was that the contradictory episodes
 * reached `apply` as two open relationships with one employer, A02 refused the second with
 * "ya tiene una relación abierta con esta empresa", and §12.3 rolled back the whole batch — a
 * refusal naming a rule the reviewer had never been asked about.
 *
 * §8.4 cannot ask either: it asks about an episode that **stops appearing**, and the episode with
 * the contradictory date is seen again in the file's last month.
 *
 * So the question belongs here, and it is a question about **which stated date is the real one**.
 * Every answer available is a date the file itself wrote. Nothing is invented, and an unanswered
 * group keeps its episodes exactly as they are — which is what makes the batch stay blocked rather
 * than silently applying one of three possible histories.
 */
final readonly class ContradictoryStarts implements \JsonSerializable
{
    /**
     * @param  list<RelationshipEpisode>  $episodes  ordered by the month they were first seen
     */
    public function __construct(
        public string $clientKey,
        public ?string $documentType,
        public ?string $documentNumber,
        public ?string $companyTaxId,
        public ?string $companyName,
        public array $episodes,
    ) {}

    /**
     * §5.3's "one question per subject", and the subject is the **employment**, not the cell.
     *
     * Keyed on the client and the employer alone: the three contradicting dates are one question
     * ("when did this person start at this employer?"), and three dialogs would make a reviewer
     * answer the same question three times and produce three different answers.
     *
     * Deliberately **not** carrying the dates. A correction that changes which date is believed
     * must produce a *new* finding rather than overwrite an answered one, and `IssueSubject::
     * overlap()` makes the same argument for the same reason.
     */
    public function subjectKey(): string
    {
        return 'contradictory_starts:'.$this->clientKey.'|'.($this->companyTaxId ?? 'no-nit');
    }

    /**
     * Every date the file stated, earliest first, deduplicated.
     *
     * This is the whole set of answers available. A resolution must name one of these, and
     * nothing outside them can be chosen — which is what keeps "pick a date" from becoming
     * "type a date".
     *
     * @return list<string>
     */
    public function statedStarts(): array
    {
        $starts = [];

        foreach ($this->episodes as $episode) {
            $start = $episode->interval->start?->format('Y-m-d');

            if ($start !== null && ! in_array($start, $starts, true)) {
                $starts[] = $start;
            }
        }

        sort($starts);

        return $starts;
    }

    /** The first month in which this person appeared at this employer. */
    public function firstMonth(): ?string
    {
        $first = $this->episodes[0]->months ?? [];

        return $first === [] ? null : min($first);
    }

    /** The last month in which this person appeared at this employer. */
    public function lastMonth(): ?string
    {
        $last = [];

        foreach ($this->episodes as $episode) {
            $last = array_merge($last, $episode->months);
        }

        return $last === [] ? null : max($last);
    }

    /** @return list<int> */
    public function sourceRowIds(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(
                static fn (RelationshipEpisode $episode): array => $episode->sourceRowIds,
                $this->episodes,
            )
        )));
    }

    /**
     * A sentence naming each episode and the date it states.
     *
     * Shown to the reviewer in the finding's message, so the question says which three dates are
     * on the table rather than asking a person to go and find them. No personal data: the
     * identity is the row's own subject, and every date came from the file.
     */
    public function explanation(): string
    {
        $lines = [];

        foreach ($this->episodes as $episode) {
            $lines[] = sprintf(
                'meses %s dice inicio %s',
                implode(', ', $episode->months),
                $episode->interval->start?->format('Y-m-d') ?? 'desconocido',
            );
        }

        return 'El archivo declara '.count($this->statedStarts())
            .' fechas de inicio distintas para el mismo cliente en la misma empresa ('
            .implode('; ', $lines)
            .'), y una de ellas es anterior a un mes posterior. Elija cuál es la fecha real.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->clientKey,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'company_tax_id' => $this->companyTaxId,
            'company_name' => $this->companyName,
            'stated_starts' => $this->statedStarts(),
            'first_month' => $this->firstMonth(),
            'last_month' => $this->lastMonth(),
            'episodes' => array_map(
                static fn (RelationshipEpisode $episode): array => [
                    'episode_key' => $episode->key(),
                    'start' => $episode->interval->start?->format('Y-m-d'),
                    'months' => $episode->months,
                ],
                $this->episodes,
            ),
            'source_row_ids' => $this->sourceRowIds(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Splits extracted text into pieces small enough to be retrieved precisely.
 *
 * ## Why chunks overlap
 *
 * A fixed split cuts mid-sentence. The sentence that mattered can then be split
 * across two chunks, and neither half answers the question on its own — the
 * retrieval finds something close and the model is handed a fragment. Carrying
 * the tail of the previous chunk into the next one costs a little duplicated
 * text and removes that failure.
 *
 * ## Why the split prefers boundaries
 *
 * Splitting on paragraph and sentence boundaries keeps chunks that a person
 * would recognise as units of meaning, which matters more than perfectly even
 * sizes: a chunk that ends at a paragraph is quotable, one that ends mid-word
 * is not.
 */
final class DocumentChunker
{
    private const SIZE = 1200;

    private const OVERLAP = 150;

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = $this->normalise($text);

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $start = 0;
        $length = mb_strlen($text);

        while ($start < $length) {
            $end = min($start + self::SIZE, $length);

            // Prefer the last natural boundary inside the window, so the chunk
            // ends somewhere meaningful rather than at an arbitrary offset.
            $candidate = $this->lastBoundary($text, $start, $end);

            if ($candidate > $start + (self::SIZE / 2)) {
                $end = $candidate;
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($end >= $length) {
                break;
            }

            // Step back by the overlap so a boundary statement survives the
            // cut. Guarded so a short chunk cannot loop forever.
            $next = $end - self::OVERLAP;

            $start = $next > $start ? $next : $end;
        }

        return $chunks;
    }

    /**
     * Collapse the whitespace an extracted document carries without
     * destroying its structure.
     *
     * Line endings are unified and runs of spaces are squeezed, but paragraph
     * breaks are kept: they are what the boundary search looks for, and
     * flattening them would turn a document into one undifferentiated run.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The last paragraph or sentence boundary inside the window.
     */
    private function lastBoundary(string $text, int $start, int $end): int
    {
        $window = mb_substr($text, $start, $end - $start);

        foreach (["\n\n", "\n", '. ', '? ', '! '] as $separator) {
            $position = mb_strrpos($window, $separator);

            if ($position !== false) {
                return $start + $position + mb_strlen($separator);
            }
        }

        return $end;
    }
}

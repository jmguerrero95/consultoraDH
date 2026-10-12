<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\AiAttachment;
use App\Models\AiAttachmentChunk;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finds the passages of a person's own documents that bear on a question.
 *
 * ## Scope is applied in the query, not after it
 *
 * Every statement here joins through `ai_attachments` and filters on the owner
 * and the conversation. Retrieving first and filtering afterwards would be a
 * leak with extra steps: the rows would exist in the process, in a debug log,
 * and in anything that read them before the check ran.
 *
 * ## Bounded on purpose
 *
 * A document can be enormous, so three limits apply together: only documents
 * in scope are searched, only a fixed number of passages come back, and each
 * passage is truncated. That is what keeps a question about a file from
 * turning into a context window full of someone's payroll.
 *
 * ## Two tiers of relevance
 *
 * `websearch_to_tsquery` joins the words with AND, which is right for a keyword
 * and useless for a question: "¿cuánto debe ACME?" becomes
 * `'cuant' & 'deb' & 'acme'`, and the passage that plainly answers it
 * contains only the last term. Matching everything means searching a document
 * with a question returns nothing, which reads as "your documents do not
 * mention it" — the most confident wrong answer available.
 *
 * So the strict query runs first and only a genuinely empty result falls back
 * to matching *any* term. Precision is kept whenever it exists; the fallback
 * only rescues questions that would otherwise find nothing at all.
 */
final class DocumentRetriever
{
    /** Passages returned per question. */
    private const MAX_PASSAGES = 6;

    /** Characters returned per passage. */
    private const PASSAGE_CHARS = 1200;

    /**
     * Words that carry no meaning in a document.
     *
     * Spanish question words are excluded from the loose tier because they
     * appear in no spreadsheet and would dilute the ranking toward whatever
     * passage happens to share a common word with the sentence.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'cuanto', 'cuanta', 'cuantos', 'cuantas', 'cuanto', 'debe', 'deben', 'debo',
        'tiene', 'tienen', 'hay', 'que', 'cual', 'cuales', 'donde', 'quien',
        'por', 'para', 'con', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas',
        'son', 'esta', 'este', 'estas', 'estos', 'sobre', 'entre', 'segun',
    ];

    public function search(User $user, string $query, ?int $conversationId = null, ?int $attachmentId = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['passages' => [], 'attachments_considered' => 0];
        }

        $attachments = $this->scopedAttachmentsQuery($user, $conversationId, $attachmentId);
        $attachmentIds = $attachments->pluck('id');

        if ($attachmentIds->isEmpty()) {
            return ['passages' => [], 'attachments_considered' => 0];
        }

        $ids = $attachmentIds->map(static fn ($id): int => (int) $id)->all();

        $rows = $this->ranked($ids, 'websearch_to_tsquery', $query, $user->id, $conversationId);

        if ($rows === []) {
            $rows = $this->ranked($ids, 'to_tsquery', $this->looseTerms($query), $user->id, $conversationId);
        }

        return $this->shape($rows, $attachmentIds->count());
    }

    /**
     * One ranking pass.
     *
     * Uses the query builder with explicit parameter binding to avoid
     * PDO positional parameter confusion.
     */
    private function ranked(array $ids, string $matcher, string $term, int $userId, ?int $conversationId): array
    {
        if (trim($term) === '') {
            return [];
        }

        $query = DB::table('ai_attachment_chunks as c')
            ->join('ai_attachments as a', 'a.id', '=', 'c.ai_attachment_id')
            ->where('a.user_id', $userId)
            ->where('a.status', AiAttachment::STATUS_PROCESSED)
            ->when($conversationId === null, function ($q) {
                $q->whereNull('a.ai_conversation_id');
            }, function ($q) use ($conversationId) {
                $q->where('a.ai_conversation_id', $conversationId);
            })
            ->whereIn('c.ai_attachment_id', $ids)
            ->select([
                'c.id as chunk_id',
                'c.ordinal',
                'c.locator',
                'c.content',
                'a.id as attachment_id',
                'a.original_name',
                'a.extension',
            ])
            ->orderByDesc('rank')
            ->orderBy('a.original_name')
            ->orderBy('c.ordinal')
            ->limit(self::MAX_PASSAGES);

        if ($matcher === 'to_tsquery') {
            $tsquery = implode(' | ', array_map(
                static fn ($w): string => "'".$w."'",
                explode(' ', $term),
            ));
            $query->addSelect(DB::raw("ts_rank(c.search_vector, to_tsquery('consultora_es', ?)) as rank", [$tsquery]))
                  ->whereRaw("c.search_vector @@ to_tsquery('consultora_es', ?)", [$tsquery]);
        } else {
            $query->addSelect(DB::raw("ts_rank(c.search_vector, websearch_to_tsquery('consultora_es', ?)) as rank", [$term]))
                  ->whereRaw("c.search_vector @@ websearch_to_tsquery('consultora_es', ?)", [$term]);
        }

        return $query->get()->all();
    }

    /**
     * The meaningful words of a question, as a space-separated phrase.
     *
     * Reduced to letters and digits: this string becomes part of a `tsquery`
     * expression, where a quote or a colon is syntax rather than text.
     */
    private function looseTerms(string $query): string
    {
        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $meaningful = array_filter(
            array_map(static fn (string $w): string => preg_replace('/[^a-z0-9]/u', '', $w) ?? '', $words),
            static fn (string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::STOP_WORDS, true),
        );

        return implode(' ', array_slice(array_values(array_unique($meaningful)), 0, 8));
    }

    /**
     * @param  list<object>  $rows
     * @return array{passages: list<array<string, mixed>>, attachments_considered: int}
     */
    private function shape(array $rows, int $considered): array
    {
        $passages = [];

        foreach ($rows as $row) {
            $passages[] = [
                'attachment_id' => (int) $row->attachment_id,
                'file' => $row->original_name,
                'locator' => $row->locator,
                'chunk' => (int) $row->ordinal,
                'rank' => round((float) $row->rank, 4),
                'content' => mb_substr((string) $row->content, 0, self::PASSAGE_CHARS),
            ];
        }

        return ['passages' => $passages, 'attachments_considered' => $considered];
    }

    /**
     * The documents a question may be answered from.
     *
     * Only readable ones: asking about a scanned PDF with no text layer would
     * return nothing anyway, and including it would report an empty result as
     * "the documents do not mention it" rather than "this file could not be read".
     *
     * @return \Illuminate\Support\Collection<int, AiAttachment>
     */
    private function scopedAttachmentsQuery(User $user, ?int $conversationId, ?int $attachmentId)
    {
        return AiAttachment::query()
            ->where('user_id', $user->id)
            ->where('status', AiAttachment::STATUS_PROCESSED)
            ->when(
                $conversationId !== null,
                fn ($query) => $query->where('ai_conversation_id', $conversationId),
            )
            ->when(
                $attachmentId !== null,
                fn ($query) => $query->where('id', $attachmentId),
            )
            ->get(['id', 'original_name', 'extension']);
    }

    /**
     * The full text of one document, bounded.
     *
     * Used for "summarise this file", where the answer should reflect the
     * document rather than the passages that happen to match a query.
     *
     * @return array<string, mixed>|null
     */
    public function wholeDocument(User $user, int $attachmentId, ?int $conversationId = null): ?array
    {
        $attachment = AiAttachment::query()
            ->where('user_id', $user->id)
            ->when(
                $conversationId !== null,
                fn ($query) => $query->where('ai_conversation_id', $conversationId),
            )
            ->find($attachmentId);

        if ($attachment === null || ! $attachment->isReadable()) {
            return null;
        }

        $chunks = AiAttachmentChunk::query()
            ->where('ai_attachment_id', $attachment->id)
            ->orderBy('ordinal')
            ->limit(40)
            ->get(['ordinal', 'locator', 'content']);

        if ($chunks->isEmpty()) {
            return null;
        }

        return [
            'attachment' => $attachment,
            'content' => $chunks->map(static fn (AiAttachmentChunk $c): string => $c->content)->implode("\n\n"),
            'truncated' => $attachment->chunk_count > $chunks->count(),
        ];
    }

    /**
     * Everything readable in a conversation, for a cross-document question.
     *
     * @return list<array<string, mixed>>
     */
    public function listReadable(User $user, ?int $conversationId = null): array
    {
        return $this->scopedAttachmentsQuery($user, $conversationId, null)
            ->map(static fn (AiAttachment $a): array => [
                'id' => $a->id,
                'name' => $a->original_name,
                'extension' => $a->extension,
            ])
            ->all();
    }
}
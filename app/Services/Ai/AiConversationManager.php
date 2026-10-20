<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiArtifact;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use Illuminate\Support\Facades\DB;

/**
 * Renaming, titling and deleting an assistant conversation.
 *
 * ## Why deletion is not a cascade
 *
 * The foreign keys already remove messages, proposals and attachments when the
 * conversation goes, but two things are *not* cascaded on purpose and must be
 * handled here:
 *
 *   - an artifact's `conversation_id` is `nullOnDelete`, because a file in a
 *     background task must outlive the thread that asked for it. Deleting a
 *     conversation therefore has to decide about the files explicitly, and it
 *     does: the files generated *in this thread* are removed from disk and from
 *     the table, so a deleted conversation does not leave orphaned documents.
 *   - the operational tasks a confirmed proposal created are not linked to the
 *     conversation at all. Deleting a thread can therefore never delete a task,
 *     an obligation or a client record — the guarantee the product requires.
 *
 * ## Titles
 *
 * A conversation keeps a human title derived from its first question, so the
 * list reads "Análisis de cartera" rather than "Conversación 2026-10-19 12:17".
 * Derivation is deterministic and costs no provider call. A title the person
 * edited is never overwritten.
 */
final class AiConversationManager
{
    /** The longest title the list stores and shows. */
    public const TITLE_LIMIT = 80;

    public function __construct(private readonly AiArtifactCleaner $cleaner) {}

    /**
     * The placeholder title a freshly opened conversation carries.
     */
    public function placeholderTitle(): string
    {
        return 'Conversación '.now()->format('Y-m-d H:i');
    }

    /**
     * Whether a title is still the automatic placeholder rather than something a
     * person chose.
     */
    public function isAutoTitle(?string $title): bool
    {
        if ($title === null || trim($title) === '') {
            return true;
        }

        return (bool) preg_match('/^Conversación \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/u', $title)
            || (bool) preg_match('/^Conversación MCP \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/u', $title);
    }

    /**
     * A short, readable title derived from the first question.
     *
     * Deterministic: no model is consulted to name a conversation. Personal
     * identifiers are redacted so a title in a list cannot expose an email or a
     * document number, and the text is cut at the first sentence within the
     * limit.
     */
    public function titleFromQuestion(string $question): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($question)) ?? trim($question);

        // Drop a polite opening or a framing verb, which says nothing about the
        // subject: "Necesito que me expliques la mora" is about the mora.
        $text = preg_replace(
            '/^(?:hola|buenas|buenos d[ií]as|buenas tardes|buenas noches|por favor|necesito que|quiero que|podr[ií]as|podrias|puedes|me gustar[ií]a que|ay[uú]dame a|expl[ií]came|dime|dame)\s+/iu',
            '',
            $text,
        ) ?? $text;

        // Redact an email or a long digit run before it becomes a title.
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '…', $text) ?? $text;
        $text = preg_replace('/\b\d{6,}\b/u', '…', $text) ?? $text;

        // First sentence only.
        $parts = preg_split('/[\.\?\!;\n:]/u', $text) ?: [$text];
        $text = trim($parts[0] ?? '');

        if ($text === '') {
            return 'Nueva consulta';
        }

        if (mb_strlen($text) > self::TITLE_LIMIT) {
            $text = rtrim(mb_substr($text, 0, self::TITLE_LIMIT - 1)).'…';
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Give a conversation its derived title if it still carries the placeholder.
     */
    public function applyDerivedTitle(AiConversation $conversation, string $question): void
    {
        if (! $this->isAutoTitle($conversation->title)) {
            return;
        }

        $conversation->forceFill(['title' => $this->titleFromQuestion($question)])->save();
    }

    /**
     * Rename a conversation the caller owns.
     *
     * @throws \InvalidArgumentException when the title is empty or too long
     */
    public function rename(AiConversation $conversation, string $title): string
    {
        // Control characters are removed rather than stored: a title travels to
        // the interface and, in a notification, further than that.
        $clean = preg_replace('/[\p{C}]+/u', '', $title) ?? $title;
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);

        if ($clean === '') {
            throw new \InvalidArgumentException('El título no puede estar vacío.');
        }

        if (mb_strlen($clean) > self::TITLE_LIMIT) {
            $clean = rtrim(mb_substr($clean, 0, self::TITLE_LIMIT - 1)).'…';
        }

        $conversation->forceFill(['title' => $clean])->save();

        return $clean;
    }

    /**
     * Delete a conversation and the private material that belonged to it.
     *
     * ## The bytes are removed only after the transaction commits
     *
     * The transaction records the paths in the {@see AiArtifactCleaner} outbox,
     * deletes the artifact rows and then the conversation. Only once that
     * commits are the bytes removed — and only then, so a rollback cannot leave
     * a file deleted for a row that still exists. If the process dies after the
     * commit but before the bytes are gone, the outbox row survives and the
     * scheduled `ai:cleanup-artifacts` command finishes the job. Deleting a
     * conversation is therefore atomic in the database and durable for the disk:
     * a committed delete always ends with no orphaned file.
     *
     * ## What it cannot touch
     *
     * The artifact and attachment queries are scoped to the conversation *and*
     * its owner, so a shared disk or a forged id cannot reach another account's
     * files. Messages, proposals, attachments and suggestions cascade with the
     * conversation;
     * operational tasks, clients, obligations and administrative documents do
     * not reference it, so nothing administrative is deleted. Re-running the
     * delete is safe: the row deletes affect zero rows and the outbox ignores the
     * duplicate path.
     */
    public function delete(AiConversation $conversation): void
    {
        $artifacts = AiArtifact::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $conversation->user_id)
            ->get(['id', 'user_id', 'storage_path']);

        $attachments = AiAttachment::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('user_id', $conversation->user_id)
            ->get(['id', 'user_id', 'storage_path']);

        DB::transaction(function () use ($conversation, $artifacts, $attachments): void {
            $this->cleaner->queue([
                ...$artifacts
                    ->filter(fn (AiArtifact $artifact): bool => is_string($artifact->storage_path) && $artifact->storage_path !== '')
                    ->map(fn (AiArtifact $artifact): array => [
                        'user_id' => (int) $artifact->user_id,
                        'storage_path' => (string) $artifact->storage_path,
                    ])
                    ->values()
                    ->all(),
                ...$attachments
                    ->filter(fn (AiAttachment $attachment): bool => is_string($attachment->storage_path) && $attachment->storage_path !== '')
                    ->map(fn (AiAttachment $attachment): array => [
                        'user_id' => (int) $attachment->user_id,
                        'storage_path' => (string) $attachment->storage_path,
                    ])
                    ->values()
                    ->all(),
            ]);

            AiArtifact::query()->whereIn('id', $artifacts->pluck('id')->all())->delete();

            // Messages, proposals, attachments and suggestions cascade with the
            // conversation; operational tasks, clients and obligations do not
            // reference it, so nothing administrative is touched.
            $conversation->delete();
        });

        // After the commit only. A failure here is not fatal: the outbox row
        // remains and the scheduler retries it.
        $this->cleaner->process();
    }
}

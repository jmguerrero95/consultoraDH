<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiSuggestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Decides whether the assistant should speak, and records that it did.
 *
 * ## The anti-intrusion contract
 *
 * An assistant that interrupts is worse than one that stays quiet, because the
 * first costs attention and the second costs nothing. So a suggestion is only
 * ever surfaced when all of these hold, and each is enforced here rather than in
 * the caller:
 *
 *   1. A real signal exists. Detection is deterministic arithmetic over rows; no
 *      signal means no suggestion, which is the normal case.
 *   2. It clears a relevance threshold. A weak signal is dropped rather than
 *      shown apologetically.
 *   3. The user may do what it suggests. Without the permission, there is no
 *      suggestion - not a disabled one.
 *   4. It has not been suggested before and dismissed. Dismissal is remembered
 *      forever, not for a cooldown.
 *   5. It has not been suggested recently. Cooldown stops the same live
 *      condition being re-raised every time the page is opened.
 *   6. The daily cap has not been reached.
 *   7. At most one is ever visible at a time.
 *
 * ## No model call happens here
 *
 * Deliberately. This class is arithmetic and can be called on navigation, on a
 * timer or by a job, and it stays free and instantaneous. A model is consulted
 * only later, for a candidate that already has evidence behind it, and even
 * then only when it adds something the numbers do not already say.
 */
final class SuggestionEngine
{
    /** Below this a signal is not worth interrupting anyone for. */
    private const MIN_RELEVANCE = 0.55;

    /** The same live condition is not re-raised within this window. */
    private const COOLDOWN_MINUTES = 240;

    /** A ceiling on how often anyone is interrupted in a day. */
    private const DAILY_LIMIT = 3;

    /** How long a suggestion stays offerable. */
    private const TTL_MINUTES = 180;

    public function __construct(
        private readonly SuggestionSignalDetector $detector,
    ) {}

    /**
     * The one suggestion to show right now, or null.
     *
     * Null is the expected result during ordinary work and is not an error.
     */
    public function currentFor(User $user, string $module, string $operation, ?int $conversationId = null): ?AiSuggestion
    {
        // Expired rows are retired first so they cannot be re-offered and do not
        // occupy the "already suggested" slot that suppresses a fresh problem.
        $this->expireStale($user);

        $existing = $this->availableFor($user);

        // Already showing one. At most one, always: two would be a queue, and a
        // queue is how an assistant becomes noise.
        if ($existing !== null) {
            return $existing;
        }

        if ($this->dailyCount($user) >= self::DAILY_LIMIT) {
            return null;
        }

        foreach ($this->detector->detect($user, $module, $operation) as $candidate) {
            if ($candidate['relevance'] < self::MIN_RELEVANCE) {
                continue;
            }

            if (! $this->userMayAct($user, $candidate)) {
                continue;
            }

            // Dismissal is permanent for that key, so a dismissed suggestion is
            // never revived by the same underlying condition.
            if ($this->wasDismissed($user, $candidate['dedupe_key'])) {
                continue;
            }

            if ($this->isCoolingDown($user, $candidate['dedupe_key'])) {
                continue;
            }

            $accepted = $this->wasAlreadyAnswered($user, $candidate['dedupe_key']);

            if ($accepted) {
                continue;
            }

            $created = $this->record($user, $candidate, $conversationId);

            if ($created !== null) {
                return $created;
            }
        }

        return null;
    }

    /**
     * Record that the person accepted the suggestion.
     *
     * The point of acceptance is that it opened a conversation, not that it
     * changed anything: acceptance never writes to the business.
     */
    public function accept(AiSuggestion $suggestion, User $user): void
    {
        $this->authorize($suggestion, $user);

        $suggestion->update([
            'status' => AiSuggestion::STATUS_ACCEPTED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Record a dismissal.
     *
     * Kept as a row rather than deleted, so "we asked about this and you said
     * no" is something the system can still see.
     */
    public function dismiss(AiSuggestion $suggestion, User $user): void
    {
        $this->authorize($suggestion, $user);

        $suggestion->update([
            'status' => AiSuggestion::STATUS_DISMISSED,
            'resolved_at' => now(),
        ]);
    }

    private function authorize(AiSuggestion $suggestion, User $user): void
    {
        if ((int) $suggestion->user_id !== (int) $user->id) {
            // The same refusal as a missing row: probing must not reveal that
            // someone else's suggestion exists.
            throw new AiToolRejected('La sugerencia no está disponible.');
        }
    }

    private function availableFor(User $user): ?AiSuggestion
    {
        return AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('status', AiSuggestion::STATUS_AVAILABLE)
            ->where('expires_at', '>', now())
            ->orderByDesc('relevance_score')
            ->orderByDesc('created_at')
            ->first();
    }

    private function expireStale(User $user): void
    {
        AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('status', AiSuggestion::STATUS_AVAILABLE)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => AiSuggestion::STATUS_EXPIRED,
                'resolved_at' => now(),
            ]);
    }

    private function wasDismissed(User $user, string $dedupeKey): bool
    {
        return AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', AiSuggestion::STATUS_DISMISSED)
            ->exists();
    }

    /**
     * Has this person already been answered on this problem?
     *
     * ## Why expiry is not an answer
     *
     * An expired suggestion says only that nobody was looking at the screen when
     * the offer lapsed. It carries no decision from the person it was offered
     * to, and counting it as one has two bad consequences:
     *
     *  - it makes `isCoolingDown()` unreachable, because every expired row
     *    already matches here before the cooldown window is ever consulted;
     *  - and it buries the problem permanently. A client whose debt is still
     *    sitting there unpaid would be mentioned once, for three hours, and then
     *    never again - which is exactly backwards for a collections assistant,
     *    because the debt that keeps being there is the one worth mentioning.
     *
     * So only an explicit acceptance counts, and an expired offer falls back to
     * the cooldown, which is what the cooldown is for.
     */
    private function wasAlreadyAnswered(User $user, string $dedupeKey): bool
    {
        return AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', AiSuggestion::STATUS_ACCEPTED)
            ->exists();
    }

    private function isCoolingDown(User $user, string $dedupeKey): bool
    {
        return $this->lastSaidAt($user, $dedupeKey)?->isAfter(now()->subMinutes(self::COOLDOWN_MINUTES)) ?? false;
    }

    private function dailyCount(User $user): int
    {
        return AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('last_offered_at', '>', now()->subDay())
            ->count();
    }

    /**
     * When this problem was last actually put in front of this person.
     *
     * `last_offered_at` rather than `created_at`: a problem that was offered,
     * ignored, and is still true has its row refreshed, so `created_at` is
     * frozen at the first offer and would stop measuring anything useful after
     * that. `last_offered_at` falls back to `created_at` so a row written
     * before the column existed still has an answer.
     */
    private function lastSaidAt(User $user, string $dedupeKey): ?Carbon
    {
        $row = AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first(['last_offered_at', 'created_at']);

        return $row?->last_offered_at ?? $row?->created_at;
    }

    /**
     * May this person act on what the suggestion proposes?
     *
     * A hint that cannot be acted on is not a hint, it is an interruption with
     * no way out. Each category maps to the permission its action needs, checked
     * through Spatie so it is the same authority the screen itself uses.
     */
    private function userMayAct(User $user, array $candidate): bool
    {
        $required = match ($candidate['category']) {
            'import_blocked', 'import_inconsistency' => 'imports.manage',
            'overdue_concentration' => 'receivables.view',
            default => 'ai.use',
        };

        try {
            return $user->can($required);
        } catch (PermissionDoesNotExist) {
            // A permission the installation has not created is not something to
            // grant by default; treat it as not permitted.
            return false;
        }
    }

    /**
     * Put the candidate in front of this person, as one row per problem.
     *
     * A problem that was offered, ignored, and is still true comes back - so
     * this refreshes the existing row rather than adding a second one. The
     * unique index on `(user_id, dedupe_key)` is what makes that safe: the same
     * problem can only ever have one row, so "raise it again" and "say it
     * twice at once" cannot both happen.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function record(User $user, array $candidate, ?int $conversationId): ?AiSuggestion
    {
        $attributes = [
            'ai_conversation_id' => $conversationId,
            'source_module' => $candidate['module'],
            'source_operation' => $candidate['operation'],
            'trigger_category' => $candidate['category'],
            'entity_type' => $candidate['entity_type'],
            'entity_reference' => $candidate['entity_reference'],
            'evidence' => $candidate['evidence'],
            'suggested_action' => $candidate['action'],
            'relevance_score' => $candidate['relevance'],
            'status' => AiSuggestion::STATUS_AVAILABLE,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'resolved_at' => null,
            'last_offered_at' => now(),
        ];

        try {
            return AiSuggestion::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'dedupe_key' => $candidate['dedupe_key'],
                ],
                $attributes,
            );
        } catch (UniqueConstraintViolationException) {
            // Two detections raced. The unique key did its job: one of them
            // wins and the other finds the existing row instead of duplicating
            // the interruption.
            return $this->availableFor($user);
        }
    }

    /**
     * Everything a client is shown, generated from the stored evidence.
     *
     * The wording is built here, from the numbers in `evidence`, rather than
     * stored as a template. That is what makes a suggestion a statement about
     * this person's data instead of a sentence with a name in it.
     *
     * @return array<string, mixed>|null
     */
    public function present(AiSuggestion $suggestion): ?array
    {
        $evidence = $suggestion->evidence ?? [];
        $explanation = $this->explain($suggestion->trigger_category, $evidence);

        if ($explanation === null) {
            // Evidence the presenter does not recognise is not evidence. Saying
            // nothing is correct; improvising a sentence from unknown fields is
            // how a suggestion becomes a hallucination.
            return null;
        }

        return [
            'id' => $suggestion->id,
            'explanation' => $explanation,
            'suggested_action' => $suggestion->suggested_action,
            'category' => $suggestion->trigger_category,
            'module' => $suggestion->source_module,
            'operation' => $suggestion->source_operation,
            'evidence' => $evidence,
            'relevance' => round((float) $suggestion->relevance_score, 2),
            'expires_at' => $suggestion->expires_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function explain(string $category, array $evidence): ?string
    {
        return match ($category) {
            'import_blocked' => $this->explainBlockedImport($evidence),
            'import_inconsistency' => $this->explainRepeatedInconsistency($evidence),
            'overdue_concentration' => $this->explainOverdueConcentration($evidence),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function explainBlockedImport(array $evidence): ?string
    {
        $issues = (int) ($evidence['blocking_issues'] ?? 0);
        $file = $evidence['file_name'] ?? null;

        if ($issues <= 0) {
            return null;
        }

        $count = $issues === 1 ? '1 problema bloqueante' : $issues.' problemas bloqueantes';

        return sprintf(
            'La importación%s tiene %s sin resolver, así que no puede aplicarse todavía.',
            is_string($file) && $file !== '' ? ' «'.$file.'»' : '',
            $count,
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function explainRepeatedInconsistency(array $evidence): ?string
    {
        $code = $evidence['code'] ?? null;
        $imports = (int) ($evidence['distinct_imports'] ?? 0);
        $occurrences = (int) ($evidence['occurrences'] ?? 0);

        if (! is_string($code) || $code === '' || $imports < 2) {
            return null;
        }

        return sprintf(
            'La validación «%s» aparece %d veces en %d importaciones distintas. Corregir una fila no lo va a resolver.',
            $code,
            $occurrences,
            $imports,
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function explainOverdueConcentration(array $evidence): ?string
    {
        $balance = (int) ($evidence['outstanding_cop'] ?? 0);
        $overdue = (int) ($evidence['overdue_cop'] ?? 0);

        if ($balance <= 0 || $overdue <= 0) {
            return null;
        }

        $share = (int) round(((float) ($evidence['overdue_share'] ?? 0)) * 100);
        $name = $evidence['client_name'] ?? null;

        return sprintf(
            '%s tiene %s en cartera y el %d%% ya está vencido (%s de %s).',
            is_string($name) && $name !== '' ? $name : 'Un cliente',
            self::formatPesos($balance),
            $share,
            self::formatPesos($overdue),
            self::formatPesos($balance),
        );
    }

    /**
     * Colombian pesos, grouped the way they are written.
     *
     * Exact whole pesos, never an abbreviation: "1.2M" in a figure somebody may
     * act on is a rounding decision made on their behalf.
     */
    private static function formatPesos(int $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }
}

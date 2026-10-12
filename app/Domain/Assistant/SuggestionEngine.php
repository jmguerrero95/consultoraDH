<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiSuggestion;
use App\Models\AiSuggestionOffer;
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

        // Detection runs once per call and its result is reused, because every
        // check below - may this still be shown, is the condition still true -
        // needs the same answer. Detecting twice would also mean deciding twice.
        $candidates = $this->detector->detect($user, $module, $operation);

        $existing = $this->revalidated($user, $module, $operation);

        // Already showing one. At most one, always: two would be a queue, and a
        // queue is how an assistant becomes noise.
        if ($existing !== null) {
            return $existing;
        }

        if ($this->dailyCount($user) >= self::DAILY_LIMIT) {
            return null;
        }

        foreach ($candidates as $candidate) {
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
     * An already-stored suggestion, re-checked before it is shown again.
     *
     * ## Why a stored row is not trusted on its own
     *
     * A suggestion is created at a moment when it was true and permitted. By
     * the time the page is opened again, any of those four things may have
     * stopped holding, and a stale row is the most damaging case the feature
     * has - it asserts something about a person's data that is no longer so.
     *
     * So all four are re-checked here, and the row is retired rather than
     * shown if any of them fails:
     *
     *   1. it has not expired;
     *   2. the person still holds the permission its action needs;
     *   3. the condition behind it is *still* detected right now - the client
     *      paid the debt, the import was applied, the issue was resolved;
     *   4. it belongs to the screen being looked at, so a receivables problem
     *      does not follow someone onto the imports screen.
     *
     * Retiring rather than hiding matters: the row keeps its history, and a
     * condition that comes back is free to be raised again under the cooldown.
     */
    private function revalidated(User $user, string $module, string $operation): ?AiSuggestion
    {
        $rows = AiSuggestion::query()
            ->where('user_id', $user->id)
            ->where('status', AiSuggestion::STATUS_AVAILABLE)
            ->orderByDesc('relevance_score')
            ->orderByDesc('created_at')
            ->get();

        foreach ($rows as $suggestion) {
            // Re-detected against the suggestion's **own** context, not the one
            // this request is for.
            //
            // Detection is context-scoped, so the candidates computed for the
            // current screen say nothing about a suggestion belonging to another
            // one. Reusing them here would make "you are on the wrong screen" and
            // "the debt was paid" indistinguishable, and the first would retire
            // live offers the moment somebody clicked a link.
            $ownContext = $this->detector->detect(
                $user,
                $suggestion->source_module,
                $suggestion->source_operation,
            );

            $verdict = $this->assess($user, $suggestion, $module, $operation, $ownContext);

            if ($verdict === self::VERDICT_SHOW) {
                return $suggestion;
            }

            // "Skip" means it is still true and still permitted, but this is not
            // the screen it belongs to. Retiring it would destroy a live offer
            // because somebody clicked a link, and coming back would then have
            // to wait out a cooldown they had no idea was running.
            if ($verdict === self::VERDICT_RETIRE) {
                $suggestion->forceFill([
                    'status' => AiSuggestion::STATUS_EXPIRED,
                    'resolved_at' => now(),
                ])->save();
            }
        }

        return null;
    }

    /** Still applicable here; show it. */
    private const VERDICT_SHOW = 'show';

    /** Still true, but not on this screen; leave it alone. */
    private const VERDICT_SKIP = 'skip';

    /** No longer true, or no longer permitted; retire it. */
    private const VERDICT_RETIRE = 'retire';

    /**
     * @param  list<array<string, mixed>>  $ownContext  candidates for the suggestion's own context
     */
    private function assess(User $user, AiSuggestion $suggestion, string $module, string $operation, array $ownContext): string
    {
        // 1. Temporal validity.
        if ($suggestion->expires_at === null || $suggestion->expires_at->isPast()) {
            return self::VERDICT_RETIRE;
        }

        $stillDetected = collect($ownContext)
            ->firstWhere('dedupe_key', $suggestion->dedupe_key);

        if ($stillDetected === null) {
            // 3. The condition is gone. Either the debt was paid, the import was
            // applied, the issue was resolved, or the person lost the permission
            // to see it - and all four mean the same thing here: do not show it,
            // and do not keep it.
            return self::VERDICT_RETIRE;
        }

        // 2. Permission, re-read now rather than trusted from when it was made.
        // A role can change between one page load and the next.
        if (! $this->userMayAct($user, $stillDetected)) {
            return self::VERDICT_RETIRE;
        }

        // 4. Context, checked last so a suggestion that has stopped being true
        // is retired rather than merely shelved until its TTL runs out.
        if ($suggestion->source_module !== $module || $suggestion->source_operation !== $operation) {
            return self::VERDICT_SKIP;
        }

        return self::VERDICT_SHOW;
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

    /**
     * How many times this person has actually been interrupted in a day.
     *
     * Counted from the offers log rather than from `ai_suggestions`, because the
     * ceiling is about interruptions and those are not the same thing as
     * distinct problems. The log also counts an offer that reused an existing
     * row, which is exactly the case a row count misses.
     */
    private function dailyCount(User $user): int
    {
        return AiSuggestionOffer::query()
            ->where('user_id', $user->id)
            ->where('offered_at', '>', now()->subDay())
            ->count();
    }

    /**
     * When this problem was last actually put in front of this person.
     *
     * Read from the offers log so that a re-offer moves the cooldown with it.
     * `ai_suggestions.last_offered_at` says the same thing for the current row,
     * but the log is the record that survives the row being retired, and having
     * one authority for "when did we last say this" avoids two disagreeing.
     */
    private function lastSaidAt(User $user, string $dedupeKey): ?Carbon
    {
        // Read the row rather than `max('offered_at')`: an aggregate returns the
        // raw column, so the model's datetime cast never runs and the caller
        // gets a string where it asked for a Carbon.
        $offer = AiSuggestionOffer::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->orderByDesc('offered_at')
            ->orderByDesc('id')
            ->first();

        return $offer?->offered_at;
    }

    /**
     * Append the offer that is about to happen.
     *
     * Written at the same moment as the suggestion row is refreshed, so the
     * two cannot disagree about whether something was shown.
     */
    private function recordOffer(User $user, AiSuggestion $suggestion): void
    {
        AiSuggestionOffer::query()->create([
            'user_id' => $user->id,
            'ai_suggestion_id' => $suggestion->id,
            'dedupe_key' => $suggestion->dedupe_key,
            'source_module' => $suggestion->source_module,
            'source_operation' => $suggestion->source_operation,
            'offered_at' => now(),
        ]);
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
            $suggestion = AiSuggestion::query()->updateOrCreate(
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
            //
            // No offer is logged on this path: nothing new was shown, so
            // counting it would inflate the daily ceiling with interruptions
            // nobody received.
            return $this->availableFor($user);
        }

        // Reaching here means the problem is going from "not on offer" to "on
        // offer", which is the only thing an interruption is.
        //
        // Re-reading a suggestion that is still on offer is deliberately *not*
        // counted: that is the same card being re-rendered on a page refresh,
        // not a second interruption.
        $this->recordOffer($user, $suggestion);

        return $suggestion;
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

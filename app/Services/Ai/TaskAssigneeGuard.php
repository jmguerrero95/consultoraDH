<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The server-side rule that decides whether the responsible party a model chose
 * for an operational task is justified by what the person actually asked for.
 *
 * ## The defect this exists for
 *
 * The T12 live run's first turn named no responsible party, and the model
 * proposed the task assigned to the person who asked. A later turn named Laura
 * and produced a second pending proposal, so two proposals for one title stayed
 * confirmable at once. An instruction in the system prompt asking the model not
 * to choose is guidance, not a guarantee: the invariant has to hold where the
 * row is written.
 *
 * ## What it guarantees
 *
 * `assigned_to` stays mandatory and `TaskAssignee` still authorises the person
 * (staff, active, RBAC through the registry). On top of that this refuses to
 * store a proposal whose assignee is not one of exactly:
 *
 *  1. a member of the active staff whose **full name** the person wrote in the
 *     request that carries the proposal;
 *  2. the person asking, when *they* asked for it in so many words ("asígnala a
 *     mí", "para mí", "yo mismo"); or
 *  3. the assignee an already-proposed proposal for **the same task** was
 *     justified with — a correction keeps the responsible party, it does not
 *     re-invent one.
 *
 * Anything else is refused before the row exists, and the model is told to ask
 * who is responsible. Nothing is invented, the requester is never assumed to be
 * the owner, and no other person's name is carried in from elsewhere.
 *
 * ## Why the name match is whole-name only
 *
 * A fragment is not a name. "Laura" matches nobody unless a colleague is named
 * exactly that, so a half-given name asks rather than guesses, and a request
 * naming two people at once is ambiguous by construction rather than by how
 * similar the strings look. Matching is case- and accent-insensitive and
 * anchored on word boundaries, which is the loosest rule that still only accepts
 * a name the person actually wrote.
 *
 * ## Why only the carrying request is read
 *
 * The name must be in the request that asks for the task, or in a proposal for
 * that same task. Inheriting a name from an unrelated earlier task would be the
 * same defect wearing a longer history: the assistant would assign new work to
 * whoever happened to be named before.
 */
final class TaskAssigneeGuard
{
    /** The write whose assignee this guards. */
    public const TOOL = 'create_operational_task';

    /**
     * The instruction appended to the turn when no responsible party can be
     * established, so the model asks instead of guessing. It names no internal
     * detail and grants no new authority.
     */
    public const ASK_DIRECTIVE = 'Falta el responsable de la tarea y no debes elegirlo tú. Pregunta a la persona '
        .'a quién se la asignas; no la asignes a quien pide la tarea, no elijas tú un responsable y no la dejes '
        .'sin responsable. No propongas la tarea hasta saberlo.';

    /** The refusal that means "ask", not "use a different name". */
    private const MISSING = 'No consta a quién asignar la tarea';

    /**
     * Whole phrases by which a person makes the work their own. Normalised, so
     * they carry no accents and no capital letters.
     *
     * @var list<string>
     */
    private const SELF_PHRASES = [
        'a mi',
        'para mi',
        'mi cuenta',
        'yo mismo',
        'yo misma',
        'a mi mismo',
        'a mi misma',
        'asignamela',
        'asignamelo',
        'asignamela a mi',
        'asignatela a mi',
        'asignatelo a mi',
    ];

    /**
     * The refusal for a chosen assignee that is not justified, or null when it is.
     *
     * @param  array<string, mixed>  $arguments  the model's arguments, already
     *                                           normalised by the registry
     * @return array{error: string, directive: string|null}|null
     */
    public function violation(string $tool, array $arguments, User $requester, AiConversation $conversation, string $question): ?array
    {
        if ($tool !== self::TOOL) {
            return null;
        }

        $chosen = (int) ($arguments['assigned_to'] ?? 0);

        if ($chosen <= 0) {
            return $this->refusal(self::MISSING);
        }

        $asked = $this->normalize($question);

        // 1. A person named in full in this request decides it.
        $named = $this->namedStaff($asked);

        if (count($named) > 1) {
            return $this->refusal(
                'La petición nombra a más de una persona; pregunta a cuál de ellas asignar la tarea antes de proponerla'
            );
        }

        if ($named !== []) {
            if ((int) array_key_first($named) !== $chosen) {
                return $this->refusal(
                    'La tarea solo puede asignarse a la persona nombrada en la petición; no la asignes a otra ni la dejes sin responsable'
                );
            }

            return null;
        }

        // 2. Asking for the work oneself is the only thing that makes the person
        //    asking the responsible party.
        if ($this->asksForSelf($asked)) {
            if ($chosen !== (int) $requester->id) {
                return $this->refusal(
                    'La persona pidió la tarea para sí misma: asígnala a quien la pide o pregúntale a quién'
                );
            }

            return null;
        }

        // 3. A correction of a task already proposed keeps that proposal's
        //    assignee; a different task never inherits it.
        if ($this->keepsProposedAssignee($conversation, $chosen, $arguments)) {
            return null;
        }

        return $this->refusal(self::MISSING);
    }

    /**
     * The identity of the task a proposal is about: its title.
     *
     * Two proposals for the same task share it; proposals for different tasks
     * never do. That is what lets a correction supersede its predecessor without
     * touching an unrelated one.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function identity(array $arguments): ?string
    {
        $title = trim((string) ($arguments['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        return mb_strtolower(Str::ascii($title));
    }

    /**
     * @return array{error: string, directive: string|null}
     */
    private function refusal(string $error): array
    {
        return [
            'error' => $error.'.',
            'directive' => str_starts_with($error, self::MISSING) ? self::ASK_DIRECTIVE : null,
        ];
    }

    /**
     * The staff accounts whose full name appears in the text.
     *
     * @return array<int, true> keyed by user id
     */
    private function namedStaff(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $found = [];

        foreach ($this->staff() as $person) {
            $name = $this->normalize((string) $person->name);

            if ($name === '' || ! $this->mentions($text, $name)) {
                continue;
            }

            $found[(int) $person->id] = true;
        }

        return $found;
    }

    /**
     * The same directory the assignee picker and `team_directory` use: active
     * staff, never a portal account.
     *
     * @return Collection<int, User>
     */
    private function staff(): Collection
    {
        return User::query()
            ->where('account_type', 'staff')
            ->active()
            ->get(['id', 'name']);
    }

    private function asksForSelf(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::SELF_PHRASES as $phrase) {
            if ($this->mentions($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a pending proposal for the *same task* already carries this
     * assignee. A correction that keeps the responsible party is allowed; one
     * that changes it must name the new person.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function keepsProposedAssignee(AiConversation $conversation, int $chosen, array $arguments): bool
    {
        $identity = self::identity($arguments);

        if ($identity === null) {
            return false;
        }

        return AiActionProposal::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('tool', self::TOOL)
            ->where('status', AiActionProposal::PENDING)
            ->get()
            ->contains(function (AiActionProposal $proposal) use ($identity, $chosen): bool {
                $proposalArguments = $proposal->arguments;

                return is_array($proposalArguments)
                    && self::identity($proposalArguments) === $identity
                    && (int) ($proposalArguments['assigned_to'] ?? 0) === $chosen;
            });
    }

    /**
     * `$needle` as a whole name inside `$haystack`: not a fragment of a longer
     * word. Word boundaries are letter/number boundaries, so punctuation and
     * spacing around a name do not stop it matching.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;
    }

    private function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($value))) ?? $value;

        return trim($collapsed);
    }
}

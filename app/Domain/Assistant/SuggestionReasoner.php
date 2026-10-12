<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Ai\AiMessage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the free model to explain a suggestion that already has evidence behind
 * it - and throws most of the answers away.
 *
 * ## What this is for
 *
 * The deterministic presenter can say "Ana Torres has $45,000,000 and 100% of
 * it is late". That is accurate and it is also a fact the operator can already
 * see on the screen, which is why on its own it is a button with a sentence on
 * it rather than assistance.
 *
 * What a person actually wants to know is the part the numbers do not answer:
 * *what is unusual here, and what would you do about it*. That is judgement, and
 * it is what this asks a model for - narrowly, once, and only where there is
 * something to judge.
 *
 * ## The rules that make it safe
 *
 * A model is asked for a sentence about somebody's finances, so its output is
 * treated as a claim to be checked rather than text to be shown:
 *
 *   1. It only ever sees the evidence for this suggestion. Nothing else about
 *      the person, the company or the portfolio is in the prompt.
 *   2. It is asked for strict JSON and told that "I do not have enough" is a
 *      valid and expected answer.
 *   3. Every figure in the answer must already appear in the evidence. A number
 *      that does not is a hallucination and the whole answer is discarded.
 *   4. The recommended action must be one this category is allowed to propose.
 *      The model cannot invent a new action, and therefore cannot propose a
 *      write it has no authority to perform.
 *   5. It is never asked about, or allowed to mention, permissions.
 *   6. Any failure at all - timeout, bad JSON, wrong shape, invented number,
 *      unknown action, refusal - produces null, and the deterministic sentence
 *      is used instead.
 *
 * There is no path through this class that returns unvalidated text.
 *
 * ## Why it is called so rarely
 *
 * A suggestion is created on navigation, so reasoning on every call would spend
 * a free tier's quota on page views. It is gated three ways: only for a category
 * with enough substance to reason about, only above a higher relevance bar than
 * being shown at all, and only once per person per problem per window. The
 * result is stored, so the cost is paid about once and then reused.
 */
final class SuggestionReasoner
{
    /** Below this there is nothing to reason about, only something to report. */
    private const MIN_RELEVANCE = 0.8;

    /** Evidence needs this much substance before a model is worth asking. */
    private const MIN_EVIDENCE_FIELDS = 3;

    /** The same problem is not re-reasoned inside this window. */
    private const REASON_WINDOW_HOURS = 24;

    /** Ceiling on reasoned suggestions per person per day, across all problems. */
    private const DAILY_REASONING_LIMIT = 2;

    public function __construct(
        private readonly AiProviderResolver $resolver,
    ) {}

    /**
     * Whether asking the model is justified at all.
     *
     * Checked before any token is spent, and kept separate from the reasoning
     * itself so the cost decision can be read - and tested - on its own.
     */
    public function shouldReason(SuggestionReasoningRequest $request): bool
    {
        if (! config('ai.suggestions.reasoning.enabled', true)) {
            return false;
        }

        // Deterministic signal first: nothing to reason about is nothing to say.
        if ($request->relevance < self::MIN_RELEVANCE) {
            return false;
        }

        // Substance: a name and one number is a notification, not a judgement.
        if ($this->substantiveFields($request->evidence) < self::MIN_EVIDENCE_FIELDS) {
            return false;
        }

        if (! $request->mayReason) {
            return false;
        }

        $last = $request->lastReasonedAt;

        if ($last !== null && $last->isAfter(now()->subHours(self::REASON_WINDOW_HOURS))) {
            return false;
        }

        return $request->reasonedToday < self::DAILY_REASONING_LIMIT;
    }

    /**
     * Ask for a judgement, and return it only if it survives every check.
     *
     * @return array{headline: string, rationale: string, action: string, model: string|null}|null
     */
    public function reason(SuggestionReasoningRequest $request): ?array
    {
        if (! $this->shouldReason($request)) {
            return null;
        }

        $evidence = $request->toPromptEvidence();

        if ($evidence === []) {
            return null;
        }

        try {
            $response = $this->resolver->complete($this->messages($request, $evidence));
        } catch (AiProviderException $e) {
            // A provider that is down is the ordinary case on a free tier, not an
            // error worth surfacing: the deterministic sentence is already good.
            Log::info('Suggestion reasoning skipped: no provider answered.', [
                'category' => $request->category,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Suggestion reasoning failed unexpectedly.', [
                'category' => $request->category,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $validated = $this->validate($response->content, $request, $evidence);

        if ($validated === null) {
            return null;
        }

        // Recorded so it is possible to say afterwards which model produced a
        // sentence, rather than having to guess.
        $validated['model'] = $response->model;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<AiMessage>
     */
    private function messages(SuggestionReasoningRequest $request, array $evidence): array
    {
        // Joined before it reaches the prompt: interpolating the array itself
        // would produce the literal word "Array" in the instructions, which is
        // both useless to the model and a warning on the way past.
        $allowed = $request->allowedActions();
        $allowed = $allowed === [] ? '(ninguna)' : implode("\n  - ", $allowed);

        $system = <<<PROMPT
        Eres un analista que ayuda a una consultora de cartera en Colombia.

        Recibes EVIDENCIA sobre un problema ya detectado en el sistema. Tu trabajo es
        ponerle nombre a lo que la evidencia muestra y proponer el siguiente paso.

        Reglas, sin excepción:
        - Usa únicamente las cifras y los hechos de la EVIDENCIA. No calcules nada que
          no esté ahí, no completes datos faltantes y no inventes nombres, fechas ni
          importes.
        - No puedes proponer acciones fuera de esta lista:
          {$allowed}
        - No hables de permisos, roles ni de quién puede ver qué.
        - Si la evidencia no alcanza para decir algo útil, responde con la decisión
          "silent". Es una respuesta válida y la preferida cuando hay dudas.
        - No inventes riesgos legales, tributarios ni normativos.

        Responde únicamente con este JSON, sin texto alrededor:
        {"decision": "answer"|"silent", "headline": string, "rationale": string, "action": string}

        Donde "headline" es una frase corta (máximo 140 caracteres) que describa lo
        anómalo, "rationale" explica en una o dos frases por qué la evidencia lo
        sostiene (máximo 320 caracteres), y "action" es una de las acciones
        permitidas, en imperativo y en menos de 120 caracteres.
        PROMPT;

        $user = "EVIDENCIA:\n".json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return [
            AiMessage::system($system),
            AiMessage::user($user),
        ];
    }

    /**
     * Check the answer against everything it is not allowed to do.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{headline: string, rationale: string, action: string}|null
     */
    private function validate(string $content, SuggestionReasoningRequest $request, array $evidence): ?array
    {
        $decoded = json_decode($content, true);

        // Models like to wrap JSON in prose or a code fence. Stripping both is
        // forgiving about formatting while staying strict about everything else.
        if (! is_array($decoded)) {
            $stripped = trim(preg_replace('/^```(?:json)?|```$/', '', trim($content)) ?? '');
            $decoded = json_decode($stripped, true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        // The model deciding it has nothing to add is a correct outcome, not a
        // failure, and it must not be retried into an answer.
        if (($decoded['decision'] ?? null) === 'silent') {
            return null;
        }

        if (($decoded['decision'] ?? null) !== 'answer') {
            return null;
        }

        $headline = $this->cleanText($decoded['headline'] ?? null, 140);
        $rationale = $this->cleanText($decoded['rationale'] ?? null, 320);
        $action = $this->cleanText($decoded['action'] ?? null, 120);

        if ($headline === null || $rationale === null || $action === null) {
            return null;
        }

        // 4. The action must be one this category is allowed to propose. A model
        // that invents a new action is proposing something nobody authorised, and
        // there is no authority check downstream that would catch it.
        if (! $request->actionIsAllowed($action)) {
            return null;
        }

        // 3. Every figure must come from the evidence.
        if (! $this->figuresAreGrounded($headline, $evidence) || ! $this->figuresAreGrounded($rationale, $evidence)) {
            return null;
        }

        return ['headline' => $headline, 'rationale' => $rationale, 'action' => $action];
    }

    /**
     * Reject any number the evidence does not already contain.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function figuresAreGrounded(string $text, array $evidence): bool
    {
        preg_match_all('/\d[\d.,]*/', $text, $matches);

        if ($matches[0] === []) {
            return true;
        }

        $permitted = [];

        foreach ($evidence as $key => $value) {
            // Only *numeric* evidence grounds a figure. A string field that
            // happens to contain digits must not license a number in prose.
            if (is_int($value) || is_float($value)) {
                $permitted[(string) $value] = true;
                $permitted[(string) (int) $value] = true;

                $formatted = number_format((float) $value, 0, ',', '.');
                $permitted[$formatted] = true;

                // Percentages and shares, which the evidence stores as ratios.
                $percent = (string) (int) round((float) $value * 100);
                $permitted[$percent] = true;
                $permitted[$percent.'%'] = true;
            }
        }

        foreach ($matches[0] as $figure) {
            $normalised = rtrim($figure, '.');

            if (isset($permitted[$normalised])) {
                continue;
            }

            // Written with spaces as thousands separators, as Spanish text often is.
            $spaced = str_replace(',', ' ', $normalised);
            if (isset($permitted[$spaced]) || isset($permitted[str_replace(' ', '', $spaced)])) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function substantiveFields(array $evidence): int
    {
        $count = 0;

        foreach ($evidence as $key => $value) {
            if ($key === 'threshold') {
                continue;
            }

            if (is_string($value)) {
                if (trim($value) !== '') {
                    $count++;
                }

                continue;
            }

            if (is_numeric($value)) {
                $count++;
            }
        }

        return $count;
    }

    private function cleanText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($text === '') {
            return null;
        }

        // Cut rather than reject: a model that runs long has usually said the
        // useful thing first, and discarding the whole answer over a trailing
        // clause helps nobody.
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1).'…' : $text;
    }
}

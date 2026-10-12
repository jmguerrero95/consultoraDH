<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Carbon\Carbon;

/**
 * Everything the reasoner is allowed to know, in one immutable bundle.
 *
 * ## Why this exists instead of passing an `AiSuggestion`
 *
 * Because the point of the class it feeds is that the model sees *only* what is
 * in here. Handing a model an Eloquent row invites reaching for `$user`,
 * `$import` or any other relation and quietly widening what the prompt is
 * allowed to talk about. The evidence is copied into a plain array here, with
 * the keys this feature is allowed to discuss and nothing else, so there is no
 * relation left to follow.
 *
 * The allowlist of actions lives here too, because it is the other half of the
 * same guarantee: a model may only propose actions that were explicitly cleared
 * for this category *before* it was asked.
 */
final readonly class SuggestionReasoningRequest
{
    /**
     * Actions each category may propose.
     *
     * These are descriptions, not operations. Nothing here performs anything:
     * a suggestion opens a conversation, and any write still goes through the
     * ordinary confirmed-proposal flow. Keeping the list to descriptions is what
     * makes that true regardless of what a model does with one.
     *
     * @var array<string, list<string>>
     */
    private const ACTIONS = [
        'overdue_concentration' => [
            'Revisar la cuenta del cliente y contactar para acordar un pago.',
            'Revisar el saldo con el cliente y definir un acuerdo de pago.',
            'Contactar al cliente para confirmar la deuda y acordar un plan de pago.',
        ],
        'import_blocked' => [
            'Abrir la importación y resolver los problemas bloqueantes.',
            'Revisar la importación y corregir los problemas bloqueantes.',
        ],
        'import_inconsistency' => [
            'Revisar la equivalencia que produce el error y corregirla.',
            'Corregir la equivalencia que se repite entre importaciones.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $category,
        public array $evidence,
        public float $relevance,
        /** Evidence keys the model is allowed to see. Anything else is dropped. */
        public array $visibleKeys,
        public bool $mayReason,
        public ?Carbon $lastReasonedAt,
        public int $reasonedToday,
    ) {}

    /**
     * The evidence, reduced to what may be shown to a model.
     *
     * Scalars only: a nested array would be a place for something to hide, and
     * the reasoner has no use for one.
     *
     * @return array<string, mixed>
     */
    public function toPromptEvidence(): array
    {
        $out = [];

        foreach ($this->visibleKeys as $key) {
            $value = $this->evidence[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || (! is_string($value) && ! is_numeric($value))) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function allowedActions(): array
    {
        return self::ACTIONS[$this->category] ?? [];
    }

    /**
     * Whether a proposed action is one this category was cleared for.
     *
     * Matched on the action's leading verb rather than its whole sentence: the
     * wording is the model's to choose, the *operation* is not, and comparing
     * whole strings would reject every answer that merely phrased it
     * differently.
     */
    public function actionIsAllowed(string $action): bool
    {
        $allowed = $this->allowedActions();

        if ($allowed === []) {
            return false;
        }

        $proposed = $this->normalise($action);

        foreach ($allowed as $candidate) {
            if ($proposed === $this->normalise($candidate)) {
                return true;
            }

            // Share the leading phrase, which is where the verb lives.
            if (mb_strlen($proposed) >= 12 && mb_substr($proposed, 0, 12) === mb_substr($this->normalise($candidate), 0, 12)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case- and accent-insensitive, punctuation-free comparison.
     */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, 'áéíóúüñ', 'aeiouun');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports\Exceptions;

use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue;

/**
 * A resolution payload the plan cannot interpret. §15's 422.
 *
 * ## Why these are messages and not just codes
 *
 * The dialogs are built from the decision's schema, so a well-formed review never sees one.
 * They are written for the case where it does happen — a crafted request, a stale browser
 * tab holding a decision that no longer exists, a bulk action replayed after a deploy — and in
 * that case naming the offending key is the difference between a reviewer fixing their input
 * and a reviewer filing a bug.
 *
 * The messages are shown to an authenticated operator with `imports.review`, and carry no
 * row contents: a rejected value is described by its *shape*, never echoed back, because the
 * value may be a document number or a name that the reviewer has no permission to read and
 * the rejection is not the place to leak it.
 */
final class InvalidIssueResolution extends \InvalidArgumentException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function missingDecision(): self
    {
        return new self(
            'La resolución necesita una decisión.',
            'missing_decision',
        );
    }

    public static function unknownDecision(string $decision): self
    {
        return new self(
            sprintf(
                '«%s» no es una decisión válida. Las decisiones válidas son: %s.',
                mb_substr($decision, 0, 64),
                implode(', ', array_map(
                    static fn (IssueResolutionDecision $case): string => $case->value,
                    IssueResolutionDecision::cases(),
                )),
            ),
            'unknown_decision',
        );
    }

    public static function decisionNotAllowed(LegacyImportIssue $issue, IssueResolutionDecision $decision): self
    {
        return new self(
            sprintf(
                'Una incidencia «%s» no se puede responder con «%s».',
                $issue->value,
                $decision->value,
            ),
            'decision_not_allowed',
        );
    }

    public static function unexpectedValue(IssueResolutionDecision $decision): self
    {
        return new self(
            sprintf('La decisión «%s» no acepta un valor.', $decision->value),
            'unexpected_value',
        );
    }

    public static function valueMustBeObject(IssueResolutionDecision $decision): self
    {
        return new self(
            sprintf('El valor de «%s» debe ser un objeto con sus campos.', $decision->value),
            'value_must_be_object',
        );
    }

    /** @param list<string> $keys */
    public static function missingValueKeys(IssueResolutionDecision $decision, array $keys): self
    {
        return new self(
            sprintf(
                'A «%s» le faltan estos campos: %s.',
                $decision->value,
                implode(', ', $keys),
            ),
            'missing_value_keys',
        );
    }

    /** @param list<string> $keys */
    public static function unknownValueKeys(IssueResolutionDecision $decision, array $keys): self
    {
        return new self(
            sprintf(
                '«%s» no acepta estos campos: %s. Un campo que se ignora es una respuesta que la persona creía haber dado y el plan descartó.',
                $decision->value,
                implode(', ', $keys),
            ),
            'unknown_value_keys',
        );
    }

    public static function badValue(string $where, string $expected): self
    {
        return new self(
            sprintf('«%s» debe ser %s.', $where, $expected),
            'bad_value',
        );
    }

    public static function unsupportedRule(string $rule): self
    {
        return new self(
            sprintf('Regla de validación desconocida: «%s».', $rule),
            'unsupported_rule',
        );
    }

    /** The named catalogue record does not exist, so the answer cannot be honoured. */
    public static function targetMissing(string $target, string $id): self
    {
        return new self(
            sprintf('No existe %s con ese identificador.', $target),
            'target_missing',
        );
    }

    /** The referenced staged row is not part of this import. */
    public static function sourceRowMissing(string $sourceKey): self
    {
        return new self(
            'La fila referida no pertenece a esta importación.',
            'source_row_missing',
        );
    }

    /**
     * §7.3's `treat_as_duplicate_of` named something that cannot be the row it counts.
     *
     * @param  string  $why  why this particular target is refused
     */
    public static function invalidDuplicateTarget(string $why): self
    {
        return new self(
            'La fila duplicada no es válida: '.$why,
            'invalid_duplicate_target',
        );
    }

    /** The decision is well-formed but this finding cannot be answered with it. */
    public static function inapplicable(LegacyImportIssue $issue, string $why): self
    {
        return new self(
            sprintf('No se puede resolver «%s» así: %s.', $issue->value, $why),
            'inapplicable',
        );
    }
}

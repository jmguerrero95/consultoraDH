<?php

declare(strict_types=1);

namespace App\Domain\Imports\Exceptions;

/**
 * An action's payload does not say what the action needs to write. §12.3's honest failure.
 *
 * ## Why this exists instead of a `return null`
 *
 * The audit's finding, in the code's own terms:
 *
 * ```php
 * if (! isset($payload['tax_id'], $payload['legal_name'])) {
 *     return null;                       // ← skip
 * }
 * …
 * $counts[$type] = ($counts[$type] ?? 0) + 1;   // ← counted anyway
 * ```
 *
 * Every writer had one of these. A malformed action was skipped without an error, counted as a
 * success in `summary['applied']`, left in state `planned`, and the import still finalised as
 * `applied` — the screen and the summary agreeing with each other and both wrong. `failOnTimeout`
 * aside, `ImportActionState::Failed` existed and was written by nothing.
 *
 * Now the payload reader throws this, the apply records it against the action with a reason, and
 * the batch refuses to finalise. §12.3 asks for "ninguna acción queda falsamente `applied`" and
 * "el batch queda en estado recuperable/failed"; this is the class that makes both true.
 *
 * ## The message is for an operator and carries no payload content
 *
 * It names the action, the field and the shape that was expected. It never echoes the value: a
 * payload can hold a document number or a person's name, and §4.3 keeps those out of anything an
 * operator reads. The `natural_key` is included because it is the plan row's own address and is
 * already on screen.
 */
final class UnusableImportAction extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $actionDescription,
    ) {
        parent::__construct($message);
    }

    public static function missingPayload(string $description): self
    {
        return new self(
            sprintf('La acción «%s» no tiene payload.', $description),
            'missing_payload',
            $description,
        );
    }

    public static function missingField(string $description, string $field): self
    {
        return new self(
            sprintf('La acción «%s» necesita el campo «%s» y no lo tiene.', $description, $field),
            'missing_field',
            $description,
        );
    }

    public static function badField(string $description, string $field, string $expected): self
    {
        return new self(
            sprintf('La acción «%s» tiene un campo «%s» que no es %s.', $description, $field, $expected),
            'bad_field',
            $description,
        );
    }

    /** @param list<string> $keys */
    public static function unknownKeys(string $description, array $keys): self
    {
        return new self(
            sprintf(
                'La acción «%s» tiene campos que el aplicador no escribe: %s. Un campo ignorado es un cambio que el plan creía aplicar y no se aplicó.',
                $description,
                implode(', ', $keys),
            ),
            'unknown_keys',
            $description,
        );
    }

    public static function missingIntervalField(string $description, string $field): self
    {
        return new self(
            sprintf('La acción «%s» no declara «%s»; un intervalo necesita sus cuatro bordes explícitos.', $description, $field),
            'missing_interval_field',
            $description,
        );
    }

    public static function inconsistentInterval(string $description, string $why): self
    {
        return new self(
            sprintf('La acción «%s» declara un intervalo imposible: %s.', $description, $why),
            'inconsistent_interval',
            $description,
        );
    }

    /** A referenced record is not there, or belongs to somebody else. */
    public static function targetUnresolvable(string $description, string $target, string $why): self
    {
        return new self(
            sprintf('La acción «%s» no puede resolver %s: %s.', $description, $target, $why),
            'target_unresolvable',
            $description,
        );
    }

    /** §11/§12.3: the world moved between the plan and the apply. */
    public static function preconditionFailed(string $description, string $why): self
    {
        return new self(
            sprintf('La acción «%s» ya no aplica: %s.', $description, $why),
            'precondition_failed',
            $description,
        );
    }

    /**
     * §9.5 could not name one relationship to attach an affiliation to.
     *
     * Its own factory rather than a `preconditionFailed()` with a formatted string, because this
     * is a distinct outcome with a distinct reason code — the caller can tell "the relationship
     * ended" from "there are several and the file does not say which" — and a caller that has to
     * string-match to tell two failures apart will eventually match the wrong one.
     */
    public static function ambiguousAssignment(string $description, int $candidates): self
    {
        return new self(
            sprintf(
                'La acción «%s» no puede decidirse: %d relaciones empresa cubren este intervalo y '
                .'§9.5 no dice cuál es. Hay que cerrar o separar alguna antes de continuar.',
                $description,
                $candidates,
            ),
            'ambiguous_assignment',
            $description,
        );
    }

    /** A02's invariants refused the write. */
    public static function invariantRefused(string $description, string $why): self
    {
        return new self(
            sprintf('La acción «%s» contradice una invariante del dominio: %s.', $description, $why),
            'invariant_refused',
            $description,
        );
    }

    /** The action type has no writer. A programming error, and a loud one. */
    public static function notImplemented(string $description): self
    {
        return new self(
            sprintf('La acción «%s» no tiene un escritor. Todos los tipos de ImportActionType deben ser ejecutables.', $description),
            'not_implemented',
            $description,
        );
    }
}

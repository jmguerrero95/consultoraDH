<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\LegacyImport;
use App\Models\LegacyImportAction;

/**
 * A plan's identity: which revision it is, and what it contains.
 *
 * ## What the audit found
 *
 * There was no plan identity at all. No revision number, no digest, and — the decisive part —
 * `POST /imports/{import}/apply` **accepted no request body**, so it re-read
 * `legacy_import_actions` at the moment it ran and applied whatever was there.
 *
 * The reviewer approved revision N. Another tab resolved an issue. The plan job finished. Apply
 * ran against revision N+1. Nothing was checked, nothing was logged, and the screen said the
 * batch had been applied.
 *
 * That defeats §5.4 outright: "La UI de preview debe leer estas acciones; no reconstruir una
 * explicación distinta a la que realmente aplicará el backend." The UI read the right rows; the
 * backend applied different ones.
 *
 * ## What identity is for
 *
 * ```text
 * preview shows (revision 4, digest abc…)
 *        ↓ reviewer confirms
 * apply submits {plan_revision: 4, plan_digest: "abc…"}
 *        ↓
 * mismatch → 409 stale_plan, nothing written
 * ```
 *
 * ## Why both a revision and a digest
 *
 * A revision number alone does not detect a rebuild that produced a *different* plan, because
 * the number would still be what the reviewer saw if the increment were ever skipped. A digest
 * alone does not order revisions, and does not survive a rebuild that legitimately changed
 * nothing — and §17.4 explicitly wants the reviewer's other answers preserved across rebuilds,
 * which means rebuilds happen that produce no change at all.
 *
 * So: the digest decides *whether* the plan is the one that was reviewed, and the revision
 * records *which* build it was. A rebuild that changes nothing keeps the digest and advances the
 * revision, so an approval of an unchanged plan is still honoured.
 *
 * ## What is hashed
 *
 * `(ordinal, action_type, natural_key, payload, source_row_ids, preconditions)` in ordinal
 * order, canonically encoded. Those are exactly the fields §17.5 renders, so the digest covers
 * everything a reviewer could see and nothing they could not — which is the property that makes
 * "the approved plan is the applied plan" checkable rather than merely asserted.
 *
 * `id`, `state`, `target_*` and `timestamps` are excluded: they are the plan's history, not its
 * content, and including them would make the digest change every time a row was applied.
 */
final class ImportPlanIdentity
{
    private const CANONICAL = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct(
        public readonly int $revision,
        public readonly string $digest,
    ) {}

    /**
     * The identity of the plan currently persisted for an import.
     *
     * Recomputes the digest from the stored actions rather than trusting the stored column, so
     * a column that disagrees with its rows cannot make a stale plan look fresh. The recomputed
     * value is what `apply` compares against.
     */
    public static function of(LegacyImport $import, ?int $revision = null, ?string $digest = null): self
    {
        $actions = $import->actions()->orderBy('ordinal')->get();

        return new self(
            $revision ?? (int) $import->plan_revision,
            $digest ?? self::digestFor($actions),
        );
    }

    /**
     * SHA-256 over the plan's content, in ordinal order.
     *
     * @param  iterable<LegacyImportAction>  $actions
     */
    public static function digestFor(iterable $actions): string
    {
        $parts = [];

        foreach ($actions as $action) {
            $parts[] = [
                'ordinal' => (int) $action->ordinal,
                'action_type' => $action->action_type?->value,
                'natural_key' => (string) $action->natural_key,
                'payload' => self::canonicalise($action->payload),
                'source_row_ids' => self::canonicalise($action->source_row_ids),
                'preconditions' => self::canonicalise($action->preconditions),
            ];
        }

        return hash('sha256', (string) json_encode($parts, self::CANONICAL));
    }

    /**
     * Whether the plan an operator approved is still the plan that would be applied.
     *
     * Both halves must agree. A revision that matches with a digest that does not means the
     * plan was rebuilt under the same number; a digest that matches with a revision that does
     * not means it was rebuilt to exactly the same content, which is safe and is the case
     * §17.4's "refrescar plan sin perder el resto" produces.
     */
    public function matches(?int $submittedRevision, ?string $submittedDigest): bool
    {
        if ($submittedRevision === null || $submittedDigest === null) {
            return false;
        }

        return $submittedRevision === $this->revision
            && hash_equals($this->digest, strtolower(trim($submittedDigest)));
    }

    /**
     * Why it does not match, for the 409 body.
     *
     * §15 wants a domain code on a 409. Distinguishing the two reasons matters because the
     * operator's next step differs: a digest mismatch means the plan content changed and they
     * must read it again, while a revision mismatch with an identical digest means somebody
     * rebuilt it and nothing changed, so re-confirming is enough.
     *
     * @return array{code: string, revision: int, digest: string}
     */
    public function explainMismatch(?int $submittedRevision, ?string $submittedDigest): array
    {
        $submittedDigest = strtolower(trim((string) $submittedDigest));

        return [
            'code' => match (true) {
                $submittedRevision === null || $submittedDigest === '' => 'plan_not_confirmed',
                $submittedRevision === $this->revision && ! hash_equals($this->digest, $submittedDigest) => 'stale_plan',
                $submittedRevision !== $this->revision && hash_equals($this->digest, $submittedDigest) => 'plan_rebuilt',
                default => 'stale_plan',
            },
            'revision' => $this->revision,
            'digest' => $this->digest,
        ];
    }

    /**
     * Recursively key-sort so two equivalent payloads hash the same.
     *
     * The plan builder assembles `payload` arrays in whatever order the reconstruction produced,
     * so insertion order cannot be allowed to decide identity — a rebuild that changed nothing
     * would otherwise get a different digest and invalidate every open approval.
     */
    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalise($item), $value);
        }

        ksort($value);

        return array_map(static fn (mixed $item): mixed => self::canonicalise($item), $value);
    }
}

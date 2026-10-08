<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;

/**
 * One EPS / AFP / CCF / ARL cell: a name, a refusal, or a shrug.
 *
 * ## §9.1: most of this column is not an affiliation
 *
 * The delivered file's affiliation columns are not a list of entities. They are a monthly
 * snapshot somebody maintained by hand, and the ways of saying "no" are numerous:
 *
 * ```text
 * NO    SIN CAJA    NO APLICA    NOAPLICA    NINGUNA    NO PORVENIR
 * ```
 *
 * `NO PORVENIR` is the one that matters most. It names an entity *inside* a negation, so a
 * matcher that only tested for `NO` would be fine, and a matcher that looked for `PORVENIR`
 * would invent a Porvenir affiliation for somebody who is not paying into it. §9.1 is
 * explicit: "`NO PORVENIR`, `NO PROTECCION`, `NO COLPENSIONES`, etc. son evidencia negativa,
 * no una afiliación activa." Every one of them has to be a refusal, and this class refuses
 * anything that starts with a negation before it ever looks for a name.
 *
 * ## `SI` is not an entity
 *
 * §9.1: "`SI` sin entidad concreta = `affiliation_entity_unknown` blocker/warning. jamás crear
 * una entidad llamada `SI`." So `SI` is neither an affiliation nor a refusal — it is a cell
 * that cannot be acted on, and it says so.
 *
 * ## §9.2: normalisation is safe transformations only
 *
 * Case, spacing, accents. `SALUD TOTAL` and `SALUDTOTAL` are the same string written twice,
 * and folding them is not a judgement. `SANITAS` and `SANITA` are *different strings that a
 * human would probably call the same entity*, and folding them would be a judgement — so
 * they produce a suggestion and an issue instead, and a mapping only after approval.
 *
 * ## What this class never does
 *
 * It never decides that two tokens are the same entity, and it never returns a canonical
 * name. It returns a normalised token plus a verdict, and `ImportSourceMapping` is what
 * turns an approved token into an entity. Keeping the decision out of the parser is what
 * makes §9.2's "no fuzzy-merge automático" true by construction rather than by promise.
 */
final readonly class SourceEntityToken
{
    /**
     * A cell that asserts an entity by name.
     */
    private function __construct(
        public SocialSecurityEntityType $type,
        /** Case-folded, accent-free, spaces collapsed. Never canonical. */
        public string $token,
        public ?string $problem,
        public string $raw,
    ) {}

    /** No cell. Nothing to decide. */
    public const OUTCOME_ABSENT = 'absent';

    /** A negation: `NO`, `NINGUNA`, `NO PORVENIR`. Evidence that there is no affiliation. */
    public const OUTCOME_NEGATIVE = 'negative';

    /** `SI` with no entity. §9.1 forbids creating an entity called `SI`. */
    public const OUTCOME_BARE_AFFIRMATIVE = 'bare_affirmative';

    /** A name that needs a person to confirm it before it becomes an entity. */
    public const OUTCOME_SUGGESTION = 'suggestion';

    /** A name that is safe to use: it matches something already in the catalogue. */
    public const OUTCOME_RESOLVED = 'resolved';

    /**
     * @param  SocialSecurityEntityType  $type  which column this came from
     */
    public static function read(
        ?string $raw,
        SocialSecurityEntityType $type,
        SensitiveSourceRedactor $redactor,
        ?string $sheet = null,
        ?int $row = null,
    ): self {
        $clean = trim((string) $redactor->redact(trim((string) $raw), $sheet, $row));
        $folded = SheetMonth::fold($clean);

        return new self($type, $folded, self::judge($folded, $type), $clean);
    }

    /**
     * Rebuild from what staging recorded, keeping §9.1's *outcome*.
     *
     * ## Why `read()` cannot be reused here
     *
     * The rebuild path used to call `read()` on the stored token string, which re-judges the
     * cell from scratch. Two of §9.1's distinctions do not survive that:
     *
     * - an **absent** cell stored as an empty token comes back with `problem = null`, which
     *   `isUsable()` reports as usable. So a column nobody wrote in could become an affiliation;
     * - `OUTCOME_BARE_AFFIRMATIVE` and `OUTCOME_SUGGESTION` are re-derived from the text, which
     *   is right for the first pass and is a second opinion for the second. The parse already
     *   decided; `rebuild-plan` restores its decision.
     *
     * So the stored outcome wins, and only a token with *no* recorded outcome is judged — which
     * happens for a row staged before this release and for a hand-written fixture.
     */
    public static function restore(
        SocialSecurityEntityType $type,
        string $token,
        ?string $problem,
        SensitiveSourceRedactor $redactor,
        ?string $sheet = null,
        ?int $row = null,
    ): self {
        $clean = trim((string) $redactor->redact(trim($token), $sheet, $row));
        $folded = SheetMonth::fold($clean);

        $known = in_array($problem, [
            self::OUTCOME_NEGATIVE,
            self::OUTCOME_BARE_AFFIRMATIVE,
            self::OUTCOME_SUGGESTION,
            self::OUTCOME_RESOLVED,
        ], true);

        if ($known) {
            // A recorded refusal whose text folded to nothing would otherwise become an
            // "absent" cell, which is the opposite of what it was.
            return new self($type, $folded, $problem, $clean);
        }

        if ($folded === '') {
            return new self($type, '', null, '');
        }

        return new self($type, $folded, self::judge($folded, $type), $clean);
    }

    /**
     * The verdict for a folded cell.
     *
     * Negation first, and this ordering is the whole point: `NO PORVENIR` must be a refusal
     * even though it contains a real entity's name, so the negation test cannot come after
     * the name test.
     */
    private static function judge(string $folded, SocialSecurityEntityType $type): ?string
    {
        if ($folded === '' || in_array($folded, ['-', 'N A', 'NA', 'X', '0'], true)) {
            return null;
        }

        if (self::isNegativePhrase($folded)) {
            return self::OUTCOME_NEGATIVE;
        }

        // §9.1: a bare `SI` is not an entity name.
        if (self::isBareAffirmativeEntityToken($folded)) {
            return self::OUTCOME_BARE_AFFIRMATIVE;
        }

        // Everything left is a name. Whether it resolves to a catalogue entry is decided
        // later, against `import_source_mappings` and A02's catalogue — not here, because
        // §9.2 forbids the parser from deciding that two spellings are one entity.
        return self::OUTCOME_SUGGESTION;
    }

    /**
     * Whether a cell is a bare affirmative — §9.1's `SI` with no entity named.
     *
     * ## Why this is one shared predicate and not a list written twice
     *
     * §9.1 says a bare `SI` is `affiliation_entity_unknown` and that *jamás* an entity called `SI`
     * may be created. `judge()` below enforced the first half, in a private list, and nothing
     * enforced the second: a reviewer could approve `create_entity` for the token `SI`, the plan
     * created a catalogue entry for the occurrence, and the approved spelling `SI` became a
     * **reusable global mapping**. Every later workbook whose EPS column said merely `SI` would then
     * resolve to that one entity — which is precisely the failure §9.1 names, reached through the
     * feature §5.5 added to make the other half work.
     *
     * So the classification and the mapping guard read the same answer. It is a whole-value test on
     * the folded form, never a substring or fuzzy match: `SI` is a bare affirmative and `SI EPS` is
     * a name that happens to start with two letters, and only one of them may be refused.
     *
     * Folds first, so a raw cell, an already-folded token and the value the parser judged all give
     * the same answer. `SÍ` folds to `SI` and `S.I.` to `S I`, which is why the real file needs
     * both spellings here.
     */
    public static function isBareAffirmativeEntityToken(string $value): bool
    {
        $folded = SheetMonth::fold($value);

        return in_array($folded, ['SI', 'S', 'S I'], true);
    }

    /**
     * Whether the cell denies an affiliation.
     *
     * Recognised as a prefix rather than as a whole-cell value, which is what makes
     * `NO PORVENIR` and `NO APLICA` refusals instead of entities. The list is §9.1's, plus
     * the two spellings the file actually contains.
     */
    public static function isNegativePhrase(string $folded): bool
    {
        // A refusal that does not start with the negation word, because it is written the
        // other way round.
        if (in_array($folded, ['NINGUNA', 'NINGUNO', 'NADA', 'SIN CAJA', 'SINC AJA', 'SIN'], true)) {
            return true;
        }

        return preg_match(
            '/^(NO|NOAPLICA|NO APLICA|NOAPLI CA|SIN|NO SE|NOSE|NO ESTA|NO ESTAN|NO TIENE|NO AFILIADO)\b/u',
            $folded,
        ) === 1;
    }

    /**
     * Whether this cell can be turned into an affiliation without a person deciding.
     *
     * "Usable" means *decided*. A token in `suggestion` is a perfectly good assertion of an
     * affiliation — it is the one that needs §9.2's mapping — so it is not usable, but it does
     * assert something.
     */
    public function isUsable(): bool
    {
        return $this->problem === null;
    }

    /**
     * Whether this cell says the person **was** affiliated to something.
     *
     * ## Why this is not `isUsable()`
     *
     * The two were conflated, and the consequence was that §9.2's `unresolved_social_entity` was
     * unreachable from both ends at once:
     *
     * - `AffiliationSegment::isAffiliation()` returned `isUsable()`, which is false for every
     *   token in `suggestion` — i.e. every token the catalogue does not already contain, which is
     *   the *only* case that needs a mapping;
     * - and `ImportPlanBuilder::collectUnresolvedEntities()` skipped anything that was not an
     *   affiliation, so the question was never asked.
     *
     * So a positive name that no approved mapping resolved produced no action **and** no issue:
     * the EPS/AFP/CCF/ARL history vanished with nothing for a reviewer to see, while the rate and
     * the relationship were applied. That is the audit's finding, and its stated consequence —
     * "a client's entire contribution history can vanish while their rate and relationship are
     * all applied".
     *
     * `suggestion` is therefore an assertion that asks a question. `negative` (a refusal) and
     * `bare_affirmative` (§9.1's `SI`) are not, and neither is an absent cell.
     */
    public function assertsEntity(): bool
    {
        return $this->problem === null
            || $this->problem === self::OUTCOME_SUGGESTION
            || $this->problem === self::OUTCOME_RESOLVED;
    }

    /** Whether a person has to look at this cell. */
    public function needsReview(): bool
    {
        return $this->problem === self::OUTCOME_BARE_AFFIRMATIVE
            || $this->problem === self::OUTCOME_SUGGESTION;
    }

    public function isNegative(): bool
    {
        return $this->problem === self::OUTCOME_NEGATIVE;
    }

    /** The token as the review screen shows it, or why it cannot be used. */
    public function label(): string
    {
        return match ($this->problem) {
            null => $this->token,
            self::OUTCOME_NEGATIVE => $this->token.' (sin afiliación)',
            self::OUTCOME_BARE_AFFIRMATIVE => $this->type->value.': «'.$this->token.'» no indica entidad',
            self::OUTCOME_SUGGESTION => $this->token.' (por confirmar)',
            default => $this->token,
        };
    }

    /**
     * Whether two cells mean the same thing, for §9.5's compression of equal snapshots.
     *
     * ## Every refusal equals every other refusal
     *
     * In January the AFP cell says `NO` and in February it says `NINGUNA`, and in both months
     * the person is not paying into a pension. §9.5 compresses consecutive equal snapshots,
     * so comparing the literal strings would open a second AFP segment for a change that did
     * not happen — and §9.5 then forbids two open affiliations of the same type, so the
     * compression is what keeps that data valid.
     *
     * What must never collapse is the difference between a refusal and a name. `NO PORVENIR`
     * is not the same snapshot as `PORVENIR`, and treating the first as the second would
     * invent an affiliation for somebody who is not paying into it.
     *
     * Two names match only when the folded token matches exactly. `SALUD TOTAL` and
     * `SANITAS` are close enough for a human to pair them and are §9.2's case for an issue
     * and an approved mapping, not for a silent merge.
     */
    public function sameAs(self $other): bool
    {
        return $this->type === $other->type
            && $this->semanticKey() === $other->semanticKey();
    }

    /**
     * What this cell asserts, for comparison.
     *
     * A refusal collapses to one key so the wording does not matter; a name keeps its token,
     * because two different names are two different things awaiting a decision.
     */
    public function semanticKey(): string
    {
        if ($this->problem === null) {
            return $this->token;
        }

        return match ($this->problem) {
            self::OUTCOME_NEGATIVE => 'none',
            self::OUTCOME_BARE_AFFIRMATIVE => 'bare_affirmative:'.$this->token,
            default => $this->token,
        };
    }

    /**
     * The fingerprint component for §7.3's duplicate comparison.
     *
     * Built from the semantic key and not from the literal cell, for the same reason as
     * `sameAs`: two rows of the same month that both say `NO` are the same row twice, and
     * the spec wants that collapsed rather than raised as a conflict.
     */
    public function fingerprintPart(): string
    {
        return $this->type->value.':'.$this->semanticKey();
    }
}

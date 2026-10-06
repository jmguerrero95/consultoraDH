<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\Company;
use App\Models\LegacyImportRow;

/**
 * §7.2's company identity **after** the reviewer has spoken.
 *
 * ## Why this is a value object and not a helper
 *
 * §7.2 has two answers that both change *which company a person worked for*, and A04-R2 applied
 * neither to the reconstruction:
 *
 * ```text
 * use_company_nit      → the base NIT the reconstruction must use
 * link_existing_company → an existing company the block actually is
 * ```
 *
 * R2 recorded them on the issue, wrote `resolved_at`, and let `taxIdProblemFor()` stop calling
 * the NIT invalid — so the title became "usable" while its `taxId` still said the source value.
 * Episodes, relationships, affiliations and rates were then keyed on the *source* NIT, so the
 * plan still contained two companies and two relationship histories for what the reviewer had
 * just said was one.
 *
 * The mistake was answering the question at the layer where it was asked rather than at the layer
 * where it matters. `taxIdProblem` is "is the NIT parseable"; identity is "which company is
 * this". They are different questions, and only one of them was being answered.
 *
 * ## The evidence is preserved, never overwritten
 *
 * §13 requires the audit trail to be able to say *why*. This object carries both:
 *
 * - the **effective** identity, which everything downstream must use;
 * - the **source** identity, unchanged, which is what the workbook actually said.
 *
 * A rebuild can therefore be explained as "the operator said these two titles are the same
 * company" without the original NIT ever leaving the row that read it.
 *
 * ## The link case is not a "skip the create"
 *
 * `link_existing_company` means the block *is* an existing company. Everything downstream —
 * episodes, relationships, rates — must name that company's canonical NIT, and the plan must not
 * contain a `create_company` for it. A04-R2 had no producer for that at all: `linkedCompanyId()`
 * had zero call sites anywhere in `app/`.
 */
final readonly class EffectiveCompanyIdentity
{
    private function __construct(
        public ?string $taxId,
        public ?string $name,
        public ?string $verificationDigit,
        /** §13: what the workbook said, whatever was decided. */
        public ?string $sourceTaxId,
        public ?string $sourceName,
        /** Null when the identity came straight from the source. */
        public ?LinkedCompany $linkedCompany = null,
        /** How the identity was decided, for the action payload and the audit trail. */
        public string $resolution = 'source',
    ) {}

    /** §7.2's default: the title's own NIT, unchanged. */
    public static function fromSource(?string $taxId, ?string $name, ?string $verificationDigit): self
    {
        return new self($taxId, $name, $verificationDigit, $taxId, $name, null, 'source');
    }

    /**
     * §7.2's `use_company_nit`: the reviewer declared the title's identity.
     *
     * The verification digit travels when it was supplied explicitly, because §7.2 treats it as
     * part of the answer ("DV separado"), and an omitted digit means "the source did not say",
     * **not** "clear the master".
     */
    public static function fromDeclaredNit(
        ?string $sourceTaxId,
        ?string $sourceName,
        string $declaredTaxId,
        ?string $declaredName,
        ?string $verificationDigit,
    ): self {
        return new self(
            $declaredTaxId,
            $declaredName ?? $sourceName,
            $verificationDigit,
            $sourceTaxId,
            $sourceName,
            null,
            'use_company_nit',
        );
    }

    /**
     * §7.2's `link_existing_company`: the block is this existing company.
     *
     * The target's canonical identity wins over everything the source said, because §7.2's whole
     * point is that a name and a plausible-looking NIT are not identity — a database row that a
     * person chose is.
     */
    public static function fromLinkedCompany(Company $company, ?string $sourceTaxId, ?string $sourceName): self
    {
        return new self(
            $company->tax_id,
            $company->legal_name,
            $company->verification_digit,
            $sourceTaxId,
            $sourceName,
            new LinkedCompany((int) $company->id, (string) $company->tax_id),
            'link_existing_company',
        );
    }

    /**
     * §7.2's rule for every staged row of one block, given the answers for that block.
     *
     * The **link** is checked before the declared NIT, because a reviewer who points a block at an
     * existing company has said more than one who supplies a number: a number cannot tell you
     * which master row the rest of the history belongs to, and a link cannot be corrected by
     * guessing digits. Applying them in the other order would let a stale `use_company_nit`
     * silently outrank an explicit link.
     *
     * @param  list<LegacyImportRow>  $rows  every staged row of the block
     */
    public static function resolve(array $rows, ImportDecisionSet $decisions): self
    {
        $first = $rows[0] ?? null;

        if ($first === null) {
            return self::fromSource(null, null, null);
        }

        $blockKey = (string) $first->company_block_key;
        $sourceTaxId = $first->company_tax_id;
        $sourceName = $first->company_display_name;
        $sourceDigit = $first->company_verification_digit;

        $linkedId = $decisions->linkedCompanyId($blockKey);

        if ($linkedId !== null) {
            $company = Company::query()->find($linkedId);

            // The row the reviewer named is gone. Falling through to the source identity would
            // create a *second* company for a block somebody already said is an existing one,
            // which is the failure §7.2 forbids in the other direction. Returning the source
            // identity keeps the plan buildable, and the `link_existing_company` blocker's
            // absence of a resolvable target keeps it from reaching Apply.
            if ($company !== null) {
                return self::fromLinkedCompany($company, $sourceTaxId, $sourceName);
            }
        }

        $declared = $decisions->companyNitFor($blockKey);

        if ($declared !== null) {
            return self::fromDeclaredNit(
                $sourceTaxId,
                $sourceName,
                (string) $declared->value['company_tax_id'],
                isset($declared->value['display_name']) ? (string) $declared->value['display_name'] : null,
                // `optional_single_char` in the schema, so an absent digit stays absent.
                isset($declared->value['verification_digit']) && $declared->value['verification_digit'] !== ''
                    ? (string) $declared->value['verification_digit']
                    : $sourceDigit,
            );
        }

        return self::fromSource($sourceTaxId, $sourceName, $sourceDigit);
    }

    /** Whether the plan must create this company, as opposed to using one that already exists. */
    public function needsCreation(): bool
    {
        return $this->linkedCompany === null;
    }

    /** Whether the decision changed the identity at all — §13's "was there a decision here?". */
    public function wasDecided(): bool
    {
        return $this->resolution !== 'source';
    }

    /**
     * §13's provenance for the action payload and the audit trail.
     *
     * Names the decision and, where there was one, the company it pointed at. Deliberately
     * excludes the source NIT: the payload carries the *effective* identity, and the audit
     * record names the resolution rather than restating a number a reviewer already saw.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return array_filter([
            'identity_resolution' => $this->resolution,
            'linked_company_id' => $this->linkedCompany?->id,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

/** §7.2's `link_existing_company`: the master row a block was declared to be. */
final readonly class LinkedCompany
{
    public function __construct(
        public int $id,
        public string $taxId,
    ) {}
}

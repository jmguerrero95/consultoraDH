<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\ImportSourceMapping;
use App\Models\User;

/**
 * §5.5's reusable source mapping, written from exactly one place.
 *
 * ## Why one writer for two callers
 *
 * A04-R4 closed `map_entity`: reviewing a token that names an existing catalogue entity now leaves
 * a verified `import_source_mappings` row, so the next workbook resolves without asking again.
 * `create_entity` was left unwired, and the two paths need the mapping written from different
 * moments — one during the review, when the entity already exists, and one inside the Apply
 * transaction, because that is the first moment a *new* entity has a real id.
 *
 * That is exactly the shape in which uniqueness rules get written twice and drift: §5.5's index is
 * `(profile, type, source_key)`, and the question of what to do when a verified mapping already
 * points somewhere else has one correct answer, not one per call site. Both callers go through
 * {@see record()}.
 *
 * ## The two refusals, and why they are refusals rather than errors
 *
 *  - **A bare affirmative is never recorded.** §9.1: `SI` names no entity, and §5.5 only stores
 *    mappings "aprobados explícitamente" — approving *this occurrence* is not approving the
 *    spelling. Recording it would make every later `SI` in every later workbook resolve to one
 *    entity, silently, forever. The current import still resolves: that is the per-import decision
 *    layer in `ImportDecisionSet`, which is untouched.
 *  - **A verified mapping to another entity is left alone.** Changing it is a decision about the
 *    policy for all future imports, and §5.5's line is that such a decision is not taken during an
 *    import. The parser would also not normally get here: a token with a verified mapping resolves
 *    and never raises a finding.
 */
final class ApprovedSourceMapping
{
    /**
     * Record an approved spelling, if it may be recorded at all.
     *
     * @return ImportSourceMapping|null null when the token must not become a global mapping, or
     *                                  when a verified mapping already points somewhere else
     */
    public static function record(
        ImportProfile $profile,
        SocialSecurityEntityType $type,
        string $token,
        int $entityId,
        ?User $actor,
    ): ?ImportSourceMapping {
        // §9.1: a bare affirmative identifies nothing. See the class docblock.
        if (SourceEntityToken::isBareAffirmativeEntityToken($token)) {
            return null;
        }

        $sourceKey = SheetMonth::fold($token);

        if ($sourceKey === '') {
            return null;
        }

        $existing = ImportSourceMapping::query()
            ->where('profile', $profile->value)
            ->where('type', $type->value)
            ->where('source_key', $sourceKey)
            ->first();

        if ($existing !== null) {
            // Already this entity: the decision is on record and nothing more is owed.
            if ($existing->verified_at !== null && (int) $existing->social_security_entity_id === $entityId) {
                return $existing;
            }

            // Verified against a different entity — somebody else's decision, about every future
            // import. Not this one's to rewrite.
            if ($existing->verified_at !== null) {
                return null;
            }

            // Unverified: the mapping exists without anybody behind it, which the schema's own
            // CHECK allows precisely so it can be completed later. Completing it is the point.
            $existing->forceFill([
                'social_security_entity_id' => $entityId,
                'verified_at' => now(),
                'verified_by' => $actor?->id,
                'note' => 'Aprobado durante la aplicación de una importación.',
            ])->save();

            return $existing->refresh();
        }

        return ImportSourceMapping::query()->create([
            'profile' => $profile,
            'type' => $type->value,
            'source_key' => $sourceKey,
            'social_security_entity_id' => $entityId,
            'verified_at' => now(),
            'verified_by' => $actor?->id,
            'note' => 'Aprobado durante la aplicación de una importación.',
        ]);
    }

    private function __construct() {}
}

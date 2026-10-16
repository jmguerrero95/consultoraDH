<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;
use App\Models\SocialSecurityEntity;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides whether a token names a usable catalogue entity.
 *
 * ## Why this was extracted
 *
 * The preview and the importer must agree exactly. While the preview carried its **own** copy of the
 * rule, they drifted, and the drift was invisible in the direction that matters: the preview reported
 * tokens as resolved that the importer would have refused, and tokens as pending that the importer
 * would have taken. Two answers to one question is one too many.
 *
 * So the rule lives here and both read it. There is still a second copy inside
 * `ImportPlanBuilder::resolveEntity()` — that one is older and it is the one with the import's
 * decisions in scope, so it cannot be deleted here — but this class states the decision **the same
 * way**, and `EntityResolutionParityTest` asserts they agree case by case, including the ones that
 * are meant to resolve nothing.
 *
 * ## The rule, in order
 *
 *     1. a refusal or a negation is answered, not asked  → `negative`, `refused`
 *     2. a sigla several entities answer to is a question → `ambiguous`
 *     3. an approved mapping whose target is **active**    → `resolved`
 *     4. an active entity whose folded name is the token → `resolved`
 *     5. an entity that exists but is deactivated         → `inactive`
 *     6. everything else                                  → `pending`
 *
 * Step 5 is separate from step 6 on purpose. A deactivated administrator is not an open question:
 * somebody already decided. Counting it as pending would ask a reviewer to decide a closed door.
 *
 * ## What this deliberately cannot do
 *
 * It does not approve anything. An ambiguous sigla with no approved mapping is `ambiguous`, and
 * `PORVENR`, a bare `COMFENALCO` and a bare `COMFAMILIAR` stay there until a person decides — which
 * is the whole point of refusing to guess.
 */
final class CatalogueEntityResolver
{
    public const BUCKET_RESOLVED = 'resolved';

    public const BUCKET_NEGATIVE = 'negative';

    public const BUCKET_REFUSED = 'refused';

    public const BUCKET_AMBIGUOUS = 'ambiguous';

    public const BUCKET_INACTIVE = 'inactive';

    public const BUCKET_PENDING = 'pending';

    /**
     * @return array{bucket: string, target: string|null, reason: string|null}
     */
    public static function resolve(string $token, string $type): array
    {
        $token = SheetMonth::fold($token);

        if (SourceEntityToken::isNegativePhrase($token)) {
            return ['bucket' => self::BUCKET_NEGATIVE, 'target' => null,
                'reason' => 'La celda dice que no hay afiliación.'];
        }

        if (SourceEntityToken::isNonEntityWord($token)) {
            return ['bucket' => self::BUCKET_REFUSED, 'target' => null,
                'reason' => 'Nivel de riesgo o cargo, no una administradora.'];
        }

        $ambiguity = self::ambiguity($token, $type);

        if ($ambiguity !== null) {
            return ['bucket' => self::BUCKET_AMBIGUOUS, 'target' => null, 'reason' => $ambiguity];
        }

        $byMapping = self::byApprovedMapping($token, $type);

        if ($byMapping !== null) {
            return ['bucket' => self::BUCKET_RESOLVED, 'target' => $byMapping->name, 'reason' => null];
        }

        $byName = self::byActiveName($token, $type);

        if ($byName !== null) {
            return ['bucket' => self::BUCKET_RESOLVED, 'target' => $byName->name, 'reason' => null];
        }

        $inactive = self::byInactiveName($token, $type);

        if ($inactive !== null) {
            return ['bucket' => self::BUCKET_INACTIVE, 'target' => $inactive->name, 'reason' => sprintf(
                '«%s» está en el catálogo pero inactiva: no admite afiliaciones nuevas.',
                $inactive->name,
            )];
        }

        return ['bucket' => self::BUCKET_PENDING, 'target' => null,
            'reason' => 'Ninguna entidad del catálogo corresponde a este token.'];
    }

    /** The entity an approved mapping points at, when that entity is still active. */
    public static function byApprovedMapping(string $token, string $type): ?SocialSecurityEntity
    {
        return SocialSecurityEntity::query()
            ->whereIn('id', DB::table('import_source_mappings')
                ->where('import_source_mappings.profile', ImportProfile::BlindenLegacyMonthlyV1->value)
                ->where('import_source_mappings.type', $type)
                ->where('import_source_mappings.source_key', $token)
                ->whereNotNull('import_source_mappings.verified_at')
                ->pluck('social_security_entity_id'))
            ->where('status', 'active')
            ->first();
    }

    public static function byActiveName(string $token, string $type): ?SocialSecurityEntity
    {
        return self::byName($token, $type, activeOnly: true);
    }

    public static function byInactiveName(string $token, string $type): ?SocialSecurityEntity
    {
        return self::byName($token, $type, activeOnly: false);
    }

    private static function byName(string $token, string $type, bool $activeOnly): ?SocialSecurityEntity
    {
        $query = SocialSecurityEntity::query()->where('type', $type);

        if ($activeOnly) {
            $query->where('status', 'active');
        }

        return $query->get()->first(
            fn (SocialSecurityEntity $entity): bool => SheetMonth::fold((string) $entity->name) === $token
        );
    }

    /**
     * A sigla several entities answer to, or one no source explains.
     */
    private static function ambiguity(string $token, string $type): ?string
    {
        if ($type === 'CCF') {
            // Two ways a sigla fails to identify one caja. The obvious one is several rows carrying
            // it exactly — `COMFAMILIAR`. The one that bit before is several *qualified* siglas
            // starting with it: `COMFENALCO` is exactly one row, and the other five regional cajas
            // are `COMFENALCO CARTAGENA`, `COMFENALCO SANTANDER` and so on. Equality alone calls the
            // bare form unambiguous, which is backwards — a workbook writing `COMFENALCO` is writing
            // the abbreviation all six share.
            foreach (CcfNationalCatalogue::records() as $record) {
                if (SheetMonth::fold($record['sigla']) !== $token) {
                    continue;
                }

                $family = 0;

                foreach (CcfNationalCatalogue::records() as $other) {
                    if (str_starts_with(SheetMonth::fold($other['sigla']), $token.' ')) {
                        $family++;
                    }
                }

                if ($family > 0) {
                    return sprintf(
                        '«%s» es la abreviatura de %d cajas regionales distintas. Hace falta la región.',
                        $token,
                        $family + 1,
                    );
                }
            }

            foreach (CcfNationalCatalogue::ambiguousSiglas() as $sigla) {
                if (SheetMonth::fold($sigla) === $token) {
                    return sprintf('«%s» es la sigla de varias cajas distintas. Hace falta la región.', $sigla);
                }
            }

            foreach (CcfNationalCatalogue::records() as $record) {
                if (in_array('source_artifact', $record['flags'] ?? [], true)
                    && SheetMonth::fold($record['sigla']) === $token) {
                    return 'La fuente publica esta sigla corrupta y no se puede adivinar la real.';
                }
            }
        }

        if ($type === 'ARL') {
            foreach (ArlNationalCatalogue::ambiguousTokens() as $candidate => $why) {
                if (SheetMonth::fold($candidate) === $token) {
                    return $why;
                }
            }
        }

        if ($type === 'AFP') {
            foreach (PensionNationalCatalogue::proposedAliases() as $alias) {
                if (SheetMonth::fold($alias['token']) === $token) {
                    return sprintf(
                        'Corrección tipográfica propuesta hacia «%s», sin aprobar. No crea una entidad nueva.',
                        $alias['target'],
                    );
                }
            }
        }

        return null;
    }
}

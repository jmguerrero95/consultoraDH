<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Which importer understands a workbook.
 *
 * ## Why a profile and not a configurable column mapper
 *
 * §2 of the specification is explicit, and the reason is worth keeping in the type: a
 * generic mapper would need every rule this domain actually has — which column holds the
 * risk and which holds the job title, how a retirement note is read, whether `NO` is a
 * value or an absence — and would put them all in configuration, where they would be
 * *edited* rather than *reviewed*. A mapping that says "the column I guessed is the risk"
 * is a guess the operator cannot see.
 *
 * Naming the profile does the opposite. `blinden_legacy_monthly_v1` says which reader
 * ran, which is what makes an import reproducible, and it is a value the database can
 * CHECK so a future profile cannot be stored without a reader.
 *
 * ## Adding a second profile
 *
 * Not in A04, and the sign that one would be needed is a workbook whose structure this
 * reader refuses with `unsupported_workbook_profile`. A refusal is a better outcome than
 * a partial parse that looks complete.
 */
enum ImportProfile: string
{
    case BlindenLegacyMonthlyV1 = 'blinden_legacy_monthly_v1';

    public function label(): string
    {
        return match ($this) {
            self::BlindenLegacyMonthlyV1 => 'Planilla mensual Blinden (legado)',
        };
    }

    /** The parser that reads this profile. */
    public function readerClass(): string
    {
        return match ($this) {
            self::BlindenLegacyMonthlyV1 => BlindenLegacyWorkbookParser::class,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $profile): array => ['value' => $profile->value, 'label' => $profile->label()],
            self::cases(),
        );
    }
}

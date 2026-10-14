<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Http\Controllers\Api\ImportController;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client's affiliation with the social security system, where **the entity is unknown**.
 *
 * The delivered workbook writes `SI` in the AFP column for 36 rows: the person was affiliated,
 * the fund was not named. §9.1 refuses to invent an entity, and until FIX-IMPORT-02 the only
 * outcomes were to block the batch or to drop the affiliation — and dropping it is worse than
 * it sounds, because A03 would go on reading "no AFP that month" for somebody who had one.
 *
 * This row is the third option. It says *that* there was an affiliation, over *which* months,
 * and does not say *which* entity. That is the whole difference from `ClientAffiliation`, and
 * the reason it is a separate table rather than a nullable foreign key on that one:
 * `client_affiliations.social_security_entity_id` is `NOT NULL` on purpose, and every query
 * that reads a client's health history joins through it.
 *
 * ## Nothing existing reads this
 *
 * No A02 or A03 query was changed to consult this table, deliberately. An observation is a note
 * about what the source did **not** say, and folding it into "which EPS did this person have"
 * would mean answering that question with a null — which is the false negative this table
 * exists to prevent. It is surfaced on its own, through
 * {@see ImportController::clientAffiliationObservations()}, so a
 * client reading their history is told the fund is missing rather than being handed a gap.
 *
 * @property int $id
 * @property SocialSecurityEntityType $type
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string $started_on_precision
 * @property string|null $ended_on_precision
 * @property list<string> $months
 * @property string $reason
 * @property int|null $legacy_import_id
 * @property list<int>|null $source_row_ids
 * @property string $dedupe_key
 */
#[Fillable([
    'client_id',
    'type',
    'started_on',
    'ended_on',
    'started_on_precision',
    'ended_on_precision',
    'months',
    'reason',
    'legacy_import_id',
    'source_row_ids',
    'dedupe_key',
])]
class ClientAffiliationObservation extends Model
{
    /**
     * Keep a date and its precision coherent on every write.
     *
     * The same rule and the same reason as `ClientAffiliation::booted()`: the constraint is
     * real, and a caller that forgets the precision should get a row that says what it knows,
     * not a PostgreSQL error. One-directional for the same reason too — an import that writes
     * `month` keeps `month`, because §8.3's imprecision has to survive to the screen.
     */
    protected static function booted(): void
    {
        static::saving(function (self $observation): void {
            if ($observation->started_on === null) {
                $observation->started_on_precision = 'unknown';
            } elseif (blank($observation->started_on_precision)) {
                $observation->started_on_precision = 'day';
            }

            if ($observation->ended_on === null) {
                $observation->ended_on_precision = null;
            } elseif (blank($observation->ended_on_precision)) {
                $observation->ended_on_precision = 'day';
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SocialSecurityEntityType::class,
            'started_on' => 'date',
            'ended_on' => 'date',
            'months' => 'array',
            'source_row_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * The import that observed this, when the observation came from one.
     *
     * Nullable and not cascade-deleted: `ClientAffiliation` uses `restrictOnDelete` for the
     * same reason, and an import being removed should not take a health record with it.
     *
     * @return BelongsTo<LegacyImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class, 'legacy_import_id');
    }

    /** Observations that the source has not yet shown as ending. */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('ended_on');
    }

    /**
     * The months the source asserted, as `YYYY-MM`, ascending and without duplicates.
     *
     * Defensive on purpose: the column is written by an importer, and a reader that trusted the
     * order would print an unsorted history the first time a re-import appended rather than
     * replaced. An empty list is possible and is not an error — it means the source asserted
     * the affiliation without saying which month, which §8.3's `unknown` precision covers.
     *
     * @return list<string>
     */
    public function monthKeys(): array
    {
        $months = $this->months ?? [];

        $months = array_values(array_unique(array_filter(
            $months,
            static fn (mixed $month): bool => is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1,
        )));

        sort($months);

        return $months;
    }

    /**
     * Whether this observation has been superseded by a real affiliation.
     *
     * True when the client now has a `client_affiliations` row of the same type whose interval
     * covers the first month observed here. That is the case where the reviewer answered the
     * question — someone named the fund — and the observation has done its job.
     */
    public function isAnswered(): bool
    {
        $first = $this->monthKeys()[0] ?? null;

        if ($first === null) {
            return false;
        }

        $monthStart = $first.'-01';

        return ClientAffiliation::query()
            ->where('client_id', $this->client_id)
            ->where('type', $this->type->value)
            ->where(function (Builder $query) use ($monthStart): void {
                $query->whereNull('started_on')->orWhere('started_on', '<=', $monthStart);
            })
            ->where(function (Builder $query) use ($monthStart): void {
                $query->whereNull('ended_on')->orWhere('ended_on', '>', $monthStart);
            })
            ->exists();
    }
}

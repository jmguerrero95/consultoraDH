<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\EffectivePeriod;
use Database\Factories\ClientAffiliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period during which a client was affiliated to a social security entity.
 *
 * Same historical rule as the company relationship: rows are closed and new ones
 * opened, never rewritten. When a client changes EPS, the old row keeps its end
 * date, and the new row starts on that same day: `[started_on, ended_on)`, so the
 * effective day belongs to the new row and to the new row only.
 *
 * `type` repeats the entity's type so the database can reject a mismatch: a
 * CHECK constraint cannot look at another table.
 *
 * `arl_risk_class` is the integer 1..5, or null when genuinely unknown. It is
 * only allowed on an ARL row.
 *
 * `started_on_precision` and `ended_on_precision` say how much of the date is
 * known: `day` for a date a person chose, `month` for one derived from a monthly
 * snapshot by an import policy, and `unknown` for a start nobody can date. The
 * columns exist so a monthly inference is never displayed as an exact day, and
 * a CHECK constraint keeps a date and its precision coherent. An import may
 * therefore write `month` here; nothing in A02 does, because A02's dates always
 * come from somebody choosing them.
 *
 * @property int $id
 * @property SocialSecurityEntityType $type
 * @property int|null $arl_risk_class
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string $started_on_precision
 * @property string|null $ended_on_precision
 */
#[Fillable([
    'client_id',
    'social_security_entity_id',
    'client_company_assignment_id',
    'type',
    'started_on',
    'ended_on',
    'started_on_precision',
    'ended_on_precision',
    'arl_risk_class',
    'notes',
])]
class ClientAffiliation extends Model
{
    /** @use HasFactory<ClientAffiliationFactory> */
    use HasFactory;

    /**
     * Keep a date and its precision coherent on every write.
     *
     * ## Why this is here and not at each call site
     *
     * §8.3's columns are held together by CHECK constraints, and a constraint only says no.
     * Every caller would otherwise have to remember to state a precision for every date it
     * writes, and one that does not — a factory, a test, a future importer — fails with a
     * PostgreSQL check violation instead of a sentence anybody can act on. The first version
     * of A04 wrote the precision at each A02 call site and still failed 77 real inserts,
     * because the factories are the other half of the write paths.
     *
     * ## What it will not do
     *
     * It never *changes* a precision that was stated. An import that writes
     * `started_on_precision = 'month'` keeps `month`, which is the whole point of §8.3: the
     * imprecision has to survive to the screen that displays it. This only fills in what the
     * caller left empty, and it is deliberately one-directional — a date with no precision is
     * completed, a precision with no date is cleared, because the constraint says those two
     * cannot both be absent.
     */
    protected static function booted(): void
    {
        static::saving(function (self $affiliation): void {
            if ($affiliation->started_on === null) {
                $affiliation->started_on_precision = 'unknown';
            } elseif (blank($affiliation->started_on_precision)) {
                $affiliation->started_on_precision = 'day';
            }

            if ($affiliation->ended_on === null) {
                // An open affiliation has no end and therefore no end precision. Keeping a
                // stale one would make the row claim a boundary it does not have.
                $affiliation->ended_on_precision = null;
            } elseif (blank($affiliation->ended_on_precision)) {
                $affiliation->ended_on_precision = 'day';
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
            // An integer and not the enum, on purpose. The enum cast raises on a
            // value outside its cases, so a legacy or imported row holding a 0 or
            // a 9 would take down every screen that loads the row, instead of
            // being reported by the quality checks that exist to report it. The
            // range is held by the validation layer and by a CHECK constraint;
            // this model stays readable whatever it finds.
            'arl_risk_class' => 'integer',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    /**
     * The risk level as an enum, or null when the column is empty.
     *
     * Used where the value has to be understood rather than shown, so a value
     * outside the range resolves to null instead of raising.
     */
    public function arlRiskClass(): ?ArlRiskClass
    {
        return $this->arl_risk_class === null
            ? null
            : ArlRiskClass::tryFrom($this->arl_risk_class);
    }

    /**
     * Whether the stored value is outside the range the domain accepts.
     *
     * Reported as data quality rather than thrown, which is the difference
     * between telling the user their record is wrong and refusing to load it.
     */
    public function hasInvalidRiskClass(): bool
    {
        return $this->arl_risk_class !== null
            && ($this->arl_risk_class < 1 || $this->arl_risk_class > 5);
    }

    /**
     * Accepts the enum the domain speaks and the integer the column stores.
     */
    public function setArlRiskClassAttribute(mixed $value): void
    {
        $this->attributes['arl_risk_class'] = $value instanceof ArlRiskClass
            ? $value->value
            : $value;
    }

    public function isActive(): bool
    {
        return $this->ended_on === null;
    }

    /**
     * Whether this row carries an ARL risk level.
     */
    public function carriesRiskClass(): bool
    {
        return $this->type->carriesRiskClass();
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value),
        );
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<SocialSecurityEntity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(SocialSecurityEntity::class, 'social_security_entity_id');
    }

    /**
     * @return BelongsTo<ClientCompanyAssignment, $this>
     */
    public function companyAssignment(): BelongsTo
    {
        return $this->belongsTo(ClientCompanyAssignment::class, 'client_company_assignment_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('ended_on');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->whereNotNull('ended_on');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, SocialSecurityEntityType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * The rows that were effective on a date.
     *
     * `[started_on, ended_on)`, the same convention the relationships use: the start
     * day belongs to the period and the end day does not. A change of entity on the
     * first of March means the old affiliation answered for the last of February and
     * the new one for the first of March.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeOn(Builder $query, \DateTimeInterface|string $date): void
    {
        EffectivePeriod::scopeActiveOn($query, $date);
    }
}

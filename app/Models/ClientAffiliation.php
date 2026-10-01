<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
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
 * date and the new row starts the next day.
 *
 * `type` repeats the entity's type so the database can reject a mismatch: a
 * CHECK constraint cannot look at another table.
 *
 * `arl_risk_class` is the integer 1..5, or null when genuinely unknown. It is
 * only allowed on an ARL row.
 *
 * @property int $id
 * @property SocialSecurityEntityType $type
 * @property int|null $arl_risk_class
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 */
#[Fillable([
    'client_id',
    'social_security_entity_id',
    'client_company_assignment_id',
    'type',
    'started_on',
    'ended_on',
    'arl_risk_class',
    'notes',
])]
class ClientAffiliation extends Model
{
    /** @use HasFactory<ClientAffiliationFactory> */
    use HasFactory;

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
}

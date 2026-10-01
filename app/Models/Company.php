<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Companies\TaxId;
use App\Domain\Shared\RecordStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employer Consultora DH administers.
 *
 * A company is a permanent master record too. Relationships reference it, so
 * removing one would either break them or rewrite history. Inactive companies
 * stay queryable.
 *
 * @property int $id
 * @property CompanyStatus $status
 * @property string|null $tax_id
 */
#[Fillable([
    'legal_name',
    'trade_name',
    'tax_id',
    'verification_digit',
    'email',
    'phone',
    'address',
    'city',
    'department',
    'status',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    /**
     * The name to show when the trade name is known, since that is what an
     * administrator says out loud.
     */
    public function displayName(): string
    {
        $trade = trim((string) $this->trade_name);

        return $trade === '' ? $this->legal_name : $trade;
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    /**
     * The NIT as a person writes it: `900.123.456-1`.
     */
    public function taxIdLabel(): ?string
    {
        $taxId = trim((string) $this->tax_id);

        if ($taxId === '') {
            return null;
        }

        return TaxId::forDisplay($taxId);
    }

    /**
     * Normalise the NIT on the way in and split off the verification digit, so
     * the column that exists for corrections is never left behind the main one.
     */
    protected function taxId(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if ($value === null || trim($value) === '') {
                    return null;
                }

                return TaxId::normalise($value);
            },
            get: fn (?string $value): ?string => $value,
        );
    }

    protected function legalName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : trim($value),
        );
    }

    protected function tradeName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value),
        );
    }

    /**
     * @return HasMany<ClientCompanyAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClientCompanyAssignment::class);
    }

    /**
     * @return HasMany<ClientCompanyAssignment, $this>
     */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_on');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', RecordStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inactive(Builder $query): void
    {
        $query->where('status', RecordStatus::Inactive);
    }
}

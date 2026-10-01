<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clients\DocumentNumber;
use App\Domain\Clients\DocumentType;
use App\Domain\Shared\RecordStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person Consultora DH administers.
 *
 * The record is permanent. It is never deleted, because the historical
 * relationships and affiliations reference it and because a person who left the
 * portfolio two years ago is still the person whose 2024 history somebody may
 * need to reconstruct.
 *
 * `document_number` is normalised on the way in, so the value stored, searched
 * and compared is always the same canonical form. See DocumentNumber.
 *
 * @property int $id
 * @property DocumentType $document_type
 * @property string $document_number
 */
#[Fillable([
    'document_type',
    'document_number',
    'first_names',
    'last_names',
    'email',
    'phone',
    'address',
    'city',
    'department',
    'status',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => RecordStatus::class,
        ];
    }

    /**
     * The full name, for display and for sorting.
     */
    public function fullName(): string
    {
        return trim($this->first_names.' '.$this->last_names);
    }

    /**
     * The document as a person writes it: `CC 12.345.678`.
     */
    public function documentLabel(): string
    {
        $number = DocumentNumber::forDisplay($this->document_number, $this->document_type);

        return $number === '' ? '' : $this->document_type->value.' '.$number;
    }

    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    /**
     * Normalise the document as it is assigned, so the stored value, the unique
     * index and the search filter all speak the same language.
     */
    protected function documentNumber(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                return DocumentNumber::normalise($value, $this->resolvedDocumentType());
            },
        );
    }

    /**
     * The document type, as an enum, whichever way it is being read.
     *
     * During a mass assignment the cast has not been applied yet, so the raw
     * attribute is still a string. Resolving it here means the number is
     * normalised the same way whether the record is created through the action
     * or directly through the model.
     */
    private function resolvedDocumentType(): DocumentType
    {
        $type = $this->getAttributes()['document_type'] ?? $this->document_type;

        if ($type instanceof DocumentType) {
            return $type;
        }

        return DocumentType::tryFrom((string) $type) ?? DocumentType::Other;
    }

    /**
     * Trim the name fields so a stray space cannot make two records look
     * different in a list.
     */
    protected function firstNames(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : trim($value),
        );
    }

    protected function lastNames(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : trim($value),
        );
    }

    /**
     * @return HasMany<ClientCompanyAssignment, $this>
     */
    public function companyAssignments(): HasMany
    {
        return $this->hasMany(ClientCompanyAssignment::class);
    }

    /**
     * Only the relationships still open.
     *
     * @return HasMany<ClientCompanyAssignment, $this>
     */
    public function activeCompanyAssignments(): HasMany
    {
        return $this->companyAssignments()->whereNull('ended_on');
    }

    /**
     * @return HasMany<ClientAffiliation, $this>
     */
    public function affiliations(): HasMany
    {
        return $this->hasMany(ClientAffiliation::class);
    }

    /**
     * @return HasMany<ClientAffiliation, $this>
     */
    public function activeAffiliations(): HasMany
    {
        return $this->affiliations()->whereNull('ended_on');
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

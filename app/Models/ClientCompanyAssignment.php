<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClientCompanyAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One period during which a client worked for a company.
 *
 * The row is history, not a pointer. Changing which company a client works for
 * closes this row and opens another one, so the question "which company was this
 * person in during 2024" is answered by the data rather than by a log that might
 * be missing. `company_id` is therefore never rewritten.
 *
 * Active means exactly `ended_on IS NULL`.
 *
 * @property int $id
 * @property Carbon $started_on
 * @property Carbon|null $ended_on
 * @property Carbon|null $parallel_authorized_at
 */
#[Fillable([
    'client_id',
    'company_id',
    'started_on',
    'ended_on',
    'job_title',
    'notes',
    'parallel_authorized_at',
    'parallel_reason',
])]
class ClientCompanyAssignment extends Model
{
    /** @use HasFactory<ClientCompanyAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'parallel_authorized_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->ended_on === null;
    }

    /**
     * Whether this row was an authorised second open relationship.
     *
     * Data quality checks read this to tell an intentional overlap from an
     * oversight, which is the whole reason the authorisation is stored rather
     * than inferred.
     */
    public function isAuthorisedParallel(): bool
    {
        return $this->parallel_authorized_at !== null;
    }

    protected function jobTitle(): Attribute
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ClientAffiliation, $this>
     */
    public function affiliations(): HasMany
    {
        return $this->hasMany(ClientAffiliation::class);
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
}

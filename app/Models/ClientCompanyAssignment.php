<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\EffectivePeriod;
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
    'started_on_precision',
    'ended_on_precision',
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
     * Keep a date and its precision coherent on every write.
     *
     * The relationship half of the same rule as `ClientAffiliation`, and the reasoning is in
     * that class: a CHECK constraint only refuses, so the correction has to happen before the
     * insert or every caller has to remember. A relationship always has a start, so the start
     * is `day` unless an import stated `month`; only the end can be absent, and a relation
     * that is open has no end precision at all.
     */
    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            if (blank($assignment->started_on_precision)) {
                $assignment->started_on_precision = 'day';
            }

            if ($assignment->ended_on === null) {
                $assignment->ended_on_precision = null;
            } elseif (blank($assignment->ended_on_precision)) {
                $assignment->ended_on_precision = 'day';
            }
        });
    }

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

    /**
     * The rows that were effective on a date.
     *
     * `[started_on, ended_on)`: a period includes its start day and excludes its end
     * day, so a transfer effective on the first of March answers the old row on the
     * last of February and the new row on the first of March, with no day in
     * between belonging to neither and no day belonging to both.
     *
     * Distinct from `active()`, which asks whether a row is open *now*; this asks
     * whether it was effective on a day in the past.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeOn(Builder $query, \DateTimeInterface|string $date): void
    {
        EffectivePeriod::scopeActiveOn($query, $date);
    }
}

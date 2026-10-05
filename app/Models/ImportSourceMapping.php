<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\ImportProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A source spelling a person has decided maps to a catalogue entity.
 *
 * ## The decision is the value
 *
 * §9.2 forbids fuzzy merging, because two names one letter apart are two entities until
 * somebody who knows the health system says otherwise. So the parser never merges: it
 * normalises, and when the normalised token is not in this table it raises
 * `unresolved_social_entity` and suggests the closest catalogue name. A person records the
 * answer here and every later workbook with the same spelling resolves without anyone
 * deciding again.
 *
 * @property int $id
 * @property ImportProfile $profile
 * @property SocialSecurityEntityType $type
 * @property string $source_key
 * @property int $social_security_entity_id
 * @property Carbon|null $verified_at
 */
#[Fillable([
    'profile',
    'type',
    'source_key',
    'social_security_entity_id',
    'verified_by',
    'verified_at',
    'note',
])]
class ImportSourceMapping extends Model
{
    /** @use HasFactory<\\Database\\Factories\\ImportSourceMappingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'profile' => ImportProfile::class,
            'type' => SocialSecurityEntityType::class,
            'verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SocialSecurityEntity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(SocialSecurityEntity::class, 'social_security_entity_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

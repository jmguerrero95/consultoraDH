<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One integration credential or public setting.
 *
 * The value is encrypted at rest by the cast. Nothing in this class ever
 * returns a decrypted value to a controller: the resolver does, and the
 * controller decides whether the caller is allowed to know that it exists.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 */
final class IntegrationSetting extends Model
{
    protected $table = 'integration_settings';

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

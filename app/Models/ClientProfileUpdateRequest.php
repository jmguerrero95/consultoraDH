<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Portal\ProfileUpdateRequestStatus;
use Database\Factories\ClientProfileUpdateRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProfileUpdateRequest extends Model
{
    /** @use HasFactory<ClientProfileUpdateRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'requested_by_user_id',
        'proposed_changes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_changes' => 'array',
            'status' => ProfileUpdateRequestStatus::class,
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentRequestStatus;
use Database\Factories\ClientDocumentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientDocumentRequest extends Model
{
    /** @use HasFactory<ClientDocumentRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'document_type_id',
        'title',
        'instructions',
        'status',
        'due_on',
        'requested_by',
        'requested_at',
        'received_at',
        'reviewed_at',
        'approved_at',
        'reviewed_by',
        'decision_note',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentRequestStatus::class,
            'due_on' => 'date',
            'requested_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class, 'document_request_id');
    }
}

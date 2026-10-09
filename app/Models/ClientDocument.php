<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\DocumentReviewStatus;
use App\Domain\Documents\DocumentVisibility;
use Database\Factories\ClientDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDocument extends Model
{
    /** @use HasFactory<ClientDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'document_type_id',
        'document_request_id',
        'title',
        'description',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'visibility',
        'review_status',
        'uploaded_by_user_id',
        'uploaded_via_portal',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'retention_until',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => DocumentVisibility::class,
            'review_status' => DocumentReviewStatus::class,
            'size_bytes' => 'integer',
            'uploaded_via_portal' => 'boolean',
            'reviewed_at' => 'datetime',
            'retention_until' => 'date',
            'archived_at' => 'datetime',
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

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientDocumentRequest::class, 'document_request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

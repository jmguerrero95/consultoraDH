<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use Database\Factories\OperationalTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTask extends Model
{
    /** @use HasFactory<OperationalTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'novelty_id',
        'document_request_id',
        'contribution_sheet_id',
        'title',
        'description',
        'assigned_to',
        'created_by',
        'priority',
        'status',
        'due_on',
        'reminder_at',
        'reminder_sent_at',
        'completed_at',
        'completed_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_on' => 'date',
            'reminder_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function novelty(): BelongsTo
    {
        return $this->belongsTo(ClientNovelty::class, 'novelty_id');
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(ClientDocumentRequest::class, 'document_request_id');
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ContributionSheet::class, 'contribution_sheet_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}

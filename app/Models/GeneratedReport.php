<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Reports\ReportFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedReport extends Model
{
    /**
     * §55: the row records when the artifact was produced. It is an immutable log entry,
     * not an editable resource, so there is no `updated_at` to write — and declaring it
     * here is what stops Eloquent sending a column the table does not have.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'report_type',
        'filters',
        'format',
        'stored_path',
        'sha256',
        'size_bytes',
        'requested_by',
        'expires_at',
        'status',
        'failure_code',
        'failure_message',
    ];

    protected $casts = [
        'filters' => 'array',
        'format' => ReportFormat::class,
        'size_bytes' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

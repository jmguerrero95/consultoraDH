<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContributionSheetFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private attachment: the operator's PDF, a payment receipt, or anything else a person needs to
 * evidence a filing.
 *
 * §25: the operator's filename is metadata. The physical name is generated, so nothing a user types
 * reaches the filesystem path.
 */
class ContributionSheetFile extends Model
{
    /** @use HasFactory<ContributionSheetFileFactory> */
    use HasFactory;

    protected $fillable = [
        'contribution_sheet_id',
        'kind',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ContributionSheet::class, 'contribution_sheet_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

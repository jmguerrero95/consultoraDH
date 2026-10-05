<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Imports\LegacyImportIssue as LegacyImportIssueCode;
use App\Domain\Imports\LegacyIssueSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a person has to answer before anything is written.
 *
 * ## `blocking` is a column and not a lookup
 *
 * A reviewer can narrow an issue — "I checked this one and it does not block" — and the
 * apply must honour that. So the answer is stored rather than derived from the code. The
 * column defaults to the code's own opinion, which means the common case writes nothing
 * and only a deliberate decision departs from it.
 *
 * ## The context is sanitised before it is written
 *
 * Sheet, row number, cell reference and the token that was recognised. `credential_like_content`
 * names the pattern and the position and nothing else, because this column is rendered in
 * the review screen and §4.3 requires that the secret never reaches it.
 *
 * @property int $id
 * @property LegacyImportIssueCode $code
 * @property LegacyIssueSeverity $severity
 * @property bool $blocking
 * @property int|null $row_id
 * @property Carbon|null $resolved_at
 * @property string $fingerprint
 */
#[Fillable([
    'legacy_import_id',
    'row_id',
    'code',
    'severity',
    'blocking',
    'field',
    'message',
    'context',
    'resolved_by',
    'resolved_at',
    'resolution',
    'fingerprint',
])]
class LegacyImportIssue extends Model
{
    /** @use HasFactory<\\Database\\Factories\\LegacyImportIssueFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'code' => LegacyImportIssueCode::class,
            'severity' => LegacyIssueSeverity::class,
            'blocking' => 'boolean',
            'context' => 'array',
            'resolved_at' => 'immutable_datetime',
            'resolution' => 'array',
        ];
    }

    /** @return BelongsTo<LegacyImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class, 'legacy_import_id');
    }

    /** @return BelongsTo<LegacyImportRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRow::class, 'row_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}

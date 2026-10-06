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
 * @property Carbon|null $superseded_at
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
    'superseded_at',
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
            'superseded_at' => 'immutable_datetime',
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

    /**
     * Whether a person answered this finding. §4.1's `resolved_at`, and only that.
     *
     * Deliberately not `! $this->blocking`: a reviewer may narrow a finding without answering it,
     * and a superseded finding is closed without having been answered. `blocking` says whether
     * this stands between the import and Apply; `resolved_at` says whether a human is on the
     * record for it, and the audit trail needs those to be different questions.
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Whether this finding stopped applying: the reconstruction no longer produces it.
     *
     * Not the same as resolved, and never to be reported as such — nobody answered it, the
     * question went away. §4.3 keeps the row rather than deleting it so the import's history
     * still shows the question was asked.
     */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * Whether this finding still stands between the import and Apply.
     *
     * §17.5's disabled-Apply rule: a finding blocks when it is open *and* was not withdrawn.
     * A narrowed finding and a superseded one are both non-blocking, but only one of them ever
     * needed a person.
     */
    public function isBlocking(): bool
    {
        return $this->blocking
            && ! $this->isResolved()
            && ! $this->isSuperseded();
    }
}

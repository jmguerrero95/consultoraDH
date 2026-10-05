<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\LegacyImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded workbook and everything that happened to it.
 *
 * ## The status is the authorisation
 *
 * §12.2: `apply` accepts exactly one `ready → applying` transition. That is not enforced
 * by this model — it is enforced by a `SELECT … FOR UPDATE` on this row inside the
 * transaction, because a check-then-act in PHP is the race §5 of the R1 specification is
 * entirely about. What the model does is refuse to make the transition impossible to
 * express: `moveTo()` only accepts a state the enum says is reachable, so `applied` cannot
 * be dragged back into review by a careless call.
 *
 * ## `summary` holds counts
 *
 * Aggregate numbers about a parse and never cell contents. §4.3 requires every
 * representation of the workbook outside the private file to be redacted, and the cheapest
 * way to keep a JSON column redacted is for it never to have held anything to redact.
 *
 * @property int $id
 * @property string $uuid
 * @property ImportProfile $profile
 * @property string $original_filename
 * @property string $stored_path
 * @property string $sha256
 * @property int $file_size
 * @property LegacyImportStatus $status
 * @property Carbon|null $parse_started_at
 * @property Carbon|null $parsed_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $summary
 */
#[Fillable([
    'uuid',
    'profile',
    'original_filename',
    'stored_path',
    'sha256',
    'file_size',
    'status',
    'created_by',
    'parse_started_at',
    'parsed_at',
    'applied_at',
    'failed_at',
    'failure_code',
    'failure_message',
    'parse_attempts',
    'apply_attempts',
    'summary',
])]
class LegacyImport extends Model
{
    /** @use HasFactory<\\Database\\Factories\\LegacyImportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'profile' => ImportProfile::class,
            'status' => LegacyImportStatus::class,
            'parse_started_at' => 'immutable_datetime',
            'parsed_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'parse_attempts' => 'integer',
            'apply_attempts' => 'integer',
            'file_size' => 'integer',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<LegacyImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(LegacyImportRow::class, 'legacy_import_id');
    }

    /** @return HasMany<LegacyImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(LegacyImportIssue::class, 'legacy_import_id');
    }

    /** @return HasMany<LegacyImportAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(LegacyImportAction::class, 'legacy_import_id');
    }

    /** Blocking issues a person has not answered. What `apply` counts before refusing. */
    public function unresolvedBlockingIssues(): int
    {
        return $this->issues()
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->count();
    }

    /**
     * Move to a state, refusing a transition the enum does not allow.
     *
     * `save()` is used rather than a mass update so the caller sees the refusal as an
     * exception instead of discovering later that nothing moved.
     *
     * @throws \DomainException when the transition is not reachable
     */
    public function moveTo(LegacyImportStatus $to, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new \DomainException(sprintf(
                'Una importación en estado «%s» no puede pasar a «%s».',
                $this->status->label(),
                $to->label(),
            ));
        }

        $this->forceFill(['status' => $to, ...$attributes])->save();
    }

    /** The absolute path of the stored original, or null when the file is gone. */
    /**
     * Where the private copy lives, relative to the import disk.
     *
     * The one place this string is built. The upload endpoint writes it and the parse job reads
     * it, and when those two built the path separately they disagreed about the directory
     * prefix — so the upload succeeded, the job could not find the file, and the import failed
     * with "the private copy is no longer on the server" for a file that had just arrived.
     *
     * §5.1: the name is the UUID's and the extension is ours. The operator's filename is kept in
     * a column for display and never becomes part of a path, so a name like `../../.env` cannot
     * escape the directory.
     */
    public function storedRelativePath(): string
    {
        return 'imports/'.$this->uuid.'/source.xlsx';
    }

    /**
     * The absolute path of the private copy, or null when it is gone.
     *
     * Null rather than a fabricated path: a caller that gets null refuses the work, and a caller
     * handed a path that does not exist fails somewhere deeper with a worse message.
     */
    public function absolutePath(): ?string
    {
        $disk = Storage::disk(config('imports.disk'));
        $relative = $this->storedRelativePath();

        return $disk->exists($relative) ? $disk->path($relative) : null;
    }
}

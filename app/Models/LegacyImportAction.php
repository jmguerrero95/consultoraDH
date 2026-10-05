<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned write, and the only list `apply` walks.
 *
 * ## Why this is a table and not a computed preview
 *
 * Acceptance criterion 11. A preview computed on request and a plan computed again at apply
 * time are two explanations of one batch, and they diverge the moment a resolution or a
 * re-parse lands in between: the reviewer approved one set of changes and a different set
 * happened. So the preview renders these rows and the apply walks these rows.
 *
 * ## `target_type`/`target_id` answer the question backwards
 *
 * §13 asks which spreadsheet line produced a record. This asks the other direction — given
 * the record, which line is the evidence — and it is the direction an auditor has when they
 * are looking at a client's history and wondering where it came from.
 *
 * @property int $id
 * @property int $legacy_import_id
 * @property int $ordinal
 * @property ImportActionType $action_type
 * @property string $natural_key
 * @property array<string, mixed> $payload
 * @property list<int>|null $source_row_ids
 * @property array<string, mixed>|null $source_evidence
 * @property array<string, mixed>|null $preconditions
 * @property string $batch_fingerprint
 * @property ImportActionState $state
 */
#[Fillable([
    'legacy_import_id',
    'ordinal',
    'action_type',
    'natural_key',
    'payload',
    'source_row_ids',
    'source_evidence',
    'preconditions',
    'batch_fingerprint',
    'state',
    'target_type',
    'target_id',
    'skip_reason',
    'failure_message',
])]
class LegacyImportAction extends Model
{
    /** @use HasFactory<\\Database\\Factories\\LegacyImportActionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'action_type' => ImportActionType::class,
            'payload' => 'array',
            'source_row_ids' => 'array',
            'source_evidence' => 'array',
            'preconditions' => 'array',
            'state' => ImportActionState::class,
            'target_id' => 'integer',
        ];
    }

    /** @return BelongsTo<LegacyImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class, 'legacy_import_id');
    }

    /**
     * The `morph`-shaped target, as a label for the interface.
     *
     * Not an actual relation: an import action can point at five different tables, and five
     * relations would be five eager loads on a page that never displays more than one.
     */
    public function targetLabel(): ?string
    {
        return $this->target_type === null ? null : class_basename((string) $this->target_type);
    }
}

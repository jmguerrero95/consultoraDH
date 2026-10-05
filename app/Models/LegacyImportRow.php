<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Imports\ImportRowState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person-line in the source, normalised and waiting for a decision.
 *
 * ## Why the free-text columns are redacted, not trimmed
 *
 * §4.3. The delivered workbook carries credentials inside company titles and notes. The
 * redactor rewrites them in place (`CLAVE: [REDACTED]`) rather than dropping the cell,
 * because the text around a credential is operational evidence — "se.shared por CLAVE: …"
 * still tells a reviewer that the sheet is a shared one, and a blank cell would not.
 *
 * ## `normalized_payload` is the fingerprint's input
 *
 * `fingerprint` is a SHA-256 over the canonical JSON of that column. §7.3 needs to tell a
 * repeated line from a contradictory one, and the only way to do that without judgement is
 * to compare what the two lines *mean*: two cells that normalise to the same bytes are one
 * fact written twice; two that share a natural key but differ are a contradiction. A
 * fingerprint over the raw cells would classify a reformatted duplicate as a conflict, and
 * a spreadsheet reformatting its own dates is the normal case, not the exception.
 *
 * @property int $id
 * @property int $legacy_import_id
 * @property string $sheet_name
 * @property Carbon $sheet_month
 * @property int $source_row_number
 * @property string|null $company_tax_id
 * @property string|null $client_identity_key
 * @property Carbon|null $affiliation_date
 * @property int|null $monthly_amount_cop
 * @property string $fingerprint
 * @property ImportRowState $parse_state
 */
#[Fillable([
    'legacy_import_id',
    'sheet_name',
    'sheet_month',
    'source_row_number',
    'block_index',
    'company_block_key',
    'company_tax_id',
    'company_display_name',
    'client_identity_key',
    'document_type',
    'document_number',
    'first_names',
    'last_names',
    'address',
    'phone',
    'email',
    'affiliation_date_raw',
    'affiliation_date',
    'affiliation_date_precision',
    'monthly_amount_cop',
    'eps_token',
    'afp_token',
    'ccf_token',
    'arl_token',
    'arl_risk_class',
    'risk_raw',
    'job_title',
    'novelty',
    'operator_ref',
    'payroll_ref',
    'source_reference',
    'retirement_month_token',
    'retirement_day_count',
    'normalized_payload',
    'fingerprint',
    'parse_state',
])]
class LegacyImportRow extends Model
{
    /** @use HasFactory<\\Database\\Factories\\LegacyImportRowFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sheet_month' => 'date',
            'source_row_number' => 'integer',
            'block_index' => 'integer',
            'affiliation_date' => 'date',
            'monthly_amount_cop' => 'integer',
            'arl_risk_class' => 'integer',
            'retirement_day_count' => 'integer',
            'normalized_payload' => 'array',
            'parse_state' => ImportRowState::class,
        ];
    }

    /** @return BelongsTo<LegacyImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class, 'legacy_import_id');
    }

    /** @return HasMany<LegacyImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(LegacyImportIssue::class, 'row_id');
    }

    /** Where a person would look to find this line: `ENERO 2026 · fila 42`. */
    public function sourceReference(): string
    {
        return sprintf('%s · fila %d', $this->sheet_name, $this->source_row_number);
    }
}

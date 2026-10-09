<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\NoveltyCategory;
use App\Domain\Operations\NoveltyStatus;
use Database\Factories\ClientNoveltyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNovelty extends Model
{
    /** @use HasFactory<ClientNoveltyFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'company_id',
        'monthly_period_id',
        'contribution_sheet_id',
        'category',
        'title',
        'details',
        'status',
        'occurred_on',
        'created_by',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => NoveltyCategory::class,
            'status' => NoveltyStatus::class,
            'occurred_on' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MonthlyPeriod::class, 'monthly_period_id');
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ContributionSheet::class, 'contribution_sheet_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

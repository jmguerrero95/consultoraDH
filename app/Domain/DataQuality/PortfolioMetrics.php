<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;

/**
 * The counts shown on the dashboard.
 *
 * Every number here is a query against the tables themselves. There is no
 * denormalised counter to drift out of date and no estimate, because a dashboard
 * that shows a plausible figure which is not the real one is worse than one that
 * shows nothing.
 *
 * Each query is a single aggregate, so the whole set costs a handful of index
 * scans rather than anything proportional to the size of the portfolio.
 */
final class PortfolioMetrics
{
    public function __construct(
        private readonly DataQualityInspector $quality,
    ) {}

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'active_clients' => Client::query()->where('status', 'active')->count(),
            'inactive_clients' => Client::query()->where('status', 'inactive')->count(),
            'active_companies' => Company::query()->where('status', 'active')->count(),
            'active_relationships' => ClientCompanyAssignment::query()->whereNull('ended_on')->count(),
            'active_affiliations' => ClientAffiliation::query()->whereNull('ended_on')->count(),
            'catalogue_entities' => SocialSecurityEntity::query()
                ->where('status', 'active')
                ->count(),
            'data_quality_issues' => $this->quality->errorCount(),
            'data_quality_warnings' => $this->quality->warningCount(),
        ];
    }

    /**
     * Clients with more than one open company relationship, whether authorised
     * or not. It is the number an administrator most wants to see, because it is
     * the one situation that usually means the source data was misread.
     */
    public function clientsWithMultipleCompanies(): int
    {
        return $this->quality->countClientsWithSeveralOpenRelationships();
    }
}

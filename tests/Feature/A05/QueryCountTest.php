<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientDocument;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A05-PERF-01 — a list does not cost one query per row
|--------------------------------------------------------------------------
|
| The client's history read the actor of every entry, and the portal's document
| list read the type of every document, without eager loading the relation. A
| client with fifty events cost fifty queries, and a client with twenty
| documents cost twenty more.
|
| The test counts the statements the request really issues, so the guarantee is
| a number and not an intention.
|
*/

beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * The statements a request issues.
 *
 * @return list<string>
 */
function perf01Queries(callable $callback): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

it('reads the history of a client without a query per entry', function (): void {
    $actor = User::factory()->create(['status' => UserStatus::Active]);
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // Fifty entries, all with an actor: the worst case for a lazy relation.
    for ($i = 0; $i < 50; $i++) {
        AuditEvent::query()->create([
            'user_id' => $actor->id,
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'action' => 'client.updated',
            'metadata' => ['n' => $i],
            'created_at' => now(),
        ]);
    }

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
    ]);

    AuditEvent::query()->create([
        'user_id' => $actor->id,
        'subject_type' => ClientCompanyAssignment::class,
        'subject_id' => $assignment->id,
        'action' => 'relationship.created',
        'created_at' => now(),
    ]);

    $viewer = userWithPermissions(['clients.view', 'relationships.view', 'affiliations.view']);

    $queries = perf01Queries(function () use ($viewer, $client): void {
        $this->actingAs($viewer)
            ->getJson("/api/clients/{$client->id}")
            ->assertOk();
    });

    $userQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "users"'));

    // One query for the fifty actors, not fifty queries.
    expect(count($userQueries))->toBeLessThanOrEqual(3)
        ->and(count($queries))->toBeLessThanOrEqual(20);
});

it('lists the documents of a portal client without a query per document', function (): void {
    $client = Client::factory()->create();
    $type = DocumentType::factory()->create();

    $portal = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $client->id,
        'status' => UserStatus::Active,
    ]);

    for ($i = 0; $i < 20; $i++) {
        ClientDocument::factory()->create([
            'client_id' => $client->id,
            'document_type_id' => $type->id,
            'visibility' => 'client',
        ]);
    }

    $queries = perf01Queries(function () use ($portal): void {
        $this->actingAs($portal)
            ->getJson('/api/portal/documents')
            ->assertOk();
    });

    $typeQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "document_types"'));

    expect(count($typeQueries))->toBeLessThanOrEqual(2)
        ->and(count($queries))->toBeLessThanOrEqual(12);
});

it('tells the truth about how many companies a client has', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
    ]);

    $viewer = userWithPermissions(['clients.view', 'relationships.view']);

    $row = collect(
        $this->actingAs($viewer)
            ->getJson("/api/clients?search={$client->document_number}")
            ->assertOk()
            ->json('clients')
    )->firstWhere('id', $client->id);

    // The count is the number of companies the row lists, not a null that
    // forced the interface to print "—".
    expect($row['companies_count'])->toBe(1)
        ->and($row['companies'])->toHaveCount(1);
});

<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use App\Models\User;

/**
 * The end to end cleanup command.
 *
 * The suite used to remove only its two accounts and left every client, company,
 * entity, relationship, affiliation and audit row behind, so a green run made the
 * development database fuller than a red one. This command is what stops that, and
 * these tests are about the two things it has to get right at once:
 *
 *  - it removes everything the run created, including the audit rows that name the
 *    deleted rows and the sign-in events that name the deleted accounts;
 *  - it removes nothing else, which is the half that matters most. A cleanup that
 *    is too broad is a different way of losing a developer's data.
 *
 * Matching is by the run stamp in a field that belongs to the record, never by a
 * name that merely looks like test data.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * One run's worth of records: two accounts and the portfolio they touch.
 */
function seedEndToEndRun(string $stamp): array
{
    $admin = User::factory()->create(['email' => "e2e.{$stamp}@consultora-dh.test"]);
    $admin->assignRole('Administrator');

    $client = Client::factory()->create([
        'document_number' => '1'.$stamp,
        'first_names' => 'Ana',
        'last_names' => 'E2E',
    ]);

    $company = Company::factory()->create([
        'legal_name' => "Empresa E2E {$stamp}",
        'tax_id' => '900'.$stamp,
    ]);

    $entity = SocialSecurityEntity::factory()->create(['name' => "EPS E2E {$stamp}"]);

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'ended_on' => null,
    ]);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'ended_on' => null,
    ]);

    // The audit rows a real run writes: about the masters, about the history rows,
    // and about the account's own sign in, which carries no subject at all.
    foreach ([
        [Client::class, $client->id, 'client.created'],
        [Company::class, $company->id, 'company.created'],
        [SocialSecurityEntity::class, $entity->id, 'entity.created'],
        [ClientCompanyAssignment::class, $assignment->id, 'relationship.created'],
        [ClientAffiliation::class, $affiliation->id, 'affiliation.created'],
    ] as [$type, $id, $action]) {
        AuditEvent::query()->create([
            'user_id' => $admin->id,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'created_at' => now(),
        ]);
    }

    AuditEvent::query()->create([
        'user_id' => $admin->id,
        'action' => 'auth.login.succeeded',
        'created_at' => now(),
    ]);

    return compact('admin', 'client', 'company', 'entity', 'assignment', 'affiliation');
}

/**
 * Run the cleanup command the way the shell script does.
 *
 * The parameters are keyed by option name, which is what Symfony's ArrayInput
 * expects: a list is read as positional arguments and the command has none.
 *
 * @param  list<string>  $emails
 */
function cleanUp(string $stamp, array $emails = []): int
{
    $parameters = ['--stamp' => $stamp];

    foreach ($emails as $email) {
        $parameters['--email'][] = $email;
    }

    return Artisan::call('consultora-dh:e2e-cleanup', $parameters);
}

it('removes everything one run created', function (): void {
    $run = seedEndToEndRun('11112222');

    expect(cleanUp('11112222', [$run['admin']->email]))->toBe(0);

    // Children, then masters, then the account.
    expect(ClientAffiliation::query()->whereKey($run['affiliation']->id)->exists())->toBeFalse()
        ->and(ClientCompanyAssignment::query()->whereKey($run['assignment']->id)->exists())->toBeFalse()
        ->and(Client::query()->whereKey($run['client']->id)->exists())->toBeFalse()
        ->and(Company::query()->whereKey($run['company']->id)->exists())->toBeFalse()
        ->and(SocialSecurityEntity::query()->whereKey($run['entity']->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($run['admin']->id)->exists())->toBeFalse();

    // And the trail, including the row that names no subject and exists only because
    // the account that wrote it has gone.
    expect(AuditEvent::query()->where('user_id', $run['admin']->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()
            ->whereIn('subject_id', [
                $run['client']->id,
                $run['company']->id,
                $run['entity']->id,
                $run['assignment']->id,
                $run['affiliation']->id,
            ])
            ->exists())->toBeFalse();
});

it('removes nothing that belongs to another run', function (): void {
    $mine = seedEndToEndRun('33334444');
    $theirs = seedEndToEndRun('55556666');

    cleanUp('33334444', [$mine['admin']->email]);

    expect(Client::query()->whereKey($theirs['client']->id)->exists())->toBeTrue()
        ->and(Company::query()->whereKey($theirs['company']->id)->exists())->toBeTrue()
        ->and(SocialSecurityEntity::query()->whereKey($theirs['entity']->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($theirs['admin']->id)->exists())->toBeTrue()
        ->and(ClientCompanyAssignment::query()->whereKey($theirs['assignment']->id)->exists())->toBeTrue()
        ->and(ClientAffiliation::query()->whereKey($theirs['affiliation']->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('user_id', $theirs['admin']->id)->exists())->toBeTrue();
});

it('does not delete a record for having a name that looks like test data', function (): void {
    // The rule the command must not break: a developer's own company that happens to
    // contain the letters "E2E" is not this run's record. Only the stamp identifies
    // a run's data.
    $company = Company::factory()->create(['legal_name' => 'Servicios E2E S.A.S.']);
    $client = Client::factory()->create(['last_names' => 'Prueba E2E']);

    cleanUp('77778888');

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeTrue();
});

it('refuses without a stamp rather than guessing', function (): void {
    $run = seedEndToEndRun('99990000');

    expect(cleanUp(''))->not->toBe(0)
        // Nothing was touched, because nothing identified anything.
        ->and(Client::query()->whereKey($run['client']->id)->exists())->toBeTrue();
});

it('refuses a stamp that is not an identifier', function (): void {
    // A wildcard would make the match a table scan of everything. A refusal is the
    // answer, and it is the reason the pattern is validated before it is used.
    $run = seedEndToEndRun('12341234');

    expect(cleanUp('%'))->not->toBe(0)
        ->and(cleanUp('a%b'))->not->toBe(0)
        ->and(cleanUp("' OR 1=1 --"))->not->toBe(0)
        ->and(Client::query()->whereKey($run['client']->id)->exists())->toBeTrue();
});

it('reports without deleting in a dry run', function (): void {
    $run = seedEndToEndRun('12121212');

    expect(Artisan::call('consultora-dh:e2e-cleanup', ['--stamp' => '12121212', '--dry-run' => true]))->toBe(0);

    expect(Client::query()->whereKey($run['client']->id)->exists())->toBeTrue()
        ->and(Company::query()->whereKey($run['company']->id)->exists())->toBeTrue();
});

it('leaves a run that created nothing alone', function (): void {
    $company = Company::factory()->create(['legal_name' => 'Empresa mia']);

    expect(cleanUp('13131313'))->toBe(0)
        ->and(Company::query()->whereKey($company->id)->exists())->toBeTrue();
});

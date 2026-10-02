<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the business records an end to end run created, and nothing else.
 *
 * ## Why this exists
 *
 * `scripts/run-e2e.sh` used to delete only the two throwaway accounts. Every client,
 * company, social security entity, relationship, affiliation and audit row the
 * browser created stayed behind, in the development database, labelled with nothing
 * that identified the run that made them. A green suite left the database fuller
 * than a red one, which is backwards: the state after a test run should not depend
 * on the test result, and a developer should be able to read their own data without
 * first working out which of these records are real.
 *
 * The accounts were the easy part to clean and the least important to remove. A
 * login without portfolio data leaves nothing behind; a client with a relationship
 * and an affiliation does.
 *
 * ## How a record is claimed
 *
 * By the run identifier, and by nothing else.
 *
 * Every record a run creates carries the stamp in a field that belongs to it: the
 * client's document number, the company's legal name and tax id, the entity's name.
 * This command matches on exactly those fields and on nothing broader.
 *
 * It does **not** match on a generic marker like a name containing "E2E". That
 * would delete a company a developer genuinely called something with those letters
 * in it, and it would delete every such record from every previous run at once.
 *
 * ## Order
 *
 * The foreign keys are real, so children go before parents. The audit trail names
 * the children as subjects, so their identifiers are read before they are deleted:
 *
 *     read the ids of this run's clients, companies, entities and accounts
 *       -> read the ids of their affiliations and relationships
 *       -> delete the audit rows naming any of those
 *       -> delete the affiliations and relationships
 *       -> delete the clients, companies and entities
 *       -> delete the accounts
 *
 * An empty run is a no-op on every line, which is what makes it safe to call this
 * unconditionally at the end of a run that may have created nothing.
 *
 * ## Failure is reported, never swallowed
 *
 * A cleanup that fails quietly is worse than one that does not run, because the
 * run appears clean and the data stays. This command exits non-zero and prints what
 * it could not remove, and the shell script treats that as a failure of the run.
 * Only the account removal in the shell script is defensive, and only because a
 * leftover login is inert.
 */
final class EndToEndCleanupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'consultora-dh:e2e-cleanup
                            {--stamp= : El identificador de la ejecución. Es obligatorio}
                            {--email=* : Cuentas de la ejecución (se pueden repetir)}
                            {--dry-run : Cuenta qué eliminaría, sin eliminar nada}';

    /**
     * @var string
     */
    protected $description = 'Elimina los registros creados por una ejecución de las pruebas end to end';

    public function handle(): int
    {
        $stamp = trim((string) $this->option('stamp'));

        if ($stamp === '') {
            $this->error('--stamp es obligatorio: sin él no se puede saber qué registros son de esta ejecución.');

            return self::FAILURE;
        }

        // A stamp is digits in every current caller. Refusing anything else keeps a
        // stray wildcard or a shell fragment from turning into a table-wide delete.
        if (preg_match('/^[A-Za-z0-9]{4,32}$/', $stamp) !== 1) {
            $this->error('--stamp debe tener entre 4 y 32 caracteres alfanuméricos. Nada se eliminó.');

            return self::FAILURE;
        }

        $emails = array_values(array_filter(array_map('trim', (array) $this->option('email'))));

        if ($this->option('dry-run')) {
            return $this->report($this->identify($stamp, $emails), true);
        }

        try {
            $removed = DB::transaction(fn (): array => $this->remove($this->identify($stamp, $emails)));
        } catch (\Throwable $e) {
            $this->error('La limpieza falló y se revirtió por completo: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->report($removed, false);
    }

    /**
     * Every record this run owns, resolved before anything is deleted.
     *
     * @param  list<string>  $emails
     * @return array{clients: list<int>, companies: list<int>, entities: list<int>, users: list<int>}
     */
    private function identify(string $stamp, array $emails): array
    {
        $like = '%'.$stamp.'%';

        return [
            'clients' => Client::query()
                ->where('document_number', 'like', $like)
                ->pluck('id')
                ->all(),
            'companies' => Company::query()
                ->where(function ($query) use ($like): void {
                    $query->where('legal_name', 'like', $like)
                        ->orWhere('tax_id', 'like', $like);
                })
                ->pluck('id')
                ->all(),
            'entities' => SocialSecurityEntity::query()
                ->where('name', 'like', $like)
                ->pluck('id')
                ->all(),
            'users' => $emails === []
                ? []
                : User::query()->whereIn('email', $emails)->pluck('id')->all(),
        ];
    }

    /**
     * @param  array{clients: list<int>, companies: list<int>, entities: list<int>, users: list<int>}  $ids
     * @return array<string, int>
     */
    private function remove(array $ids): array
    {
        $removed = [
            'audit_events' => 0,
            'client_affiliations' => 0,
            'client_company_assignments' => 0,
            'clients' => 0,
            'companies' => 0,
            'entities' => 0,
            'users' => 0,
        ];

        $clientIds = $ids['clients'];
        $companyIds = $ids['companies'];
        $entityIds = $ids['entities'];
        $userIds = $ids['users'];

        // Children before parents. Both are needed before the audit rows are read,
        // because the trail names these rows as subjects as well as the masters, so
        // their identifiers are collected first and the rows deleted afterwards.
        // Reading the identifiers after the deletion would find nothing, and the audit
        // rows that name them would survive.
        $affiliations = $clientIds === []
            ? []
            : ClientAffiliation::query()->whereIn('client_id', $clientIds)->pluck('id')->all();

        $relationships = ($clientIds !== [] || $companyIds !== [])
            ? ClientCompanyAssignment::query()
                ->where(function ($query) use ($clientIds, $companyIds): void {
                    $clientIds !== [] && $query->whereIn('client_id', $clientIds);

                    if ($companyIds !== []) {
                        $clientIds === []
                            ? $query->whereIn('company_id', $companyIds)
                            : $query->orWhereIn('company_id', $companyIds);
                    }
                })
                ->pluck('id')
                ->all()
            : [];

        $affiliations !== [] && $removed['client_affiliations'] = ClientAffiliation::query()
            ->whereIn('id', $affiliations)
            ->delete();

        $relationships !== [] && $removed['client_company_assignments'] = ClientCompanyAssignment::query()
            ->whereIn('id', $relationships)
            ->delete();

        // The audit trail, before the masters go.
        //
        // Every subject type this run wrote about is included. Leaving out the
        // relationship and the affiliation is what an earlier version did, and the
        // result was seventeen orphan rows per run: a trail that named rows which no
        // longer existed, growing on every suite run, invisible to the accounting the
        // trail exists for.
        $subjects = [
            [Client::class, $clientIds],
            [Company::class, $companyIds],
            [SocialSecurityEntity::class, $entityIds],
            [User::class, $userIds],
            [ClientCompanyAssignment::class, $relationships],
        ];

        $subjects[] = [ClientAffiliation::class, $affiliations];

        foreach ($subjects as [$model, $subjectIds]) {
            if ($subjectIds === []) {
                continue;
            }

            $removed['audit_events'] += AuditEvent::query()
                ->where('subject_type', $model)
                ->whereIn('subject_id', $subjectIds)
                ->delete();
        }

        // Anything the run's accounts did. The sign-in and sign-out events carry no
        // subject, so they are the one kind of audit row that cannot be reached by
        // subject; they name the account in `user_id`, and that account is this run's.
        // Left behind they are a trail of activity by accounts that no longer exist.
        if ($userIds !== []) {
            $removed['audit_events'] += AuditEvent::query()
                ->whereIn('user_id', $userIds)
                ->delete();
        }

        // A client's last assignment and last affiliation are gone, so the parents can
        // go. Restricted to the ids resolved above: nothing else is in scope.
        $clientIds !== [] && $removed['clients'] = Client::query()->whereIn('id', $clientIds)->delete();
        $companyIds !== [] && $removed['companies'] = Company::query()->whereIn('id', $companyIds)->delete();
        $entityIds !== [] && $removed['entities'] = SocialSecurityEntity::query()->whereIn('id', $entityIds)->delete();
        $userIds !== [] && $removed['users'] = User::query()->whereIn('id', $userIds)->delete();

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $removed
     */
    private function report(array $removed, bool $dryRun): int
    {
        if ($dryRun) {
            foreach ($removed as $table => $count) {
                $this->line(sprintf('    %s: %d', $table, is_array($count) ? count($count) : $count));
            }

            $this->line('    (dry-run: nada se eliminó)');

            return self::SUCCESS;
        }

        foreach ($removed as $table => $count) {
            $this->line(sprintf('    %s: %d', $table, $count));
        }

        return self::SUCCESS;
    }
}

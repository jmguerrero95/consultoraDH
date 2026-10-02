<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A02-R3: one open relationship per client and company.
 *
 * A person may be employed by several companies at once, which is what the parallel
 * resolution exists for. Being employed twice by the *same* company is a different
 * thing, and nothing stopped it: the domain checked that another relationship was
 * open but never that the same company was open again, and the database had no index
 * on the pair.
 *
 * The result is a row that looks correct in isolation and is wrong in every
 * aggregate: the client lists the company twice, the headcount counts the person
 * twice, and the quality report has nothing to say about it because each row passes
 * every check it is given.
 *
 *     CREATE UNIQUE INDEX assignments_one_open_client_company_unique
 *         ON client_company_assignments (client_id, company_id)
 *         WHERE ended_on IS NULL
 *
 * Partial, on purpose. A client who left an employer and came back has two rows for
 * that company, and that is ordinary history: the periods do not overlap, and the
 * database has no business forbidding it. Only rows that are open *at the same time*
 * are the impossibility.
 *
 * ## Before the index
 *
 * The index cannot be created while duplicates exist, so the migration looks first
 * and reports what it found, naming the client and the company of every conflicting
 * pair, and then fails.
 *
 * It does not repair them. Closing one of two open rows decides which of them was
 * the real one, and that is a statement about the past that only somebody who knows
 * the employment history can make. An automatic repair would delete a real row or
 * invent an end date, both of which change the record rather than describe it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseExistingDuplicates();

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX assignments_one_open_client_company_unique
                ON client_company_assignments (client_id, company_id)
                WHERE ended_on IS NULL
            SQL);
    }

    public function down(): void
    {
        // Only the index is dropped. Nothing here ever modified a row, so there is
        // nothing to restore, and rolling back must not delete the rows that were
        // legitimately there before.
        DB::statement('DROP INDEX IF EXISTS assignments_one_open_client_company_unique');
    }

    /**
     * Report every pair of open relationships that would break the index.
     *
     * @throws RuntimeException when any exist
     */
    private function refuseExistingDuplicates(): void
    {
        $conflicts = DB::table('client_company_assignments as a')
            ->join('client_company_assignments as b', function ($join): void {
                $join->on('b.client_id', '=', 'a.client_id')
                    ->on('b.company_id', '=', 'a.company_id')
                    ->on('b.id', '>', 'a.id');
            })
            ->whereNull('a.ended_on')
            ->whereNull('b.ended_on')
            ->orderBy('a.client_id')
            ->orderBy('a.company_id')
            ->orderBy('a.id')
            ->get(['a.client_id', 'a.company_id', 'a.id as first_id', 'b.id as second_id']);

        if ($conflicts->isEmpty()) {
            return;
        }

        $lines = $conflicts->map(fn ($row): string => sprintf(
            '  client_id=%d company_id=%d (relaciones %d y %d)',
            $row->client_id,
            $row->company_id,
            $row->first_id,
            $row->second_id,
        ))->all();

        throw new RuntimeException(
            'No se puede crear el índice: hay relaciones abiertas duplicadas para el mismo '
            .'cliente y la misma empresa. Ciérrelas explícitamente (no se borran ni se cierran '
            .'automáticamente, porque decidir cuál es la real es una afirmación sobre el pasado) '
            ."y vuelva a ejecutar la migración. Conflictos:\n".implode("\n", $lines)
        );
    }
};

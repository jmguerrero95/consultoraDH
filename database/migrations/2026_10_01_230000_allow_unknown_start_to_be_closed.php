<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A02-R2: an affiliation with an unknown start date can be closed.
 *
 * `started_on` is nullable on purpose: the historical source data contains
 * affiliations whose start nobody knows, and inventing a date to satisfy a
 * constraint would replace a doubt with a fact.
 *
 * The A02 check then made that doubt permanent:
 *
 *     CHECK (ended_on IS NULL OR (started_on IS NOT NULL AND ended_on >= started_on))
 *
 * A row with `started_on = NULL` satisfies the constraint only while `ended_on` is
 * also NULL. So such a record could be created and never closed, and never moved to
 * another entity: the two operations that write an `ended_on` were unreachable for
 * exactly the rows the nullable column was meant to allow. The domain has no way to
 * invent a start date to get past it, so the honest answer is to widen the
 * constraint rather than to guess at a date.
 *
 * The new rule orders the bounds only when both are known:
 *
 *     CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)
 *
 *   both known       the order is enforced, as before
 *   unknown start    a known end may be recorded, and the start stays unknown
 *   no end           anything goes
 *
 * Uncertainty is preserved rather than resolved, which is the whole principle of
 * this layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT affiliations_dates_check');

        DB::statement(
            'ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_dates_check '
            .'CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)'
        );
    }

    public function down(): void
    {
        // The old constraint cannot be restored while a row has an unknown start and
        // a known end, which is the state this migration exists to make reachable.
        DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT affiliations_dates_check');

        DB::statement(
            'ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_dates_check '
            .'CHECK (ended_on IS NULL OR (started_on IS NOT NULL AND ended_on >= started_on))'
        );
    }
};

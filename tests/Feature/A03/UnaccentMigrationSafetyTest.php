<?php

declare(strict_types=1);

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * A03-R3 §5: the `unaccent` migration must not destroy a shared extension when rolled back.
 *
 * ## The defect
 *
 * `2026_10_03_130000_enable_unaccent_extension.php` runs
 *
 *     CREATE EXTENSION IF NOT EXISTS unaccent
 *
 * in `up()`, and its `down()` ran
 *
 *     DROP EXTENSION IF EXISTS unaccent
 *
 * `IF NOT EXISTS` makes the statement idempotent. It does not make it *informative*: the
 * database does not record whether the statement created the extension or found it already
 * present, so by the time `down()` runs there is nothing left to tell those two cases apart.
 *
 * They need opposite rollbacks:
 *
 *  1. This migration created it → dropping it leaves the database as it was.
 *  2. It already existed, or another feature depends on it → dropping it breaks that consumer,
 *     irreversibly and from inside a rollback nobody was thinking about.
 *
 * The migration chose case 1 on no evidence. That is the destructive branch.
 *
 * ## What is asserted
 *
 * The migration's **source**, not its behaviour. A behavioural test that runs `down()` and
 * checks the extension is gone would assert exactly the defect; a behavioural test that runs
 * `down()` and checks it survived only proves the extension is idempotent, which is a
 * property of PostgreSQL and not of this file. What can regress is somebody rewriting `down()`
 * to drop it again, and that is a source property.
 *
 * The assertions also check `up()` is untouched, because the fix here is to the rollback only.
 * Removing `unaccent` from the application's search was explicitly out of scope, and a test
 * that only checked for the absence of `DROP EXTENSION` would pass against a migration that
 * had been gutted entirely.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function a03_unaccentMigration(): string
{
    $path = database_path('migrations/2026_10_03_130000_enable_unaccent_extension.php');

    expect(is_file($path), $path)->toBeTrue();

    return (string) file_get_contents($path);
}

it('never drops the unaccent extension when rolled back', function (): void {
    $source = a03_unaccentMigration();

    // The defect, stated as a property of the file rather than of a run: any statement that
    // drops the extension out of a rollback is the destructive branch, whether it is spelled
    // `DROP EXTENSION`, lower case, or split across a heredoc.
    expect($source)->not->toMatch('/DROP\s+EXTENSION/i');

    // And the function body itself, isolated from the prose that explains why it is absent.
    // A comment may name the command; executable PHP may not.
    $body = a03_migrationDownBody($source);

    expect($body)->not->toMatch('/DROP\s+EXTENSION/i')
        ->and($body)->not->toMatch('/unprepared\s*\(/i')
        ->and($body)->not->toMatch('/DB::statement\s*\(/i');
});

it('is a genuine no-op, and says so rather than leaving it silent', function (): void {
    $source = a03_unaccentMigration();

    // Two separate claims, checked separately on purpose.
    //
    // First, that the body really is empty. "No `DROP EXTENSION`" and "does nothing" are
    // not the same statement: a body that ran `DB::unprepared('SELECT 1')` satisfies the
    // first and fails this one.
    // Matched as a pattern rather than compared as a string, so the assertion is about the
    // method being an empty pair of braces and not about how it happens to be indented.
    expect(a03_migrationDownBody($source))
        ->toMatch('/public\s+function\s+down\s*\(\s*\)\s*:\s*void\s*\{\s*\}/');

    // Second, that the decision is documented. Asserted against the whole file, because the
    // explanation *is* comments — both the docblock above the method and the note inside it.
    // A silently empty `down()` reads as an oversight and gets "fixed" by the next person who
    // runs it, which is how the destructive version came to exist in the first place.
    expect($source)->toContain('no-op')
        // The specific reason, not a generic apology: this migration cannot know whether it
        // created a shared object.
        ->and($source)->toMatch('/cannot report whether|does not know whether|does not own/i')
        ->and($source)->toMatch('/shared/i');
});

it('still installs the extension on the way up', function (): void {
    $source = a03_unaccentMigration();

    // `up()` is unchanged by this round. If it were gutted, "there is no DROP EXTENSION"
    // would still be true and every other assertion in this file would still pass.
    expect($source)->toMatch('/CREATE\s+EXTENSION\s+IF\s+NOT\s+EXISTS\s+unaccent/i');
});

it('actually has the extension installed, so the searches are not silently broken', function (): void {
    // The counterpart to the rollback change: making `down()` a no-op must not have been
    // achieved by removing the extension from the schema. `SafeSearch::match()` is written
    // with `unaccent`, so without the extension every searchable screen would 500 — and a
    // test that only reads the migration's source would not notice.
    expect(DB::selectOne(
        "select extname from pg_extension where extname = 'unaccent'",
    ))->not->toBeNull();

    expect(DB::selectOne("select lower(unaccent('ÚNICA')) as folded"))
        ->folded->toBe('unica');

    // And through the HTTP layer, which is where the absence would be felt.
    Client::factory()->create([
        'first_names' => 'Única',
        'last_names' => 'Prueba',
        'document_number' => '93001111',
    ]);

    $ids = collect(
        $this->actingAs(userWithPermissions(['clients.view']))
            ->getJson('/api/clients?search='.urlencode('Única'))
            ->assertOk()
            ->json('clients')
    )->pluck('id');

    expect($ids)->not->toBeEmpty();
});

/**
 * The source of `down()`, with its comments removed.
 *
 * The same distinction `EndToEndIsolationTest` had to learn for shell: this migration's
 * docblock names `DROP EXTENSION IF EXISTS unaccent` several times **while explaining why it
 * is not there**, so a search over the whole file cannot distinguish the decision from the
 * reasoning about it.
 *
 * Only full-line comments are stripped. A trailing `// DROP EXTENSION` after real code is
 * still real code.
 */
function a03_migrationDownBody(string $source): string
{
    $start = strpos($source, 'public function down');

    expect($start)->not->toBeFalse();

    $from = substr($source, (int) $start);

    $kept = array_filter(
        explode("\n", $from),
        static function (string $line): bool {
            $trimmed = ltrim($line);

            return $trimmed !== '' && ! str_starts_with($trimmed, '//') && ! str_starts_with($trimmed, '*');
        },
    );

    $body = implode("\n", $kept);

    // Strip the opening brace of the method and anything after the closing one, so the
    // assertions above are about this method and not about whatever follows it in the file.
    $close = strrpos($body, '}');

    return $close === false ? $body : substr($body, 0, (int) $close);
}

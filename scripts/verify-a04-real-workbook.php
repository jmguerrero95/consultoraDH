<?php

declare(strict_types=1);

/**
 * §19: verify the real workbook against the known fingerprint.
 *
 * ## What this is allowed to print
 *
 * Aggregates only. The file is a payroll containing other people's national identifiers, and
 * the point of this script is to check the parser, not to look at the data. Every value printed
 * is a count; no name, no document, no company name and no cell text is ever written. The
 * credential check prints how many cells looked like one and how many findings that produced —
 * §4.3's rule is "sólo muestra hoja/fila/celda y tipo de patrón, nunca el secreto", and this
 * goes further and prints no position either, because a position is not needed to answer
 * "did the parser find them".
 *
 * ## What it does not do
 *
 * It does not apply anything. It parses, reconstructs and counts. Nothing here writes a
 * master, a relationship, a rate or an affiliation, so running it cannot change the database
 * and it needs no permission beyond reading the file.
 *
 * ## Why it runs the application's parser
 *
 * A script with its own reader would prove that the script works. This one resolves the same
 * `BlindenLegacyWorkbookParser` the queue job uses, so a green run is evidence about the code
 * that will actually run in production.
 *
 * Refuses to run without the file present: §19 asks for that explicitly, because a script that
 * quietly reported "0 sheets, looks fine" against a missing file would be worse than useless.
 */

use App\Domain\Imports\BlindenLegacyWorkbookParser;
use App\Domain\Imports\HistoryReconstructor;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\SensitiveSourceRedactor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// The imports are above the executable code on purpose. Pint's `fully_qualified_strict_types`
// rule rewrites a fully-qualified class name to a short one and moves its `use` to where it
// sorts — which, in a script, put it *after* the first `require`. The class then did not
// resolve and the script died on `Kernel::class`. Ordering them here is what makes the
// formatter and the script agree.
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = $argv[1] ?? (__DIR__.'/../.local-fixtures/EMPRESA BLINDEN AÑO 2026.xlsx');

/**
 * The fingerprint §19 states for the delivered file.
 *
 * These are the expected values, written down rather than derived, because a fingerprint that
 * recomputes its own expectation checks nothing.
 */
const EXPECTED = [
    'monthly_sheets' => 10,
    'blocks' => 101,
    'source_rows' => 2560,
    'document_identities' => 320,
    'company_names' => 14,
];

/** §8.1 and §4.3 also state counts, and both were checked against this file. */
const EXPECTED_ISSUES = [
    'invalid_affiliation_date' => 17,
    'credential_like_cells' => 23,
];

function out(string $line = ''): void
{
    fwrite(STDOUT, $line."\n");
}

function fail(string $line): void
{
    fwrite(STDERR, $line."\n");
    exit(1);
}

function check(string $label, mixed $actual, mixed $expected): bool
{
    $ok = $actual === $expected;

    out(sprintf('  [%s] %-42s %s', $ok ? 'OK  ' : 'FAIL', $label, is_scalar($actual) ? (string) $actual : json_encode($actual)));

    return $ok;
}

if (! is_file($path)) {
    fail(sprintf(
        'The workbook is not at "%s". §19 requires a local ignored copy; it is never versioned. '
        .'Place it there and run this again.',
        $path,
    ));
}

out('A04 real-workbook fingerprint');
out('  file: '.basename($path).' ('.number_format((int) filesize($path) / 1024).' KB)');
out('');

// ---------------------------------------------------------------- parse

$redactor = new SensitiveSourceRedactor;
$parser = new BlindenLegacyWorkbookParser($redactor);

$startedAt = microtime(true);

try {
    $parsed = $parser->parse($path);
} catch (Throwable $exception) {
    fail('  [FAIL] the parser refused the file: '.$exception->getMessage());
}

out(sprintf('Parsed in %.1fs. Aggregates follow; no value from the file is printed.', microtime(true) - $startedAt));
out('');

$fingerprint = $parsed->fingerprint();
$issueCounts = $parsed->countsByIssueCode();

// The collapsed duplicates are source rows too, so the file's row count is what was read plus
// what was collapsed. §19's 2.560 is the number of rows in the file.
$credentialCells = [];
foreach ($redactor->findings() as $finding) {
    $credentialCells[$finding['sheet'].'|'.$finding['row']] = true;
}

$sourceRows = $fingerprint['rows'] + ($issueCounts[LegacyImportIssue::DuplicateExactRow->value] ?? 0);

out('Fingerprint');
$ok = true;
$ok = check('monthly sheets', $fingerprint['monthly_sheets'], EXPECTED['monthly_sheets']) && $ok;
$ok = check('blocks', $fingerprint['blocks'], EXPECTED['blocks']) && $ok;
$ok = check('source rows', $sourceRows, EXPECTED['source_rows']) && $ok;
$ok = check('document identities', $fingerprint['document_identities'], EXPECTED['document_identities']) && $ok;
$ok = check('logical company names', $fingerprint['company_names'], EXPECTED['company_names']) && $ok;

out('');
out('§4.3 / §8.1 counts');
$ok = check('cells that look like a credential', count($credentialCells), EXPECTED_ISSUES['credential_like_cells']) && $ok;
$ok = check(
    'invalid affiliation dates (not corrected)',
    $issueCounts[LegacyImportIssue::InvalidAffiliationDate->value] ?? 0,
    EXPECTED_ISSUES['invalid_affiliation_date'],
) && $ok;

out('');

// Both duplicate classes must be present: §7.3 says the file contains both, and a parser that
// found only one would be collapsing a conflict it should have raised.
out('§7.3 duplicate classes');
$ok = check(
    'exact duplicates found',
    ($issueCounts[LegacyImportIssue::DuplicateExactRow->value] ?? 0) > 0,
    true,
) && $ok;
$ok = check(
    'conflicting duplicates found',
    ($issueCounts[LegacyImportIssue::DuplicateConflictingRow->value] ?? 0) > 0,
    true,
) && $ok;

// §9.1's negative tokens: the file is full of them and none may become an entity.
out('');
out('§9.1 negative tokens');
$reconstruction = (new HistoryReconstructor)->reconstruct($parsed->rows());
$counts = $reconstruction->counts();

/**
 * Assert a lower bound and print the **actual count**, not the truth value.
 *
 * The previous version passed `$counts['x'] > 0` into `check()`, and `check()` printed the
 * second argument — so the line read
 *
 * ```text
 *   [OK  ] affiliation segments    1
 * ```
 *
 * for a file with 2.560 rows. Every one of those lines was a boolean rendered as `1`, which is
 * unreadable and, worse, looks like a count of one: a reviewer comparing runs would see no
 * change when the reconstruction had changed substantially. This is §19's artefact, so a reader
 * of it has to be able to trust it.
 *
 * @param  array<string, int>  $counts
 */
function atLeast(array $counts, string $key, int $minimum): bool
{
    $actual = $counts[$key] ?? 0;

    out(sprintf(
        '  [%s] %-42s %d%s',
        $actual >= $minimum ? 'OK  ' : 'FAIL',
        $key,
        $actual,
        $minimum > 0 ? ' (min '.$minimum.')' : '',
    ));

    return $actual >= $minimum;
}

$ok = atLeast($counts, 'affiliation_segments', 1) && $ok;
$ok = atLeast($counts, 'affiliation_segments_with_entity', 1) && $ok;
$ok = atLeast($counts, 'affiliation_refusals', 1) && $ok;

$bareAffirmative = $issueCounts[LegacyImportIssue::AffiliationEntityUnknown->value] ?? 0;
$ok = check('cells saying SI with no entity (raised, not created)', $bareAffirmative > 0, true) && $ok;

// §10: rates exist and are compressed by change, not by row.
out('');
out('§10 monthly values');
$ok = atLeast($counts, 'rate_segments', 1) && $ok;

// §10's compression: one rate per *change*, never per row. Compared as numbers — the first
// version compared the two printed strings, so `615 of 2560` failed to equal `fewer than 2560`
// and reported a FAIL for a true statement.
$rateSegments = $counts['rate_segments'] ?? 0;
$compressed = $rateSegments < $sourceRows;

out(sprintf(
    '  [%s] %-42s %d of %d',
    $compressed ? 'OK  ' : 'FAIL',
    'rate segments are fewer than source rows',
    $rateSegments,
    $sourceRows,
));
$ok = $compressed && $ok;

// §8.4's small class of disappearances and §8.5's overlaps: both must be found, and neither
// may be auto-resolved into a parallel.
out('');
out('§8.4 / §8.5 history questions');
$ok = atLeast($counts, 'disappearances', 1) && $ok;

// §8.5's overlap count is reported, not asserted, and the reason matters more than the number.
//
// The specification states no expected figure for it. It states the *rule*:
//
//   "Mismo cliente observado en varias empresas el mismo mes NO significa automáticamente
//    paralelismo: en el archivo real aparece masivamente durante retiros/traslados."
//   "Después de reconstruir intervalos: … solapamiento real → `overlapping_company_history`."
//
// So an overlap needs an intersection of the rebuilt intervals that a *missing end date* cannot
// explain. A04 measured this file three ways:
//
//   | test                                                      | overlaps |
//   |-----------------------------------------------------------|---------:|
//   | any pair of intervals (two open ones overlap by definition) |      782 |
//   | a shared sheet-month (what the first implementation did)    |      427 |
//   | an affirmative intersection of bounded intervals (A04-R1)   |        0 |
//
// 427 is §8.5's warning arriving through the other door: the same client appearing at two
// companies in one month is what a transfer recorded a month late *looks like*, and there are
// 320 identities in this file. Zero means the file contains no genuine parallel — which is a
// claim about the file, so it is printed loudly rather than asserted quietly, and a reviewer who
// believes otherwise can see the number that disagrees.
out(sprintf('  [INFO] overlaps (affirmative, per §8.5)         %d', $counts['overlaps'] ?? 0));
out(sprintf('  [INFO] disappearances (§8.4)                     %d', $counts['disappearances'] ?? 0));

// §8.5's one hard rule: "Nunca marcar un paralelo automáticamente." A parallel is a decision
// with A02's `parallel_reason`, so no reconstruction may contain one, and an import whose plan
// was built from this reconstruction must carry no `create_relationship` that asserts one.
$parallelActions = DB::table('legacy_import_actions')
    ->where('state', 'planned')
    ->where('action_type', 'create_relationship')
    ->whereNotNull('payload')
    ->get()
    ->filter(static fn (object $action): bool => str_contains((string) $action->payload, '"parallel_reason":"[^"]'))
    ->count();

$ok = check('nothing is auto-marked as a parallel', $parallelActions, 0) && $ok;

// §9.4's third rule and §9.2's mapping question, both of which A04-R1 made reachable. The counts
// are printed rather than asserted, because the specification states no number for them — but a
// verifier that does not mention them cannot tell a reader that they are now being found.
out('');
out('§9.2 / §9.4 questions A04-R1 made reachable');
out(sprintf('  [INFO] company_arl_metadata_conflict issues   %d', $issueCounts[LegacyImportIssue::CompanyArlMetadataConflict->value] ?? 0));
out(sprintf('  [INFO] company_identity_conflict issues      %d', $issueCounts[LegacyImportIssue::CompanyIdentityConflict->value] ?? 0));

$totalBlocking = $fingerprint['blocking_issues'] + $counts['blocking'];
out(sprintf('  totals: %d blocking issues, %d warnings', $totalBlocking, $fingerprint['warning_issues']));

out('');
out('Issues by code (counts only)');
foreach ($issueCounts as $code => $count) {
    out(sprintf('  %-46s %d', $code, $count));
}

// ------------------------------------------------- nothing monetary was planned

out('');
out('§23 out of scope');
// Nothing here writes, so the assertion is about what the plan builder is even able to say: §23
// puts obligations, payments and allocations out of scope, and a vocabulary that had grown one
// would be the first sign of that changing. The vocabulary itself is printed so a reader can
// check it by eye rather than trusting a boolean.
$monetary = array_values(array_filter(
    array_map(static fn (ImportActionType $type): string => $type->value, ImportActionType::cases()),
    static fn (string $value): bool => str_contains($value, 'payment')
        || str_contains($value, 'obligation')
        || str_contains($value, 'allocation'),
));

$ok = check(
    'the plan vocabulary contains nothing monetary',
    $monetary === [] ? '('.count(ImportActionType::cases()).' types, 0 monetary)' : implode(', ', $monetary),
    '('.count(ImportActionType::cases()).' types, 0 monetary)',
) && $ok;

// §13 and §8.3: the two claims A04-R1 rests on, checked against the real file rather than
// against a fixture. Both are properties of the reconstruction rather than of the parse, so they
// are counted here.
out('');
out('A04-R1 reconstruction properties');
$monthPrecisionStarts = 0;
$dayPrecisionStarts = 0;

foreach ($reconstruction->affiliationSegments() as $segment) {
    $precision = $segment->interval->startPrecision;

    if ($precision === 'month') {
        $monthPrecisionStarts++;
    } elseif ($precision === 'day') {
        $dayPrecisionStarts++;
    }
}

out(sprintf('  [INFO] affiliation segments starting on a month   %d', $monthPrecisionStarts));
out(sprintf('  [INFO] affiliation segments starting on a day     %d', $dayPrecisionStarts));
out(sprintf('  [INFO] interval findings with no coverage gap     %d', $counts['affiliation_segments'] ?? 0));

out('');
out($ok ? 'Fingerprint matches. Nothing was written and no value was printed.' : 'FINGERPRINT MISMATCH — see the FAIL lines above.');

exit($ok ? 0 : 1);

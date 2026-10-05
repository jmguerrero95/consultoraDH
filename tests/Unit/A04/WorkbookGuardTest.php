<?php

declare(strict_types=1);

use App\Domain\Imports\WorkbookGuard;
use App\Domain\Imports\WorkbookRejected;
use Tests\Support\SyntheticWorkbook;
use Tests\Support\TempFiles;

beforeEach(function () {
    $this->guard = WorkbookGuard::fromConfig();
});

afterEach(function () {
    TempFiles::forgetAll();
});

/** A valid workbook on disk, remembered for cleanup. */
function validWorkbook(string $name = 'ok.xlsx'): string
{
    $path = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow()],
    ]])->path($name);

    return TempFiles::track($path);
}

/** Write arbitrary bytes as a file the guard can be pointed at. */
function rawFile(string $bytes, string $name = 'raw.xlsx'): string
{
    $path = sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(6)).'-'.$name;

    file_put_contents($path, $bytes);

    return TempFiles::track($path);
}

/** Build a ZIP with the given entries, for the container checks. */
function zipWith(array $entries, string $name = 'crafted.xlsx'): string
{
    $path = sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(6)).'-'.$name;

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($entries as $entryName => $contents) {
        $zip->addFromString((string) $entryName, $contents);
    }

    $zip->close();

    return TempFiles::track($path);
}

/** The minimum a ZIP has to contain to look like a workbook. */
function workbookEntries(array $extra = []): array
{
    return $extra + [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
    ];
}

it('accepts a workbook it just wrote', function () {
    $this->guard->assertAcceptable(validWorkbook(), 'source.xlsx');

    expect(true)->toBeTrue();
});

it('refuses an extension that is not xlsx', function () {
    expect(fn () => $this->guard->assertAcceptable(validWorkbook(), 'source.xlsm'))
        ->toThrow(WorkbookRejected::class);

    try {
        $this->guard->assertAcceptable(validWorkbook(), 'source.xlsm');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('unsupported_extension');
    }
});

it('refuses a file that is not an OpenXML container at all', function () {
    // A PDF renamed `.xlsx`, which is the case §4.1 is about.
    $path = rawFile("%PDF-1.4\nnot a zip at all\n");

    try {
        $this->guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted a non-archive');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('not_an_openxml_container');
    }
});

it('refuses an empty file', function () {
    try {
        $this->guard->assertAcceptable(rawFile(''), 'source.xlsx');
        $this->fail('the guard accepted an empty file');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('empty_file');
    }
});

it('refuses a file above the configured size', function () {
    $guard = new WorkbookGuard(maxBytes: 100, maxEntries: 10, maxUncompressedBytes: 1000);

    try {
        $guard->assertAcceptable(validWorkbook(), 'source.xlsx');
        $this->fail('the guard accepted a file over its size limit');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('file_too_large');
    }
});

it('refuses a zip that declares more expansion than the limit', function () {
    // The zip-bomb shape: a tiny file whose entry declares a huge uncompressed size. The
    // guard reads the declaration and never expands anything.
    $path = sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(6)).'-bomb.xlsx';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', 200000));
    $zip->close();

    TempFiles::track($path);

    $guard = new WorkbookGuard(
        maxBytes: 10 * 1024 * 1024,
        maxEntries: 100,
        maxUncompressedBytes: 50_000,
    );

    try {
        $guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted a zip bomb');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('expansion_too_large');
    }
});

it('refuses a zip with too many entries', function () {
    $entries = workbookEntries();

    for ($i = 0; $i < 40; $i++) {
        $entries['xl/worksheets/sheet'.$i.'.xml'] = '<?xml version="1.0"?><sheet/>';
    }

    $guard = new WorkbookGuard(maxBytes: 10_000_000, maxEntries: 10, maxUncompressedBytes: 10_000_000);

    try {
        $guard->assertAcceptable(zipWith($entries), 'source.xlsx');
        $this->fail('the guard accepted too many entries');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('too_many_zip_entries');
    }
});

it('refuses an entry whose path escapes the archive', function () {
    foreach (['../outside.xml', '/etc/cron.d/x', 'xl\\..\\evil.xml', 'C:/windows/x'] as $name) {
        $path = zipWith(workbookEntries([$name => 'payload']));

        try {
            $this->guard->assertAcceptable($path, 'source.xlsx');
            $this->fail('the guard accepted an escaping entry: '.$name);
        } catch (WorkbookRejected $rejected) {
            expect($rejected->reason)->toBe('unsafe_entry_name');
        }
    }
});

it('refuses a macro-enabled workbook renamed to xlsx', function () {
    $path = zipWith(workbookEntries(['xl/vbaProject.bin' => 'macro bytes']));

    try {
        $this->guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted a macro-enabled workbook');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('macro_enabled_workbook');
    }
});

it('refuses a workbook that resolves content outside itself', function () {
    $path = zipWith(workbookEntries([
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships>'
            .'<Relationship Target="file:///etc/passwd" TargetMode="External"/></Relationships>',
    ]));

    try {
        $this->guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted an external reference');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('external_reference');
    }
});

it('refuses a well-formed zip that is not a workbook', function () {
    $path = zipWith(['[Content_Types].xml' => '<?xml version="1.0"?><Types/>']);

    try {
        $this->guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted a non-workbook archive');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->reason)->toBe('not_a_workbook');
    }
});

it('never puts the server path in the message it shows a person', function () {
    $path = rawFile('not a zip', 'secreto.xlsx');

    try {
        $this->guard->assertAcceptable($path, 'source.xlsx');
        $this->fail('the guard accepted a non-archive');
    } catch (WorkbookRejected $rejected) {
        expect($rejected->getMessage())->not->toContain($path);
        expect($rejected->getMessage())->not->toContain(sys_get_temp_dir());
        expect($rejected->userMessage())->toBe($rejected->getMessage());
    }
});

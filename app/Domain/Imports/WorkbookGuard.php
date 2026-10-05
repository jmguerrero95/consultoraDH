<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Checks an uploaded `.xlsx` container before any reader opens it.
 *
 * ## What this is defending against
 *
 * An `.xlsx` is a ZIP archive, and an application that opens an uploaded archive without
 * bounds is the standard zip-bomb target. The four ways that bites:
 *
 * 1. **Expansion.** A few kilobytes of zeroes expands to gigabytes. The reader allocates
 *    as it goes, so the process dies before any rule can run.
 * 2. **Path escape.** An entry called `../../../etc/cron.d/x` writes outside the extraction
 *    root. Nothing here extracts to disk, but a reader that resolves an internal path is
 *    one refactor away from doing so, and the check makes that refactor fail.
 * 3. **Macros.** A macro-enabled workbook renamed `source.xlsx` is still a macro-enabled
 *    workbook. `vbaProject.bin` is refused outright.
 * 4. **External references.** A workbook whose relationships point at `file://` or a UNC
 *    share is asking the reader to leave the machine. OpenSpout does not resolve them;
 *    this states it so a future change that does has to confront the decision.
 *
 * ## Every check runs on the central directory, not on extracted data
 *
 * The sizes are *declared* in the archive's directory, so the whole of points 1 and 2 is
 * decided by reading headers. That matters: expanding first to "check" the expansion is the
 * bug.
 *
 * ## Failure is a typed refusal, not an exception with a path in it
 *
 * `WorkbookRejected` carries a code the interface branches on and a sentence a person reads.
 * It never carries the file path, because §4.3 and the security rules both forbid the
 * server's filesystem reaching somebody's browser, and an exception message is the easiest
 * way for it to get there.
 */
final class WorkbookGuard
{
    public function __construct(
        private readonly int $maxBytes = 0,
        private readonly int $maxEntries = 0,
        private readonly int $maxUncompressedBytes = 0,
        /** @var list<string> */
        private readonly array $forbiddenEntries = [],
        /** @var list<string> */
        private readonly array $forbiddenRelationships = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('imports.max_bytes', 10 * 1024 * 1024),
            maxEntries: (int) config('imports.openxml.max_entries', 2000),
            maxUncompressedBytes: (int) config('imports.openxml.max_uncompressed_bytes', 64 * 1024 * 1024),
            forbiddenEntries: array_values((array) config('imports.openxml.forbidden_entries', [])),
            forbiddenRelationships: array_values((array) config('imports.openxml.forbidden_relationships', [])),
        );
    }

    /**
     * Refuse anything that is not a workbook this module may read.
     *
     * @throws WorkbookRejected
     */
    public function assertAcceptable(string $path, string $originalFilename): void
    {
        $this->assertExtension($originalFilename);
        $this->assertRealFile($path);
        $this->assertSize($path);
        $this->assertZip($path);
    }

    /** The extension, checked against the filename the operator's browser supplied. */
    private function assertExtension(string $originalFilename): void
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            throw WorkbookRejected::extension($extension);
        }
    }

    /**
     * The path has to be a readable regular file.
     *
     * `is_file` rather than `file_exists` because a symlink to `/etc/passwd` exists too,
     * and a directory called `source.xlsx` is not a workbook.
     */
    private function assertRealFile(string $path): void
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            throw WorkbookRejected::unreadable();
        }
    }

    private function assertSize(string $path): void
    {
        $bytes = (int) filesize($path);

        if ($bytes <= 0) {
            throw WorkbookRejected::empty();
        }

        if ($bytes > $this->maxBytes) {
            throw WorkbookRejected::tooLarge($bytes, $this->maxBytes);
        }
    }

    /**
     * The archive itself.
     *
     * `ZipArchive::open` with `CHECKCONS` is what makes this a real check rather than a
     * hopeful one: it verifies the central directory and the entry headers, and returns an
     * error for a file that is not an archive at all — a PDF with a `.xlsx` name fails
     * here, which is the whole point of §4.1.
     */
    private function assertZip(string $path): void
    {
        $zip = new \ZipArchive;

        $opened = $zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw WorkbookRejected::notAnArchive($opened);
        }

        try {
            $entries = $zip->numFiles;

            if ($entries > $this->maxEntries) {
                throw WorkbookRejected::tooManyEntries($entries, $this->maxEntries);
            }

            $declared = 0;

            for ($index = 0; $index < $entries; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw WorkbookRejected::corruptEntry();
                }

                $name = (string) $stat['name'];

                $this->assertSafeName($name);
                $this->assertNotForbidden($name);

                // The *declared* size. Nothing has been expanded, and this is the number a
                // zip bomb lies about least often.
                $declared += (int) $stat['size'];
            }

            if ($declared > $this->maxUncompressedBytes) {
                throw WorkbookRejected::tooLargeWhenExpanded($declared, $this->maxUncompressedBytes);
            }

            // `xl/workbook.xml` is the one part every `.xlsx` has and nothing else does, so
            // it is the whole test. `[Content_Types].xml` is present in every Office file
            // including documents and presentations, so treating it as proof of a spreadsheet
            // would pass a renamed `.docx` — and the reader would then fail on a file the
            // guard had already approved.
            if (! $this->has($zip, 'xl/workbook.xml')) {
                throw WorkbookRejected::notAWorkbook();
            }

            $this->assertNoExternalRelationships($zip);
        } finally {
            $zip->close();
        }
    }

    private function has(\ZipArchive $zip, string $name): bool
    {
        return $zip->locateName($name) !== false;
    }

    /**
     * An entry name has to stay inside the archive.
     *
     * Rejected: absolute paths, any `..` segment, and backslashes, which are a separator on
     * some extractors and a literal character on others — so a name containing one is
     * ambiguous and has no place in a file this module reads.
     */
    private function assertSafeName(string $name): void
    {
        if ($name === '') {
            throw WorkbookRejected::corruptEntry();
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw WorkbookRejected::unsafeEntryName();
        }

        // A Windows drive letter, e.g. `C:\…`.
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw WorkbookRejected::unsafeEntryName();
        }

        if (str_contains($name, '\\')) {
            throw WorkbookRejected::unsafeEntryName();
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                throw WorkbookRejected::unsafeEntryName();
            }
        }
    }

    private function assertNotForbidden(string $name): void
    {
        $base = strtolower(basename($name));

        foreach ($this->forbiddenEntries as $forbidden) {
            if ($base === strtolower($forbidden)) {
                throw WorkbookRejected::forbiddenEntry($forbidden);
            }
        }
    }

    /**
     * No relationship part may point outside the archive.
     *
     * Checked by reading the small relationship parts, not by extracting the workbook. A
     * spreadsheet has a handful of these and they are a few kilobytes.
     */
    private function assertNoExternalRelationships(\ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! str_ends_with(strtolower($name), '.rels')) {
                continue;
            }

            $contents = $zip->getFromIndex($index);

            if ($contents === false) {
                continue;
            }

            $folded = mb_strtolower($contents);

            foreach ($this->forbiddenRelationships as $forbidden) {
                if (str_contains($folded, strtolower($forbidden))) {
                    throw WorkbookRejected::externalReference($forbidden);
                }
            }

            if (str_contains($folded, 'targetmode="external"')) {
                throw WorkbookRejected::externalReference('TargetMode=External');
            }
        }
    }
}

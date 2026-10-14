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
 *    this states it so a future change that does has to confront the decision. An ordinary
 *    `mailto:` contact hyperlink is *not* in this class — it names no resource — so
 *    `assertNoExternalRelationships` tells the two apart rather than refusing both.
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
     * No relationship part may make the reader leave the machine.
     *
     * Checked by reading the small relationship parts, not by extracting the workbook. A
     * spreadsheet has a handful of these and they are a few kilobytes.
     *
     * ## Why this classifies instead of banning
     *
     * A blanket `TargetMode="External"` ban is the obvious rule and it is the wrong one:
     * the delivered workbook carries **401** `TargetMode="External"` relationships and every
     * single one is an ordinary `mailto:` hyperlink on a contact cell. A payroll workbook
     * with a contact column and hyperlinks is normal, and refusing it means the one file
     * this module exists to read cannot be uploaded. Over-refusal that stops the real
     * deliverable is a defect, not caution.
     *
     * So the rule is about **what the reference can do**, not about whether the string
     * `External` appears:
     *
     * 1. **External *links* are refused** — an `externalLink`/`externalReference`
     *    relationship points at another workbook whose values Excel substitutes into
     *    cells. That is data this module would be reading from somewhere else, and it is
     *    the genuine SSRF/local-read vector. Unchanged, and matched on the relationship
     *    *type*, so it cannot be evaded by relabelling a target.
     * 2. **`file:` and UNC targets are refused** regardless of relationship type. A path
     *    out of the archive is a path out of the container, whatever it is attached to.
     * 3. **Legacy active content is refused** — `oleObject`, `activeX`, `vmlDrawing` with
     *    an external target, and `frame`. These are the mechanisms by which a spreadsheet
     *    executes or fetches something.
     * 4. **Inert `mailto:`/`tel:` hyperlinks are allowed**, because nothing resolves them.
     *    A hyperlink target is display metadata: OpenSpout reads cell values and ignores
     *    it, and so does every reader this module has. Allowing them is not "resolving
     *    external references" — they are never dereferenced, here or later.
     *
     * Everything else with `TargetMode="External"` stays refused, so this loosens exactly
     * one class of reference and no other.
     *
     * ## Why each relationship is parsed
     *
     * The previous check was `str_contains` over the whole part, which cannot tell a
     * hyperlink's `mailto:` from an `externalLink` to `\\host\share` because it never
     * looks at which relationship it is inside. Elements are matched individually so
     * type, target and mode are read together and the decision has something to be made on.
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

            // The relationship *type* check stays a whole-part substring match on purpose:
            // it is the cheapest way to be sure no relationship anywhere in this part is an
            // external link, including one this parser does not recognise. A `hyperlink`
            // type URI does not contain either token, so the real workbook is unaffected.
            foreach ($this->forbiddenRelationships as $forbidden) {
                if (str_contains($folded, strtolower($forbidden))) {
                    throw WorkbookRejected::externalReference($forbidden);
                }
            }

            foreach ($this->relationshipsIn($contents) as $relationship) {
                $this->assertRelationshipInert($relationship);
            }
        }
    }

    /**
     * Each `<Relationship>` element as `type` / `target` / `mode`.
     *
     * @return list<array{type: string, target: string, mode: string}>
     */
    private function relationshipsIn(string $xml): array
    {
        $found = preg_match_all('/<Relationship\b[^>]*>/i', $xml, $matches);

        if ($found === false || $found === 0) {
            return [];
        }

        $relationships = [];

        foreach ($matches[0] as $element) {
            $relationships[] = [
                'type' => $this->attribute($element, 'Type'),
                'target' => $this->attribute($element, 'Target'),
                'mode' => $this->attribute($element, 'TargetMode'),
            ];
        }

        return $relationships;
    }

    private function attribute(string $element, string $name): string
    {
        // Single- and double-quoted attributes are both legal and Office emits both.
        $pattern = '/\b'.preg_quote($name, '/').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';

        if (preg_match($pattern, $element, $matches) !== 1) {
            return '';
        }

        return $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
    }

    /**
     * @param  array{type: string, target: string, mode: string}  $relationship
     */
    private function assertRelationshipInert(array $relationship): void
    {
        $target = trim($relationship['target']);
        $type = $relationship['type'];

        if ($target === '') {
            return;
        }

        // A path off the disk or off the container, whatever it hangs off. Checked before
        // the mode test so an internal-mode `file:` target is caught too.
        if ($this->isLocalOrUncTarget($target)) {
            throw WorkbookRejected::externalReference('file/UNC target');
        }

        if (mb_strtolower($relationship['mode']) !== 'external') {
            return;
        }

        // Case 4: an inert hyperlink. `mailto:` is a scheme with no fetcher behind it and
        // `tel:` likewise; neither names a resource, and nothing in this pipeline opens
        // either. The target is deliberately not dereferenced, normalised or stored.
        if ($this->isInertHyperlink($type, $target)) {
            return;
        }

        throw WorkbookRejected::externalReference($type !== '' ? $type : 'TargetMode=External');
    }

    private function isLocalOrUncTarget(string $target): bool
    {
        // `file:` in any casing, and the `file://host/share` form that is really a UNC share.
        if (preg_match('#^file:#i', $target) === 1) {
            return true;
        }

        // `\\host\share` and the protocol-relative `//host/share`.
        return str_starts_with($target, '\\\\') || str_starts_with($target, '//');
    }

    /**
     * A hyperlink relationship whose target names no resource the reader could open.
     *
     * The type must be `hyperlink`: a `mailto:` target on some other relationship type is
     * not the ordinary contact link this is about, and the scheme check is not enough on
     * its own to conclude anything.
     */
    private function isInertHyperlink(string $type, string $target): bool
    {
        if (! str_ends_with(mb_strtolower($type), '/hyperlink')) {
            return false;
        }

        foreach (self::INERT_TARGET_SCHEMES as $scheme) {
            if (preg_match('#^'.preg_quote($scheme, '#').'#i', $target) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schemes that cannot name a resource to fetch, open or execute.
     *
     * `mailto:` and `tel:` only. Deliberately *not* `http`/`https` — a hyperlink to a URL
     * is not fetched during parsing either, but it is the one external target an operator
     * can be walked into clicking, so it keeps the old refusal rather than being argued
     * about later.
     */
    private const INERT_TARGET_SCHEMES = [
        'mailto:',
        'tel:',
    ];
}

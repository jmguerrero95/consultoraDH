<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A registry of temporary files, so a test helper can leave a fixture behind for cleanup.
 *
 * ## Why not a test property
 *
 * Pest's `test()` returns a proxy that cannot be written to indirectly — `$this->paths[] = $x`
 * inside a helper function raises "Indirect modification of overloaded property". That is a
 * good reason to keep the list somewhere that is not the test case, and a static is the right
 * shape for it: the entries belong to the current process and are all thrown away when it
 * ends.
 *
 * ## Why every fixture goes through here
 *
 * §20 requires tests that never touch the real workbook, and the nearest way to violate that
 * by accident is to have a fixture written into the repository. Everything is created under
 * `sys_get_temp_dir()`, and `forgetAll()` runs from `afterEach`, so a test that fails
 * mid-assertion cannot leave an `.xlsx` on disk for a later `git add .`.
 */
final class TempFiles
{
    /** @var list<string> */
    private static array $paths = [];

    public static function track(string $path): string
    {
        self::$paths[] = $path;

        return $path;
    }

    /** Remove everything tracked. Safe to call when nothing was tracked. */
    public static function forgetAll(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::removeDirectory($path);
            }
        }

        self::$paths = [];
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $child) {
            if (is_dir($child)) {
                self::removeDirectory($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($directory);
    }
}

<?php
use PHPUnit\Framework\TestCase;

/**
 * The catalogue has to be in what WordPress.org PUBLISHES, not only in the repository.
 *
 * This is the test the 0.4.26 bug walked past. `languages` sat in .distignore from 2026-07-28, so the
 * copy rsynced into Subversion carried no catalogue at all - every wordpress.org install read the
 * plugin in English, checkout included, while the identical code rsynced to our own sites was
 * Bulgarian. Nothing caught it: TranslationsLoadTest and PhpCatalogueTest read the files from the
 * working copy, where they have always been, and Plugin Check reads the zip from bin/build-zip, which
 * strips its own list rather than .distignore and therefore carried them too.
 *
 * So this test judges the one thing none of those look at: the exclusion list that decides what the
 * directory receives. The matching mirrors rsync, which is what 10up's deploy action uses - a pattern
 * with a slash is matched against the path, a bare pattern against any single segment of it (which is
 * how one word, `languages`, removed a whole folder).
 *
 * @group core
 */
final class CataloguePackagedTest extends TestCase {

    /** The compiled forms WordPress actually reads: the PHP catalogue since 6.5, the .mo before it. */
    private const SHIPPED = array(
        'languages/bg-couriers-bg_BG.l10n.php',
        'languages/bg-couriers-bg_BG.mo',
    );

    private function root(): string {
        return dirname(__DIR__, 2);
    }

    /** The .distignore patterns, comments and blank lines dropped. */
    private function patterns(): array {
        $lines = file($this->root() . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines, '.distignore is missing - the published package is then the whole repository');
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $out[] = rtrim($line, '/');
        }
        return $out;
    }

    /** Would rsync --exclude-from drop this path? */
    private function excluded(string $path): ?string {
        foreach ($this->patterns() as $pattern) {
            if (strpos($pattern, '/') !== false) {
                if (fnmatch($pattern, $path)) { return $pattern; }
                continue;
            }
            foreach (explode('/', $path) as $segment) {
                if (fnmatch($pattern, $segment)) { return $pattern; }
            }
        }
        return null;
    }

    public function test_the_compiled_catalogue_is_not_excluded_from_the_published_package(): void {
        foreach (self::SHIPPED as $file) {
            $pattern = $this->excluded($file);
            $this->assertNull(
                $pattern,
                "$file would be stripped from the WordPress.org package by the .distignore line \"$pattern\" - "
                . 'a Bulgarian shop would then read the plugin in English, as it did in 0.4.26'
            );
        }
    }

    public function test_the_compiled_catalogue_exists_and_carries_the_translations(): void {
        foreach (self::SHIPPED as $file) {
            $path = $this->root() . '/' . $file;
            $this->assertFileExists($path, "$file is not in the repository, so it cannot be published either");
            // A stub is the failure mode seen in the field: a 701-byte .l10n.php left by Loco Translate
            // reads as a catalogue and translates nothing. 50 KB is far below the real files (125-130 KB)
            // and far above anything empty.
            $this->assertGreaterThan(
                50000,
                (int) filesize($path),
                "$file is too small to be the Bulgarian catalogue - a stub translates nothing"
            );
        }
    }

    /** The sources stay behind on purpose: whoever installs the plugin has no use for them. */
    public function test_the_translation_sources_are_left_out_of_the_package(): void {
        foreach (array('languages/bg-couriers-bg_BG.po', 'languages/bg-couriers.pot') as $file) {
            $this->assertNotNull(
                $this->excluded($file),
                "$file is shipped to users - .distignore should keep the translation sources out"
            );
        }
    }
}

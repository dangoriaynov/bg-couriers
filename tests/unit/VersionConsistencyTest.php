<?php
use PHPUnit\Framework\TestCase;

/**
 * The version is written in three places and they have to agree.
 *
 * bin/build-zip already refuses to package a plugin header that disagrees with the readme's stable tag,
 * but BGCOURIERS_VERSION is a fourth reader of the same number - it versions every enqueued asset - and
 * nothing was watching it. It was left at 0.2.1 by the 0.2.2 release commit, which would have served
 * browsers a stale cached stylesheet under a version that no longer existed.
 */
final class VersionConsistencyTest extends TestCase {

    public function test_header_constant_and_readme_say_the_same_version(): void {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/bg-couriers.php');
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/readme.txt');

        preg_match('/^ \* Version: (.+)$/m', $plugin, $header);
        preg_match("/define\('BGCOURIERS_VERSION', '([^']+)'\)/", $plugin, $constant);
        preg_match('/^Stable tag: (.+)$/m', $readme, $stable);

        $this->assertNotEmpty($header[1] ?? '', 'the plugin header must declare a Version');
        $this->assertSame(trim($header[1]), trim($constant[1] ?? ''), 'BGCOURIERS_VERSION must match the plugin header');
        $this->assertSame(trim($header[1]), trim($stable[1] ?? ''), 'readme Stable tag must match the plugin header');
    }

    /** A released version is also a changelog entry - otherwise nobody can tell what they are updating to. */
    public function test_the_released_version_has_a_changelog_entry(): void {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/readme.txt');
        preg_match('/^Stable tag: (.+)$/m', $readme, $stable);
        $this->assertStringContainsString('= ' . trim($stable[1]) . ' =', $readme);
    }
}

<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers ilObjLanguageExt::syncMigratedFilesAfterDeleteModeImport() (see its docblock in
 * classes/class.ilObjLanguageExt.php), the fix for the second, previously undocumented PO/MO
 * write-back gap: importLanguageFile()'s "delete" mode wipes lng_data/lng_modules for the *whole*
 * language up front. That means _saveValues()'s own per-module loop
 * (class.ilObjLanguageExt.php::_saveValues()) finds no existing lng_modules row for *any* module -
 * migrated or not - and therefore never reaches replaceLangModule() (and, through it, the ordinary
 * PO/MO sync) for a single one of them. This method does not rely on _saveValues() having synced
 * anything: it independently syncs every module the language previously had any lng_data row for,
 * unioned with every module actually present in $to_save, each with exactly the entries $to_save just
 * wrote back for it (or an empty map for a module the imported file did not mention at all) -
 * "replace" semantics, matching every other write path. Without this method, a migrated module
 * would keep serving stale/orphaned values forever via ilLanguage's
 * migrated-file read path after a "delete"-mode import, regardless of whether the imported file
 * mentioned it or not.
 *
 * Under the current design, the sync targets the per-installation OVERLAY `.po`/`.mo` pair under
 * CLIENT_DATA_DIR - never the SHIPPED `.po` (written exactly once by the conversion tool). The method passes
 * the resolved client data directory (CLIENT_DATA_DIR here) to MigratedLanguageFileSync::sync(),
 * together with the import's $refreshOriginalFromShipped as sync()'s only flag. A missing overlay is
 * created by every sync (the overlay mirrors "language installed"); most tests nevertheless start
 * from an already-compiled overlay, exactly like an ordinary admin-GUI edit of an installed
 * language would find it.
 *
 * Deliberately tests this private method directly via reflection instead of driving the whole
 * importLanguageFile() end-to-end: importLanguageFile()'s "delete" mode routes through
 * ilObjLanguageExt::_saveValues(), which in turn requires a real global language file
 * (ilLanguageFile::_getGlobalLanguageFile()) and a long chain of further raw lng_data/lng_modules
 * queries entirely unrelated to this fix. Exercising all of that just to reach this method would
 * make the test suite fragile and hard to maintain without adding coverage of the actual new logic -
 * the reflection-based approach isolates exactly what changed. importLanguageFile()'s wiring itself
 * (capturing modules_before_delete via self::_getModules() before the delete, then calling this method
 * afterwards only for mode "delete") is a two-line change visible by inspection in
 * class.ilObjLanguageExt.php.
 *
 * Uses a throwaway fixture module, exactly like PoMigrationWriteBackTest.php - never real pilot (tos)
 * data.
 *
 * Runs every test method in its own separate process: a full-suite run can have CLIENT_DATA_DIR
 * already defined by an earlier, unrelated test class sharing the same process (e.g.
 * Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php define it
 * as /var/iliasdata) - without this, guardClientDataDirIsTestOwned()'s guard would then skip every
 * single test below for the rest of that process, since a PHP constant cannot be redefined. A fresh
 * process per test method guarantees CLIENT_DATA_DIR starts out undefined here, exactly like a lone
 * test run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SyncMigratedFilesAfterDeleteModeImportTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;
    /**
     * True exactly when this test's own setUp() defined CLIENT_DATA_DIR itself (as opposed to a
     * pre-existing, foreign definition it merely reused) - see tearDown(). With every test running in
     * its own process (see this class' own #[RunTestsInSeparateProcesses]), this is true for every
     * ordinary test run, so the freshly generated, uniquely-named root this test created is always
     * cleaned up again instead of accumulating one leftover temp directory per test method.
     */
    private bool $created_client_data_dir_root = false;

    /**
     * syncMigratedFilesAfterDeleteModeImport() evaluates ILIAS_ABSOLUTE_PATH as a plain function
     * argument to MigratedLanguageFileSync::sync() - unconditionally, before sync() itself gets a
     * chance to no-op for a module without a contributed directory. Every test method must therefore
     * be able to rely on the constant being defined, including one that never calls
     * seedFixtureModule() itself (e.g. a non-migrated-module scenario) - defining it here in setUp()
     * rather than lazily inside seedFixtureModule() avoids a test-order dependency on some other test
     * happening to run first in the same process (executionOrder="random" in phpunit.xml). The same
     * applies to CLIENT_DATA_DIR, which the method resolves the exact same way.
     *
     * A PHP constant, once defined, is shared by every test in the whole suite's process - including
     * one from a completely different test class that got there first. This method therefore never
     * assumes exclusive ownership of CLIENT_DATA_DIR: it happily (re)uses whatever value is already
     * there - safe, because this class's own tearDown() only ever deletes its own uniquely-named
     * fixture subdirectory (see overlayFixtureDirectory()), never CLIENT_DATA_DIR itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        // testTearDownNeverDeletesAClientDataDirThisClassDidNotCreateItself() and
        // testRefusesToWriteIntoAClientDataDirOutsideSysTempDir() below need full control over
        // exactly when/to-what CLIENT_DATA_DIR first gets defined, to simulate a pre-existing,
        // foreign definition - so they manage the constant entirely on their own instead.
        if (in_array($this->name(), [
            'testTearDownNeverDeletesAClientDataDirThisClassDidNotCreateItself',
            'testRefusesToWriteIntoAClientDataDirOutsideSysTempDir',
        ], true)) {
            return;
        }
        $this->guardClientDataDirIsTestOwned();
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_sync_delete_import_test_' . bin2hex(random_bytes(4)));
            $this->created_client_data_dir_root = true;
        }
    }

    /**
     * A full-suite run can have CLIENT_DATA_DIR already pointing at the real client data directory
     * (e.g. Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php
     * define it as /var/iliasdata). Never deleting it (see tearDown()) is not enough on its own:
     * writing fixture files into real production data must be refused just as strictly - every test
     * in this class calls syncMigratedFilesAfterDeleteModeImport(), which resolves CLIENT_DATA_DIR
     * unconditionally.
     */
    private function guardClientDataDirIsTestOwned(): void
    {
        if (defined('CLIENT_DATA_DIR') && !str_starts_with(CLIENT_DATA_DIR, sys_get_temp_dir() . '/')) {
            $this->markTestSkipped(
                'CLIENT_DATA_DIR ("' . CLIENT_DATA_DIR . '") is not a test-owned temp directory - '
                . 'refusing to write fixture files there.'
            );
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        // Regression guard for a real incident: an earlier version of this method recursively deleted
        // the whole CLIENT_DATA_DIR whenever it was is_dir() - in a full-suite run that constant was
        // the real, pre-existing /var/iliasdata, which got wiped. It must therefore only ever delete a
        // CLIENT_DATA_DIR this test itself created (tracked by $created_client_data_dir_root, set
        // exactly once by setUp() - see there); a foreign, pre-existing definition is left completely
        // untouched, still only ever having its own uniquely-named subdirectory (see
        // overlayFixtureDirectory()) removed, exactly as before.
        $overlay_fixture_dir = $this->overlayFixtureDirectory();
        if ($overlay_fixture_dir !== null && is_dir($overlay_fixture_dir)) {
            $this->removeDirectoryRecursively($overlay_fixture_dir);
        }
        if ($this->created_client_data_dir_root && defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            $this->removeDirectoryRecursively(CLIENT_DATA_DIR);
        }

        parent::tearDown();
    }

    /**
     * The one subdirectory under CLIENT_DATA_DIR this test method's fixture (if any) ever wrote to -
     * see bootstrapOverlayFromShipped()/overlayDirectory(). Returns null when either CLIENT_DATA_DIR
     * was never resolved/defined in this test, or no fixture was ever seeded, so tearDown() has
     * nothing of its own to clean up.
     */
    private function overlayFixtureDirectory(): ?string
    {
        if (!isset($this->fixture_directory) || !defined('CLIENT_DATA_DIR')) {
            return null;
        }

        return rtrim($this->overlayDirectory(), '/');
    }

    private function removeDirectoryRecursively(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Seeds only the SHIPPED `.po` file for a throwaway module - never a shipped `.mo`, which the
     * current design never writes at all. Returns the
     * LanguageFileDirectory that makes it discoverable.
     *
     * @param array<string, string> $entries identifier => value
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-clearmigrated-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        foreach ($entries as $identifier => $value) {
            $translations->add(MigratedPoFixture::entry($module, $identifier, $value));
        }

        $base_path = $this->fixture_directory . '/' . $module . '_' . $lang_key;
        MigratedPoFixture::writePo($base_path . '.po', $translations);

        $relative_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/';

        return new class ($module, $relative_path) implements LanguageFileDirectory {
            public function __construct(private string $prefix, private string $path)
            {
            }

            public function getPrefix(): string
            {
                return $this->prefix;
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getSuffix(): string
            {
                return '';
            }

            public function isLocal(): bool
            {
                return false;
            }
        };
    }

    /**
     * Bootstraps the OVERLAY `.po`+`.mo` pair for $module/$lang_key from its current shipped `.po`
     * content, mirroring the shipped file's relative path under CLIENT_DATA_DIR - simulating an
     * already-migrated module whose overlay was compiled by an earlier install - the usual starting
     * point of an import into an installed language (a still-missing overlay would be created by
     * MigratedLanguageFileSync::sync() as well, see testCreatesAMissingOverlayWithTheShippedOriginal()).
     */
    private function bootstrapOverlayFromShipped(string $module, string $lang_key): void
    {
        $overlay_dir = $this->overlayDirectory();
        if (!is_dir($overlay_dir)) {
            mkdir($overlay_dir, 0775, true);
        }
        copy(
            $this->fixture_directory . '/' . $module . '_' . $lang_key . '.po',
            $overlay_dir . $module . '_' . $lang_key . '.po'
        );
        MigratedPoFixture::writeMo($overlay_dir . $module . '_' . $lang_key . '.mo', MigratedPoFixture::readPo($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po'));
    }

    private function overlayDirectory(): string
    {
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename((string) $this->fixture_directory) . '/';
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function loadFixturePo(string $module, string $lang_key): \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog
    {
        return MigratedPoFixture::readPo($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    private function loadOverlayPo(string $module, string $lang_key): \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog
    {
        return MigratedPoFixture::readPo($this->overlayDirectory() . $module . '_' . $lang_key . '.po');
    }

    private function overlayMoExists(string $module, string $lang_key): bool
    {
        return is_file($this->overlayDirectory() . $module . '_' . $lang_key . '.mo');
    }

    /**
     * @param array<string, string> $to_save module.separator.topic => value, exactly the shape
     *        importLanguageFile() passes on (via _saveValues()'s own $a_values parameter).
     */
    private function invoke(array $modules_before_delete, array $to_save): void
    {
        $instance = (new ReflectionClass(ilObjLanguageExt::class))->newInstanceWithoutConstructor();
        $instance->key = 'de';
        $instance->separator = '#:#';

        $method = new ReflectionMethod(ilObjLanguageExt::class, 'syncMigratedFilesAfterDeleteModeImport');
        $method->invoke($instance, $modules_before_delete, $to_save);
    }

    /**
     * A module that existed before the "delete"-mode wipe and has no entries at all in $to_save (the
     * imported file did not mention it) must be synced with an empty map - i.e. every one of its
     * entries removed from the OVERLAY .po/.mo files, matching "replace" semantics for a module that is
     * now genuinely gone. The shipped `.po` (never written to by this path) must stay untouched.
     */
    public function testSyncsAModuleMissingFromTheImportWithAnEmptyMap(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->bootstrapOverlayFromShipped('dtest', 'de');
        $this->registerDirectoryManager($directory);

        // "dtest" existed before the "delete"-mode wipe, but the imported file contained nothing for
        // it at all.
        $this->invoke(['dtest'], []);

        $this->assertNull($this->loadOverlayPo('dtest', 'de')->find('dtest', 'greeting'));
        $this->assertNotNull(
            $this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting'),
            'the shipped .po must never be touched by this sync path'
        );
    }

    /**
     * The actual bugfix: a module that both existed before the delete AND is present in $to_save must
     * be synced with exactly its identifier => value subset from $to_save - not ignored (the old,
     * buggy assumption that _saveValues() already handled it via replaceLangModule(), which does not
     * hold in "delete" mode - see this class' and the method's own docblock) and not wiped empty
     * either. The write lands in the OVERLAY, never the shipped `.po`.
     */
    public function testSyncsAModulePresentInTheImportWithItsToSaveSubset(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->bootstrapOverlayFromShipped('dtest', 'de');
        $this->registerDirectoryManager($directory);

        $this->invoke(['dtest'], ['dtest#:#greeting' => 'Hallo, neu']);

        $translation = $this->loadOverlayPo('dtest', 'de')->find('dtest', 'greeting');
        $this->assertNotNull($translation);
        $this->assertSame('Hallo, neu', $translation->getTranslation());

        $shipped_translation = $this->loadFixturePo('dtest', 'de')->find('dtest', 'greeting');
        $this->assertNotNull($shipped_translation);
        $this->assertSame('Hallo', $shipped_translation->getTranslation(), 'the shipped .po must never be touched');
    }

    public function testDoesNotThrowForANonMigratedModuleMissingFromTheImport(): void
    {
        $this->registerDirectoryManager();

        // "plain_module" never contributed a LanguageFileDirectory at all.
        $this->invoke(['plain_module'], []);

        $this->addToAssertionCount(1);
    }

    public function testIsANoOpWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // Deliberately no registerDirectoryManager() call - $DIC->offsetExists() must be false.
        $this->invoke(['dtest'], []);

        $this->addToAssertionCount(1);
    }

    /**
     * Boundary case for the $to_save key parsing: explode($this->separator, $key) must yield exactly
     * two parts to be attributed to a module at all (see the method's `count($parts) === 2` check). A
     * key with no separator in it at all must not be misread as belonging to some module - this pins
     * that boundary against a mutation loosening it to e.g. `>= 1` or dropping the check, either of
     * which could wrongly attribute a bogus entry to a module or silently skip the union entirely.
     */
    public function testAToSaveKeyWithoutASeparatorDoesNotAccidentallyAttachToAnyModule(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->bootstrapOverlayFromShipped('dtest', 'de');
        $this->registerDirectoryManager($directory);

        // A malformed/unexpected key with no "#:#" in it at all - "dtest" is still only reached via
        // $modules_before_delete here, so it must be synced with an empty map, exactly as if $to_save
        // had been empty.
        $this->invoke(['dtest'], ['not_a_valid_key' => 'value']);

        $this->assertNull($this->loadOverlayPo('dtest', 'de')->find('dtest', 'greeting'));
    }

    /**
     * Multiple modules existed before the delete; only one of them is present in $to_save. Each must
     * be synced with its own correct entries - the missing one emptied, the present one updated to its
     * $to_save subset - not all treated the same (e.g. an implementation that clears everything in
     * $modules_before_delete regardless of $to_save, or one that skips every module in $to_save
     * instead of syncing it).
     */
    public function testSyncsEachOfSeveralModulesWithItsOwnCorrectEntries(): void
    {
        $missing = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        $this->bootstrapOverlayFromShipped('dtest', 'de');
        $kept = $this->seedFixtureModule('ktest', 'de', ['greeting' => 'Hallo']);
        $this->bootstrapOverlayFromShipped('ktest', 'de');
        $this->registerDirectoryManager($missing, $kept);

        $this->invoke(['dtest', 'ktest'], ['ktest#:#greeting' => 'Hallo, neu']);

        $this->assertNull($this->loadOverlayPo('dtest', 'de')->find('dtest', 'greeting'));
        $updated = $this->loadOverlayPo('ktest', 'de')->find('ktest', 'greeting');
        $this->assertNotNull($updated);
        $this->assertSame('Hallo, neu', $updated->getTranslation());
    }

    /**
     * The overlay mirrors "language installed": an import into a language without overlay creates
     * it - unconditionally, sync() has no flag gating this -, seeded with the shipped originals.
     */
    public function testCreatesAMissingOverlayWithTheShippedOriginal(): void
    {
        $directory = $this->seedFixtureModule('dtest', 'de', ['greeting' => 'Hallo']);
        // deliberately no bootstrapOverlayFromShipped() call - no overlay exists at all yet
        $this->registerDirectoryManager($directory);

        $this->invoke(['dtest'], ['dtest#:#greeting' => 'Hallo, neu']);

        $this->assertTrue($this->overlayMoExists('dtest', 'de'));
        $greeting = $this->loadOverlayPo('dtest', 'de')->find('dtest', 'greeting');
        $this->assertSame('Hallo, neu', $greeting->getTranslation());
        $this->assertSame('Hallo', \ILIAS\Language\ComponentTranslation\LocalChangeComments::getOriginal($greeting));
    }

    /**
     * Regression guard for a real incident: tearDown() used to recursively delete CLIENT_DATA_DIR
     * whenever it was merely is_dir() - in a full-suite run where another, unrelated test had
     * already defined it as the real client data directory (e.g. /var/iliasdata), that wiped real
     * data. tearDown() must now only ever delete a CLIENT_DATA_DIR this class created itself; a
     * foreign, pre-existing definition must be left completely untouched.
     *
     * Runs in a separate process so this class' own CLIENT_DATA_DIR handling (defined by other test
     * methods sharing the normal process) cannot leak in and mask the "foreign, pre-existing"
     * scenario under test here. setUp() special-cases this test method by name (see above) so it
     * never auto-defines CLIENT_DATA_DIR itself.
     */
    #[RunInSeparateProcess]
    public function testTearDownNeverDeletesAClientDataDirThisClassDidNotCreateItself(): void
    {
        $this->assertFalse(defined('CLIENT_DATA_DIR'));

        $foreign_dir = sys_get_temp_dir() . '/ilias_foreign_client_data_dir_' . bin2hex(random_bytes(4));
        mkdir($foreign_dir, 0775, true);
        file_put_contents($foreign_dir . '/marker.txt', 'do-not-delete');
        // Simulates a completely foreign, already-existing CLIENT_DATA_DIR (e.g. the real
        // /var/iliasdata in a full-suite run) that this test class did not create and does not own.
        define('CLIENT_DATA_DIR', $foreign_dir);

        try {
            $directory = $this->seedFixtureModule('dforeign', 'de', ['greeting' => 'Hallo']);
            $this->bootstrapOverlayFromShipped('dforeign', 'de');
            $this->registerDirectoryManager($directory);
            $this->assertTrue($this->overlayMoExists('dforeign', 'de'));

            // tearDown() is protected; calling it directly from within the class itself is allowed
            // regardless of visibility. PHPUnit will also invoke it again once this test method
            // returns - harmless, since a second run finds nothing left to clean up.
            $this->tearDown();

            $this->assertFileExists(
                $foreign_dir . '/marker.txt',
                'tearDown() deleted (part of) a CLIENT_DATA_DIR this test class did not create itself.'
            );
            // This test's own fixture subdirectory, by contrast, is safely cleaned up - it is uniquely
            // named and this test itself created it, regardless of who owns the CLIENT_DATA_DIR root.
            $this->assertFalse($this->overlayMoExists('dforeign', 'de'));
        } finally {
            // Clean up the rest of what this test itself created - tearDown() correctly leaves the
            // foreign CLIENT_DATA_DIR root alone, by design. Runs regardless of whether an assertion
            // above failed, so a failing assertion never leaves $foreign_dir behind on disk.
            if (is_file($foreign_dir . '/marker.txt')) {
                unlink($foreign_dir . '/marker.txt');
            }
            if (is_dir($foreign_dir)) {
                $this->removeDirectoryRecursively($foreign_dir);
            }
        }
    }

    /**
     * A CLIENT_DATA_DIR that is not even a test-owned temp directory - e.g. the real client data
     * directory a full-suite run may already have defined - must not merely be spared from deletion
     * (see the test above): this class must refuse to WRITE fixture files there at all.
     * guardClientDataDirIsTestOwned() (called from setUp() for every other test) therefore skips
     * instead, for every path outside sys_get_temp_dir().
     */
    #[RunInSeparateProcess]
    public function testRefusesToWriteIntoAClientDataDirOutsideSysTempDir(): void
    {
        $foreign_dir = __DIR__ . '/tmp-not-a-temp-dir-' . bin2hex(random_bytes(4));
        $this->assertFalse(str_starts_with($foreign_dir, sys_get_temp_dir() . '/'));
        mkdir($foreign_dir, 0775, true);
        define('CLIENT_DATA_DIR', $foreign_dir);

        try {
            try {
                $this->guardClientDataDirIsTestOwned();
                $this->fail('guardClientDataDirIsTestOwned() must refuse a CLIENT_DATA_DIR outside sys_get_temp_dir().');
            } catch (\PHPUnit\Framework\SkippedWithMessageException $e) {
                $this->assertStringContainsString($foreign_dir, $e->getMessage());
            }

            $this->assertSame([], array_diff(scandir($foreign_dir) ?: [], ['.', '..']));
        } finally {
            if (is_dir($foreign_dir)) {
                $this->removeDirectoryRecursively($foreign_dir);
            }
        }
    }
}

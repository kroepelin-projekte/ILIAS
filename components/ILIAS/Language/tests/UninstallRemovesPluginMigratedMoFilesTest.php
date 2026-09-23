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
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Covers ilPluginLanguage::uninstall()'s overlay-file cleanup - the plugin-level counterpart to
 * ilObjLanguage::removeMigratedMoFiles() (see UninstallRemovesMigratedMoFilesTest.php).
 *
 * Before this, uninstalling a plugin only ever deleted its lng_data/lng_modules rows via raw SQL - a
 * migrated plugin's compiled overlay .po/.mo files (for every language it ships) were left completely
 * untouched on disk. ilLanguage::loadLanguageModule()/txtlng() never check whether a module's owning
 * plugin is still installed before reading its overlay .mo file, so a stale one would keep serving the
 * uninstalled plugin's content forever, exactly the same class of gap that existed for whole-language
 * uninstalls.
 *
 * Under the current design, the SHIPPED `.po` is written exactly once by the conversion tool and never
 * again - there is no shipped `.mo` at all. Every actual read/write goes through a per-installation
 * "overlay" `.po`+`.mo` pair under CLIENT_DATA_DIR, mirroring the shipped file's own relative location.
 * removeMigratedMoFiles() removes BOTH overlay files together via
 * MigratedLanguageFileSync::removeOverlay() and must leave the (never-written) shipped `.po` untouched.
 *
 * Uses a throwaway fixture module/prefix (not any real plugin) so these tests never touch real data.
 *
 * Runs every test method in its own separate process: a full-suite run can have CLIENT_DATA_DIR
 * already defined by an earlier, unrelated test class sharing the same process (e.g.
 * Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php define it
 * as /var/iliasdata) - without this, ensureClientDataDirDefined()'s guard would then skip every single
 * test below for the rest of that process, since a PHP constant cannot be redefined. A fresh process
 * per test method guarantees CLIENT_DATA_DIR starts out undefined here, exactly like a lone test run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class UninstallRemovesPluginMigratedMoFilesTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;
    private ?string $plugin_root_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            $this->removeDirectoryRecursively($this->fixture_directory);
        }
        if (isset($this->plugin_root_directory) && is_dir($this->plugin_root_directory)) {
            $this->removeDirectoryRecursively($this->plugin_root_directory);
        }

        // Deliberately never deletes CLIENT_DATA_DIR itself (a regression guard for a real incident:
        // an earlier version of this method recursively deleted the whole CLIENT_DATA_DIR whenever it
        // was merely defined() - in a full-suite run that constant was the real, pre-existing
        // /var/iliasdata, which got wiped). Instead it deletes only the one subdirectory this specific
        // test method's fixture ever wrote to (see overlayFixtureDirectory()) - a directory this test
        // is guaranteed to have created itself, since its name is derived from $this->fixture_directory
        // (a fresh random name per test method, see seedShippedModule()).
        $overlay_fixture_dir = $this->overlayFixtureDirectory();
        if ($overlay_fixture_dir !== null && is_dir($overlay_fixture_dir)) {
            $this->removeDirectoryRecursively($overlay_fixture_dir);
        }

        parent::tearDown();
    }

    /**
     * The one subdirectory under CLIENT_DATA_DIR this test method's fixture (if any) ever wrote to -
     * see seedOverlay(). Returns null when either CLIENT_DATA_DIR was never resolved/defined in this
     * test, or no fixture was ever seeded, so tearDown() has nothing of its own to clean up.
     */
    private function overlayFixtureDirectory(): ?string
    {
        if (!isset($this->fixture_directory) || !defined('CLIENT_DATA_DIR')) {
            return null;
        }

        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename($this->fixture_directory);
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
     * A PHP constant cannot be redefined - guarded exactly like ILIAS_ABSOLUTE_PATH above. Deliberately
     * NOT called from setUp(): the "CLIENT_DATA_DIR cannot be resolved" test below must observe it as
     * undefined, and runs in its own process (see #[RunInSeparateProcess]).
     *
     * A PHP constant, once defined, is shared by every test in the whole suite's process - including
     * one from a completely different test class that got there first. This method therefore never
     * assumes exclusive ownership of CLIENT_DATA_DIR: it happily (re)uses whatever value is already
     * there - safe, because this class's own tearDown() only ever deletes its own uniquely-named
     * fixture subdirectory (see overlayFixtureDirectory()), never CLIENT_DATA_DIR itself.
     */
    private function ensureClientDataDirDefined(): void
    {
        // A full-suite run can have CLIENT_DATA_DIR already pointing at the real client data
        // directory (e.g. Filesystem/tests/ilServicesFileSystemTest.php or
        // Test/tests/ilTestBaseTestCaseTrait.php define it as /var/iliasdata). Never deleting it
        // (see tearDown()) is not enough on its own: writing fixture files into real production data
        // must be refused just as strictly.
        if (defined('CLIENT_DATA_DIR') && !str_starts_with(CLIENT_DATA_DIR, sys_get_temp_dir() . '/')) {
            $this->markTestSkipped(
                'CLIENT_DATA_DIR ("' . CLIENT_DATA_DIR . '") is not a test-owned temp directory - '
                . 'refusing to write fixture files there.'
            );
        }
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_plugin_uninstall_mo_test_' . bin2hex(random_bytes(4)));
        }
    }

    /**
     * Seeds only the SHIPPED `.po` file for a throwaway module/language - never a shipped `.mo`, which
     * the current design never writes at all. Returns the
     * LanguageFileDirectory that makes it discoverable the same way a real ComponentLanguageFileDirectory
     * contribution would.
     */
    private function seedShippedModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-plugin-uninstall-fixtures-' . bin2hex(random_bytes(4));
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
     * Seeds the OVERLAY `.po`+`.mo` pair for a module/language directly under CLIENT_DATA_DIR,
     * mirroring the shipped file's relative path one-to-one. Requires seedShippedModule() to have run
     * first for this fixture's directory.
     */
    private function seedOverlay(string $module, string $lang_key, array $entries): void
    {
        $this->ensureClientDataDirDefined();

        $translations = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        foreach ($entries as $identifier => $value) {
            $translations->add(MigratedPoFixture::entry($module, $identifier, $value));
        }

        $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename((string) $this->fixture_directory) . '/';
        if (!is_dir($overlay_dir)) {
            mkdir($overlay_dir, 0775, true);
        }

        $base_path = $overlay_dir . $module . '_' . $lang_key;
        MigratedPoFixture::writePo($base_path . '.po', $translations);
        MigratedPoFixture::writeMo($base_path . '.mo', $translations);
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function shippedPath(string $module, string $lang_key, string $extension): string
    {
        return $this->fixture_directory . '/' . $module . '_' . $lang_key . '.' . $extension;
    }

    private function overlayPath(string $module, string $lang_key, string $extension): string
    {
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . basename((string) $this->fixture_directory) . '/' . $module . '_' . $lang_key . '.' . $extension;
    }

    /**
     * A plugin's getAvailableLangFiles() scans "<plugin path>/lang" for "ilias_<key>.lang" files -
     * content is irrelevant to uninstall()/getAvailableLangFiles(), only the file names matter.
     *
     * @param list<string> $lang_keys
     */
    private function createPluginInfo(string $module_prefix, array $lang_keys): ilPluginInfo
    {
        $this->plugin_root_directory = __DIR__ . '/tmp-plugin-root-' . bin2hex(random_bytes(4));
        mkdir($this->plugin_root_directory . '/lang', 0775, true);
        foreach ($lang_keys as $lang_key) {
            touch($this->plugin_root_directory . '/lang/ilias_' . $lang_key . '.lang');
        }

        $component = $this->createStub(ilComponentInfo::class);
        $component->method('getId')->willReturn('comp');

        $slot = $this->createStub(ilPluginSlotInfo::class);
        $slot->method('getId')->willReturn('slot');

        $plugin_info = $this->createStub(ilPluginInfo::class);
        $plugin_info->method('getComponent')->willReturn($component);
        $plugin_info->method('getPluginSlot')->willReturn($slot);
        $plugin_info->method('getId')->willReturn($module_prefix);
        $plugin_info->method('getPath')->willReturn($this->plugin_root_directory);

        return $plugin_info;
    }

    private function createDatabaseStub(): ilDBInterface
    {
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn(mixed $v): string => "'" . (string) $v . "'");

        return $db;
    }

    public function testUninstallRemovesBothOverlayFilesForEveryLanguageThePluginShipsButLeavesTheShippedPoUntouched(): void
    {
        // getPrefix() = "comp_slot_ptest" (see createPluginInfo()'s component/slot mocks)
        $module = 'comp_slot_ptest';
        $directory = $this->seedShippedModule($module, 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay($module, 'de', ['greeting' => 'Hallo']);
        $this->seedShippedModule($module, 'fr', ['greeting' => 'Bonjour']);
        $this->seedOverlay($module, 'fr', ['greeting' => 'Bonjour']);
        $this->registerDirectoryManager($directory);
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de', 'fr']));
        $plugin_language->uninstall();

        $this->assertFileDoesNotExist($this->overlayPath($module, 'de', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath($module, 'de', 'po'));
        $this->assertFileDoesNotExist($this->overlayPath($module, 'fr', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath($module, 'fr', 'po'));
        $this->assertFileExists($this->shippedPath($module, 'de', 'po'));
        $this->assertFileExists($this->shippedPath($module, 'fr', 'po'));
    }

    public function testUninstallDoesNotTouchAnotherModulesOverlay(): void
    {
        $module = 'comp_slot_ptest';
        $other_module = 'comp_slot_other';
        $directory = $this->seedShippedModule($module, 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay($module, 'de', ['greeting' => 'Hallo']);
        $other_directory = $this->seedShippedModule($other_module, 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay($other_module, 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory, $other_directory);
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de']));
        $plugin_language->uninstall();

        $this->assertFileDoesNotExist($this->overlayPath($module, 'de', 'mo'));
        $this->assertFileExists($this->overlayPath($other_module, 'de', 'mo'));
        $this->assertFileExists($this->overlayPath($other_module, 'de', 'po'));
    }

    public function testIsANoOpWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        // must not throw even though nothing was contributed
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de']));
        $plugin_language->uninstall();

        $this->addToAssertionCount(1);
    }

    public function testIsANoOpWhenTheModuleHasNoOverlayForALanguageItShips(): void
    {
        $module = 'comp_slot_ptest';
        $directory = $this->seedShippedModule($module, 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay($module, 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        // the plugin claims to ship "es" too, but no es .po/.mo fixture exists for it at all
        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de', 'es']));
        $plugin_language->uninstall();

        $this->assertFileDoesNotExist($this->overlayPath($module, 'de', 'mo'));
    }

    /**
     * The central overlay-vs-shipped behavior change: when CLIENT_DATA_DIR cannot be resolved at all,
     * uninstall()'s overlay cleanup must no-op entirely - never fall back to touching the shipped `.po`.
     * Runs in a separate process because CLIENT_DATA_DIR, once defined, cannot be undefined again for
     * the rest of this test class' shared process.
     */
    #[RunInSeparateProcess]
    public function testIsANoOpWhenClientDataDirIsNotResolvable(): void
    {
        $this->assertFalse(defined('CLIENT_DATA_DIR'));

        $module = 'comp_slot_ptest';
        $directory = $this->seedShippedModule($module, 'de', ['greeting' => 'Hallo']);
        $shipped_po_before = file_get_contents($this->shippedPath($module, 'de', 'po'));
        $this->registerDirectoryManager($directory);
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de']));
        $plugin_language->uninstall();

        $this->assertSame($shipped_po_before, file_get_contents($this->shippedPath($module, 'de', 'po')));
    }

    /**
     * The catch(\Throwable) branch: a failure removing one language's overlay must be logged via
     * $DIC->logger()->forComponent('lang')->warning(...) and swallowed - not thrown - and must not
     * abort processing of the plugin's other shipped languages. Same technique as
     * UninstallRemovesMigratedMoFilesTest::testLogsAndSwallowsARemovalFailureForOneModuleWithoutAbortingOthers().
     */
    public function testLogsAndSwallowsARemovalFailureForOneLanguageWithoutAbortingOthers(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unremovable file while running as root; skipping.');
        }

        // Unlike ilObjLanguage::removeMigratedMoFiles() (which loops over *modules*, each potentially
        // in its own directory), this method loops over *languages* for one single module/directory -
        // unlink()'s failure mode is a directory permission, not a file permission, so both languages'
        // overlay necessarily live in (and fail to remove from) the same directory here. The loop
        // continuing past a failure is instead proven by both languages being attempted and logged
        // individually, rather than by one succeeding while the other fails.
        $module = 'comp_slot_ptest';
        $this->ensureClientDataDirDefined();
        $this->fixture_directory ??= __DIR__ . '/tmp-plugin-uninstall-fixtures-' . bin2hex(random_bytes(4));
        $shipped_readonly_dir = $this->fixture_directory . '/readonly';
        mkdir($shipped_readonly_dir, 0775, true);

        $relative_readonly_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/readonly/';
        $overlay_readonly_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . $relative_readonly_path;
        mkdir($overlay_readonly_dir, 0775, true);

        foreach (['de' => 'Hallo', 'fr' => 'Bonjour'] as $lang_key => $value) {
            $translations = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
            $translations->add(MigratedPoFixture::entry($module, 'greeting', $value));
            MigratedPoFixture::writePo("$shipped_readonly_dir/{$module}_{$lang_key}.po", $translations);
            MigratedPoFixture::writePo("$overlay_readonly_dir/{$module}_{$lang_key}.po", $translations);
            MigratedPoFixture::writeMo("$overlay_readonly_dir/{$module}_{$lang_key}.mo", $translations);
        }

        $directory = new class ($module, $relative_readonly_path) implements LanguageFileDirectory {
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
        $this->registerDirectoryManager($directory);
        $this->setGlobalVariable('ilDB', $this->createDatabaseStub());

        chmod($overlay_readonly_dir, 0555);

        $warnings = [];
        $logger = $this->createStub(ilLogger::class);
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturnMap([
            ['lang', $logger],
        ]);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $plugin_language = new ilPluginLanguage($this->createPluginInfo('ptest', ['de', 'fr']));
            $plugin_language->uninstall();
        } finally {
            restore_error_handler();
            chmod($overlay_readonly_dir, 0775);
        }

        // both languages failed to remove (same read-only overlay directory) and were each logged
        // individually - proving the loop attempted 'fr' too instead of aborting after 'de' failed.
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('de', $warnings[0] ?? '');
        $this->assertStringContainsString('fr', $warnings[1] ?? '');
        $this->assertFileExists("$overlay_readonly_dir/{$module}_de.mo");
        $this->assertFileExists("$overlay_readonly_dir/{$module}_fr.mo");
    }

    /**
     * Regression guard for a real incident: tearDown() used to recursively delete CLIENT_DATA_DIR
     * whenever it was merely defined() - in a full-suite run where another, unrelated test had
     * already defined it as the real client data directory (e.g. /var/iliasdata), that wiped real
     * data. tearDown() must now only ever delete a CLIENT_DATA_DIR this class created itself; a
     * foreign, pre-existing definition must be left completely untouched.
     *
     * Runs in a separate process so this class' own CLIENT_DATA_DIR handling (defined by other test
     * methods sharing the normal process) cannot leak in and mask the "foreign, pre-existing"
     * scenario under test here.
     */
    #[RunInSeparateProcess]
    public function testTearDownNeverDeletesAClientDataDirThisClassDidNotCreateItself(): void
    {
        $foreign_dir = sys_get_temp_dir() . '/ilias_foreign_client_data_dir_' . bin2hex(random_bytes(4));
        mkdir($foreign_dir, 0775, true);
        file_put_contents($foreign_dir . '/marker.txt', 'do-not-delete');
        // Simulates a completely foreign, already-existing CLIENT_DATA_DIR (e.g. the real
        // /var/iliasdata in a full-suite run) that this test class did not create and does not own.
        define('CLIENT_DATA_DIR', $foreign_dir);

        try {
            $directory = $this->seedShippedModule('uforeign', 'de', ['greeting' => 'Hallo']);
            $this->seedOverlay('uforeign', 'de', ['greeting' => 'Hallo']);
            $this->registerDirectoryManager($directory);
            $this->assertFileExists($this->overlayPath('uforeign', 'de', 'po'));

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
            $this->assertFileDoesNotExist($this->overlayPath('uforeign', 'de', 'po'));
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
     * ensureClientDataDirDefined() therefore skips instead, for every path outside
     * sys_get_temp_dir() - not only ones this class did not create.
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
                $directory = $this->seedShippedModule('uoutside', 'de', ['greeting' => 'Hallo']);
                $this->seedOverlay('uoutside', 'de', ['greeting' => 'Hallo']);
                $this->fail('seedOverlay() must refuse to write into a CLIENT_DATA_DIR outside sys_get_temp_dir().');
            } catch (SkippedWithMessageException $e) {
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

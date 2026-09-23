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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
 */
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
        if (defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            $this->removeDirectoryRecursively(CLIENT_DATA_DIR);
        }

        parent::tearDown();
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
     */
    private function ensureClientDataDirDefined(): void
    {
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

        $translations = new \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog();
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

        $translations = new \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog();
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
            $translations = new \ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog();
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
}

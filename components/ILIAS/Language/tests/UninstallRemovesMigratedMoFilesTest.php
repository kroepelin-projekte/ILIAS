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
 * Covers ilObjLanguage::removeMigratedMoFiles() - the overlay-file counterpart to uninstall()'s DB-side
 * flush() for every module migrated to the PO/MO pilot.
 *
 * Without this method, uninstalling a language left a migrated module's compiled overlay .po/.mo pair
 * completely untouched: lng_data/lng_modules are gone and the language shows "not_installed" in
 * administration, but ilLanguage::txtlng() never checks whether its $lang_key argument is actually
 * installed before reading a migrated module's overlay .mo file - it would keep serving the now-stale,
 * uninstalled content forever.
 *
 * Under the current design, the SHIPPED `.po` (e.g. components/ILIAS/TermsOfService/lang/tos_de.po) is
 * written exactly once, by tools/po-migration/convert_module_to_po.php, and never again - there is no
 * shipped `.mo` at all any more. Every actual read/write goes through a per-installation "overlay"
 * `.po`+`.mo` pair under CLIENT_DATA_DIR, mirroring the shipped file's own relative location.
 * removeMigratedMoFiles() therefore now removes BOTH overlay files together via
 * MigratedLanguageFileSync::removeOverlay() - unlike the old
 * removeMoFile() (which removed only the .mo, deliberately leaving the then-precious shipped .po alone) -
 * and must leave the shipped .po (which was never written to begin with) completely untouched.
 *
 * Uses throwaway fixture modules (not tos's real files) so these tests never touch real pilot data.
 * Follows the same fixture pattern as PoMigrationWriteBackTest/ResetMigratedLocalChangesTest, extended
 * with a second, independent root for the overlay - mirroring
 * ComponentTranslation/MigratedLanguageFileSyncTest.php's "two independent roots" pattern.
 */
class UninstallRemovesMigratedMoFilesTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

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

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }

        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            $this->removeDirectoryRecursively($this->fixture_directory);
        }

        if (defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            $this->removeDirectoryRecursively(CLIENT_DATA_DIR);
        }

        parent::tearDown();
    }

    /**
     * A PHP constant cannot be redefined - guarded exactly like ILIAS_ABSOLUTE_PATH above. Deliberately
     * NOT called from setUp(): the "CLIENT_DATA_DIR cannot be resolved" test below must observe it as
     * undefined, and runs in its own process (see #[RunInSeparateProcess]) precisely so that an earlier
     * test in this class having defined it does not leak into that one.
     */
    private function ensureClientDataDirDefined(): void
    {
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_uninstall_mo_test_' . bin2hex(random_bytes(4)));
        }
    }

    /**
     * Seeds only the SHIPPED `.po` file for a throwaway module - never a shipped `.mo`, which the
     * current design never writes at all. Returns the
     * LanguageFileDirectory that makes it discoverable the same way a real ComponentLanguageFileDirectory
     * contribution would.
     *
     * @param array<string, string> $entries identifier => value
     */
    private function seedShippedModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-uninstall-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = new \ILIAS\Language\ComponentTranslation\Gettext\Catalog();
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
     * mirroring the shipped file's relative path one-to-one - simulating an already-installed language
     * whose overlay was compiled by an earlier install (see MigratedLanguageFileSync's private
     * overlayBasePath()). Requires seedShippedModule() to have run first for this fixture's directory.
     *
     * @param array<string, string> $entries identifier => value
     */
    private function seedOverlay(string $module, string $lang_key, array $entries): void
    {
        $this->ensureClientDataDirDefined();

        $translations = new \ILIAS\Language\ComponentTranslation\Gettext\Catalog();
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

    private function loadShippedPo(string $module, string $lang_key): \ILIAS\Language\ComponentTranslation\Gettext\Catalog
    {
        return MigratedPoFixture::readPo($this->shippedPath($module, $lang_key, 'po'));
    }

    /**
     * The method under test is private static - invoked the same way
     * ResetMigratedLocalChangesTest::callResetMigratedLocalChanges() invokes its sibling.
     */
    private function callRemoveMigratedMoFiles(string $lang_key): void
    {
        (new ReflectionClass(ilObjLanguage::class))
            ->getMethod('removeMigratedMoFiles')
            ->invoke(null, $lang_key);
    }

    /**
     * ilLanguage's own migrated-file cache is private static - invoked the same way
     * PoMigrationLoadLanguageModuleTest::callLoadFromMigratedLanguageFile() invokes its sibling. Reads
     * exclusively from the overlay (see ilLanguage::migratedOverlayMoFile()), never the shipped file.
     *
     * @return array<string, string>|null
     */
    private function callLoadFromMigratedLanguageFile(string $module, string $lang_key): ?array
    {
        return (new ReflectionClass(ilLanguage::class))
            ->getMethod('loadFromMigratedLanguageFile')
            ->invoke(null, $module, $lang_key);
    }

    public function testRemovesBothOverlayFilesButLeavesTheShippedPoUntouched(): void
    {
        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);
        $po_before = file_get_contents($this->shippedPath('utest', 'de', 'po'));

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->overlayPath('utest', 'de', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath('utest', 'de', 'po'));
        $this->assertFileExists($this->shippedPath('utest', 'de', 'po'));
        $this->assertSame($po_before, file_get_contents($this->shippedPath('utest', 'de', 'po')));
    }

    public function testRemovesEachContributedModuleIndependently(): void
    {
        $first = $this->seedShippedModule('uone', 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay('uone', 'de', ['greeting' => 'Hallo']);
        $second = $this->seedShippedModule('utwo', 'de', ['greeting' => 'Servus']);
        $this->seedOverlay('utwo', 'de', ['greeting' => 'Servus']);
        $this->registerDirectoryManager($first, $second);

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->overlayPath('uone', 'de', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath('uone', 'de', 'po'));
        $this->assertFileDoesNotExist($this->overlayPath('utwo', 'de', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath('utwo', 'de', 'po'));
        $this->assertFileExists($this->shippedPath('uone', 'de', 'po'));
        $this->assertFileExists($this->shippedPath('utwo', 'de', 'po'));
    }

    public function testOnlyRemovesTheOverlayForTheRequestedLanguageNotOtherLanguages(): void
    {
        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay('utest', 'de', ['greeting' => 'Hallo']);
        // Same module, different language - must survive uninstalling only 'de'.
        $this->seedOverlay('utest', 'fr', ['greeting' => 'Bonjour']);
        $this->registerDirectoryManager($directory);

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->overlayPath('utest', 'de', 'mo'));
        $this->assertFileExists($this->overlayPath('utest', 'fr', 'mo'));
        $this->assertFileExists($this->overlayPath('utest', 'fr', 'po'));
    }

    public function testIsANoOpWhenNoOverlayExistsForThatLanguage(): void
    {
        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);
        // deliberately no seedOverlay() call - nothing to remove at all

        // must not throw even though there is nothing to remove
        $this->callRemoveMigratedMoFiles('de');

        $this->addToAssertionCount(1);
        $this->assertFileExists($this->shippedPath('utest', 'de', 'po'));
    }

    public function testIsANoOpForALanguageThatHasNoOverlayAtAll(): void
    {
        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // "fr" was never seeded at all -> must not throw, must not affect the "de" fixture
        $this->callRemoveMigratedMoFiles('fr');

        $this->assertFileExists($this->overlayPath('utest', 'de', 'mo'));
        $this->assertFileExists($this->overlayPath('utest', 'de', 'po'));
    }

    public function testReturnsImmediatelyWithoutErrorWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // must not throw even though nothing was contributed and no fixture file exists anywhere
        $this->callRemoveMigratedMoFiles('de');

        $this->addToAssertionCount(1);
    }

    /**
     * The central overlay-vs-shipped behavior change (see MigratedLanguageFileSyncTest's identically
     * named guarantee for sync()/removeOverlay() themselves): when CLIENT_DATA_DIR cannot be resolved at
     * all, removeMigratedMoFiles() must no-op entirely - never fall back to touching the shipped `.po`.
     * Runs in a separate process because CLIENT_DATA_DIR, once defined, cannot be undefined again for
     * the rest of this test class' shared process (other tests above define it via seedOverlay()).
     */
    #[RunInSeparateProcess]
    public function testIsANoOpWhenClientDataDirIsNotResolvable(): void
    {
        $this->assertFalse(defined('CLIENT_DATA_DIR'));

        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $shipped_po_before = file_get_contents($this->shippedPath('utest', 'de', 'po'));
        $this->registerDirectoryManager($directory);

        $this->callRemoveMigratedMoFiles('de');

        $this->assertSame($shipped_po_before, file_get_contents($this->shippedPath('utest', 'de', 'po')));
    }

    public function testInvalidatesIlLanguagesCacheSoTheRemovalIsVisibleImmediately(): void
    {
        $directory = $this->seedShippedModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->seedOverlay('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // populates loadFromMigratedLanguageFile()'s static cache for 'utest'|'de'
        $before = $this->callLoadFromMigratedLanguageFile('utest', 'de');
        $this->assertSame(['greeting' => 'Hallo'], $before);

        $this->callRemoveMigratedMoFiles('de');

        // the overlay is gone - a stale cached hit from before the removal must not leak through
        $this->assertNull($this->callLoadFromMigratedLanguageFile('utest', 'de'));
    }

    /**
     * The catch(\Throwable) branch: a failure removing one module's overlay must be logged via
     * $DIC->logger()->forComponent('lang')->warning(...) and swallowed - not thrown - and must not
     * abort processing of other contributed modules. $DIC->logger() is a real \ILIAS\DI\LoggingServices
     * object (not an offsetGet-based service, see class.ilObjLanguage.php's other DIC calls), so it is
     * stubbed at the level it actually delegates to: the 'ilLoggerFactory' service - same technique as
     * ResetMigratedLocalChangesTest::testLogsAndSwallowsAReadFailureForOneModuleWithoutAbortingOthers().
     *
     * unlink() fails on a directory permission, not a file permission, so the "failing" module's own
     * overlay subdirectory (not the whole CLIENT_DATA_DIR tree, which would also make the "working"
     * module fail) is made unwritable to force it - unlink() also emits a PHP warning before returning
     * false, silenced the same way testThrowsWhenTheOverlayPoFileCannotBeWritten() does in
     * MigratedLanguageFileSyncTest.
     */
    public function testLogsAndSwallowsARemovalFailureForOneModuleWithoutAbortingOthers(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unremovable file while running as root; skipping.');
        }

        $this->ensureClientDataDirDefined();
        $this->fixture_directory ??= __DIR__ . '/tmp-uninstall-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        // The "failing" module lives in its own shipped + overlay "readonly" sub-path, distinct from
        // the "working" module's normal fixture root, so only its overlay directory gets locked down.
        $shipped_readonly_dir = $this->fixture_directory . '/readonly';
        mkdir($shipped_readonly_dir, 0775, true);
        $shipped_translations = new \ILIAS\Language\ComponentTranslation\Gettext\Catalog();
        $shipped_translations->add(MigratedPoFixture::entry('uone', 'greeting', 'Hallo'));
        MigratedPoFixture::writePo($shipped_readonly_dir . '/uone_de.po', $shipped_translations);

        $relative_readonly_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/readonly/';
        $overlay_readonly_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . $relative_readonly_path;
        mkdir($overlay_readonly_dir, 0775, true);
        MigratedPoFixture::writePo($overlay_readonly_dir . 'uone_de.po', $shipped_translations);
        MigratedPoFixture::writeMo($overlay_readonly_dir . 'uone_de.mo', $shipped_translations);

        $failing = new class ('uone', $relative_readonly_path) implements LanguageFileDirectory {
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
        $working = $this->seedShippedModule('utwo', 'de', ['greeting' => 'Servus']);
        $this->seedOverlay('utwo', 'de', ['greeting' => 'Servus']);
        $this->registerDirectoryManager($failing, $working);

        chmod($overlay_readonly_dir, 0555);

        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('uone'));
        $logger_factory = $this->createMock(ilLoggerFactory::class);
        $logger_factory->expects($this->once())->method('getComponentLogger')->with('lang')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->callRemoveMigratedMoFiles('de');
        } finally {
            restore_error_handler();
            chmod($overlay_readonly_dir, 0775);
        }

        // the failing module's overlay was never actually removed
        $this->assertFileExists($overlay_readonly_dir . 'uone_de.mo');
        $this->assertFileExists($overlay_readonly_dir . 'uone_de.po');

        // the other, unaffected module's overlay was still removed despite the first module's failure
        $this->assertFileDoesNotExist($this->overlayPath('utwo', 'de', 'mo'));
        $this->assertFileDoesNotExist($this->overlayPath('utwo', 'de', 'po'));
    }
}

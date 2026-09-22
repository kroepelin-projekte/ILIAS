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
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers ilObjLanguage::removeMigratedMoFiles() - the .mo-file counterpart to uninstall()'s DB-side
 * flush() for every module migrated to the PO/MO pilot (see
 * components/ILIAS/Language/tools/po-migration/README.md, "Rollback").
 *
 * Without this method, uninstalling a language left a migrated module's compiled .mo file completely
 * untouched: lng_data/lng_modules are gone and the language shows "not_installed" in administration,
 * but ilLanguage::txtlng() never checks whether its $lang_key argument is actually installed before
 * reading a migrated module's .mo file - it would keep serving the now-stale, uninstalled content
 * forever. Deliberately leaves the .po file untouched - it ships like a .lang file, and is what a
 * later re-install compiles a fresh .mo from again (see LanguageInstallationManager's
 * $create_missing_mo).
 *
 * Uses throwaway fixture modules (not tos's real files) so these tests never touch real pilot data.
 * Follows the same fixture pattern as PoMigrationWriteBackTest/ResetMigratedLocalChangesTest.
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
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_translations_cache')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            $this->removeDirectoryRecursively($this->fixture_directory);
        }

        parent::tearDown();
    }

    /**
     * Seeds a real .po/.mo pair for a throwaway module, exactly like a real installation would, and
     * returns the LanguageFileDirectory that makes it discoverable the same way a real
     * ComponentLanguageFileDirectory contribution would.
     *
     * @param array<string, string> $entries identifier => value
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-uninstall-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $value) {
            $translations->add(Translation::create($module, $identifier)->translate($value));
        }

        $base_path = $this->fixture_directory . '/' . $module . '_' . $lang_key;
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

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

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function fixturePath(string $module, string $lang_key, string $extension): string
    {
        return $this->fixture_directory . '/' . $module . '_' . $lang_key . '.' . $extension;
    }

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixturePath($module, $lang_key, 'po'));
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
     * PoMigrationLoadLanguageModuleTest::callLoadFromMigratedLanguageFile() invokes its sibling.
     *
     * @return array<string, string>|null
     */
    private function callLoadFromMigratedLanguageFile(string $module, string $lang_key): ?array
    {
        return (new ReflectionClass(ilLanguage::class))
            ->getMethod('loadFromMigratedLanguageFile')
            ->invoke(null, $module, $lang_key);
    }

    public function testRemovesTheMoFileButLeavesThePoFileUntouched(): void
    {
        $directory = $this->seedFixtureModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);
        $po_before = file_get_contents($this->fixturePath('utest', 'de', 'po'));

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->fixturePath('utest', 'de', 'mo'));
        $this->assertFileExists($this->fixturePath('utest', 'de', 'po'));
        $this->assertSame($po_before, file_get_contents($this->fixturePath('utest', 'de', 'po')));
    }

    public function testRemovesEachContributedModuleIndependently(): void
    {
        $first = $this->seedFixtureModule('uone', 'de', ['greeting' => 'Hallo']);
        $second = $this->seedFixtureModule('utwo', 'de', ['greeting' => 'Servus']);
        $this->registerDirectoryManager($first, $second);

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->fixturePath('uone', 'de', 'mo'));
        $this->assertFileDoesNotExist($this->fixturePath('utwo', 'de', 'mo'));
        $this->assertFileExists($this->fixturePath('uone', 'de', 'po'));
        $this->assertFileExists($this->fixturePath('utwo', 'de', 'po'));
    }

    public function testOnlyRemovesTheMoFileForTheRequestedLanguageNotOtherLanguages(): void
    {
        $directory = $this->seedFixtureModule('utest', 'de', ['greeting' => 'Hallo']);
        // Same module, different language - must survive uninstalling only 'de'.
        (new MoGenerator())->includeHeaders(true)->generateFile(
            Translations::create('utest', 'fr')->add(Translation::create('utest', 'greeting')->translate('Bonjour')),
            $this->fixturePath('utest', 'fr', 'mo')
        );
        $this->registerDirectoryManager($directory);

        $this->callRemoveMigratedMoFiles('de');

        $this->assertFileDoesNotExist($this->fixturePath('utest', 'de', 'mo'));
        $this->assertFileExists($this->fixturePath('utest', 'fr', 'mo'));
    }

    public function testIsANoOpWhenNoMoFileExistsForThatLanguage(): void
    {
        $directory = $this->seedFixtureModule('utest', 'de', ['greeting' => 'Hallo']);
        unlink($this->fixturePath('utest', 'de', 'mo'));

        // must not throw even though there is nothing left to remove
        $this->callRemoveMigratedMoFiles('de');

        $this->addToAssertionCount(1);
        $this->assertFileExists($this->fixturePath('utest', 'de', 'po'));
    }

    public function testIsANoOpForALanguageThatHasNeitherPoNorMoAtAll(): void
    {
        $directory = $this->seedFixtureModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // "fr" was never seeded at all -> must not throw, must not affect the "de" fixture
        $this->callRemoveMigratedMoFiles('fr');

        $this->assertFileExists($this->fixturePath('utest', 'de', 'mo'));
    }

    public function testReturnsImmediatelyWithoutErrorWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // must not throw even though nothing was contributed and no fixture file exists anywhere
        $this->callRemoveMigratedMoFiles('de');

        $this->addToAssertionCount(1);
    }

    public function testInvalidatesIlLanguagesCacheSoTheRemovalIsVisibleImmediately(): void
    {
        $directory = $this->seedFixtureModule('utest', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);

        // populates loadFromMigratedLanguageFile()'s static cache for 'utest'|'de'
        $before = $this->callLoadFromMigratedLanguageFile('utest', 'de');
        $this->assertSame(['greeting' => 'Hallo'], $before);

        $this->callRemoveMigratedMoFiles('de');

        // the .mo is gone - a stale cached hit from before the removal must not leak through
        $this->assertNull($this->callLoadFromMigratedLanguageFile('utest', 'de'));
    }

    /**
     * The catch(\Throwable) branch: a failure removing one module's .mo must be logged via
     * $DIC->logger()->forComponent('lang')->warning(...) and swallowed - not thrown - and must not
     * abort processing of other contributed modules. $DIC->logger() is a real \ILIAS\DI\LoggingServices
     * object (not an offsetGet-based service, see class.ilObjLanguage.php's other DIC calls), so it is
     * stubbed at the level it actually delegates to: the 'ilLoggerFactory' service - same technique as
     * ResetMigratedLocalChangesTest::testLogsAndSwallowsAReadFailureForOneModuleWithoutAbortingOthers().
     *
     * unlink() fails on a directory permission, not a file permission, so the "failing" module's own
     * subdirectory (not the whole fixture tree, which would also make the "working" module fail) is
     * made unwritable to force it - unlink() also emits a PHP warning before returning false, silenced
     * the same way testThrowsWhenThePoFileCannotBeWritten() does in MigratedLanguageFileSyncTest.
     */
    public function testLogsAndSwallowsARemovalFailureForOneModuleWithoutAbortingOthers(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unremovable file while running as root; skipping.');
        }

        $this->fixture_directory ??= __DIR__ . '/tmp-uninstall-fixtures-' . bin2hex(random_bytes(4));
        $readonly_dir = $this->fixture_directory . '/readonly';
        mkdir($readonly_dir, 0775, true);

        $translations = Translations::create('uone', 'de');
        $translations->add(Translation::create('uone', 'greeting')->translate('Hallo'));
        (new PoGenerator())->generateFile($translations, $readonly_dir . '/uone_de.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $readonly_dir . '/uone_de.mo');

        $relative_readonly_path = 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/readonly/';
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
        $working = $this->seedFixtureModule('utwo', 'de', ['greeting' => 'Servus']);
        $this->registerDirectoryManager($failing, $working);

        chmod($readonly_dir, 0555);

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
            chmod($readonly_dir, 0775);
        }

        // the failing module's .mo was never actually removed
        $this->assertFileExists($readonly_dir . '/uone_de.mo');

        // the other, unaffected module's .mo was still removed despite the first module's failure
        $this->assertFileDoesNotExist($this->fixturePath('utwo', 'de', 'mo'));
    }
}

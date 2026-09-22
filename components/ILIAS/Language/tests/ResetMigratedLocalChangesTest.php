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
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers ilObjLanguage::resetMigratedLocalChanges() - the .po/.mo counterpart to
 * removeLocalChanges()'s DB-side flush("all") + insertLanguageForRemovingLocalChanges() for every
 * module migrated to the PO/MO pilot (see components/ILIAS/Language/tools/po-migration/README.md).
 *
 * Without this method, "Lokale Änderungen entfernen" left a migrated module's .po/.mo files
 * completely untouched: the DB looked clean, but ilLanguage::txt() reads a migrated module's .mo
 * file, so end users kept seeing the stale, locally-changed value (confirmed live in the browser -
 * see the calling method's docblock in class.ilObjLanguage.php).
 *
 * Uses throwaway fixture modules (not tos's real files) so these tests never touch real pilot data.
 * Follows the same fixture pattern as PoMigrationWriteBackTest/PoMigrationLoadLanguageModuleTest.
 */
class ResetMigratedLocalChangesTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;
    private ?string $overlay_alias_symlink = null;

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
        if ($this->overlay_alias_symlink !== null && (is_link($this->overlay_alias_symlink) || file_exists($this->overlay_alias_symlink))) {
            unlink($this->overlay_alias_symlink);
        }
        $this->overlay_alias_symlink = null;

        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        parent::tearDown();
    }

    /**
     * ilObjLanguage::resetMigratedLocalChanges() resolves its .po/.mo location via its
     * $client_data_dir parameter now, exactly like ilLanguage::migratedOverlayMoFile() does for
     * reading (see class.ilObjLanguage.php and tools/po-migration/README.md, "Overlay:
     * Installations-eigene `.po`/`.mo`-Dateien") - never via ILIAS_ABSOLUTE_PATH any more.
     *
     * seedFixtureModule() below writes the fixture's .po/.mo pair into a bare temp directory
     * ($this->fixture_directory), not literally under a CLIENT_DATA_DIR tree - this creates a single
     * leaf symlink so that the CLIENT_DATA_DIR-rooted overlay path callResetMigratedLocalChanges()
     * passes resolves to that very same physical directory, without having to duplicate every fixture
     * file into a second location. This aliases only this fixture's own path - every other test in
     * this suite (which never defines CLIENT_DATA_DIR at all) is unaffected.
     */
    private function aliasOverlayToShippedFixture(string $module, string $lang_key): void
    {
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_lang_test_client_data_dir');
        }

        $relative_path = 'components/ILIAS/Language/tests/' . basename((string) $this->fixture_directory);
        $overlay_parent = rtrim(CLIENT_DATA_DIR, '/') . '/lang/' . dirname($relative_path);
        if (!is_dir($overlay_parent)) {
            mkdir($overlay_parent, 0775, true);
        }

        $overlay_leaf = $overlay_parent . '/' . basename($relative_path);
        if (!file_exists($overlay_leaf)) {
            symlink($this->fixture_directory, $overlay_leaf);
        }
        $this->overlay_alias_symlink = $overlay_leaf;
    }

    /**
     * Seeds a real .po/.mo pair for a throwaway module, exactly like the real conversion tool would,
     * and returns the LanguageFileDirectory that makes it discoverable the same way a real
     * ComponentLanguageFileDirectory contribution would.
     *
     * @param array<string, array{value: string, fuzzy?: bool, original?: string, local_change?: string}> $entries
     *   'original', when given, seeds the entry exactly as convert_module_to_po.php would (see
     *   LocalChangeComments) - omit it to simulate a key that never went through the conversion tool.
     *   'local_change', when given, is written verbatim (bypassing LocalChangeComments::refresh()'s
     *   own logic) so a fixture can start out already locally-changed without going through a real
     *   write first.
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-reset-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($module, $identifier)->translate($entry['value']);
            if ($entry['fuzzy'] ?? false) {
                $translation->getFlags()->add('fuzzy');
            }
            if (isset($entry['original'])) {
                LocalChangeComments::setOriginal($translation, $entry['original']);
            }
            if (isset($entry['local_change'])) {
                $translation->getComments()->add('local_change: ' . $entry['local_change']);
            }
            $translations->add($translation);
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

    private function loadFixtureMo(string $module, string $lang_key): Translations
    {
        return (new MoLoader())->loadFile($this->fixturePath($module, $lang_key, 'mo'));
    }

    /**
     * The method under test is private static - invoked the same way
     * PoMigrationLoadLanguageModuleTest::callLoadFromMigratedLanguageFile() invokes its sibling.
     * Aliases the fixture directory into the CLIENT_DATA_DIR-rooted overlay location first (see
     * aliasOverlayToShippedFixture()'s docblock) whenever a fixture was actually seeded - a no-op
     * fixture-less call (e.g. "no directory manager registered at all") has nothing to alias and
     * passes $client_data_dir purely so the method's new required parameter is satisfied.
     */
    private function callResetMigratedLocalChanges(string $lang_key): void
    {
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_lang_test_client_data_dir');
        }
        if ($this->fixture_directory !== null) {
            $this->aliasOverlayToShippedFixture('', '');
        }

        (new ReflectionClass(ilObjLanguage::class))
            ->getMethod('resetMigratedLocalChanges')
            ->invoke(null, $lang_key, CLIENT_DATA_DIR);
    }

    public function testResetsAnEntryWithLocalChangeAndOriginalBackToItsOriginalValueInPoAndMo(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($directory);

        $this->callResetMigratedLocalChanges('de');

        $po_translation = $this->loadFixturePo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertNotNull($po_translation);
        $this->assertSame('Hallo', $po_translation->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($po_translation));

        $mo_translation = $this->loadFixtureMo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertNotNull($mo_translation);
        $this->assertSame('Hallo', $mo_translation->getTranslation());
    }

    public function testLeavesAnEntryWithLocalChangeButNoOriginalCompletelyUntouched(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            // simulates a key added after migration - never had a shipped baseline
            'farewell' => ['value' => 'Tschüss, geändert', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($directory);

        $this->callResetMigratedLocalChanges('de');

        $translation = $this->loadFixturePo('rtest', 'de')->find('rtest', 'farewell');
        $this->assertNotNull($translation);
        $this->assertSame('Tschüss, geändert', $translation->getTranslation());
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
    }

    public function testLeavesAnEntryWithNoLocalChangeUntouchedAndDoesNotRewriteTheFileAtAll(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
        ]);
        $this->registerDirectoryManager($directory);

        $po_path = $this->fixturePath('rtest', 'de', 'po');
        $mo_path = $this->fixturePath('rtest', 'de', 'mo');
        $po_before_content = file_get_contents($po_path);
        $mo_before_content = file_get_contents($mo_path);
        // ensure a rewrite (if it wrongly happened) would produce a detectably different mtime
        touch($po_path, time() - 10);
        touch($mo_path, time() - 10);
        $po_before_mtime = filemtime($po_path);
        $mo_before_mtime = filemtime($mo_path);

        $this->callResetMigratedLocalChanges('de');

        $this->assertSame($po_before_content, file_get_contents($po_path));
        $this->assertSame($mo_before_content, file_get_contents($mo_path));
        $this->assertSame($po_before_mtime, filemtime($po_path));
        $this->assertSame($mo_before_mtime, filemtime($mo_path));
    }

    public function testOnlyResetsTheEntryThatNeedsItAndLeavesTheOtherEntryInTheSameModuleAlone(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
        ]);
        $this->registerDirectoryManager($directory);

        $this->callResetMigratedLocalChanges('de');

        $translations = $this->loadFixturePo('rtest', 'de');

        $greeting = $translations->find('rtest', 'greeting');
        $this->assertNotNull($greeting);
        $this->assertSame('Hallo', $greeting->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($greeting));

        $farewell = $translations->find('rtest', 'farewell');
        $this->assertNotNull($farewell);
        $this->assertSame('Tschüss', $farewell->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($farewell));
    }

    public function testResetsEachModuleIndependentlyWhenTwoDifferentModulesBothNeedIt(): void
    {
        $first = $this->seedFixtureModule('rone', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $second = $this->seedFixtureModule('rtwo', 'de', [
            'greeting' => ['value' => 'Servus, geändert', 'original' => 'Servus', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($first, $second);

        $this->callResetMigratedLocalChanges('de');

        $one = $this->loadFixturePo('rone', 'de')->find('rone', 'greeting');
        $this->assertSame('Hallo', $one->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($one));

        $two = $this->loadFixturePo('rtwo', 'de')->find('rtwo', 'greeting');
        $this->assertSame('Servus', $two->getTranslation());
        $this->assertNull(LocalChangeComments::getLocalChange($two));
    }

    public function testSkipsAContributedDirectoryThatHasNoMoOrPoPairForTheRequestedLanguageWithoutError(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($directory);

        // "fr" has no rtest_fr.po/.mo at all -> must not throw, must not affect the "de" fixture
        $this->callResetMigratedLocalChanges('fr');

        $translation = $this->loadFixturePo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertSame('Hallo, geändert', $translation->getTranslation());
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
    }

    public function testSkipsAContributedDirectoryMissingOnlyTheMoFileWithoutAffectingOtherModules(): void
    {
        $incomplete = $this->seedFixtureModule('rone', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        unlink($this->fixturePath('rone', 'de', 'mo'));

        $complete = $this->seedFixtureModule('rtwo', 'de', [
            'greeting' => ['value' => 'Servus, geändert', 'original' => 'Servus', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($incomplete, $complete);

        $this->callResetMigratedLocalChanges('de');

        // rone was left alone (no .mo -> gated out, same as syncMigratedLanguageFile()'s check)
        $this->assertFileDoesNotExist($this->fixturePath('rone', 'de', 'mo'));
        $one = $this->loadFixturePo('rone', 'de')->find('rone', 'greeting');
        $this->assertSame('Hallo, geändert', $one->getTranslation());

        // rtwo, which had both files, was still reset
        $two = $this->loadFixturePo('rtwo', 'de')->find('rtwo', 'greeting');
        $this->assertSame('Servus', $two->getTranslation());
    }

    /**
     * $client_data_dir === null (CLIENT_DATA_DIR unresolvable, see ilSetupLanguage::
     * resolveClientDataDir()) must be a pure no-op - never a fallback to writing the shipped/
     * ILIAS_ABSOLUTE_PATH-based location, which would reintroduce exactly the git-dirtying problem
     * the overlay split exists to avoid. Runs in its own process (see #[RunInSeparateProcess]) since
     * CLIENT_DATA_DIR, once defined by any other test in this class, cannot be undefined again.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testIsANoOpWhenClientDataDirCannotBeResolved(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($directory);

        (new ReflectionClass(ilObjLanguage::class))
            ->getMethod('resetMigratedLocalChanges')
            ->invoke(null, 'de', null);

        $translation = $this->loadFixturePo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertSame('Hallo, geändert', $translation->getTranslation());
    }

    public function testReturnsImmediatelyWithoutErrorWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // must not throw even though nothing was contributed and no fixture file exists anywhere
        $this->callResetMigratedLocalChanges('de');

        $this->addToAssertionCount(1);
    }

    public function testClearsTheFuzzyFlagOnAResetEntry(): void
    {
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => [
                'value' => 'Hallo, geändert',
                'original' => 'Hallo',
                'local_change' => '2020-01-01T00:00:00Z',
                'fuzzy' => true,
            ],
        ]);
        $this->registerDirectoryManager($directory);

        $translation_before = $this->loadFixturePo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertTrue($translation_before->getFlags()->has('fuzzy'));

        $this->callResetMigratedLocalChanges('de');

        $translation_after = $this->loadFixturePo('rtest', 'de')->find('rtest', 'greeting');
        $this->assertSame('Hallo', $translation_after->getTranslation());
        $this->assertFalse($translation_after->getFlags()->has('fuzzy'));
    }

    public function testInvalidatesIlLanguagesCacheSoTheResetValueIsVisibleImmediately(): void
    {
        // loadLanguageModule()'s migrated-file match path calls the pre-existing
        // ilLanguage::isUsageLogEnabled(), which unconditionally reads $DIC->database() - unrelated
        // to the reset logic itself (see PoMigrationLoadLanguageModuleTest::stubUsageLogDependencies()).
        $this->setGlobalVariable('ilDB', $this->createStub(ilDBInterface::class));
        $directory = $this->seedFixtureModule('rtest', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($directory);
        $this->aliasOverlayToShippedFixture('rtest', 'de');

        $before = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($before))->getProperty('lang_key')->setValue($before, 'de');
        $before->loadLanguageModule('rtest');
        $this->assertSame('Hallo, geändert', $before->txt('greeting'));

        $this->callResetMigratedLocalChanges('de');

        $after = (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($after))->getProperty('lang_key')->setValue($after, 'de');
        $after->loadLanguageModule('rtest');
        $this->assertSame('Hallo', $after->txt('greeting'));
    }

    /**
     * The catch(\Throwable) branch: a failure processing one module must be logged via
     * $DIC->logger()->forComponent('lang')->warning(...) and swallowed - not thrown - and must not
     * abort processing of other contributed modules. $DIC->logger() is a real \ILIAS\DI\LoggingServices
     * object (not an offsetGet-based service, see class.ilObjLanguage.php's other DIC calls), so it is
     * stubbed at the level it actually delegates to: the 'ilLoggerFactory' service.
     *
     * The failure is forced by making the .po file unreadable: PoLoader::loadFile() (the very first
     * call inside the try block) then throws (see Gettext\Loader\Loader::readFile()), rather than by
     * making the write fail - Gettext\Generator\Generator::generateFile() only returns false on a
     * failed file_put_contents() (a PHP warning, not a \Throwable), so a write-side permission failure
     * would never actually reach this catch block.
     */
    public function testLogsAndSwallowsAReadFailureForOneModuleWithoutAbortingOthers(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unreadable file while running as root; skipping.');
        }

        $failing = $this->seedFixtureModule('rone', 'de', [
            'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $working = $this->seedFixtureModule('rtwo', 'de', [
            'greeting' => ['value' => 'Servus, geändert', 'original' => 'Servus', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);
        $this->registerDirectoryManager($failing, $working);

        $po_path = $this->fixturePath('rone', 'de', 'po');
        chmod($po_path, 0000);

        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('rone'));
        $logger_factory = $this->createMock(ilLoggerFactory::class);
        $logger_factory->expects($this->once())->method('getComponentLogger')->with('lang')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        try {
            $this->callResetMigratedLocalChanges('de');
        } finally {
            chmod($po_path, 0664);
        }

        // the failing module's file was left as-is (never even successfully re-read)
        $one = $this->loadFixturePo('rone', 'de')->find('rone', 'greeting');
        $this->assertSame('Hallo, geändert', $one->getTranslation());

        // the other, unaffected module was still reset despite the first module's failure
        $two = $this->loadFixturePo('rtwo', 'de')->find('rtwo', 'greeting');
        $this->assertSame('Servus', $two->getTranslation());
    }
}

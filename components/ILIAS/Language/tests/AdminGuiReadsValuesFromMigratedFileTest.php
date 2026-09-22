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
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Translation;
use Gettext\Translations;

/**
 * Covers the admin-GUI read side of the PO/MO pilot:
 * ilObjLanguageExt::_getValues()/_getModules() - which back the "Sprachvariablen anpassen" table - now
 * read a migrated module's value from its `.po` file instead of `lng_data`, which is no longer
 * authoritative for it even though it is still dual-written. lng_data remains the sole source for
 * every module that isn't migrated - the vast majority.
 *
 * Also covers the new MigratedLanguageFileSync::loadModuleTranslations()/getMigratedModules() read
 * primitives directly.
 *
 * Uses throwaway fixture modules (not any real pilot data) so these tests never touch real files, and
 * a stubbed $ilDB - the actual SQL text built by _getValues()/_getModules() is never executed, only the
 * canned fetchRow()/query() results matter, exactly like PoLastLocalChangeTest.php.
 */
class AdminGuiReadsValuesFromMigratedFileTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../'));
        }
        // The read side (MigratedLanguageFileSync::loadModuleTranslations()/getMigratedModules(), and
        // ilObjLanguageExt's admin-GUI methods that call them) now resolves a migrated module's
        // OVERLAY location from CLIENT_DATA_DIR - never from ILIAS_ABSOLUTE_PATH. A PHP constant
        // cannot be redefined, so this is guarded exactly like ILIAS_ABSOLUTE_PATH above.
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_lang_test_client_data_dir');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory)) {
            $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
                . $this->fixture_directory;
            if (is_dir($overlay_dir)) {
                array_map('unlink', glob($overlay_dir . '/*') ?: []);
                rmdir($overlay_dir);
            }
        }

        parent::tearDown();
    }

    /**
     * Builds a real .po/.mo pair for a throwaway module, exactly like a real installation's first sync
     * would produce, directly at the OVERLAY location (under CLIENT_DATA_DIR) - not the shipped,
     * git-tracked location - since loadModuleTranslations()/getMigratedModules() (and therefore
     * ilObjLanguageExt's admin-GUI read methods) only ever read the overlay. Returns the
     * LanguageFileDirectory that makes it discoverable the same way a real ComponentLanguageFileDirectory
     * contribution would. Follows the same fixture pattern as PoLastLocalChangeTest.php.
     *
     * @param array<string, array{value: string, original?: string}> $entries keyed by identifier;
     *   'original', when given, seeds LocalChangeComments::setOriginal() so a later value change is
     *   detectable as a local change.
     * @param list<string> $changed_identifiers identifiers whose value differs from 'original' and must
     *   therefore carry a local_change comment - stamped via LocalChangeComments::refresh().
     */
    private function seedFixtureModule(
        string $module,
        string $lang_key,
        array $entries,
        array $changed_identifiers = []
    ): LanguageFileDirectory {
        $this->fixture_directory ??= 'tmp-admingui-values-fixtures-' . bin2hex(random_bytes(4));
        $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . $this->fixture_directory;
        if (!is_dir($overlay_dir)) {
            mkdir($overlay_dir, 0775, true);
        }

        $now = new DateTimeImmutable('2026-01-02T03:04:05Z', new DateTimeZone('UTC'));
        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($module, $identifier)->translate($entry['value']);
            if (isset($entry['original'])) {
                LocalChangeComments::setOriginal($translation, $entry['original']);
            }
            if (in_array($identifier, $changed_identifiers, true)) {
                $previous = $entry['original'] ?? '';
                LocalChangeComments::refresh(
                    $translation,
                    $previous === $entry['value'] ? $previous . ' (old)' : $previous,
                    $entry['value'],
                    $now
                );
            }
            $translations->add($translation);
        }

        $base_path = $overlay_dir . '/' . $module . '_' . $lang_key;
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

        $relative_path = 'components/ILIAS/Language/tests/' . $this->fixture_directory . '/';

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
     * The overlay path a fixture module seeded via seedFixtureModule() was actually written to (see
     * that method) - used to reach in and remove one of the two files to simulate an incomplete/
     * not-yet-migrated overlay.
     */
    private function overlayFile(string $module, string $lang_key, string $extension): string
    {
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . $this->fixture_directory . '/' . $module . '_' . $lang_key . '.' . $extension;
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    /**
     * @param list<array{module: string, identifier: string, value: string}> $db_rows every row
     *   lng_data would return for the query - _getValues()/_getModules() never see the actual SQL
     *   text built, only this canned result, exactly like PoLastLocalChangeTest::stubDatabase().
     */
    /**
     * query() returns a *fresh* statement (with its own row cursor reset to the start of $db_rows)
     * every time it is called, rather than one shared statement whose consecutive-call sequence would
     * otherwise be consumed across multiple _getValues()/_getModules() calls within the same test.
     */
    private function stubDatabase(array $db_rows): void
    {
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value, string $type = ''): string => "'" . (string) $value . "'"
        );
        $db->method('in')->willReturn("module IN ('placeholder')");
        $db->method('like')->willReturn("value LIKE '%placeholder%'");
        $db->method('query')->willReturnCallback(function () use ($db_rows): ilDBStatement {
            $statement = $this->createStub(ilDBStatement::class);
            $calls = array_map(static fn(array $row): array => $row, $db_rows);
            $calls[] = false; // terminates _getValues()'s/_getModules()'s while ($rec = ...) loop
            $statement->method('fetchRow')->willReturnOnConsecutiveCalls(...$calls);

            return $statement;
        });

        $this->setGlobalVariable('ilDB', $db);

        // _getValues() reads $lng->separator ("#:#", already set as ilLanguage's default property
        // value without running the constructor) to build its "module#:#topic" composite keys.
        $this->setGlobalVariable('lng', (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor());
    }

    // -----------------------------------------------------------------
    // MigratedLanguageFileSync::loadModuleTranslations()/getMigratedModules() - the new primitives
    // -----------------------------------------------------------------

    public function testLoadModuleTranslationsReturnsValueAndLocalChangeFlagPerIdentifier(): void
    {
        $directory = $this->seedFixtureModule(
            'mtest',
            'de',
            [
                'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo'],
                'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
            ],
            ['greeting']
        );
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $directory);

        $result = MigratedLanguageFileSync::loadModuleTranslations($manager, 'de', 'mtest', CLIENT_DATA_DIR);

        $this->assertSame(
            [
                'greeting' => ['value' => 'Hallo, geändert', 'local_change' => true],
                'farewell' => ['value' => 'Tschüss', 'local_change' => false],
            ],
            $result
        );
    }

    public function testLoadModuleTranslationsReturnsNullWhenTheModuleHasNoContributedDirectory(): void
    {
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory());

        $this->assertNull(
            MigratedLanguageFileSync::loadModuleTranslations($manager, 'de', 'mtest', CLIENT_DATA_DIR)
        );
    }

    public function testLoadModuleTranslationsReturnsNullWhenThereIsNoMoFileForThisLanguageYet(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->overlayFile('mtest', 'de', 'mo'));
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $directory);

        $this->assertNull(
            MigratedLanguageFileSync::loadModuleTranslations($manager, 'de', 'mtest', CLIENT_DATA_DIR)
        );
    }

    public function testGetMigratedModulesListsOnlyContributedModulesWithACompiledMoFile(): void
    {
        $with_mo = $this->seedFixtureModule('mone', 'de', ['greeting' => ['value' => 'Hallo']]);
        $without_mo = $this->seedFixtureModule('mtwo', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->overlayFile('mtwo', 'de', 'mo'));
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $with_mo, $without_mo);

        $this->assertSame(['mone'], MigratedLanguageFileSync::getMigratedModules($manager, 'de', CLIENT_DATA_DIR));
    }

    // -----------------------------------------------------------------
    // ilObjLanguageExt::_getValues() - the admin-GUI listing method
    // -----------------------------------------------------------------

    public function testValueForAMigratedModuleComesFromThePoFileNotFromTheStaleDbRow(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Aktuell']]);
        $this->registerDirectoryManager($directory);
        // lng_data would still return a stale value here (e.g. a direct .po edit bypassing the
        // dual-write, or simply proving the DB row is no longer consulted at all for this module).
        $this->stubDatabase([['module' => 'mtest', 'identifier' => 'greeting', 'value' => 'VERALTET']]);

        $values = ilObjLanguageExt::_getValues('de');

        $this->assertSame(['mtest#:#greeting' => 'Aktuell'], $values);
    }

    public function testValuesForANonMigratedModuleStillComeFromLngData(): void
    {
        // no directory contributed at all -> "common" is never migrated
        $this->stubDatabase([['module' => 'common', 'identifier' => 'hello', 'value' => 'Welt']]);

        $values = ilObjLanguageExt::_getValues('de');

        $this->assertSame(['common#:#hello' => 'Welt'], $values);
    }

    public function testMergesAMigratedModuleWithANonMigratedOne(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Aktuell']]);
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([['module' => 'common', 'identifier' => 'hello', 'value' => 'Welt']]);

        $values = ilObjLanguageExt::_getValues('de');

        $this->assertSame(
            ['common#:#hello' => 'Welt', 'mtest#:#greeting' => 'Aktuell'],
            $values
        );
    }

    public function testAppliesThePatternFilterToTheMigratedModulesValues(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', [
            'greeting' => ['value' => 'Hallo Welt'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([]);

        $values = ilObjLanguageExt::_getValues('de', [], [], 'Welt');

        $this->assertSame(['mtest#:#greeting' => 'Hallo Welt'], $values);
    }

    public function testAppliesTheTopicsFilterToTheMigratedModulesValues(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([]);

        $values = ilObjLanguageExt::_getValues('de', [], ['farewell']);

        $this->assertSame(['mtest#:#farewell' => 'Tschüss'], $values);
    }

    public function testAppliesTheChangedStateFilterToTheMigratedModulesValues(): void
    {
        $directory = $this->seedFixtureModule(
            'mtest',
            'de',
            [
                'greeting' => ['value' => 'Hallo, geändert', 'original' => 'Hallo'],
                'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
            ],
            ['greeting']
        );
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([]);

        $this->assertSame(
            ['mtest#:#greeting' => 'Hallo, geändert'],
            ilObjLanguageExt::_getValues('de', [], [], '', 'changed')
        );
        $this->assertSame(
            ['mtest#:#farewell' => 'Tschüss'],
            ilObjLanguageExt::_getValues('de', [], [], '', 'unchanged')
        );
    }

    public function testWhenSpecificModulesAreRequestedAMigratedOneAmongThemStillComesFromThePoFile(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Aktuell']]);
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([['module' => 'common', 'identifier' => 'hello', 'value' => 'Welt']]);

        $values = ilObjLanguageExt::_getValues('de', ['mtest', 'common']);

        $this->assertSame(
            ['common#:#hello' => 'Welt', 'mtest#:#greeting' => 'Aktuell'],
            $values
        );
    }

    public function testFallsBackToDbOnlyBehaviorWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        // must not error even though nothing was contributed - identical to the pre-existing behavior
        $this->stubDatabase([['module' => 'common', 'identifier' => 'hello', 'value' => 'Welt']]);

        $values = ilObjLanguageExt::_getValues('de');

        $this->assertSame(['common#:#hello' => 'Welt'], $values);
    }

    // -----------------------------------------------------------------
    // ilObjLanguageExt::_getModules()
    // -----------------------------------------------------------------

    public function testGetModulesIncludesAMigratedModuleEvenWithoutAnyLngDataRow(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->registerDirectoryManager($directory);
        // lng_data has no row for "mtest" at all here - only the .mo's existence must surface it.
        $this->stubDatabase([['module' => 'common']]);

        $modules = ilObjLanguageExt::_getModules('de');

        $this->assertSame(['common', 'mtest'], $modules);
    }

    public function testGetModulesFallsBackToDbOnlyBehaviorWhenNoDirectoryManagerIsRegisteredAtAll(): void
    {
        $this->stubDatabase([['module' => 'common']]);

        $this->assertSame(['common'], ilObjLanguageExt::_getModules('de'));
    }
}

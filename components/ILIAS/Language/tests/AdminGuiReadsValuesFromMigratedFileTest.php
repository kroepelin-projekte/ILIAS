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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\SkippedWithMessageException;

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
 *
 * Runs every test method in its own separate process: a full-suite run can have CLIENT_DATA_DIR
 * already defined by an earlier, unrelated test class sharing the same process (e.g.
 * Filesystem/tests/ilServicesFileSystemTest.php or Test/tests/ilTestBaseTestCaseTrait.php define it
 * as /var/iliasdata) - without this, MigratedPoFixture::ensureClientDataDirDefinedOrSkip()'s guard
 * would then skip every single test below for the rest of that process, since a PHP constant cannot
 * be redefined. A fresh process per test method guarantees CLIENT_DATA_DIR starts out undefined here,
 * exactly like a lone test run.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AdminGuiReadsValuesFromMigratedFileTest extends ilLanguageBaseTestCase
{
    private ?string $fixture_directory = null;
    /** @var list<array{0: string, 1: string}> column and value of every ilDBInterface::like() call */
    private array $like_calls = [];
    /** @var list<string> every query _getValues() sent */
    private array $sent_queries = [];

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
        //
        // Deliberately NOT resolved/defined here already: seedFixtureModule() below is the one place
        // that actually writes fixture files under CLIENT_DATA_DIR, so it (not setUp()) is where a
        // foreign, non-temp CLIENT_DATA_DIR from an earlier test in a full-suite run must skip rather
        // than write there (see MigratedPoFixture::ensureClientDataDirDefinedOrSkip()) - and where
        // testRefusesToWriteIntoAClientDataDirOutsideSysTempDir() below needs it to still be
        // undefined when its own test body runs.
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
            MigratedPoFixture::removeShippedDirectory('components/ILIAS/Language/tests/' . $this->fixture_directory);
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
        MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);
        $this->fixture_directory ??= 'tmp-admingui-values-fixtures-' . bin2hex(random_bytes(4));
        $overlay_dir = rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/'
            . $this->fixture_directory;
        if (!is_dir($overlay_dir)) {
            mkdir($overlay_dir, 0775, true);
        }

        $now = new DateTimeImmutable('2026-01-02T03:04:05Z', new DateTimeZone('UTC'));
        $translations = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        foreach ($entries as $identifier => $entry) {
            $translation = MigratedPoFixture::entry($module, $identifier, $entry['value']);
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
        MigratedPoFixture::writePo($base_path . '.po', $translations);
        MigratedPoFixture::writeMo($base_path . '.mo', $translations);

        $relative_path = 'components/ILIAS/Language/tests/' . $this->fixture_directory . '/';
        // only migrated (and therefore read from the overlay) while the shipped .po exists
        MigratedPoFixture::writeShippedPo($relative_path, $module, $lang_key, $translations);

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
        $db->method('like')->willReturnCallback(function (string $column, string $type, string $value = '?'): string {
            $this->like_calls[] = [$column, $value];
            return "value LIKE '%placeholder%'";
        });
        $db->method('query')->willReturnCallback(function (string $query) use ($db_rows): ilDBStatement {
            $this->sent_queries[] = $query;
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
                'greeting' => [
                    'value' => 'Hallo, geändert',
                    'local_change' => true,
                    'local_change_date' => '2026-01-02 03:04:05',
                    'original' => 'Hallo',
                ],
                'farewell' => [
                    'value' => 'Tschüss',
                    'local_change' => false,
                    'local_change_date' => null,
                    'original' => 'Tschüss',
                ],
            ],
            $result
        );
    }

    public function testLoadModuleTranslationsReturnsNullWhenTheModuleHasNoContributedDirectory(): void
    {
        // No fixture is written here, but CLIENT_DATA_DIR is still passed on to
        // loadModuleTranslations() below - it must be resolved (and test-owned) exactly like every
        // other test in this class, even though nothing ends up being read from it.
        MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);
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

    /**
     * The search in a migrated module behaves like `UPPER(value) LIKE UPPER('%pattern%')` on
     * lng_data: case-insensitive (also for non-ASCII), "%" = any sequence, "_" = exactly one
     * character, "\" escapes the next character, match anywhere in the value; every other regex
     * meta character is literal.
     *
     * @param list<string> $expected identifiers expected to match
     */
    #[DataProvider('likePatterns')]
    public function testThePatternFilterOfAMigratedModuleFollowsSqlLikeSemantics(string $pattern, array $expected): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', array_map(static fn(string $v): array => ['value' => $v], self::likeValues()));
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([]);

        $found = array_map(
            static fn(string $key): string => substr($key, strlen('mtest#:#')),
            array_keys(ilObjLanguageExt::_getValues('de', [], [], $pattern))
        );

        sort($found);
        sort($expected);
        $this->assertSame($expected, $found);
    }

    /**
     * @return array<string, string> identifier => value the LIKE patterns are matched against
     */
    private static function likeValues(): array
    {
        return [
            'plain' => 'Hallo Welt',
            'umlaut' => 'ÄRGER über Öl',
            'percent' => 'Rabatt 50% heute',
            'fifty' => 'Rabatt 50 heute',
            'underscore' => 'snake_case',
            'snakeXcase' => 'snakeXcase',
            'regex' => 'a.b*c (d) [e] $f ^g |h /i',
            'backslash' => 'C:\\temp',
            'multiline' => "erste Zeile\nzweite Zeile",
        ];
    }

    public static function likePatterns(): array
    {
        return [
            'case-insensitive ascii' => ['hallo WELT', ['plain']],
            'case-insensitive umlaut' => ['ärger ÜBER öl', ['umlaut']],
            'substring anywhere' => ['lo We', ['plain']],
            'percent is a wildcard' => ['Hallo%Welt', ['plain']],
            'percent wildcard across the value' => ['Rabatt%heute', ['fifty', 'percent']],
            'escaped percent is literal' => ['50\\%', ['percent']],
            'underscore is exactly one character' => ['snake_case', ['snakeXcase', 'underscore']],
            'underscore does not match zero characters' => ['Hallo_Welt', ['plain']],
            'escaped underscore is literal' => ['snake\\_case', ['underscore']],
            'two underscores need two characters' => ['Hallo__Welt', []],
            'two underscores match exactly two characters' => ['Hall__Welt', ['plain']],
            'regex meta characters are literal' => ['a.b*c (d) [e] $f ^g |h /i', ['regex']],
            'a dot is not a wildcard' => ['Hallo.Welt', []],
            'escaped backslash' => ['C:\\\\temp', ['backslash']],
            'wildcard spans a newline' => ['erste%zweite', ['multiline']],
            'consecutive percent signs match like one' => ['Hallo%%Welt', ['plain']],
            'only percent signs match everything' => ['%%', array_keys(self::likeValues())],
            'escaped percent followed by a wildcard' => ['50\\%%heute', ['percent']],
            'no match' => ['gibt es nicht', []],
        ];
    }

    /**
     * Consecutive "%" are collapsed into one "any sequence" - equivalent to a single "%", also
     * around other characters, at the start/end and for the empty value.
     */
    #[DataProvider('consecutivePercentPatterns')]
    public function testConsecutivePercentSignsMatchLikeASinglePercentSign(string $value, string $pattern, bool $expected): void
    {
        $matches = new ReflectionMethod(ilObjLanguageExt::class, 'matchesLikePattern');

        $this->assertSame($expected, $matches->invoke(null, $value, $pattern));
        if (!str_contains($pattern, '\\')) {
            $this->assertSame(
                $expected,
                $matches->invoke(null, $value, (string) preg_replace('/%+/', '%', $pattern)),
                'same result as with single percent signs'
            );
        }
    }

    public static function consecutivePercentPatterns(): array
    {
        return [
            '%% matches an empty value' => ['', '%%', true],
            '%% matches any value' => ['beliebig', '%%', true],
            'a%%%b: nothing in between' => ['ab', 'a%%%b', true],
            'a%%%b: something in between' => ['aXYZb', 'a%%%b', true],
            'a%%%b: order matters' => ['ba', 'a%%%b', false],
            'a%%%b: across a line break' => ["a\nb", 'a%%%b', true],
            '%%a%% anywhere' => ['xxaxx', '%%a%%', true],
            '%%a%% not contained' => ['xxbxx', '%%a%%', false],
            'escaped percent then wildcard' => ['50% Rabatt', '50\\%%Rabatt', true],
            'escaped percent is still required' => ['50 Rabatt', '50\\%%Rabatt', false],
            'underscore between percent runs' => ['ab', 'a%%_%%b', false],
            'underscore between percent runs, one char' => ['axb', 'a%%_%%b', true],
        ];
    }

    /**
     * The merged-in migrated modules are sorted like lng_data's ORDER BY module, identifier under
     * the *_unicode_ci collation: case-insensitive (a plain ksort() sorts every upper-case
     * identifier before every lower-case one), module first.
     */
    public function testValuesAreSortedCaseInsensitivelyByModuleAndIdentifier(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', [
            'beta' => ['value' => 'b'],
            'Alpha' => ['value' => 'A'],
            'gamma' => ['value' => 'g'],
            'Delta' => ['value' => 'D'],
        ]);
        $this->registerDirectoryManager($directory);
        // Already in ORDER BY module, identifier order, as the database returns them
        $this->stubDatabase([
            ['module' => 'aaa', 'identifier' => 'Zeta', 'value' => 'z'],
            ['module' => 'zzz', 'identifier' => 'eta', 'value' => 'e'],
        ]);

        $this->assertSame(
            ['aaa#:#Zeta', 'mtest#:#Alpha', 'mtest#:#beta', 'mtest#:#Delta', 'mtest#:#gamma', 'zzz#:#eta'],
            array_keys(ilObjLanguageExt::_getValues('de'))
        );
    }

    /**
     * Regression: the SQL branch checked the pattern for truthiness, so searching for "0" returned
     * every lng_data row. Both branches must filter for "0".
     */
    public function testTheSearchPatternZeroIsAppliedToLngDataAndToMigratedModules(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', [
            'with_zero' => ['value' => 'Version 10'],
            'without_zero' => ['value' => 'Version eins'],
        ]);
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([]);

        $values = ilObjLanguageExt::_getValues('de', [], [], '0');

        $this->assertSame(['mtest#:#with_zero' => 'Version 10'], $values);
        $this->assertSame([['value', '%0%']], $this->like_calls);
        $this->assertStringContainsString("value LIKE '%placeholder%'", $this->sent_queries[0]);
    }

    public function testAnEmptySearchPatternDoesNotFilter(): void
    {
        $this->stubDatabase([['module' => 'common', 'identifier' => 'hello', 'value' => 'Welt']]);

        $this->assertSame(['common#:#hello' => 'Welt'], ilObjLanguageExt::_getValues('de', [], [], ''));
        $this->assertSame([], $this->like_calls);
    }

    /**
     * An overlay that cannot be read must not break the admin GUI: warning, then the dual-written
     * lng_data rows are shown for that module.
     */
    public function testAnUnreadableOverlayFallsBackToLngDataWithAWarning(): void
    {
        $directory = $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Aus dem Overlay']]);
        file_put_contents($this->overlayFile('mtest', 'de', 'po'), "msgid \"kaputt\n");
        $this->registerDirectoryManager($directory);
        $this->stubDatabase([['module' => 'mtest', 'identifier' => 'greeting', 'value' => 'Aus lng_data']]);
        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('"mtest"'));
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        $this->assertSame(['mtest#:#greeting' => 'Aus lng_data'], ilObjLanguageExt::_getValues('de'));
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

    /**
     * A CLIENT_DATA_DIR that is not a test-owned temp directory (e.g. the real /var/iliasdata a
     * full-suite run may already have defined, see MigratedPoFixture::ensureClientDataDirDefinedOrSkip())
     * must never be written into by seedFixtureModule() - the test skips instead. Runs in its own
     * process so this class' own setUp() (which would otherwise define CLIENT_DATA_DIR first in the
     * shared process) cannot pre-empt the "not yet defined" starting point this test needs.
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
                $this->seedFixtureModule('mtest', 'de', ['greeting' => ['value' => 'Hallo']]);
                $this->fail('seedFixtureModule() must refuse to write into a CLIENT_DATA_DIR outside sys_get_temp_dir().');
            } catch (SkippedWithMessageException $e) {
                $this->assertStringContainsString($foreign_dir, $e->getMessage());
            }

            $this->assertSame([], array_diff(scandir($foreign_dir) ?: [], ['.', '..']));
        } finally {
            if (is_dir($foreign_dir)) {
                array_map('unlink', glob($foreign_dir . '/*') ?: []);
                rmdir($foreign_dir);
            }
        }
    }
}

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
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * ilObjLanguageExt: for a module migrated to PO/MO, the shipped `.po` is the only source of its
 * shipped values - also in the admin GUI's "clear" import (importLanguageFile(..., 'replace', true))
 * and in _saveValues()' local_change comparison against the global file values. Without the refresh
 * flag (an uploaded file) the file's own values are kept.
 *
 * Driven through the public methods with a recording database stub; the global language file
 * cache is seeded, the shipped `.po` lives in a throwaway directory below the ILIAS root.
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
class ImportUsesShippedValuesOfMigratedModulesTest extends ilLanguageBaseTestCase
{
    private const string LANG = 'zz';

    /**
     * Nullable (not a fixed default in the property declaration itself) so tearDown() can tell
     * "setUp() was skipped before ever reaching the assignment" (e.g. by
     * MigratedPoFixture::ensureClientDataDirDefinedOrSkip()) apart from "already assigned" -
     * accessing an uninitialized, non-nullable typed property would otherwise turn that skip into a
     * fatal `\Error` in tearDown() instead of a clean skip.
     */
    private ?string $fixture_directory = null;
    /**
     * True exactly when this test's own call to MigratedPoFixture::ensureClientDataDirDefinedOrSkip()
     * defined CLIENT_DATA_DIR itself (as opposed to a pre-existing, foreign definition it merely
     * reused) - see tearDown(). With every test running in its own process (see this class' own
     * #[RunTestsInSeparateProcesses]), this is true for every ordinary test run, so the freshly
     * generated, uniquely-named root this test created is always cleaned up again instead of
     * accumulating one leftover temp directory per test method.
     */
    private bool $created_client_data_dir_root = false;
    private string $upload_file;
    /** @var array<string, array<string, string>> module => lang_array written to lng_modules */
    private array $lng_modules = [];
    /** @var array<string, ?string> "module#:#identifier" => local_change written to lng_data */
    private array $local_changes = [];
    private ?string $last_lang_array = null;
    /** @var list<array{module: string, identifier: string, value: string}> lng_data rows _getValues() reads */
    private array $db_rows = [];
    /** @var list<string> every writing database call (manipulate/insert/replace), in call order */
    private array $writes = [];
    /** Whether the "SELECT lang_array FROM lng_modules" lookup of _deleteValues() answers with $lng_modules_row */
    private bool $answer_lng_modules_lookup = false;
    /** @var array{lang_array: mixed}|null the lng_modules row that lookup returns (null = no row) */
    private ?array $lng_modules_row = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'ILIAS_ABSOLUTE_PATH' => realpath(__DIR__ . '/../../../../'),
            'ILIAS_HTTP_PATH' => 'http://localhost',
            'ILIAS_VERSION' => 'test',
        ] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
        // importLanguageFile() writes a migrated module's overlay under CLIENT_DATA_DIR on every test
        // in this class - a foreign, non-temp CLIENT_DATA_DIR from an earlier test in a full-suite run
        // must therefore skip rather than write there (see
        // MigratedPoFixture::ensureClientDataDirDefinedOrSkip()).
        $this->created_client_data_dir_root = MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);

        (new ReflectionClass(ilCachedLanguage::class))->getProperty('instances')->setValue(null, []);
        MigratedPoFixture::resetRuntime();

        $this->fixture_directory = __DIR__ . '/tmp-import-fixtures-' . bin2hex(random_bytes(4));
        MigratedPoFixture::writePo(
            $this->fixture_directory . '/itest_' . self::LANG . '.po',
            MigratedPoFixture::catalog('itest', ['greeting' => 'Aus der PO', 'farewell' => 'Tschüss aus der PO'])
        );
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                MigratedPoFixture::directory('itest', 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/')
            )
        );
        $this->setGlobalVariable('lng', (new ReflectionClass(ilLanguage::class))->newInstanceWithoutConstructor());
        $this->setGlobalVariable('ilErr', $this->createStub(ilErrorHandling::class));
        $this->setGlobalVariable('ilDB', $this->database());

        $this->upload_file = $this->fixture_directory . '/ilias_' . self::LANG . '.lang';
        file_put_contents(
            $this->upload_file,
            "/* header */\n<!-- language file start -->\n"
            . "itest#:#greeting#:#Aus der lang-Datei\n"
            . "common#:#yes#:#Ja\n"
        );
    }

    protected function tearDown(): void
    {
        // $fixture_directory stays null when setUp() was skipped before ever reaching its assignment
        // (see the property's own docblock) - nothing was written anywhere in that case.
        if ($this->fixture_directory !== null) {
            MigratedPoFixture::removeDirectory($this->fixture_directory);
            MigratedPoFixture::removeDirectory(rtrim(CLIENT_DATA_DIR, '/') . '/lang');
        }
        // Only this test's own, freshly generated CLIENT_DATA_DIR root is removed here - never a
        // pre-existing, foreign one (see ensureClientDataDirDefinedOrSkip()'s own docblock and
        // $created_client_data_dir_root's).
        if ($this->created_client_data_dir_root && defined('CLIENT_DATA_DIR') && is_dir(CLIENT_DATA_DIR)) {
            MigratedPoFixture::removeDirectory(CLIENT_DATA_DIR);
        }
        (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects')->setValue(null, []);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $values "module#:#identifier" => value of lang/ilias_zz.lang
     */
    private function seedGlobalLanguageFile(array $values): void
    {
        $global = (new ReflectionClass(ilLanguageFile::class))->newInstanceWithoutConstructor();
        $global->setAllValues($values);
        $global->setAllComments([]);
        (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects')->setValue(null, [self::LANG => $global]);
    }

    private function database(): ilDBInterface
    {
        $self_check = $this->createStub(ilDBStatement::class);

        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn($value): string => "'" . (string) $value . "'");
        $db->method('in')->willReturn('1 = 1');
        $lng_modules_lookup = $this->createStub(ilDBStatement::class);
        $db->method('query')->willReturnCallback(function (string $sql) use ($lng_modules_lookup): ilDBStatement {
            if ($this->answer_lng_modules_lookup && str_starts_with($sql, /** @lang text */ 'SELECT lang_array FROM lng_modules')) {
                return $lng_modules_lookup;
            }
            $rows = str_starts_with($sql, 'SELECT module, identifier, value FROM lng_data') ? $this->db_rows : [];
            $statement = $this->createStub(ilDBStatement::class);
            $statement->method('fetchRow')->willReturnCallback(static function () use (&$rows): ?array {
                return array_shift($rows);
            });
            return $statement;
        });
        $db->method('queryF')->willReturn($self_check);
        $db->method('manipulate')->willReturnCallback(function (string $sql): int {
            $this->writes[] = $sql;
            return 1;
        });
        $db->method('fetchAssoc')->willReturnCallback(
            fn(ilDBStatement $statement): ?array => match ($statement) {
                $self_check => ['lang_array' => $this->last_lang_array],
                $lng_modules_lookup => $this->lng_modules_row,
                default => null,
            }
        );
        $db->method('insert')->willReturnCallback(function (string $table, array $values): int {
            $this->writes[] = 'insert ' . $table;
            if ($table === 'lng_modules') {
                $this->last_lang_array = $values['lang_array'][1];
                $this->lng_modules[$values['module'][1]] = unserialize($values['lang_array'][1]);
            }
            return 1;
        });
        $db->method('replace')->willReturnCallback(function (string $table, array $keys, array $values): int {
            $this->writes[] = 'replace ' . $table;
            $this->local_changes[$keys['module'][1] . '#:#' . $keys['identifier'][1]] = $values['local_change'][1];
            return 1;
        });

        return $db;
    }

    private function languageObject(): ilObjLanguageExt
    {
        $object = (new ReflectionClass(ilObjLanguageExt::class))->newInstanceWithoutConstructor();
        $object->key = self::LANG;
        // Set by the (skipped) constructor from $lng->separator
        $object->separator = '#:#';

        return $object;
    }

    public function testAnImportWithoutRefreshKeepsTheValuesOfTheFile(): void
    {
        $this->seedGlobalLanguageFile([]);

        $this->languageObject()->importLanguageFile($this->upload_file, 'replace');

        // Adapted to the delta overlay: the import merges onto the module as it is served - its
        // shipped state - so the not imported "farewell" keeps its shipped value
        $this->assertSame(['greeting' => 'Aus der lang-Datei', 'farewell' => 'Tschüss aus der PO'], $this->lng_modules['itest']);
    }

    /**
     * _saveValues() compares against the global file values to decide local_change - for a migrated
     * module those are the shipped `.po` values, not stale lines in lang/ilias_xx.lang.
     */
    public function testSaveValuesComparesAMigratedModuleAgainstTheShippedPoNotTheLangFile(): void
    {
        $this->seedGlobalLanguageFile([
            'itest#:#greeting' => 'Veraltet in lang/',
            'common#:#yes' => 'Ja',
        ]);
        // the stored values differ from what is saved, so the global file value decides
        $this->db_rows = [
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Alt in der DB'],
            ['module' => 'common', 'identifier' => 'yes', 'value' => 'Alt in der DB'],
        ];
        // Adapted to the delta overlay: the stored value of a migrated module is the served one
        // (shipped .po plus overlay), not its lng_data row - so it differs only with an overlay
        MigratedPoFixture::writePair(
            $this->overlayDirectory() . '/itest_' . self::LANG,
            MigratedPoFixture::catalog('itest', ['greeting' => 'Alt im Overlay'])
        );

        ilObjLanguageExt::_saveValues(self::LANG, [
            'itest#:#greeting' => 'Aus der PO',
            'common#:#yes' => 'Ja',
        ]);

        $this->assertNull($this->local_changes['itest#:#greeting'], 'equals the shipped .po value: not a local change');
        $this->assertNull($this->local_changes['common#:#yes'], 'non-migrated: compared against lang/ as before');
    }

    public function testSaveValuesMarksAValueThatOnlyEqualsTheStaleLangLineAsLocalChange(): void
    {
        $this->seedGlobalLanguageFile(['itest#:#greeting' => 'Veraltet in lang/']);
        $this->db_rows = [['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Alt in der DB']];

        ilObjLanguageExt::_saveValues(self::LANG, ['itest#:#greeting' => 'Veraltet in lang/']);

        $this->assertNotNull($this->local_changes['itest#:#greeting']);
    }

    public static function importModes(): array
    {
        return [
            'keepall' => ['keepall'],
            'keepnew' => ['keepnew'],
            'replace' => ['replace'],
            'delete' => ['delete'],
        ];
    }

    /**
     * Without the refresh flag (an uploaded/customizing file) the shipped .po is not needed - an
     * unreadable one does not block the import.
     */
    public function testAnImportWithoutRefreshIsNotBlockedByAnUnreadableShippedPo(): void
    {
        $this->seedGlobalLanguageFile([]);
        file_put_contents($this->fixture_directory . '/itest_' . self::LANG . '.po', "msgid \"kaputt\n");
        $this->stubLogger();

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->languageObject()->importLanguageFile($this->upload_file, 'replace');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['greeting' => 'Aus der lang-Datei'], $this->lng_modules['itest']);
    }

    // ---------------------------------------------- overlay that cannot be written

    private function overlayDirectory(): string
    {
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/itest/' . self::LANG;
    }

    /**
     * Root-proof: a regular file occupies the place of the overlay directory, so no user can create
     * it. Removed again by the returned closure.
     */
    private function blockOverlayDirectory(): \Closure
    {
        $blocked = $this->overlayDirectory();
        if (!is_dir(dirname($blocked))) {
            mkdir(dirname($blocked), 0775, true);
        }
        file_put_contents($blocked, 'not a directory');

        return static function () use ($blocked): void {
            if (is_file($blocked)) {
                unlink($blocked);
            }
        };
    }

    private function stubLogger(): ilLogger&\PHPUnit\Framework\MockObject\Stub
    {
        $logger = $this->createStub(ilLogger::class);
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        return $logger;
    }

    /**
     * The database is written regardless (dual-write stays the source of truth), but the module
     * whose overlay could not be written is returned - by the import and by _saveValues().
     */
    public function testAnImportReturnsTheMigratedModuleWhoseOverlayCouldNotBeWritten(): void
    {
        $this->seedGlobalLanguageFile([]);
        $this->stubLogger();
        $unblock = $this->blockOverlayDirectory();

        // Adapted to the delta overlay: an import that keeps the file's values (no refresh) - the
        // clear import takes the shipped values, which need no overlay and cannot fail to be written
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = $this->languageObject()->importLanguageFile($this->upload_file, 'replace');
        } finally {
            restore_error_handler();
            $unblock();
        }

        $this->assertSame(['itest'], $unwritten);
        $this->assertSame(['yes' => 'Ja'], $this->lng_modules['common'], 'the database is written regardless');
        $this->assertSame(
            ['farewell' => 'Tschüss aus der PO', 'greeting' => 'Aus der lang-Datei'],
            $this->sorted($this->lng_modules['itest'])
        );
    }

    public function testSaveValuesReturnsOnlyTheMigratedModuleWhoseOverlayCouldNotBeWrittenAndLogsIt(): void
    {
        $this->seedGlobalLanguageFile(['common#:#yes' => 'Ja']);
        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('"itest"'));
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);
        $unblock = $this->blockOverlayDirectory();

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = ilObjLanguageExt::_saveValues(self::LANG, [
                'itest#:#greeting' => 'Servus',
                'common#:#yes' => 'Jawohl',
            ]);
        } finally {
            restore_error_handler();
            $unblock();
        }

        $this->assertSame(['itest'], $unwritten);
        // Adapted to the delta overlay: merged onto the served module (its shipped state)
        $this->assertSame(
            ['greeting' => 'Servus', 'farewell' => 'Tschüss aus der PO'],
            $this->lng_modules['itest'],
            'the database is written regardless'
        );
        $this->assertSame(['yes' => 'Jawohl'], $this->lng_modules['common']);
    }

    public function testSaveValuesReturnsNothingWhenEveryOverlayWasWritten(): void
    {
        $this->seedGlobalLanguageFile([]);

        $this->assertSame([], ilObjLanguageExt::_saveValues(self::LANG, ['itest#:#greeting' => 'Servus', 'common#:#yes' => 'Ja']));
        $this->assertFileExists(MigratedPoFixture::overlayMo($this->overlayDirectory() . '/itest_' . self::LANG));
    }

    /**
     * replaceLangModule() - the primitive every admin write goes through - reports the overlay
     * failure as `false` (database written), and `true` for a module that is not maintained in PO
     * files, even while the overlay location is blocked.
     */
    public function testReplaceLangModuleReturnsFalseOnlyForAMigratedModuleWhoseOverlayCouldNotBeWritten(): void
    {
        $this->stubLogger();
        $unblock = $this->blockOverlayDirectory();

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $migrated = ilObjLanguage::replaceLangModule(self::LANG, 'itest', ['greeting' => 'Servus']);
            $not_migrated = ilObjLanguage::replaceLangModule(self::LANG, 'common', ['yes' => 'Ja']);
        } finally {
            restore_error_handler();
            $unblock();
        }

        $this->assertFalse($migrated);
        $this->assertTrue($not_migrated);
        $this->assertSame(['greeting' => 'Servus'], $this->lng_modules['itest'], 'the database is written regardless');

        $this->assertTrue(ilObjLanguage::replaceLangModule(self::LANG, 'itest', ['greeting' => 'Servus']), 'writable again');
    }

    /**
     * The GUI's "clear" asks getUnreadableShippedPoModules() after the ilLanguageException: the
     * import already read the shipped .po files through the same per-object cache, so the answer is
     * available without parsing - and logging - a second time.
     */
    // ------------------------------------------------------------ _deleteValues()

    /**
     * @param list<string> $warnings collects every PHP warning/notice raised during $action
     */
    private function collectingWarnings(array &$warnings, \Closure $action): mixed
    {
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            return $action();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Without an lng_modules row there is nothing left to keep: the module is rewritten empty - the
     * entries to delete must not be written back as its content, and no warning is raised.
     */
    public function testDeleteValuesWithoutLngModulesRowWritesAnEmptyModuleWithoutWarning(): void
    {
        $this->answer_lng_modules_lookup = true;
        $this->lng_modules_row = null;
        $warnings = [];

        $unwritten = $this->collectingWarnings(
            $warnings,
            static fn(): array => ilObjLanguageExt::_deleteValues(self::LANG, ['common#:#yes' => 'Ja', 'common#:#no' => 'Nein'])
        );

        $this->assertSame([], $this->lng_modules['common']);
        $this->assertSame([], $unwritten);
        $this->assertSame([], $warnings);
    }

    /**
     * An unreadable row (NULL or corrupt lang_array) is treated like a missing one.
     */
    public function testDeleteValuesWithAnUnreadableLngModulesRowWritesAnEmptyModule(): void
    {
        $this->answer_lng_modules_lookup = true;
        $this->lng_modules_row = ['lang_array' => null];
        $warnings = [];

        $this->collectingWarnings(
            $warnings,
            static fn(): array => ilObjLanguageExt::_deleteValues(self::LANG, ['common#:#yes' => 'Ja'])
        );

        $this->assertSame([], $this->lng_modules['common']);
        $this->assertSame([], $warnings);
    }

    public function testDeleteValuesKeepsTheRemainingEntriesOfTheLngModulesRow(): void
    {
        $this->answer_lng_modules_lookup = true;
        $this->lng_modules_row = ['lang_array' => serialize(['yes' => 'Ja', 'no' => 'Nein', 'maybe' => 'Vielleicht'])];

        $unwritten = ilObjLanguageExt::_deleteValues(self::LANG, ['common#:#yes' => 'Ja', 'common#:#unknown' => 'x']);

        $this->assertSame(['no' => 'Nein', 'maybe' => 'Vielleicht'], $this->lng_modules['common']);
        $this->assertSame([], $unwritten);
    }

    /**
     * The remaining entries of a migrated module go through replaceLangModule() as well - a module
     * whose overlay cannot be written is returned (the database is written regardless).
     */
    public function testDeleteValuesReturnsTheMigratedModuleWhoseOverlayCouldNotBeWritten(): void
    {
        $this->stubLogger();
        $this->answer_lng_modules_lookup = true;
        $this->lng_modules_row = ['lang_array' => serialize(['greeting' => 'Hallo', 'farewell' => 'Tschüss'])];
        $unblock = $this->blockOverlayDirectory();

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = ilObjLanguageExt::_deleteValues(self::LANG, [
                'itest#:#farewell' => 'Tschüss',
                'common#:#yes' => 'Ja',
            ]);
        } finally {
            restore_error_handler();
            $unblock();
        }

        // Adapted to the delta overlay: the remaining entries are read from the served module (its
        // shipped state - the lng_modules row is only the fallback for an unreadable one), so only
        // shipped values remain: there is no overlay to write, and nothing can fail
        $this->assertSame([], $unwritten);
        $this->assertSame(['greeting' => 'Aus der PO'], $this->lng_modules['itest'], 'the database is written regardless');
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function sorted(array $values): array
    {
        ksort($values);
        return $values;
    }

    // -------------------------------------------------------- invalid markup

    /**
     * Writes a file containing one plain, valid entry ("common#:#yes") and one entry with markup
     * TranslationMarkupPolicy does not allow, both new keys (no current DB row, no shipped value) - so
     * both count as "changed" and the invalid one is genuinely checked.
     */
    private function writeUploadFileWithOneInvalidEntry(): string
    {
        $file = $this->fixture_directory . '/ilias_invalid_' . self::LANG . '.lang';
        file_put_contents(
            $file,
            "/* header */\n<!-- language file start -->\n"
            . "common#:#yes#:#Ja\n"
            . "common#:#bad#:#<script>alert(1)</script>\n"
        );

        return $file;
    }

    /**
     * "delete" mode wipes lng_data/lng_modules for the WHOLE language up front (see
     * importLanguageFile()) - independently of what the file contains. The markup check must run
     * BEFORE that wipe: a value that is rejected must leave the database completely untouched, not
     * merely "not overwritten".
     */
    #[DataProvider('importModes')]
    public function testInvalidMarkupAbortsBeforeAnyChangeIncludingTheDeleteModeWipe(string $mode): void
    {
        $this->seedGlobalLanguageFile([]);
        $file = $this->writeUploadFileWithOneInvalidEntry();

        try {
            $this->languageObject()->importLanguageFile($file, $mode);
            $this->fail('Expected an ilLanguageInvalidMarkupException');
        } catch (ilLanguageInvalidMarkupException $e) {
            $this->assertSame(['common#:#bad'], array_keys($e->getInvalidValues()));
            // the message names only the key, never the value
            $this->assertStringNotContainsString('alert(1)', $e->getMessage());
            $this->assertStringNotContainsString('script', $e->getMessage());
        }

        $this->assertSame([], $this->writes, 'no database write at all - not even the "delete" mode wipe');
        $this->assertSame([], $this->lng_modules);
    }

    /**
     * $skipInvalidMarkup = true (the customizing-file/installation write path): the invalid entry is
     * left out, every other entry is still imported, and the skipped one is reported via
     * getSkippedInvalidMarkupValues() - no exception at all.
     */
    public function testSkipInvalidMarkupImportsOnlyTheValidEntriesAndReportsTheSkippedOne(): void
    {
        $this->seedGlobalLanguageFile([]);
        $file = $this->writeUploadFileWithOneInvalidEntry();

        $object = $this->languageObject();
        $object->importLanguageFile($file, 'replace', true);

        $this->assertSame('Ja', $this->lng_modules['common']['yes']);
        $this->assertArrayNotHasKey('bad', $this->lng_modules['common']);
        $skipped = $object->getSkippedInvalidMarkupValues();
        $this->assertArrayHasKey('common#:#bad', $skipped);
        $this->assertNotEmpty($skipped['common#:#bad']);
    }

    /**
     * Konzept-Schritt-4 follow-up: in "keepall"/"keepnew" mode, a key the mode decides to KEEP (its
     * current database value survives, the uploaded file's value for it is discarded entirely, see
     * importLanguageFile()'s `$to_keep` handling) must never block the import merely because the
     * file happens to also carry a disallowed value for that same key - it is never actually applied.
     */
    public function testAnInvalidFileValueForAKeyThatModeKeepsDoesNotBlockTheImport(): void
    {
        $this->seedGlobalLanguageFile(['common#:#yes' => 'Ja']);
        // "yes" already exists in the database - keepall/keepnew therefore KEEP it, discarding
        // whatever the uploaded file says for that key
        $this->db_rows = [['module' => 'common', 'identifier' => 'yes', 'value' => 'Aus der Datenbank']];
        $file = $this->fixture_directory . '/ilias_kept_' . self::LANG . '.lang';
        file_put_contents(
            $file,
            "/* header */\n<!-- language file start -->\n"
            . "common#:#yes#:#<script>alert(1)</script>\n"
        );

        // must not throw - the file's disallowed value for "yes" is entirely discarded ($to_keep
        // wins), never actually applied, so it must never even be checked
        $this->languageObject()->importLanguageFile($file, 'keepall');

        // "yes" is the module's only key and it is kept as-is - nothing needs to be (and nothing is)
        // rewritten into lng_modules for it at all, so the database still holds its original value
        $this->assertArrayNotHasKey('common', $this->lng_modules, 'the already-correct, kept value needs no rewrite');
    }

    // ---------------------------------------------- "clear" removes remarks of migrated modules

    private function directoryManager(): LanguageFileDirectoryManager
    {
        return new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            MigratedPoFixture::directory('itest', 'components/ILIAS/Language/tests/' . basename((string) $this->fixture_directory) . '/')
        );
    }

    /**
     * @return array<string, string>
     */
    private function loadRemarks(): array
    {
        return \ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync::loadRemarks(
            $this->directoryManager(),
            self::LANG,
            'itest',
            CLIENT_DATA_DIR,
            ILIAS_ABSOLUTE_PATH
        ) ?? [];
    }

    /**
     * A migrated module's "###" line comment is taken over as a remark exactly as for any other
     * module - importLanguageFile() no longer has a "clear"/refresh branch that would strip it (that
     * reset is now RemoveLocalLanguageChanges/LanguageInstallationManager::
     * insertLanguageForRemovingLocalChanges(), see LanguageInstallationManagerMigratedModulesTest).
     */
    public function testImportTakesOverALangLineCommentOfAMigratedModuleAsARemark(): void
    {
        $this->seedGlobalLanguageFile([]);
        file_put_contents(
            $this->upload_file,
            "/* header */\n<!-- language file start -->\n"
            . "itest#:#greeting#:#Aus der lang-Datei###Bitte pruefen\n"
            . "common#:#yes#:#Ja\n"
        );

        $this->languageObject()->importLanguageFile($this->upload_file, 'replace', false);

        $this->assertSame('Bitte pruefen', $this->loadRemarks()['greeting'] ?? null);
    }
}

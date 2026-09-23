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

/**
 * ilObjLanguageExt: for a module migrated to PO/MO, the shipped `.po` is the only source of its
 * shipped values - also in the admin GUI's "clear" import (importLanguageFile(..., 'replace', true))
 * and in _saveValues()' local_change comparison against the global file values. Without the refresh
 * flag (an uploaded file) the file's own values are kept.
 *
 * Driven through the public methods with a recording database stub; the global language file
 * cache is seeded, the shipped `.po` lives in a throwaway directory below the ILIAS root.
 */
class ImportUsesShippedValuesOfMigratedModulesTest extends ilLanguageBaseTestCase
{
    private const string LANG = 'zz';

    private string $fixture_directory;
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
        MigratedPoFixture::ensureClientDataDirDefinedOrSkip($this);

        (new ReflectionClass(ilCachedLanguage::class))->getProperty('instances')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);

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
        MigratedPoFixture::removeDirectory($this->fixture_directory);
        MigratedPoFixture::removeDirectory(
            rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/' . basename($this->fixture_directory)
        );
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

    public function testTheClearImportTakesTheValuesOfAMigratedModuleFromTheShippedPo(): void
    {
        $this->seedGlobalLanguageFile([]);

        $this->languageObject()->importLanguageFile($this->upload_file, 'replace', true);

        $this->assertSame(
            ['farewell' => 'Tschüss aus der PO', 'greeting' => 'Aus der PO'],
            $this->sorted($this->lng_modules['itest'])
        );
        $this->assertSame(['yes' => 'Ja'], $this->lng_modules['common'], 'non-migrated modules keep the file value');
    }

    public function testAnImportWithoutRefreshKeepsTheValuesOfTheFile(): void
    {
        $this->seedGlobalLanguageFile([]);

        $this->languageObject()->importLanguageFile($this->upload_file, 'replace');

        $this->assertSame(['greeting' => 'Aus der lang-Datei'], $this->lng_modules['itest']);
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

    // ------------------------------------------------ "clear" with an unreadable shipped .po

    /**
     * "clear" (refresh from shipped) must not silently reset a migrated module to its outdated .lang
     * lines: with an unreadable shipped .po nothing at all is changed - not even the up-front wipe of
     * the "delete" mode - and an ilLanguageException is thrown.
     */
    #[DataProvider('importModes')]
    public function testTheClearImportAbortsBeforeAnyChangeWhenAShippedPoIsUnreadable(string $mode): void
    {
        $this->seedGlobalLanguageFile([]);
        file_put_contents($this->fixture_directory . '/itest_' . self::LANG . '.po', "msgid \"kaputt\n");
        $this->stubLogger();

        try {
            $this->languageObject()->importLanguageFile($this->upload_file, $mode, true);
            $this->fail('Expected an ilLanguageException');
        } catch (ilLanguageException $e) {
            $this->assertStringContainsString('itest', $e->getMessage());
        }

        $this->assertSame([], $this->writes, 'no database write at all');
        $this->assertSame([], $this->lng_modules);
        $this->assertDirectoryDoesNotExist($this->overlayDirectory(), 'no overlay written either');
    }

    public static function importModes(): array
    {
        return ['replace' => ['replace'], 'delete' => ['delete']];
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
        return rtrim(CLIENT_DATA_DIR, '/') . '/lang/components/ILIAS/Language/tests/' . basename($this->fixture_directory);
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

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $unwritten = $this->languageObject()->importLanguageFile($this->upload_file, 'replace', true);
        } finally {
            restore_error_handler();
            $unblock();
        }

        $this->assertSame(['itest'], $unwritten);
        $this->assertSame(['yes' => 'Ja'], $this->lng_modules['common'], 'the database is written regardless');
        $this->assertSame(
            ['farewell' => 'Tschüss aus der PO', 'greeting' => 'Aus der PO'],
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
        $this->assertSame(['greeting' => 'Servus'], $this->lng_modules['itest'], 'the database is written regardless');
        $this->assertSame(['yes' => 'Jawohl'], $this->lng_modules['common']);
    }

    public function testSaveValuesAndTheImportReturnNothingWhenEveryOverlayWasWritten(): void
    {
        $this->seedGlobalLanguageFile([]);

        $this->assertSame([], ilObjLanguageExt::_saveValues(self::LANG, ['itest#:#greeting' => 'Servus', 'common#:#yes' => 'Ja']));
        $this->assertSame([], $this->languageObject()->importLanguageFile($this->upload_file, 'replace', true));
        $this->assertFileExists($this->overlayDirectory() . '/itest_' . self::LANG . '.mo');
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
    public function testAfterAnAbortedClearTheUnreadableModulesAreKnownAndLoggedOnlyOnce(): void
    {
        $this->seedGlobalLanguageFile([]);
        file_put_contents($this->fixture_directory . '/itest_' . self::LANG . '.po', "msgid \"kaputt\n");
        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('"itest"'));
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);
        $object = $this->languageObject();

        try {
            $object->importLanguageFile($this->upload_file, 'replace', true);
            $this->fail('Expected an ilLanguageException');
        } catch (ilLanguageException) {
        }

        $this->assertSame(['itest'], $object->getUnreadableShippedPoModules());
        $this->assertSame(['itest'], $object->getUnreadableShippedPoModules());
    }

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

        $this->assertSame(['itest'], $unwritten);
        $this->assertSame(['greeting' => 'Hallo'], $this->lng_modules['itest'], 'the database is written regardless');
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
}

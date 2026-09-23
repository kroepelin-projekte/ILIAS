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

/**
 * The admin GUI's readers of the shipped values (ilObjLanguageExt::getShippedValues()/
 * getShippedComments() and everything built on them: getAddedValues(), getCommentedValues(),
 * getMergedValues(), getMergedRemarks()) and the developer mode's "merge" into the global language
 * file (mergeLocalChangesIntoGlobalLanguageFile()): for a module maintained in PO files, its lines in
 * lang/ilias_xx.lang are not its source - the shipped .po is. Non-migrated modules are unchanged.
 *
 * The global language file is a real file in a throwaway directory (seeded into
 * ilLanguageFile's per-request cache), the shipped .po lives in a throwaway directory below the
 * ILIAS root, and the database is a stub returning canned lng_data rows (no overlay exists, so
 * _getValues() reads every module from lng_data).
 */
class ShippedValuesOfMigratedModulesTest extends ilLanguageBaseTestCase
{
    private const string LANG = 'zz';

    private string $fixture_directory;
    private string $global_file;
    /** @var list<array{module: string, identifier: string, value: string}> */
    private array $value_rows = [];
    /** @var list<array{module: string, identifier: string, remarks: ?string}> */
    private array $remark_rows = [];
    /** @var array<int, list<array<string, ?string>>> rows fetchAssoc() hands out per statement */
    private array $statement_rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'ILIAS_ABSOLUTE_PATH' => realpath(__DIR__ . '/../../../../'),
            'CLIENT_DATA_DIR' => sys_get_temp_dir() . '/ilias_lang_test_client_data_dir',
            'ILIAS_HTTP_PATH' => 'http://localhost',
            'ILIAS_VERSION' => 'test',
        ] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        (new ReflectionClass(ilCachedLanguage::class))->getProperty('instances')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);

        $this->fixture_directory = __DIR__ . '/tmp-shipped-values-fixtures-' . bin2hex(random_bytes(4));
        $shipped = MigratedPoFixture::catalog('itest', [
            'greeting' => 'Aus der PO',
            'po_only' => 'Nur in der PO',
            'farewell' => 'Tschüss',
        ]);
        $shipped->find('itest', 'greeting')->addExtractedComment('Begrüßung auf der Startseite');
        $shipped->find('itest', 'greeting')->addExtractedComment('kurz halten');
        MigratedPoFixture::writePo($this->shippedPo(), $shipped);
        $this->registerDirectoryManager();

        $this->setGlobalVariable('lng', $this->createStub(ilLanguage::class));
        $this->setGlobalVariable('ilDB', $this->database());
        $this->setGlobalVariable('ilUser', $this->createStub(ilObjUser::class));
        $this->setGlobalVariable('ilErr', $this->createStub(ilErrorHandling::class));
        // The header written by ilLanguageFile::write() is rendered by an ilTemplate, which asks for UI hook plugins
        $component_factory = $this->createStub(ilComponentFactory::class);
        $component_factory->method('getActivePluginsInSlot')->willReturn(new ArrayIterator([]));
        $this->setGlobalVariable('component.factory', $component_factory);

        $this->global_file = $this->fixture_directory . '/ilias_' . self::LANG . '.lang';
        $this->writeGlobalFile([
            'common#:#yes#:#Ja###Zustimmung',
            'itest#:#greeting#:#Veraltet in lang/###alter lang-Kommentar',
            'itest#:#stale#:#Nur noch in lang/###Kommentar einer entfernten Variable',
            'itest#:#farewell#:#Tschüss',
            'common#:#no#:#Nein',
        ]);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->fixture_directory);
        (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects')->setValue(null, []);

        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function shippedPo(): string
    {
        return $this->fixture_directory . '/itest_' . self::LANG . '.po';
    }

    private function registerDirectoryManager(): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                MigratedPoFixture::directory('itest', 'components/ILIAS/Language/tests/' . basename($this->fixture_directory) . '/')
            )
        );
    }

    /**
     * Writes lang/ilias_zz.lang and makes it the (freshly read) global language file.
     *
     * @param list<string> $lines raw lines after the header
     */
    private function writeGlobalFile(array $lines): void
    {
        if (!is_dir(dirname($this->global_file))) {
            mkdir(dirname($this->global_file), 0775, true);
        }
        file_put_contents(
            $this->global_file,
            "<?php exit; ?>\n/**\n* @module language file\n*/\n<!-- language file start -->\n" . implode("\n", $lines)
        );
        $global = new ilLanguageFile($this->global_file, self::LANG, 'global');
        $this->assertTrue($global->read(), $global->getErrorMessage());
        (new ReflectionClass(ilLanguageFile::class))->getProperty('global_file_objects')->setValue(null, [self::LANG => $global]);
    }

    private function database(): ilDBInterface
    {
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(static fn($value): string => "'" . (string) $value . "'");
        $db->method('in')->willReturn('1 = 1');
        $db->method('query')->willReturnCallback(function (string $sql): ilDBStatement {
            $value_rows = str_starts_with($sql, /** @lang text */ 'SELECT module, identifier, value FROM lng_data') ? $this->value_rows : [];
            $statement = $this->createStub(ilDBStatement::class);
            $statement->method('fetchRow')->willReturnCallback(static function () use (&$value_rows): ?array {
                return array_shift($value_rows);
            });
            $this->statement_rows[spl_object_id($statement)] = str_starts_with($sql, /** @lang text */ 'SELECT module, identifier, remarks')
                ? $this->remark_rows
                : [];
            return $statement;
        });
        $db->method('fetchAssoc')->willReturnCallback(function (ilDBStatement $statement): ?array {
            return array_shift($this->statement_rows[spl_object_id($statement)]);
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

    private function expectAWarningAboutTheUnreadablePo(): void
    {
        $logger = $this->createMock(ilLogger::class);
        $logger->expects($this->atLeastOnce())->method('warning')->with($this->stringContains('"itest"'));
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private static function sorted(array $values): array
    {
        ksort($values);
        return $values;
    }

    // ---------------------------------------------------- getShippedValues()

    public function testShippedValuesTakeAMigratedModuleFromItsShippedPoInsteadOfItsLangLines(): void
    {
        $object = $this->languageObject();

        $this->assertSame(
            [
                'common#:#no' => 'Nein',
                'common#:#yes' => 'Ja',
                'itest#:#farewell' => 'Tschüss',
                'itest#:#greeting' => 'Aus der PO',
                'itest#:#po_only' => 'Nur in der PO',
            ],
            self::sorted($object->getShippedValues())
        );
        $this->assertSame(['itest'], $object->getModulesMaintainedInPoFiles());
        $this->assertSame([], $object->getUnreadableShippedPoModules());
    }

    /**
     * A module maintained in PO files contributes the extracted comments of its shipped .po (joined
     * into one line) - never its .lang comments, not even for a line the .po no longer has.
     */
    public function testShippedCommentsTakeAMigratedModuleFromTheExtractedCommentsOfItsShippedPo(): void
    {
        $this->assertSame(
            [
                'common#:#yes' => 'Zustimmung',
                'itest#:#greeting' => 'Begrüßung auf der Startseite kurz halten',
            ],
            self::sorted($this->languageObject()->getShippedComments())
        );
    }

    /**
     * An unreadable shipped .po keeps that module's .lang lines (values and comments) as the best
     * available approximation - logged, and reported for a warning in the GUI. The module still is
     * maintained in PO files.
     */
    public function testAnUnreadableShippedPoFallsBackToTheLangLinesAndIsReported(): void
    {
        file_put_contents($this->shippedPo(), "msgid \"kaputt\n");
        $this->expectAWarningAboutTheUnreadablePo();
        $object = $this->languageObject();

        $this->assertSame(
            [
                'common#:#no' => 'Nein',
                'common#:#yes' => 'Ja',
                'itest#:#farewell' => 'Tschüss',
                'itest#:#greeting' => 'Veraltet in lang/',
                'itest#:#stale' => 'Nur noch in lang/',
            ],
            self::sorted($object->getShippedValues())
        );
        $this->assertSame('alter lang-Kommentar', $object->getShippedComments()['itest#:#greeting']);
        $this->assertSame(['itest'], $object->getUnreadableShippedPoModules());
        $this->assertSame(['itest'], $object->getModulesMaintainedInPoFiles());
    }

    /**
     * Without contributed directories nothing is maintained in PO files: the plain .lang content,
     * exactly as before.
     */
    public function testWithoutDirectoryManagerTheShippedValuesAreThePlainGlobalLanguageFile(): void
    {
        unset($GLOBALS['DIC'][LanguageFileDirectoryManager::class]);
        $object = $this->languageObject();

        $this->assertSame(ilLanguageFile::_getGlobalLanguageFile(self::LANG)->getAllValues(), $object->getShippedValues());
        $this->assertSame(ilLanguageFile::_getGlobalLanguageFile(self::LANG)->getAllComments(), $object->getShippedComments());
        $this->assertSame([], $object->getModulesMaintainedInPoFiles());
    }

    // ------------------------------------------- readers built on the shipped values

    /**
     * "Added" = not shipped: a value that only still has a stale .lang line of a migrated module is
     * added (the .po does not ship it), a value the .po ships is not - even without .lang line.
     */
    public function testAddedValuesAreComparedAgainstTheShippedPoOfAMigratedModule(): void
    {
        $this->value_rows = [
            ['module' => 'common', 'identifier' => 'custom', 'value' => 'Eigene'],
            ['module' => 'common', 'identifier' => 'yes', 'value' => 'Ja'],
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Aus der PO'],
            ['module' => 'itest', 'identifier' => 'po_only', 'value' => 'Nur in der PO'],
            ['module' => 'itest', 'identifier' => 'stale', 'value' => 'Nur noch in lang/'],
        ];

        $this->assertSame(
            ['common#:#custom' => 'Eigene', 'itest#:#stale' => 'Nur noch in lang/'],
            $this->languageObject()->getAddedValues()
        );
    }

    public function testCommentedValuesAreThoseWithAShippedCommentOfTheShippedPoForAMigratedModule(): void
    {
        $this->value_rows = [
            ['module' => 'common', 'identifier' => 'no', 'value' => 'Nein'],
            ['module' => 'common', 'identifier' => 'yes', 'value' => 'Ja'],
            ['module' => 'itest', 'identifier' => 'farewell', 'value' => 'Tschüss'],
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Servus'],
            ['module' => 'itest', 'identifier' => 'stale', 'value' => 'Nur noch in lang/'],
        ];

        $this->assertSame(
            ['common#:#yes' => 'Ja', 'itest#:#greeting' => 'Servus'],
            $this->languageObject()->getCommentedValues()
        );
    }

    public function testMergedValuesAreTheShippedValuesOverriddenByTheLocalOnes(): void
    {
        $this->value_rows = [
            ['module' => 'common', 'identifier' => 'custom', 'value' => 'Eigene'],
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Servus'],
        ];

        $this->assertSame(
            [
                'common#:#custom' => 'Eigene',
                'common#:#no' => 'Nein',
                'common#:#yes' => 'Ja',
                'itest#:#farewell' => 'Tschüss',
                'itest#:#greeting' => 'Servus',
                'itest#:#po_only' => 'Nur in der PO',
            ],
            self::sorted($this->languageObject()->getMergedValues())
        );
    }

    public function testMergedRemarksAreTheShippedCommentsOverriddenByTheLocalRemarks(): void
    {
        $this->remark_rows = [
            ['module' => 'common', 'identifier' => 'yes', 'remarks' => 'lokale Bemerkung'],
            ['module' => 'itest', 'identifier' => 'farewell', 'remarks' => null],
        ];

        $this->assertSame(
            [
                'common#:#yes' => 'lokale Bemerkung',
                'itest#:#farewell' => null,
                'itest#:#greeting' => 'Begrüßung auf der Startseite kurz halten',
            ],
            self::sorted($this->languageObject()->getMergedRemarks())
        );
    }

    // ------------------------------------------- mergeLocalChangesIntoGlobalLanguageFile()

    /**
     * @return list<string> the entry lines of the written global language file
     */
    private function entryLinesOfTheGlobalFile(): array
    {
        $content = (string) file_get_contents($this->global_file);
        $start = strpos($content, '<!-- language file start -->');
        $this->assertNotFalse($start);

        return explode("\n", ltrim(substr($content, $start + strlen('<!-- language file start -->')), "\n"));
    }

    /**
     * Option B: a module maintained in PO files is skipped entirely - its lines stay byte for byte as
     * they were (also a line with surrounding whitespace, which every other line loses), neither its
     * local values nor its local remarks are written, and no line is added for it. Local changes of
     * every other module are merged as before, including new entries. The skipped modules are
     * returned for the user message.
     */
    public function testMergeSkipsModulesMaintainedInPoFilesAndKeepsTheirLinesByteForByte(): void
    {
        $this->writeGlobalFile([
            'common#:#yes#:#Ja###Zustimmung',
            "itest#:#greeting#:#Veraltet in lang/   \t",
            ' itest#:#stale#:#Alt###Kommentar ',
            'common#:#no#:#Nein  ',
            'itest#:#farewell#:#Tschüss',
        ]);
        $this->value_rows = [
            ['module' => 'common', 'identifier' => 'new', 'value' => 'Neu'],
            ['module' => 'common', 'identifier' => 'yes', 'value' => 'Jawohl'],
            ['module' => 'itest', 'identifier' => 'farewell', 'value' => 'Servus'],
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Lokal geändert'],
            ['module' => 'itest', 'identifier' => 'local_only', 'value' => 'Nur lokal'],
        ];
        $this->remark_rows = [
            ['module' => 'common', 'identifier' => 'yes', 'remarks' => 'lokale Bemerkung'],
            ['module' => 'itest', 'identifier' => 'farewell', 'remarks' => 'lokale Bemerkung einer PO-Variable'],
        ];

        $skipped = $this->languageObject()->mergeLocalChangesIntoGlobalLanguageFile();

        $this->assertSame(['itest'], $skipped);
        $this->assertSame(
            [
                'common#:#yes#:#Jawohl###lokale Bemerkung',
                "itest#:#greeting#:#Veraltet in lang/   \t",
                ' itest#:#stale#:#Alt###Kommentar ',
                'common#:#no#:#Nein',
                'itest#:#farewell#:#Tschüss',
                'common#:#new#:#Neu',
            ],
            $this->entryLinesOfTheGlobalFile()
        );
    }

    /**
     * Without modules maintained in PO files nothing is skipped: the merge writes exactly what it
     * wrote before the change (local values and remarks over the file's lines).
     */
    public function testMergeWithoutModulesMaintainedInPoFilesMergesEverything(): void
    {
        unset($GLOBALS['DIC'][LanguageFileDirectoryManager::class]);
        $this->value_rows = [
            ['module' => 'itest', 'identifier' => 'greeting', 'value' => 'Lokal geändert'],
        ];

        $this->assertSame([], $this->languageObject()->mergeLocalChangesIntoGlobalLanguageFile());
        $this->assertSame(
            [
                'common#:#yes#:#Ja###Zustimmung',
                'itest#:#greeting#:#Lokal geändert###alter lang-Kommentar',
                'itest#:#stale#:#Nur noch in lang/###Kommentar einer entfernten Variable',
                'itest#:#farewell#:#Tschüss',
                'common#:#no#:#Nein',
            ],
            $this->entryLinesOfTheGlobalFile()
        );
    }
}

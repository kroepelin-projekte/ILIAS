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
        $db->method('query')->willReturnCallback(function (string $sql): ilDBStatement {
            $rows = str_starts_with($sql, 'SELECT module, identifier, value FROM lng_data') ? $this->db_rows : [];
            $statement = $this->createStub(ilDBStatement::class);
            $statement->method('fetchRow')->willReturnCallback(static function () use (&$rows): ?array {
                return array_shift($rows);
            });
            return $statement;
        });
        $db->method('queryF')->willReturn($self_check);
        $db->method('fetchAssoc')->willReturnCallback(
            fn(ilDBStatement $statement): ?array => $statement === $self_check
                ? ['lang_array' => $this->last_lang_array]
                : null
        );
        $db->method('insert')->willReturnCallback(function (string $table, array $values): int {
            if ($table === 'lng_modules') {
                $this->last_lang_array = $values['lang_array'][1];
                $this->lng_modules[$values['module'][1]] = unserialize($values['lang_array'][1]);
            }
            return 1;
        });
        $db->method('replace')->willReturnCallback(function (string $table, array $keys, array $values): int {
            $this->local_changes[$keys['module'][1] . '#:#' . $keys['identifier'][1]] = $values['local_change'][1];
            return 1;
        });

        return $db;
    }

    private function languageObject(): ilObjLanguageExt
    {
        $object = (new ReflectionClass(ilObjLanguageExt::class))->newInstanceWithoutConstructor();
        $object->key = self::LANG;

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

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

use PHPUnit\Framework\Attributes\DataProvider;

class ilObjLanguageExtTest extends ilLanguageBaseTestCase
{
    private const FILE_VALUES = [
        'content#:#cont_Link' => 'Lien',
        'content#:#cont_link' => 'Link',
        'common#:#numeric' => '10',
    ];

    private const FILE_COMMENTS = [
        'content#:#cont_link' => '31 10 2023 new variable',
    ];

    public static function localChangeProvider(): array
    {
        return [
            'value and comment equal to file' => ['content#:#cont_link', 'Link', '31 10 2023 new variable', false],
            'value equal to file, remark not saved' => ['content#:#cont_link', 'Link', null, false],
            'case variant of a key equal to file' => ['content#:#cont_Link', 'Lien', null, false],
            'case variant without file comment, empty remark' => ['content#:#cont_Link', 'Lien', '', false],
            'value differs from file' => ['content#:#cont_link', 'Lien', '31 10 2023 new variable', true],
            'value differs only in case' => ['content#:#cont_link', 'link', null, true],
            'numeric string compared strictly' => ['common#:#numeric', '1e1', null, true],
            'remark differs from file comment' => ['content#:#cont_link', 'Link', 'changed remark', true],
            'remark removed' => ['content#:#cont_link', 'Link', '', true],
            'remark added to key without file comment' => ['content#:#cont_Link', 'Lien', 'new remark', true],
            'key not in file (plugin variable)' => ['rep_robj_xtst#:#rep_robj_xtst_title', 'Title', null, true],
        ];
    }

    public function testGetAddedValuesIgnoresTheCaseOfIdentifiers(): void
    {
        $lng = $this->createStub(ilLanguage::class);
        $lng->separator = '#:#';
        $this->setGlobalVariable('lng', $lng);

        $statement = $this->createStub(ilDBStatement::class);
        $statement->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['module' => 'content', 'identifier' => 'cont_Link', 'value' => 'Mein Link'],
            ['module' => 'content', 'identifier' => 'cont_new', 'value' => 'Neu'],
            null
        );
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnArgument(0);
        $db->method('query')->willReturn($statement);
        $this->setGlobalVariable('ilDB', $db);

        $file = $this->createStub(ilLanguageFile::class);
        $file->method('getAllValues')->willReturn(['content#:#cont_link' => 'Link']);

        $language = $this->getMockBuilder(ilObjLanguageExt::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGlobalLanguageFile'])
            ->getMock();
        $language->expects($this->once())->method('getGlobalLanguageFile')->willReturn($file);
        $language->key = 'fr';

        $this->assertSame(['content#:#cont_new' => 'Neu'], $language->getAddedValues());
    }

    #[DataProvider('localChangeProvider')]
    public function testIsLocalChange(string $key, string $value, ?string $remark, bool $expected): void
    {
        $this->assertSame(
            $expected,
            ilObjLanguageExtLocalChangeProbe::probe($key, $value, $remark, self::FILE_VALUES, self::FILE_COMMENTS)
        );
    }

    public static function saveValuesProvider(): array
    {
        // file and database: common#:#num => 10 ###10, common#:#txt => Text ###Hinweis
        return [
            'unchanged value and remark' => ['common#:#num', '10', '10', null],
            'numeric value written differently' => ['common#:#num', '1e1', '10', ['1e1', true, '10']],
            'numeric remark written differently' => ['common#:#num', '10', '1e1', ['10', true, '1e1']],
            'changed value' => ['common#:#txt', 'Neuer Text', 'Hinweis', ['Neuer Text', true, 'Hinweis']],
            'changed remark' => ['common#:#txt', 'Text', 'Neuer Hinweis', ['Text', true, 'Neuer Hinweis']],
            'removed remark' => ['common#:#txt', 'Text', '', ['Text', true, null]],
            'key missing in file and database' => ['common#:#added', 'Neu', '', ['Neu', true, null]],
        ];
    }

    /**
     * @param array{0: string, 1: bool, 2: ?string}|null $expected value, local change set?, remarks of the saved entry
     */
    #[DataProvider('saveValuesProvider')]
    public function testSaveValuesWritesOnlyRealChanges(string $key, string $value, string $remark, ?array $expected): void
    {
        if (!defined('ILIAS_HTTP_PATH')) {
            define('ILIAS_HTTP_PATH', 'http://localhost');
        }
        if (!defined('ILIAS_VERSION')) {
            define('ILIAS_VERSION', '12.0');
        }

        $lang_key = 'test_' . str_replace('.', '', uniqid('', true));
        $dir = sys_get_temp_dir();
        $file = $dir . '/ilias_' . $lang_key . '.lang';
        file_put_contents(
            $file,
            "<!-- language file start -->\ncommon#:#num#:#10###10\ncommon#:#txt#:#Text###Hinweis"
        );

        $lng = $this->createStub(ilLanguage::class);
        $lng->separator = '#:#';
        $lng->comment_separator = '###';
        $lng->lang_path = $dir;
        $this->setGlobalVariable('lng', $lng);

        $values = $this->createStub(ilDBStatement::class);
        $values->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['module' => 'common', 'identifier' => 'num', 'value' => '10'],
            ['module' => 'common', 'identifier' => 'txt', 'value' => 'Text'],
            null
        );
        $remarks = $this->createStub(ilDBStatement::class);
        $remark_rows = [
            ['module' => 'common', 'identifier' => 'num', 'remarks' => '10'],
            ['module' => 'common', 'identifier' => 'txt', 'remarks' => 'Hinweis'],
        ];
        $replaced = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnArgument(0);
        $db->method('query')->willReturnOnConsecutiveCalls($values, $remarks, $this->createStub(ilDBStatement::class));
        $db->method('fetchAssoc')->willReturnCallback(
            static function (ilDBStatement $statement) use ($remarks, &$remark_rows): ?array {
                return $statement === $remarks ? array_shift($remark_rows) : null;
            }
        );
        $db->method('replace')->willReturnCallback(
            static function (string $table, array $primary_keys, array $other_columns) use (&$replaced): int {
                $replaced[] = [$table, $primary_keys, $other_columns];
                return 1;
            }
        );
        $this->setGlobalVariable('ilDB', $db);
        $this->setGlobalVariable('ilLoggerFactory', $this->createStub(ilLoggerFactory::class));
        $this->setGlobalVariable('global_cache', $this->createStub(\ILIAS\Cache\Services::class));

        try {
            ilObjLanguageExt::_saveValues($lang_key, [$key => $value], [$key => $remark]);
        } finally {
            unlink($file);
        }

        if ($expected === null) {
            $this->assertSame([], $replaced);
            return;
        }
        [$identifier] = array_slice(explode('#:#', $key), 1);
        $this->assertCount(1, $replaced);
        [$table, $primary_keys, $other_columns] = $replaced[0];
        $this->assertSame('lng_data', $table);
        $this->assertSame(['text', $identifier], $primary_keys['identifier']);
        $this->assertSame(['text', $lang_key], $primary_keys['lang_key']);
        $this->assertSame($expected[0], $other_columns['value'][1]);
        $this->assertSame($expected[1], $other_columns['local_change'][1] !== null);
        $this->assertSame($expected[2], $other_columns['remarks'][1]);
    }

    public static function casePairProvider(): array
    {
        return [
            'database has the last spelling' => ['cont_link', 'Link', []],
            'database has the first spelling' => [
                'cont_Link',
                'Lien',
                [['content', 'cont_link', 'Link', false, '31 10 2023 new variable']],
            ],
            'database has a local change of the last spelling' => [
                'cont_link',
                'Mein Link',
                [['content', 'cont_link', 'Link', false, '31 10 2023 new variable']],
            ],
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: bool, 4: ?string}> $expected module, identifier, value,
     *                                                                                    local change set?, remarks
     */
    #[DataProvider('casePairProvider')]
    public function testSaveValuesWritesTheLastSpellingOfACasePair(
        string $db_identifier,
        string $db_value,
        array $expected
    ): void {
        $file_content = "<!-- language file start -->\n"
            . "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable";
        [$replaced, $modules] = $this->runWithStubs(
            $file_content,
            [['module' => 'content', 'identifier' => $db_identifier, 'value' => $db_value]],
            static fn(string $lang_key): null => ilObjLanguageExt::_saveValues(
                $lang_key,
                [
                    'content#:#cont_Link' => 'Lien',
                    'content#:#cont_link' => 'Link',
                ],
                ['content#:#cont_link' => '31 10 2023 new variable']
            )
        );

        $written = array_map(
            static fn(array $call): array => [
                $call[1]['module'][1],
                $call[1]['identifier'][1],
                $call[2]['value'][1],
                $call[2]['local_change'][1] !== null,
                $call[2]['remarks'][1],
            ],
            $replaced
        );
        $this->assertSame($expected, $written);
        $this->assertSame(['content' => ['cont_Link' => 'Lien', 'cont_link' => 'Link']], $modules);
    }

    public static function importModeProvider(): array
    {
        $saved = [['content', 'cont_link', 'Link', false, '31 10 2023 new variable']];
        $both_spellings = ['content' => ['cont_Link' => 'Lien', 'cont_link' => 'Link']];

        return [
            'keepnew, local change on the last spelling' => ['keepnew', 'cont_link', [], []],
            'keepnew, local change on the first spelling' => ['keepnew', 'cont_Link', [], []],
            'keepall' => ['keepall', 'cont_Link', [], []],
            'replace' => ['replace', 'cont_Link', $saved, $both_spellings],
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: bool, 4: ?string}> $expected_rows
     */
    #[DataProvider('importModeProvider')]
    public function testImportLanguageFileComparesExistingEntriesCaseInsensitively(
        string $mode,
        string $db_identifier,
        array $expected_rows,
        array $expected_modules
    ): void {
        $db_row = ['module' => 'content', 'identifier' => $db_identifier, 'value' => 'Mein Link'];
        $existing = ['content#:#' . $db_identifier => 'Mein Link'];
        $this->setGlobalVariable('ilErr', $this->createStub(ilErrorHandling::class));

        [$replaced, $modules] = $this->runWithStubs(
            "<!-- language file start -->\n"
            . "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable",
            [$db_row],
            function (string $lang_key, string $file) use ($mode, $existing): void {
                $language = $this->getMockBuilder(ilObjLanguageExt::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['getAllValues', 'getChangedValues'])
                    ->getMock();
                $language->expects($mode === 'keepall' ? $this->once() : $this->never())
                    ->method('getAllValues')->willReturn($existing);
                $language->expects($mode === 'keepnew' ? $this->once() : $this->never())
                    ->method('getChangedValues')->willReturn($existing);
                $language->key = $lang_key;
                $language->importLanguageFile($file, $mode);
            }
        );

        $this->assertSame($expected_rows, array_map(
            static fn(array $call): array => [
                $call[1]['module'][1],
                $call[1]['identifier'][1],
                $call[2]['value'][1],
                $call[2]['local_change'][1] !== null,
                $call[2]['remarks'][1],
            ],
            $replaced
        ));
        $this->assertSame($expected_modules, $modules);
    }

    /**
     * Runs $run against a temporary global language file and database stubs.
     *
     * @param callable(string $lang_key, string $file): void $run
     * @return array{0: list<array{0: string, 1: array, 2: array}>, 1: array<string, array<string, string>>}
     *         the calls of ilDBInterface::replace and the saved lng_modules entries
     */
    private function runWithStubs(string $file_content, array $db_rows, callable $run): array
    {
        if (!defined('ILIAS_HTTP_PATH')) {
            define('ILIAS_HTTP_PATH', 'http://localhost');
        }
        if (!defined('ILIAS_VERSION')) {
            define('ILIAS_VERSION', '12.0');
        }

        $lang_key = 'test_' . str_replace('.', '', uniqid('', true));
        $file = sys_get_temp_dir() . '/ilias_' . $lang_key . '.lang';
        file_put_contents($file, $file_content);

        $lng = $this->createStub(ilLanguage::class);
        $lng->separator = '#:#';
        $lng->comment_separator = '###';
        $lng->lang_path = sys_get_temp_dir();
        $this->setGlobalVariable('lng', $lng);

        $values_statement = $this->createStub(ilDBStatement::class);
        $values_statement->method('fetchRow')->willReturnOnConsecutiveCalls(...[...$db_rows, null]);
        $remarks_statement = $this->createStub(ilDBStatement::class);
        $replaced = [];
        $modules = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnArgument(0);
        $db->method('query')->willReturnOnConsecutiveCalls(
            $values_statement,
            $remarks_statement,
            $this->createStub(ilDBStatement::class)
        );
        $db->method('fetchAssoc')->willReturnCallback(
            static fn(ilDBStatement $statement): ?array => $statement === $remarks_statement
                ? null
                : ['lang_array' => serialize([])]
        );
        $db->method('replace')->willReturnCallback(
            static function (string $table, array $primary_keys, array $other_columns) use (&$replaced): int {
                $replaced[] = [$table, $primary_keys, $other_columns];
                return 1;
            }
        );
        $db->method('insert')->willReturnCallback(
            static function (string $table, array $columns) use (&$modules): int {
                $modules[$columns['module'][1]] = unserialize($columns['lang_array'][1]);
                return 1;
            }
        );
        $this->setGlobalVariable('ilDB', $db);
        $this->setGlobalVariable('global_cache', $this->createStub(\ILIAS\Cache\Services::class));

        try {
            $run($lang_key, $file);
        } finally {
            unlink($file);
        }

        return [$replaced, $modules];
    }

    public static function mergedProvider(): array
    {
        $pair = ['content#:#cont_Link' => 'Lien', 'content#:#cont_link' => 'Link'];
        return [
            'renamed spelling gets the spelling of the file' => [
                ['content#:#cont_link' => 'Link'],
                'cont_Link',
                ['content#:#cont_link' => 'Mein Link'],
                ['content#:#cont_link' => 'Mein Hinweis'],
            ],
            'pair, database has the first spelling' => [
                $pair,
                'cont_Link',
                ['content#:#cont_Link' => 'Mein Link', 'content#:#cont_link' => 'Link'],
                ['content#:#cont_link' => '31 10 2023 new variable', 'content#:#cont_Link' => 'Mein Hinweis'],
            ],
            'pair, database has the last spelling' => [
                $pair,
                'cont_link',
                ['content#:#cont_Link' => 'Lien', 'content#:#cont_link' => 'Mein Link'],
                ['content#:#cont_link' => 'Mein Hinweis'],
            ],
            'spelling of neither file line is added under its own key' => [
                ['content#:#cont_link' => 'Link'],
                'cont_other',
                ['content#:#cont_link' => 'Link', 'content#:#cont_other' => 'Mein Link'],
                ['content#:#cont_link' => '31 10 2023 new variable', 'content#:#cont_other' => 'Mein Hinweis'],
            ],
        ];
    }

    #[DataProvider('mergedProvider')]
    public function testMergedValuesAndRemarksUseTheSpellingOfTheFile(
        array $file_values,
        string $db_identifier,
        array $expected_values,
        array $expected_remarks
    ): void {
        $language = $this->languageWithDatabaseRow(
            $file_values,
            ['content#:#cont_link' => '31 10 2023 new variable'],
            ['module' => 'content', 'identifier' => $db_identifier, 'value' => 'Mein Link', 'remarks' => 'Mein Hinweis']
        );

        $this->assertSame($expected_values, $language->getMergedValues());
        $this->assertSame($expected_remarks, $language->getMergedRemarks());
    }

    public function testGetCommentedValuesIgnoresTheCaseOfIdentifiers(): void
    {
        $language = $this->languageWithDatabaseRow(
            ['content#:#cont_link' => 'Link'],
            ['content#:#cont_link' => '31 10 2023 new variable'],
            ['module' => 'content', 'identifier' => 'cont_Link', 'value' => 'Mein Link', 'remarks' => null]
        );

        $this->assertSame(['content#:#cont_Link' => 'Mein Link'], $language->getCommentedValues());
    }

    /**
     * @param array{module: string, identifier: string, value: string, remarks: ?string} $db_row
     */
    private function languageWithDatabaseRow(array $file_values, array $file_comments, array $db_row): ilObjLanguageExt
    {
        $lng = $this->createStub(ilLanguage::class);
        $lng->separator = '#:#';
        $this->setGlobalVariable('lng', $lng);

        $statement = $this->createStub(ilDBStatement::class);
        $statement->method('fetchRow')->willReturnOnConsecutiveCalls($db_row, null, $db_row, null);
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnArgument(0);
        $db->method('query')->willReturn($statement);
        $db->method('fetchAssoc')->willReturnOnConsecutiveCalls($db_row, null, $db_row, null);
        $this->setGlobalVariable('ilDB', $db);

        $file = $this->createStub(ilLanguageFile::class);
        $file->method('getAllValues')->willReturn($file_values);
        $file->method('getAllComments')->willReturn($file_comments);

        $language = $this->getMockBuilder(ilObjLanguageExt::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGlobalLanguageFile'])
            ->getMock();
        $language->expects($this->atLeastOnce())->method('getGlobalLanguageFile')->willReturn($file);
        $language->key = 'fr';

        return $language;
    }
}

class ilObjLanguageExtLocalChangeProbe extends ilObjLanguageExt
{
    public static function probe(
        string $key,
        string $value,
        ?string $remark,
        array $file_values,
        array $file_comments
    ): bool {
        return self::isLocalChange($key, $value, $remark, $file_values, $file_comments);
    }
}

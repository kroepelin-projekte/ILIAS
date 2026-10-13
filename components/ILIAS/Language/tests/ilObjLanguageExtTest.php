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

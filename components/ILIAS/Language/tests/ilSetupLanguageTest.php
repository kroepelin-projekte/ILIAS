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

/**
 * Class ilSetupLanguageTest
 * @author  Sílvia Mariné <silvia.marine@kroepelin-projekte.de>
 */
class ilSetupLanguageTest extends ilLanguageBaseTestCase
{
    private ilSetupLanguage $newLangSetupDe;
    private ilSetupLanguage $newLangSetupEs;

    /**
     * @var \ILIAS\Language\Setup\Language[]
     */
    private array $langInstalled;

    protected function setUp(): void
    {
        $this->newLangSetupDe = new ilSetupLanguage('de');
        $this->newLangSetupEs = new ilSetupLanguage('es');

        $this->langInstalled[] = $this->newLangSetupDe;
        $this->langInstalled[] = $this->newLangSetupEs;
    }

    public function testRetrieveLanguageKey(): void
    {
        $this->assertEquals('de', $this->newLangSetupDe->getLangKey());
        $this->assertEquals('es', $this->newLangSetupEs->getLangKey());
    }

    public function testRetrieveInstalledLanguage(): void
    {
        $languagesAsKeys = [];
        foreach ($this->langInstalled as $languageAsKey) {
            $languagesAsKeys[] = $languageAsKey->getLangKey();
        }

        $this->assertContains('de', $languagesAsKeys);
        $this->assertContains('es', $languagesAsKeys);
    }

    public function testInsertLanguageStoresTheRemarksOfTheLastLineOfACasePair(): void
    {
        $inserts = $this->insertLanguageFile(
            "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable\n",
            []
        );

        $this->assertCount(1, $inserts);
        $this->assertStringContainsString(
            "('content','cont_link','fr','Link',NULL,'31 10 2023 new variable')",
            $inserts[0]
        );
        $this->assertStringNotContainsString('cont_Link', $inserts[0]);
        $this->assertStringEndsWith(
            ' ON DUPLICATE KEY UPDATE identifier=VALUES(identifier),value=VALUES(value),local_change=VALUES(local_change),remarks=VALUES(remarks);',
            $inserts[0]
        );
    }

    public function testInsertLanguageResetsOnlyLocalChangesEqualToTheFile(): void
    {
        $inserts = $this->insertLanguageFile(
            "acc#:#acc_add_document_btn_label#:#Dokument hinzufügen\n"
            . "poll#:#poll_absolute#:#Aktuelle Stimmen\n",
            [
                ['module' => 'acc', 'identifier' => 'acc_add_document_btn_label', 'value' => 'Dokument hinzufügen'],
                ['module' => 'poll', 'identifier' => 'poll_absolute', 'value' => 'Aktuelle Stimmen (Overlay-Test)'],
            ]
        );

        $this->assertCount(1, $inserts);
        $this->assertStringContainsString(
            "('acc','acc_add_document_btn_label','fr','Dokument hinzufügen',NULL,NULL)",
            $inserts[0]
        );
        $this->assertStringNotContainsString('poll_absolute', $inserts[0]);
        $this->assertStringContainsString('local_change=VALUES(local_change)', $inserts[0]);
    }

    public function testInsertLanguageMovesAResetLocalChangeToTheLastSpelling(): void
    {
        $inserts = $this->insertLanguageFile(
            "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable\n",
            [['module' => 'content', 'identifier' => 'cont_Link', 'value' => 'Lien']]
        );

        $this->assertCount(1, $inserts);
        $this->assertStringContainsString(
            "('content','cont_link','fr','Link',NULL,'31 10 2023 new variable')",
            $inserts[0]
        );
        $this->assertStringContainsString('identifier=VALUES(identifier)', $inserts[0]);
    }

    public function testInsertLanguageMarksTheEntriesOfALocalFileAsLocalChanges(): void
    {
        $inserts = $this->insertLanguageFile(
            "content#:#cont_link#:#Mein Link\n"
            . "common#:#ok#:#OK\n",
            [['module' => 'common', 'identifier' => 'ok', 'value' => 'Okay']],
            'local'
        );

        $this->assertCount(1, $inserts);
        $this->assertMatchesRegularExpression(
            "/\\('content','cont_link','fr','Mein Link','\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}',NULL\\)/",
            $inserts[0]
        );
        $this->assertStringNotContainsString("'ok'", $inserts[0]);
        $this->assertStringContainsString('local_change=VALUES(local_change)', $inserts[0]);
    }

    public function testInsertLanguageKeepsBothSpellingsInTheLanguageModules(): void
    {
        $queries = $this->runInsertLanguage(
            "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable\n",
            []
        );

        $modules = array_values(array_filter(
            $queries,
            static fn(string $query): bool => str_starts_with($query, 'INSERT INTO lng_modules')
        ));
        $this->assertCount(1, $modules);
        $this->assertStringContainsString(
            "('content','fr','" . serialize(['cont_Link' => 'Lien', 'cont_link' => 'Link']) . "')",
            $modules[0]
        );
    }

    /**
     * @param list<array{module: string, identifier: string, value: string}> $local_change_rows
     * @return list<string> the INSERT queries into lng_data
     */
    private function insertLanguageFile(string $lines, array $local_change_rows, string $scope = ''): array
    {
        return array_values(array_filter(
            $this->runInsertLanguage($lines, $local_change_rows, $scope),
            static fn(string $query): bool => str_starts_with($query, 'INSERT INTO lng_data')
        ));
    }

    /**
     * @param list<array{module: string, identifier: string, value: string}> $local_change_rows
     * @return list<string> all manipulating queries
     */
    private function runInsertLanguage(string $lines, array $local_change_rows, string $scope = ''): array
    {
        $dir = sys_get_temp_dir() . '/ilSetupLanguageTest_' . uniqid('', true);
        mkdir($dir);
        $file = $dir . '/ilias_fr.lang' . ($scope === 'local' ? '.local' : '');
        file_put_contents($file, "header\n<!-- language file start -->\n" . $lines);

        $statement = $this->createStub(ilDBStatement::class);
        $statement->method('fetchRow')->willReturnOnConsecutiveCalls(...[...$local_change_rows, null]);

        $queries = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn($value): string => $value === null ? 'NULL' : "'" . $value . "'"
        );
        $db->method('query')->willReturn($statement);
        $db->method('in')->willReturn('1 = 1');
        $db->method('fetchAssoc')->willReturn(['lang_array' => serialize([])]);
        $db->method('manipulate')->willReturnCallback(
            static function (string $query) use (&$queries): int {
                $queries[] = $query;
                return 1;
            }
        );
        $GLOBALS['ilDB'] = $db;

        $setup = new class ('fr') extends ilSetupLanguage {
            public function insert(string $lang_key, string $scope): void
            {
                $this->insertLanguage($lang_key, $scope);
            }

            public function usePath(string $path): void
            {
                $this->lang_path = $path;
                $this->cust_lang_path = $path;
            }
        };
        $setup->usePath($dir);

        $cwd = getcwd();
        try {
            $setup->insert('fr', $scope);
        } finally {
            chdir($cwd);
            unlink($file);
            rmdir($dir);
            unset($GLOBALS['ilDB']);
        }

        return $queries;
    }
}

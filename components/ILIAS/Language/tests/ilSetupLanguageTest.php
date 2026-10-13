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
        $dir = sys_get_temp_dir() . '/ilSetupLanguageTest_' . uniqid('', true);
        mkdir($dir);
        $file = $dir . '/ilias_fr.lang';
        file_put_contents(
            $file,
            "header\n<!-- language file start -->\n"
            . "content#:#cont_Link#:#Lien\n"
            . "content#:#cont_link#:#Link###31 10 2023 new variable\n"
        );

        $queries = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static fn($value): string => $value === null ? 'NULL' : "'" . $value . "'"
        );
        $db->method('query')->willReturn($this->createStub(ilDBStatement::class));
        $db->method('in')->willReturn('1 = 1');
        $db->method('manipulate')->willReturnCallback(
            static function (string $query) use (&$queries): int {
                $queries[] = $query;
                return 1;
            }
        );
        $GLOBALS['ilDB'] = $db;

        $setup = new class ('fr') extends ilSetupLanguage {
            public function insert(string $lang_key): void
            {
                $this->insertLanguage($lang_key);
            }
        };
        $setup->lang_path = $dir;

        try {
            $setup->insert('fr');
        } finally {
            unlink($file);
            rmdir($dir);
            unset($GLOBALS['ilDB']);
        }

        $inserts = array_values(array_filter(
            $queries,
            static fn(string $query): bool => str_starts_with($query, 'INSERT INTO lng_data')
        ));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString(
            "('content','cont_link','fr','Link',NULL,'31 10 2023 new variable')",
            $inserts[0]
        );
        $this->assertStringNotContainsString('cont_Link', $inserts[0]);
        $this->assertStringContainsString('remarks=VALUES(remarks)', $inserts[0]);
    }
}

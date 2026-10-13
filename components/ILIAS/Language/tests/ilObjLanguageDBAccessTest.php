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

/**
 * Class ilObjLanguageDBAccessTest
 * @author  Christian Knof <christian.knof@kroepelin-projekte.de>
 */
class ilObjLanguageDBAccessTest extends ilLanguageBaseTestCase
{
    private ilDBInterface $ilDB;

    protected function setUp(): void
    {
        $this->ilDB = $this->createStub(ilDBInterface::class);
    }

    public function testCreate(): void
    {
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document", "administration#:#adm_achievements#:#Achievements"];
        $local_changes = [];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);
        $this->assertInstanceOf(\ilObjLanguageDBAccess::class, $ilObjLanguageDBAccess);
    }

    public function testInsertLangEntriesReturnsArray(): void
    {
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document", "administration#:#adm_achievements#:#Achievements"];
        $local_changes = [];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);

        $result = $ilObjLanguageDBAccess->insertLangEntries("lang/ilias_en.lang");

        $this->assertIsArray($result);
    }

    public function testInsertLangEntriesReturnedArrayHasValuesFromContent(): void
    {
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document"];
        $local_changes = [];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);
        $result = $ilObjLanguageDBAccess->insertLangEntries("lang/ilias_en.lang");

        $this->assertArrayHasKey("acc", $result);
        $this->assertArrayHasKey("acc_add_document_btn_label", $result["acc"]);
        $this->assertEquals("Add Document", $result["acc"]["acc_add_document_btn_label"]);
    }

    public function testInsertLangEntriesLocalChangesAreNotOverwritten(): void
    {
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document"];
        $local_changes = ["acc" => ["acc_add_document_btn_label" => "Add Documents"]];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);
        $result = $ilObjLanguageDBAccess->insertLangEntries("lang/ilias_en.lang");

        $this->assertEquals("Add Documents", $result["acc"]["acc_add_document_btn_label"]);
    }

    public function testInsertLangEntriesManipulateCalledOnce(): void
    {
        $this->ilDB = $this->createMock(ilDBInterface::class);
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document"];
        $local_changes = [];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);

        $this->ilDB->expects($this->once())->method("manipulate");
        $result = $ilObjLanguageDBAccess->insertLangEntries("lang/ilias_en.lang");
    }

    public function testInsertLangEntriesManipulateCalledNeverWhenEveryContentHasALocalChange(): void
    {
        $this->ilDB = $this->createMock(ilDBInterface::class);
        $key = "en";
        $content = ["acc#:#acc_add_document_btn_label#:#Add Document"];
        $local_changes = ["acc" => ["acc_add_document_btn_label" => "Add Documents"]];

        $ilObjLanguageDBAccess = new ilObjLanguageDBAccess($this->ilDB, $key, $content, $local_changes);

        $this->ilDB->expects($this->never())->method("manipulate");
        $result = $ilObjLanguageDBAccess->insertLangEntries("lang/ilias_en.lang");
    }

    private const CASE_PAIR = [
        "content#:#cont_Link#:#Lien",
        "content#:#cont_link#:#Link###31 10 2023 new variable",
    ];

    public function testInsertLangEntriesWritesOneRowPerCasePairWithTheLastLine(): void
    {
        $this->ilDB = $this->createMock(ilDBInterface::class);
        $this->ilDB->method("quote")->willReturnCallback(
            static fn($value): string => $value === null ? "NULL" : "'" . $value . "'"
        );
        $this->ilDB->expects($this->once())->method("manipulate")->with(
            $this->callback(static function (string $query): bool {
                return substr_count($query, "'content'") === 1
                    && str_contains($query, "('content','cont_link','fr','Link',NULL,'31 10 2023 new variable')")
                    && !str_contains($query, "cont_Link")
                    && str_contains($query, " ON DUPLICATE KEY UPDATE identifier=VALUES(identifier),");
            })
        );

        $result = (new ilObjLanguageDBAccess($this->ilDB, "fr", self::CASE_PAIR, []))
            ->insertLangEntries("lang/ilias_fr.lang");

        $this->assertSame(["cont_Link" => "Lien", "cont_link" => "Link"], $result["content"]);
    }

    public function testCollectEntriesLetsTheLastSpellingWin(): void
    {
        $entries = ilObjLanguageDBAccess::collectEntries(
            ["style#:#style#:#Style", "content#:#cont_link#:#Link", "content#:#cont_Link#:#Lien", "style#:#Style#:#Stil"],
            []
        );

        $this->assertSame(
            [
                ["module" => "style", "identifier" => "Style", "value" => "Stil", "remarks" => null],
                ["module" => "content", "identifier" => "cont_Link", "value" => "Lien", "remarks" => null],
            ],
            $entries["rows"]
        );
        $this->assertSame(["cont_link" => "Link", "cont_Link" => "Lien"], $entries["lang_array"]["content"]);
        $this->assertSame([], $entries["duplicates"]);
    }

    public static function localChangeOfCasePairProvider(): array
    {
        return [
            "local change on the last spelling" => ["cont_link", "Mein Link", ["cont_Link" => "Lien", "cont_link" => "Mein Link"]],
            "local change on the first spelling" => ["cont_Link", "Mein Lien", ["cont_Link" => "Mein Lien", "cont_link" => "Link"]],
            "local change on a spelling missing in the file" => ["CONT_LINK", "Mein Link", ["cont_Link" => "Lien", "cont_link" => "Mein Link"]],
        ];
    }

    #[DataProvider('localChangeOfCasePairProvider')]
    public function testCollectEntriesKeepsALocalChangeOfACasePair(
        string $local_identifier,
        string $local_value,
        array $expected_lang_array
    ): void {
        foreach (["", "local"] as $scope) {
            $entries = ilObjLanguageDBAccess::collectEntries(
                self::CASE_PAIR,
                ["content" => [$local_identifier => $local_value]],
                $scope
            );

            $this->assertSame([], $entries["rows"], "scope '$scope'");
            $this->assertSame($expected_lang_array, $entries["lang_array"]["content"], "scope '$scope'");
        }
    }

    public function testCollectEntriesAppliesALocalChangeOfARenamedSpellingToTheFileSpelling(): void
    {
        $entries = ilObjLanguageDBAccess::collectEntries(
            ["content#:#cont_link#:#Link"],
            ["content" => ["cont_Link" => "Mein Link"]]
        );

        $this->assertSame([], $entries["rows"]);
        $this->assertSame(["cont_link" => "Mein Link"], $entries["lang_array"]["content"]);
    }

    public function testCollectEntriesResetsALocalChangeEqualToTheFileInGlobalScope(): void
    {
        $entries = ilObjLanguageDBAccess::collectEntries(
            self::CASE_PAIR,
            ["content" => ["cont_link" => "Link"]]
        );

        $this->assertSame(
            [["module" => "content", "identifier" => "cont_link", "value" => "Link", "remarks" => "31 10 2023 new variable"]],
            $entries["rows"]
        );
    }

    public function testCollectEntriesKeepsALocalChangeEqualToTheFileInLocalScope(): void
    {
        $entries = ilObjLanguageDBAccess::collectEntries(
            self::CASE_PAIR,
            ["content" => ["cont_link" => "Link"]],
            "local"
        );

        $this->assertSame([], $entries["rows"]);
    }

    public function testCollectEntriesCutsRemarksToTheColumnLength(): void
    {
        $remark = str_repeat("ä", 260);
        $entries = ilObjLanguageDBAccess::collectEntries(["common#:#ok#:#OK###" . $remark], []);

        $this->assertSame(mb_substr($remark, 0, 250), $entries["rows"][0]["remarks"]);
    }

    public function testCollectEntriesReportsExactDuplicates(): void
    {
        $entries = ilObjLanguageDBAccess::collectEntries(
            ["common#:#ok#:#OK", "common#:#ok#:#Okay"],
            []
        );

        $this->assertSame(["common#:#ok#:#Okay"], $entries["duplicates"]);
    }
}

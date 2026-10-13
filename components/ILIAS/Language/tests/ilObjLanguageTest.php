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

class ilObjLanguageTest extends ilLanguageBaseTestCase
{
    public function testReplaceLangEntryCutsByCharacters(): void
    {
        $columns = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('replace')->willReturnCallback(
            static function (string $table, array $primary_keys, array $other_columns) use (&$columns): int {
                $columns = $other_columns;
                return 1;
            }
        );
        $this->setGlobalVariable('ilDB', $db);

        ilObjLanguage::replaceLangEntry('common', 'ok', 'de', str_repeat('ä', 4010), null, str_repeat('ö', 260));

        $this->assertSame(str_repeat('ä', 4000), $columns['value'][1]);
        $this->assertSame(str_repeat('ö', 250), $columns['remarks'][1]);
    }

    public function testUpdateLangEntryCutsByCharacters(): void
    {
        $quoted = [];
        $db = $this->createStub(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            static function ($value) use (&$quoted): string {
                $quoted[] = $value;
                return "'" . $value . "'";
            }
        );
        $this->setGlobalVariable('ilDB', $db);

        ilObjLanguage::updateLangEntry('common', 'ok', 'de', str_repeat('ä', 4010), null, str_repeat('ö', 260));

        $this->assertSame(str_repeat('ä', 4000), $quoted[0]);
        $this->assertSame(str_repeat('ö', 250), $quoted[2]);
    }
}

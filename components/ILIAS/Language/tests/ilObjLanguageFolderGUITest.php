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

class ilObjLanguageFolderGUITest extends ilLanguageBaseTestCase
{
    public function testUserLanguageChangedMessageUsesTheGivenLanguage(): void
    {
        $lng = $this->createMock(ilLanguage::class);
        $lng->expects($this->once())->method('loadLanguageModule')->with('meta');
        $lng->method('txt')->willReturnMap([
            ['user_language', '', 'Langue Utilisateur'],
            ['changed_to', '', 'Modifié en'],
            ['meta_l_fr', '', 'Français'],
        ]);

        $this->assertSame(
            'Langue Utilisateur Modifié en Français.',
            ilObjLanguageFolderGUIMessageProbe::probe($lng, 'fr')
        );
    }
}

class ilObjLanguageFolderGUIMessageProbe extends ilObjLanguageFolderGUI
{
    public static function probe(ilLanguage $lng, string $lang_key): string
    {
        return self::getUserLanguageChangedMessage($lng, $lang_key);
    }
}

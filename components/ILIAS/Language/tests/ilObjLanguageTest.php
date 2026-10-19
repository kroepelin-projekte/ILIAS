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
use PHPUnit\Framework\TestCase;

/**
 * ilObjLanguage's absolute_path must be four "../" up from components/ILIAS/Language/classes/ (a fifth once made
 * check() report every language as invalid); the constructor's expression is inspected since a real object needs a DB.
 */
class ilObjLanguageTest extends TestCase
{
    public function testAbsolutePathExpressionResolvesToTheIliasRoot(): void
    {
        $file = (new ReflectionClass(ilObjLanguage::class))->getFileName();
        $source = file_get_contents($file);

        self::assertStringContainsString(
            '$this->absolute_path = (string) realpath(__DIR__ . "/../../../../");',
            $source,
            'ilObjLanguage::__construct() no longer builds absolute_path with the ' .
            'expected four "../" segments - update this test if the file moved, ' .
            'but otherwise this is the off-by-one regression this test guards against.'
        );

        $computed_root = realpath(dirname($file) . '/../../../../');
        self::assertNotFalse($computed_root, 'The computed absolute_path must resolve to a real directory.');
        self::assertFileExists(
            $computed_root . '/lang/ilias_en.lang',
            'absolute_path must resolve to the ILIAS root, where lang/ilias_xx.lang files ' .
            'live - otherwise check()/refresh()/removeLocalChanges() will report every ' .
            'language as invalid.'
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['DIC']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function multibyteCharacterProvider(): array
    {
        return [
            '2-byte umlaut' => ['ä'],
            '2-byte cyrillic' => ['ж'],
        ];
    }

    #[DataProvider('multibyteCharacterProvider')]
    public function testReplaceLangEntryCutsTheRemarkToExactly250CharactersOfValidUtf8(string $character): void
    {
        $remarks = null;
        $db = $this->createMock(ilDBInterface::class);
        $db->method('replace')->willReturnCallback(
            function (string $table, array $pk, array $data) use (&$remarks): int {
                $remarks = $data['remarks'][1];
                return 1;
            }
        );
        $GLOBALS['DIC'] = new \ILIAS\DI\Container();
        $GLOBALS['DIC']['ilDB'] = $db;

        ilObjLanguage::replaceLangEntry('common', 'access', 'de', 'Zugang', null, str_repeat($character, 251));

        self::assertSame(str_repeat($character, 250), $remarks);
        self::assertTrue(mb_check_encoding($remarks, 'UTF-8'));
    }

    #[DataProvider('multibyteCharacterProvider')]
    public function testUpdateLangEntryCutsTheRemarkToExactly250CharactersOfValidUtf8(string $character): void
    {
        $quoted_texts = [];
        $db = $this->createMock(ilDBInterface::class);
        $db->method('quote')->willReturnCallback(
            function ($value, string $type) use (&$quoted_texts): string {
                $quoted_texts[] = $value;
                return "'" . $value . "'";
            }
        );
        $GLOBALS['DIC'] = new \ILIAS\DI\Container();
        $GLOBALS['DIC']['ilDB'] = $db;

        ilObjLanguage::updateLangEntry('common', 'access', 'de', 'Zugang', null, str_repeat($character, 251));

        $remarks = array_values(array_filter($quoted_texts, static fn($v): bool => is_string($v) && str_starts_with($v, $character)));
        self::assertSame([str_repeat($character, 250)], $remarks);
        self::assertTrue(mb_check_encoding($remarks[0], 'UTF-8'));
    }
}

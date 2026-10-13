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

class ilLanguageFileTest extends ilLanguageBaseTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_HTTP_PATH')) {
            define('ILIAS_HTTP_PATH', 'http://localhost');
        }
        if (!defined('ILIAS_VERSION')) {
            define('ILIAS_VERSION', '12.0');
        }

        $lng = $this->createStub(ilLanguage::class);
        $lng->separator = '#:#';
        $lng->comment_separator = '###';
        $this->setGlobalVariable('lng', $lng);

        $this->file = tempnam(sys_get_temp_dir(), 'ilLanguageFileTest');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        unset($GLOBALS['lng']);

        parent::tearDown();
    }

    public static function lineEndingProvider(): array
    {
        return [
            'LF' => ["\n"],
            'CRLF' => ["\r\n"],
        ];
    }

    #[DataProvider('lineEndingProvider')]
    public function testWriteWithTheReadHeaderKeepsTheFileUnchanged(string $eol): void
    {
        $header = implode($eol, [
            '/**',
            ' * ILIAS language file',
            ' *',
            " * @module\t\tlanguage file French",
            " * @created\t\t2023-10-31",
            ' */',
            '<!-- language file start -->',
        ]) . $eol;
        $original = $header . implode($eol, [
            'common#:#ok#:#OK',
            'content#:#cont_Link#:#Lien',
            'content#:#cont_link#:#Link###31 10 2023 new variable',
        ]);
        file_put_contents($this->file, $original);

        $file = new ilLanguageFile($this->file, 'fr');
        $this->assertTrue($file->read());
        $this->assertSame($header, $file->getHeader());

        $file->write($file->getHeader());

        $this->assertSame($original, file_get_contents($this->file));
    }

    public function testBuildAppendsChangedValuesDirectlyAfterTheHeader(): void
    {
        file_put_contents(
            $this->file,
            "/**\n */\n<!-- language file start -->\ncommon#:#ok#:#OK###remark"
        );
        $file = new ilLanguageFile($this->file, 'fr');
        $file->read();
        $file->setAllValues(['common#:#ok' => 'Okay', 'common#:#new' => 'Neu']);

        $this->assertSame(
            "/**\n */\n<!-- language file start -->\ncommon#:#ok#:#Okay###remark\ncommon#:#new#:#Neu",
            $file->build($file->getHeader())
        );
    }
}

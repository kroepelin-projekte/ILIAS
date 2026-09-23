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
 * ilLanguageFile::keepOriginalLines(): build()/write() emit the kept lines exactly as read() found
 * them - independent of values/comments set since, without the whitespace normalisation of every
 * other line. build() is called with a fixed header, so no header template is rendered.
 */
class ilLanguageFileTest extends ilLanguageBaseTestCase
{
    private const string HEADER = "HEADER\n<!-- language file start -->";

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ILIAS_HTTP_PATH' => 'http://localhost', 'ILIAS_VERSION' => 'test'] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
        $this->setGlobalVariable('lng', $this->createStub(ilLanguage::class));
        // Fetched by build() even when a fixed header is given
        $this->setGlobalVariable('ilUser', $this->createStub(ilObjUser::class));
        $this->file = sys_get_temp_dir() . '/ilias_lang_file_test_' . bin2hex(random_bytes(4)) . '.lang';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $lines
     */
    private function read(array $lines): ilLanguageFile
    {
        file_put_contents($this->file, self::HEADER . "\n" . implode("\n", $lines) . "\n");
        $language_file = new ilLanguageFile($this->file, 'zz');
        $this->assertTrue($language_file->read(), $language_file->getErrorMessage());

        return $language_file;
    }

    /**
     * @return list<string>
     */
    private static function builtLines(ilLanguageFile $language_file): array
    {
        return explode("\n", substr($language_file->build(self::HEADER), strlen(self::HEADER) + 1));
    }

    public function testWithoutKeptLinesEveryLineIsNormalisedAsBefore(): void
    {
        $language_file = $this->read(["a#:#x#:#Wert  ", "\tb#:#y#:#Zwei###Kommentar "]);

        $this->assertSame(['a#:#x#:#Wert', 'b#:#y#:#Zwei###Kommentar'], self::builtLines($language_file));
    }

    /**
     * A kept line keeps its surrounding whitespace byte for byte, the other lines are normalised.
     */
    public function testAKeptLineIsWrittenExactlyAsReadIncludingSurroundingWhitespace(): void
    {
        $language_file = $this->read(["a#:#x#:#Wert  \t", " b#:#y#:#Zwei###Kommentar ", 'c#:#z#:#Drei  ']);

        $language_file->keepOriginalLines(['a#:#x', 'b#:#y']);

        $this->assertSame(
            ["a#:#x#:#Wert  \t", " b#:#y#:#Zwei###Kommentar ", 'c#:#z#:#Drei'],
            self::builtLines($language_file)
        );
    }

    /**
     * Values and comments set after read() do not change a kept line - also one without surrounding
     * whitespace (rebuilt from the values/comments as read) - while they do change the others.
     */
    public function testAKeptLineIgnoresValuesAndCommentsSetAfterReading(): void
    {
        $language_file = $this->read(['a#:#x#:#Wert###Kommentar', 'b#:#y#:#Zwei', 'c#:#z#:#Drei']);
        $language_file->keepOriginalLines(['a#:#x', 'b#:#y']);

        $language_file->setAllValues(['a#:#x' => 'Neu', 'b#:#y' => 'Neu', 'c#:#z' => 'Neu']);
        $language_file->setAllComments(['b#:#y' => 'neuer Kommentar', 'c#:#z' => 'neuer Kommentar']);

        $this->assertSame(
            ['a#:#x#:#Wert###Kommentar', 'b#:#y#:#Zwei', 'c#:#z#:#Neu###neuer Kommentar'],
            self::builtLines($language_file)
        );
    }

    /**
     * Only lines read from the file can be kept; the position of a kept line follows the order of
     * the values set, and a kept key no longer among the values is not written.
     */
    public function testUnknownKeysAreIgnoredAndTheValuesSetDetermineOrderAndPresence(): void
    {
        $language_file = $this->read(['a#:#x#:#Eins ', 'b#:#y#:#Zwei ', 'c#:#z#:#Drei ']);
        $language_file->keepOriginalLines(['a#:#x', 'c#:#z', 'd#:#new']);

        $language_file->setAllValues(['c#:#z' => 'Neu', 'd#:#new' => 'Neu', 'b#:#y' => 'Neu']);

        $this->assertSame(['c#:#z#:#Drei ', 'd#:#new#:#Neu', 'b#:#y#:#Neu'], self::builtLines($language_file));
    }

    /**
     * read() starts over: lines kept before are not kept for the newly read content.
     */
    public function testReadingAgainForgetsTheKeptLines(): void
    {
        $language_file = $this->read(['a#:#x#:#Eins ']);
        $language_file->keepOriginalLines(['a#:#x']);

        file_put_contents($this->file, self::HEADER . "\na#:#x#:#Zwei  \n");
        $this->assertTrue($language_file->read());

        $this->assertSame(['a#:#x#:#Zwei'], self::builtLines($language_file));
    }

    /**
     * A kept line is written as read also by write() - with Windows line endings in the file the
     * line itself (without its line break) is kept.
     */
    public function testWriteEmitsTheKeptLineAsReadWithoutItsLineBreak(): void
    {
        file_put_contents($this->file, self::HEADER . "\r\na#:#x#:#Eins \r\nb#:#y#:#Zwei \r\n");
        $language_file = new ilLanguageFile($this->file, 'zz');
        $this->assertTrue($language_file->read());
        $language_file->keepOriginalLines(['a#:#x']);

        $language_file->write(self::HEADER);

        $this->assertSame(self::HEADER . "\na#:#x#:#Eins \nb#:#y#:#Zwei", file_get_contents($this->file));
    }
}

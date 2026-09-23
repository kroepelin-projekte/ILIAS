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

namespace ILIAS\Language\Tests\ComponentTranslation\Gettext;

use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\Gettext\Entry;
use ILIAS\Language\ComponentTranslation\Gettext\PoParser;
use ILIAS\Language\ComponentTranslation\Gettext\PoWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PoParser/PoWriter: the component's own `.po` reader and writer. The central property is the round
 * trip - the overlay's bookkeeping lives in `.po` comments, so everything written must be read back
 * exactly (byte for byte for values and comments).
 */
class PoParserWriterTest extends TestCase
{
    private static function roundTrip(Catalog $catalog): Catalog
    {
        return PoParser::parseString(PoWriter::toString($catalog));
    }

    private static function singleEntryCatalog(Entry $entry): Catalog
    {
        $catalog = new Catalog();
        $catalog->add($entry);
        return $catalog;
    }

    #[DataProvider('values')]
    public function testValuesRoundTripExactly(string $value): void
    {
        $entry = new Entry('mod', 'id');
        $entry->translate($value);

        $read = self::roundTrip(self::singleEntryCatalog($entry))->find('mod', 'id');

        $this->assertNotNull($read);
        $this->assertSame($value, $read->getTranslation());
    }

    public static function values(): array
    {
        return [
            'empty' => [''],
            'plain' => ['Hallo'],
            'double quote' => ['Klick "hier"'],
            'backslash' => ['C:\\temp\\'],
            'backslash before quote' => ['a\\"b'],
            'only a backslash' => ['\\'],
            'tab and carriage return' => ["a\tb\rc"],
            'multiline' => ["Zeile 1\nZeile 2\nZeile 3"],
            'trailing newline' => ["Zeile\n"],
            'only newlines' => ["\n\n"],
            'leading and trailing spaces' => ['  Hallo  '],
            'unicode' => ['Ünïcödé – 日本語 🙂'],
            'placeholder and html' => ['<a href="%1$s">%s</a>'],
            'literal escape sequence text' => ['\\n is not a newline'],
            'hash at start' => ['# not a comment'],
            'msgid-like text' => ["msgid \"x\"\nmsgstr \"y\""],
        ];
    }

    public function testIdentifierAndContextRoundTripIncludingSpecialCharacters(): void
    {
        $entry = new Entry("ctx \"q\"", "id\\with\nnewline");
        $entry->translate('v');

        $read = self::roundTrip(self::singleEntryCatalog($entry));

        $this->assertNotNull($read->find("ctx \"q\"", "id\\with\nnewline"));
    }

    public function testAnEntryWithoutContextIsDistinctFromOneWithAnEmptyContext(): void
    {
        $without = new Entry(null, 'id');
        $without->translate('ohne');
        $empty = new Entry('', 'id');
        $empty->translate('leer');
        $catalog = new Catalog();
        $catalog->add($without);
        $catalog->add($empty);

        $read = self::roundTrip($catalog);

        $this->assertSame('ohne', $read->find(null, 'id')->getTranslation());
        $this->assertSame('leer', $read->find('', 'id')->getTranslation());
    }

    /**
     * "original: ..." comments are compared with the value byte for byte - surrounding whitespace
     * must survive (the parser must not trim comment text).
     */
    public function testCommentsFlagsAndReferencesRoundTripIncludingSurroundingWhitespace(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->translate('v');
        $entry->addTranslatorComment('original:  Hallo  ');
        $entry->addTranslatorComment('');
        $entry->addTranslatorComment(' leading space');
        $entry->addTranslatorComment('.looks like an extracted marker');
        $entry->addExtractedComment('  extracted  ');
        $entry->addReference('file.php:12');
        $entry->addFlag('fuzzy');
        $entry->addFlag('php-format');

        $read = self::roundTrip(self::singleEntryCatalog($entry))->find('mod', 'id');

        $this->assertSame(
            ['original:  Hallo  ', '', ' leading space', '.looks like an extracted marker'],
            $read->getTranslatorComments()
        );
        $this->assertSame(['  extracted  '], $read->getExtractedComments());
        $this->assertSame(['file.php:12'], $read->getReferences());
        $this->assertSame(['fuzzy', 'php-format'], $read->getFlags());
    }

    public function testANewlineInACommentIsWrittenAsASpaceSoItCannotCorruptTheFile(): void
    {
        $entry = new Entry('mod', 'id');
        $entry->translate('v');
        $entry->addTranslatorComment("eins\nzwei\r\ndrei");

        $read = self::roundTrip(self::singleEntryCatalog($entry))->find('mod', 'id');

        $this->assertSame(['eins zwei drei'], $read->getTranslatorComments());
    }

    public function testPluralFormsRoundTrip(): void
    {
        $entry = new Entry('mod', 'item');
        $entry->setPlural('items');
        $entry->translate('%d Eintrag');
        $entry->setPluralTranslation(1, '%d Einträge');
        $entry->setPluralTranslation(2, '%d Einträgen');

        $po = PoWriter::toString(self::singleEntryCatalog($entry));
        $read = PoParser::parseString($po)->find('mod', 'item');

        $this->assertStringContainsString("msgstr[0] \"%d Eintrag\"\nmsgstr[1] \"%d Einträge\"\nmsgstr[2] \"%d Einträgen\"", $po);
        $this->assertSame('items', $read->getPlural());
        $this->assertSame('%d Eintrag', $read->getTranslation());
        $this->assertSame(['%d Einträge', '%d Einträgen'], $read->getPluralTranslations());
    }

    /**
     * A missing plural form is written as an empty msgstr[n] so every index up to the highest one
     * is present (a gap would shift later forms when read back by other tools).
     */
    public function testPluralFormsWithAGapAreWrittenWithoutGap(): void
    {
        $entry = new Entry('mod', 'item');
        $entry->setPlural('items');
        $entry->translate('ein');
        $entry->setPluralTranslation(3, 'drei');

        $po = PoWriter::toString(self::singleEntryCatalog($entry));
        $read = PoParser::parseString($po)->find('mod', 'item');

        $this->assertStringContainsString("msgstr[0] \"ein\"\nmsgstr[1] \"\"\nmsgstr[2] \"\"\nmsgstr[3] \"drei\"\n", $po);
        $this->assertSame([0 => '', 1 => '', 2 => 'drei'], $read->getPluralTranslations());
    }

    public function testPluralFormsInAFileOutOfOrderAreReadByIndex(): void
    {
        $po = "msgid \"item\"\nmsgid_plural \"items\"\nmsgstr[2] \"zwei\"\nmsgstr[0] \"null\"\nmsgstr[1] \"eins\"\n";

        $read = PoParser::parseString($po)->find(null, 'item');

        $this->assertSame('null', $read->getTranslation());
        $this->assertSame([0 => 'eins', 1 => 'zwei'], $read->getPluralTranslations());
        $this->assertStringContainsString(
            "msgstr[0] \"null\"\nmsgstr[1] \"eins\"\nmsgstr[2] \"zwei\"",
            PoWriter::toString(PoParser::parseString($po))
        );
    }

    public function testAPluralEntryWithoutPluralTranslationsWritesOnlyMsgstr0(): void
    {
        $entry = new Entry('mod', 'item');
        $entry->setPlural('items');
        $entry->translate('ein');

        $po = PoWriter::toString(self::singleEntryCatalog($entry));

        $this->assertStringContainsString("msgid_plural \"items\"\nmsgstr[0] \"ein\"\n", $po);
        $this->assertStringNotContainsString('msgstr[1]', $po);
    }

    public function testHeadersHeaderCommentsAndHeaderFlagsRoundTripWithHeadersSortedByName(): void
    {
        $catalog = new Catalog();
        $catalog->addHeaderComment('Translation of ILIAS');
        $catalog->addHeaderFlag('fuzzy');
        $catalog->setHeader('X-Domain', 'tos');
        $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $catalog->setHeader('Language', 'de');

        $po = PoWriter::toString($catalog);
        $read = PoParser::parseString($po);

        $this->assertSame(
            "# Translation of ILIAS\n#, fuzzy\nmsgid \"\"\nmsgstr \"\"\n"
            . "\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: de\\n\"\n\"X-Domain: tos\\n\"\n",
            $po
        );
        $this->assertSame(['Content-Type' => 'text/plain; charset=UTF-8', 'Language' => 'de', 'X-Domain' => 'tos'], $read->getHeaders());
        $this->assertSame(['Translation of ILIAS'], $read->getHeaderComments());
        $this->assertSame(['fuzzy'], $read->getHeaderFlags());
        $this->assertSame([], $read->getEntries(), 'the header is not an entry');
    }

    public function testTheWriterLayoutMatchesTheShippedFiles(): void
    {
        $catalog = new Catalog();
        $catalog->setHeader('Language', 'de');
        $entry = new Entry('tos', 'tos_agreement');
        $entry->translate("Zeile 1\nZeile 2");
        $catalog->add($entry);

        $this->assertSame(
            "msgid \"\"\nmsgstr \"\"\n\"Language: de\\n\"\n\n"
            . "msgctxt \"tos\"\nmsgid \"tos_agreement\"\nmsgstr \"\"\n\"Zeile 1\\n\"\n\"Zeile 2\"\n",
            PoWriter::toString($catalog)
        );
    }

    /**
     * Property: every shipped `.po`/`.pot` of the migrated tos module re-serializes byte-identically
     * (convert_module_to_po.php and the overlay rely on the parser/writer pair being lossless).
     */
    public function testEveryShippedTosFileRoundTripsByteIdentically(): void
    {
        $files = glob(__DIR__ . '/../../../../TermsOfService/lang/*.{po,pot}', GLOB_BRACE) ?: [];
        if ($files === []) {
            $this->markTestSkipped('No shipped tos .po files found.');
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertSame($content, PoWriter::toString(PoParser::parseString($content)), basename($file));
        }
    }

    public function testParsesMultilineStringsCrLfLineEndingsAndABom(): void
    {
        $po = "\xEF\xBB\xBFmsgid \"\"\r\nmsgstr \"\"\r\n\"Language: de\\n\"\r\n\r\n"
            . "msgctxt \"mod\"\r\nmsgid \"id\"\r\nmsgstr \"\"\r\n\"eins \"\r\n  \"zwei\"   \r\n";

        $catalog = PoParser::parseString($po);

        $this->assertSame('de', $catalog->getHeader('Language'));
        $this->assertSame('eins zwei', $catalog->find('mod', 'id')->getTranslation());
    }

    public function testDecodesOctalHexAndControlEscapes(): void
    {
        $catalog = PoParser::parseString("msgid \"id\"\nmsgstr \"\\101\\x42\\a\\b\\f\\v\\q\"\n");

        $this->assertSame("AB\x07\x08\x0c\x0bq", $catalog->find(null, 'id')->getTranslation());
    }

    public function testIgnoresObsoleteAndPreviousMessageLines(): void
    {
        $po = "#| msgid \"old\"\nmsgid \"id\"\nmsgstr \"v\"\n\n#~ msgid \"gone\"\n#~ msgstr \"weg\"\n";

        $catalog = PoParser::parseString($po);

        $this->assertCount(1, $catalog->getEntries());
        $this->assertSame([], $catalog->find(null, 'id')->getTranslatorComments());
        $this->assertNull($catalog->find(null, 'gone'));
    }

    /**
     * Messages not separated by a blank line are still split: a comment or a new msgctxt/msgid after
     * a msgstr starts the next message.
     */
    public function testSplitsMessagesThatAreNotSeparatedByABlankLine(): void
    {
        $po = "msgid \"a\"\nmsgstr \"A\"\n# comment of b\nmsgid \"b\"\nmsgstr \"B\"\nmsgctxt \"c\"\nmsgid \"c\"\nmsgstr \"C\"\n";

        $catalog = PoParser::parseString($po);

        $this->assertSame('A', $catalog->find(null, 'a')->getTranslation());
        $this->assertSame(['comment of b'], $catalog->find(null, 'b')->getTranslatorComments());
        $this->assertSame('C', $catalog->find('c', 'c')->getTranslation());
    }

    public function testALaterDuplicateReplacesTheEarlierEntry(): void
    {
        $catalog = PoParser::parseString("msgid \"a\"\nmsgstr \"first\"\n\nmsgid \"a\"\nmsgstr \"second\"\n");

        $this->assertCount(1, $catalog->getEntries());
        $this->assertSame('second', $catalog->find(null, 'a')->getTranslation());
    }

    public function testAnEmptyStringYieldsAnEmptyCatalog(): void
    {
        $catalog = PoParser::parseString('');

        $this->assertSame([], $catalog->getEntries());
        $this->assertSame([], $catalog->getHeaders());
    }

    #[DataProvider('brokenPo')]
    public function testThrowsForSyntacticallyBrokenContent(string $po): void
    {
        $this->expectException(RuntimeException::class);
        PoParser::parseString($po);
    }

    public static function brokenPo(): array
    {
        return [
            'unterminated string' => ["msgid \"abc\nmsgstr \"\"\n"],
            'escaped closing quote' => ["msgid \"abc\\\"\nmsgstr \"\"\n"],
            'unquoted value' => ["msgid abc\nmsgstr \"\"\n"],
            'unknown keyword' => ["msgfoo \"abc\"\n"],
            'keyword without value' => ["msgid\n"],
            'continuation without keyword' => ["\"dangling\"\n"],
            'text after the closing quote' => ["msgid \"a\" trailing\nmsgstr \"\"\n"],
            'only a quote' => ["msgid \"\nmsgstr \"\"\n"],
        ];
    }

    public function testTheLineNumberOfABrokenLineIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/line 3/');
        PoParser::parseString("msgid \"a\"\nmsgstr \"A\"\nbroken\n");
    }

    public function testParseFileThrowsForAMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read PO file/');
        PoParser::parseFile(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4)) . '.po');
    }

    public function testParseFileThrowsForADirectory(): void
    {
        $this->expectException(RuntimeException::class);
        PoParser::parseFile(sys_get_temp_dir());
    }
}

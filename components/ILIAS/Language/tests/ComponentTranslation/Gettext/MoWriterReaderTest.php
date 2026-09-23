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
use ILIAS\Language\ComponentTranslation\Gettext\MoReader;
use ILIAS\Language\ComponentTranslation\Gettext\MoWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MoWriter/MoReader: the binary `.mo` the runtime (ilLanguage::txt()) reads. A corrupt file must
 * surface as RuntimeException (ilLanguage then falls back to the database), never as garbage.
 */
class MoWriterReaderTest extends TestCase
{
    private static function entry(?string $context, string $id, string $translation): Entry
    {
        $entry = new Entry($context, $id);
        $entry->translate($translation);
        return $entry;
    }

    private static function sampleCatalog(): Catalog
    {
        $catalog = new Catalog();
        $catalog->setHeader('Language', 'de');
        $catalog->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
        $catalog->add(self::entry('mod', 'zeta', 'Z'));
        $catalog->add(self::entry('mod', 'alpha', "Mehr\nzeilig"));
        $catalog->add(self::entry(null, 'no_context', 'ohne Kontext'));
        $fuzzy = self::entry('mod', 'fuzzy', 'Hello');
        $fuzzy->addFlag('fuzzy');
        $catalog->add($fuzzy);
        $catalog->add(self::entry('mod', 'untranslated', ''));
        $plural = self::entry('mod', 'item', '%d Eintrag');
        $plural->setPlural('items');
        $plural->setPluralTranslation(1, '%d Einträge');
        $catalog->add($plural);
        return $catalog;
    }

    /**
     * Rewrites a little endian `.mo` as big endian (header and both index tables are 32-bit words).
     */
    private static function toBigEndian(string $mo): string
    {
        $header = unpack('V7', substr($mo, 0, 28));
        $words = 7 + 4 * $header[3];
        $swapped = '';
        foreach (unpack('V' . $words, substr($mo, 0, $words * 4)) as $word) {
            $swapped .= pack('N', $word);
        }
        return $swapped . substr($mo, $words * 4);
    }

    public function testWrittenMessagesAreReadBackRawWithContextPluralAndHeader(): void
    {
        $messages = MoReader::parseString(MoWriter::toString(self::sampleCatalog()));

        $this->assertSame(
            [
                '' => "Language: de\nPlural-Forms: nplurals=2; plural=(n != 1);",
                "mod\x04alpha" => "Mehr\nzeilig",
                "mod\x04fuzzy" => 'Hello',
                "mod\x04item\x00items" => "%d Eintrag\x00%d Einträge",
                "mod\x04zeta" => 'Z',
                'no_context' => 'ohne Kontext',
            ],
            $messages
        );
    }

    /**
     * Plural forms are stored positionally ("\0"-separated); a missing form becomes an empty
     * string so later forms keep their position.
     */
    public function testPluralFormsWithAGapKeepTheirPositionInTheMo(): void
    {
        $catalog = new Catalog();
        $entry = self::entry('mod', 'item', 'ein');
        $entry->setPlural('items');
        $entry->setPluralTranslation(3, 'drei');
        $entry->setPluralTranslation(1, 'eins');
        $catalog->add($entry);
        $plain = self::entry('mod', 'single', 'nur singular');
        $plain->setPlural('singles');
        $catalog->add($plain);

        $this->assertSame(
            [
                "mod\x04item\x00items" => "ein\x00eins\x00\x00drei",
                "mod\x04single\x00singles" => 'nur singular',
            ],
            MoReader::parseString(MoWriter::toString($catalog))
        );
    }

    /**
     * gettext looks messages up by binary search: the originals must be sorted byte-wise.
     */
    public function testOriginalsAreSortedByteWise(): void
    {
        $catalog = new Catalog();
        foreach (['b', 'B', 'a', '10', '9'] as $id) {
            $catalog->add(self::entry(null, $id, 'x'));
        }

        $this->assertSame(['10', '9', 'B', 'a', 'b'], array_map('strval', array_keys(MoReader::parseString(MoWriter::toString($catalog)))));
    }

    public function testReadTranslationsDropsContextHeaderAndPluralForms(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mo');
        file_put_contents($file, MoWriter::toString(self::sampleCatalog()));
        try {
            $this->assertSame(
                [
                    'alpha' => "Mehr\nzeilig",
                    'fuzzy' => 'Hello',
                    'item' => '%d Eintrag',
                    'zeta' => 'Z',
                    'no_context' => 'ohne Kontext',
                ],
                MoReader::readTranslations($file)
            );
        } finally {
            unlink($file);
        }
    }

    public function testAnEmptyCatalogProducesAValidMoWithoutMessages(): void
    {
        $mo = MoWriter::toString(new Catalog());

        $this->assertSame(28, strlen($mo));
        $this->assertSame([], MoReader::parseString($mo));
    }

    public function testReadsABigEndianMoFile(): void
    {
        $little = MoWriter::toString(self::sampleCatalog());
        $big = self::toBigEndian($little);

        $this->assertNotSame($little, $big);
        $this->assertSame(MoReader::parseString($little), MoReader::parseString($big));
    }

    #[DataProvider('corruptMo')]
    public function testThrowsForACorruptMo(\Closure $corrupt, string $reason): void
    {
        $mo = $corrupt(MoWriter::toString(self::sampleCatalog()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');
        MoReader::parseString($mo);
    }

    public static function corruptMo(): array
    {
        return [
            'empty' => [static fn(string $mo): string => '', 'too short'],
            'one byte short of the header' => [static fn(string $mo): string => substr($mo, 0, 27), 'too short'],
            'bad magic' => [static fn(string $mo): string => "\x00\x00\x00\x00" . substr($mo, 4), 'bad magic'],
            'text file' => [static fn(string $mo): string => str_repeat('msgid "x"', 10), 'bad magic'],
            'count beyond file size' => [
                static fn(string $mo): string => substr($mo, 0, 8) . pack('V', 0x7fffffff) . substr($mo, 12),
                'index out of bounds',
            ],
            'translation index beyond file size' => [
                static fn(string $mo): string => substr($mo, 0, 16) . pack('V', strlen($mo)) . substr($mo, 20),
                'index out of bounds',
            ],
            // the last byte is the NUL terminator, which is not part of the stored length
            'truncated string table' => [static fn(string $mo): string => substr($mo, 0, -2), 'string out of bounds'],
            // entry 0 is the header ("", length 0) - use entry 1's offset
            'string offset beyond file size' => [
                static fn(string $mo): string => substr($mo, 0, 40) . pack('V', strlen($mo)) . substr($mo, 44),
                'string out of bounds',
            ],
        ];
    }

    /**
     * Boundary: a string that ends exactly at the end of the file is valid (> not >=).
     */
    public function testAStringEndingExactlyAtTheEndOfTheFileIsValid(): void
    {
        $catalog = new Catalog();
        $catalog->add(self::entry(null, 'id', 'last'));
        $mo = rtrim(MoWriter::toString($catalog), "\x00");

        $this->assertSame(['id' => 'last'], MoReader::parseString($mo));
    }

    public function testReadingAMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read MO file/');
        MoReader::readTranslations(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)) . '.mo');
    }

    /**
     * Compatibility with the reference implementation, only where gettext/gettext happens to be
     * installed (it is not a dependency of this component any more).
     */
    public function testIsCompatibleWithGettextGettextIfInstalled(): void
    {
        if (!class_exists(\Gettext\Loader\MoLoader::class) || !class_exists(\Gettext\Generator\MoGenerator::class)) {
            $this->markTestSkipped('gettext/gettext is not installed.');
        }

        $ours = MoWriter::toString(self::sampleCatalog());
        $loaded = (new \Gettext\Loader\MoLoader())->loadString($ours);
        $this->assertSame('Z', $loaded->find('mod', 'zeta')?->getTranslation());
        $this->assertSame("Mehr\nzeilig", $loaded->find('mod', 'alpha')?->getTranslation());
        $this->assertSame(['%d Einträge'], $loaded->find('mod', 'item')?->getPluralTranslations());
        $this->assertSame('ohne Kontext', $loaded->find(null, 'no_context')?->getTranslation());

        $translations = \Gettext\Translations::create('mod', 'de');
        $translations->add(\Gettext\Translation::create('mod', 'greeting')->translate('Hallo'));
        $translations->add(\Gettext\Translation::create(null, 'plain')->translate('Einfach'));
        $theirs = (new \Gettext\Generator\MoGenerator())->includeHeaders(true)->generateString($translations);
        $file = tempnam(sys_get_temp_dir(), 'mo');
        file_put_contents($file, $theirs);
        try {
            $this->assertSame(['greeting' => 'Hallo', 'plain' => 'Einfach'], MoReader::readTranslations($file));
        } finally {
            unlink($file);
        }

        // same catalog through both writers: byte-identical output
        $catalog = new Catalog();
        $catalog->setHeader('Language', 'de');
        $catalog->add(self::entry('mod', 'greeting', 'Hallo'));
        $catalog->add(self::entry(null, 'plain', 'Einfach'));
        $reference = \Gettext\Translations::create();
        $reference->getHeaders()->set('Language', 'de');
        $reference->add(\Gettext\Translation::create('mod', 'greeting')->translate('Hallo'));
        $reference->add(\Gettext\Translation::create(null, 'plain')->translate('Einfach'));
        $this->assertSame(
            bin2hex((new \Gettext\Generator\MoGenerator())->includeHeaders(true)->generateString($reference)),
            bin2hex(MoWriter::toString($catalog))
        );
    }
}

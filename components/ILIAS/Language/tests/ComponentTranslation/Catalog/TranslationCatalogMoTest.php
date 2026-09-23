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

namespace ILIAS\Language\Tests\ComponentTranslation\Catalog;

use Gettext\Translations;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * TranslationCatalog's `.mo` side: toMoString() (what MigratedLanguageFileSync writes and compares
 * byte for byte) and readMoTranslations() (what ilLanguage::txt() is served from). A corrupt file
 * must surface as RuntimeException - ilLanguage then falls back to the database - never as silently
 * shortened data, a PHP warning or a TypeError, which is what MoLoader alone produces for several
 * of the corruptions below.
 */
class TranslationCatalogMoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ilias_translation_catalog_mo_' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775);
    }

    protected function tearDown(): void
    {
        \MigratedPoFixture::removeDirectory($this->directory);
    }

    private static function entry(?string $context, string $id, string $translation): TranslationEntry
    {
        $entry = new TranslationEntry($context, $id);
        $entry->translate($translation);

        return $entry;
    }

    private static function sampleCatalog(): TranslationCatalog
    {
        $catalog = new TranslationCatalog();
        $catalog->setHeader('Language', 'de');
        $catalog->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
        $catalog->add(self::entry('mod', 'zeta', 'Z'));
        $catalog->add(self::entry('mod', 'alpha', "Mehr\nzeilig"));
        $catalog->add(self::entry(null, 'no_context', 'ohne Kontext'));
        $catalog->add(self::entry('mod', 'zero', '0'));
        $fuzzy = self::entry('mod', 'fuzzy', 'Hello');
        $fuzzy->addFlag('fuzzy');
        $catalog->add($fuzzy);
        $catalog->add(self::entry('mod', 'untranslated', ''));

        return $catalog;
    }

    /**
     * @return array<string, string>
     */
    private function read(string $mo): array
    {
        $file = $this->directory . '/test.mo';
        file_put_contents($file, $mo);

        return TranslationCatalog::readMoTranslations($file);
    }

    /**
     * The originals of a little endian `.mo`, in file order (independent of the code under test).
     *
     * @return list<string>
     */
    private static function originals(string $mo): array
    {
        ['count' => $count, 'offset' => $table] = unpack('Vcount/Voffset', $mo, 8);
        $originals = [];
        for ($i = 0; $i < $count; $i++) {
            ['length' => $length, 'offset' => $offset] = unpack('Vlength/Voffset', $mo, $table + $i * 8);
            $originals[] = substr($mo, $offset, $length);
        }

        return $originals;
    }

    // ------------------------------------------------------------- content

    /**
     * Fuzzy messages are compiled (unlike msgfmt's default - "fuzzy" marks an unreviewed legacy
     * value, leaving it out would show "-identifier-"), untranslated ones are left out, the context
     * is dropped and "0" is served as "0".
     */
    public function testReadMoTranslationsServesEveryTranslatedMessageByIdentifier(): void
    {
        $this->assertSame(
            [
                'alpha' => "Mehr\nzeilig",
                'fuzzy' => 'Hello',
                'zero' => '0',
                'zeta' => 'Z',
                'no_context' => 'ohne Kontext',
            ],
            $this->read(self::sampleCatalog()->toMoString())
        );
    }

    /**
     * msgfmt-compatible layout: the header message ("" => headers) comes first, the originals are
     * sorted byte-wise (C gettext looks them up by binary search), untranslated messages are absent.
     */
    public function testTheOriginalsAreTheHeaderAndTheTranslatedMessagesSortedByteWise(): void
    {
        $catalog = new TranslationCatalog();
        foreach (['b', 'B', 'a', '10', '9'] as $id) {
            $catalog->add(self::entry(null, $id, 'x'));
        }
        $catalog->add(self::entry('mod', 'a', 'x'));
        $catalog->add(self::entry('mod', 'empty', ''));

        $this->assertSame(['', '10', '9', 'B', 'a', 'b', "mod\x04a"], self::originals($catalog->toMoString()));
    }

    /**
     * The "0" workaround stores "0" plus its NUL; C gettext and readMoTranslations() end the string
     * there. The next message must not be affected.
     */
    public function testTheValueZeroDoesNotAffectTheFollowingMessage(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(self::entry('mod', 'a', '0'));
        $catalog->add(self::entry('mod', 'b', 'bee'));

        $this->assertSame(['a' => '0', 'b' => 'bee'], $this->read($catalog->toMoString()));
    }

    public function testNumericIdentifiersAreServed(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add(self::entry('mod', '0', 'null'));
        $catalog->add(self::entry('mod', '10', 'zehn'));

        $translations = $this->read($catalog->toMoString());

        $this->assertSame('null', $translations['0'] ?? null);
        $this->assertSame('zehn', $translations['10'] ?? null);
        $this->assertCount(2, $translations);
    }

    /**
     * Only the singular translation is served; a plural message's other forms are dropped.
     */
    public function testReadMoTranslationsServesTheSingularOfAPluralMessage(): void
    {
        $catalog = TranslationCatalog::fromPoString(
            "msgid \"\"\nmsgstr \"\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\n"
            . "msgctxt \"mod\"\nmsgid \"item\"\nmsgid_plural \"items\"\nmsgstr[0] \"Eintrag\"\nmsgstr[1] \"Einträge\"\n"
        );

        $mo = $catalog->toMoString();

        $this->assertSame(['item' => 'Eintrag'], $this->read($mo));
        $this->assertSame(['', "mod\x04item\x00items"], self::originals($mo));
        $this->assertStringContainsString("Eintrag\x00Einträge\x00", $mo);
    }

    /**
     * In a plural message the NUL of the "0" workaround would become an additional, empty plural
     * form - compiling it throws instead (ILIAS itself uses no plurals).
     */
    public function testASingularTranslationZeroOfAPluralMessageCannotBeCompiled(): void
    {
        $catalog = TranslationCatalog::fromPoString("msgid \"a\"\nmsgid_plural \"as\"\nmsgstr[0] \"0\"\nmsgstr[1] \"1\"\n");

        $this->expectException(RuntimeException::class);
        $catalog->toMoString();
    }

    public function testAnEmptyCatalogCompilesToAValidMoWithoutMessages(): void
    {
        $mo = (new TranslationCatalog())->toMoString();

        $this->assertSame([''], self::originals($mo), 'only the (empty) header');
        $this->assertSame([], $this->read($mo));
    }

    /**
     * MigratedLanguageFileSync::sync() decides by byte comparison whether the `.mo` is current - so
     * the same content must always compile to the same bytes, independent of the insertion order
     * and of how often it was compiled.
     */
    public function testToMoStringIsDeterministic(): void
    {
        $build = static function (array $ids): TranslationCatalog {
            $catalog = new TranslationCatalog();
            $catalog->setHeader('Language', 'de');
            foreach ($ids as $id) {
                $catalog->add(self::entry('mod', $id, $id === 'zero' ? '0' : 'Wert ' . $id));
            }
            return $catalog;
        };
        $catalog = $build(['b', 'zero', 'a', '10', '9', 'B']);

        $mo = $catalog->toMoString();

        $this->assertSame($mo, $catalog->toMoString());
        $this->assertSame($mo, $build(['b', 'zero', 'a', '10', '9', 'B'])->toMoString());
        $this->assertSame($mo, $build(['9', 'B', 'zero', '10', 'a', 'b'])->toMoString());
        $this->assertSame($mo, TranslationCatalog::fromPoString($catalog->toPoString())->toMoString());
        $this->assertNotSame($mo, $build(['b', 'zero', 'a', '10', '9'])->toMoString());
    }

    /**
     * The `.mo` content depends on everything ilLanguage serves - a changed value must produce
     * different bytes, or the self-healing byte comparison of sync() would keep a stale `.mo`.
     */
    public function testAChangedValueChangesTheMo(): void
    {
        $catalog = self::sampleCatalog();
        $mo = $catalog->toMoString();

        $catalog->find('mod', 'zeta')?->translate('Y');

        $this->assertNotSame($mo, $catalog->toMoString());
    }

    public function testReadsABigEndianMo(): void
    {
        $little = self::sampleCatalog()->toMoString();
        $words = 7 + 4 * unpack('V', $little, 8)[1];
        $big = '';
        foreach (unpack('V' . $words, $little) as $word) {
            $big .= pack('N', $word);
        }
        $big .= substr($little, $words * 4);

        $this->assertNotSame($little, $big);
        $this->assertSame($this->read($little), $this->read($big));
    }

    // ------------------------------------------------------ corrupt files

    #[DataProvider('corruptMo')]
    public function testThrowsARuntimeExceptionForACorruptMo(\Closure $corrupt): void
    {
        $mo = $corrupt(self::sampleCatalog()->toMoString());

        $this->expectException(RuntimeException::class);
        $this->read($mo);
    }

    public static function corruptMo(): array
    {
        return [
            'empty' => [static fn(string $mo): string => ''],
            'garbage' => [static fn(string $mo): string => 'SENTINEL'],
            'long garbage' => [static fn(string $mo): string => str_repeat('msgid "x"', 10)],
            'a .po file' => [static fn(string $mo): string => self::sampleCatalog()->toPoString()],
            'bad magic' => [static fn(string $mo): string => "\x00\x00\x00\x00" . substr($mo, 4)],
            'truncated to 10 bytes' => [static fn(string $mo): string => substr($mo, 0, 10)],
            'one byte short of the header' => [static fn(string $mo): string => substr($mo, 0, 27)],
            'only the header' => [static fn(string $mo): string => substr($mo, 0, 28)],
            'truncated to 40 bytes' => [static fn(string $mo): string => substr($mo, 0, 40)],
            'truncated to half' => [static fn(string $mo): string => substr($mo, 0, intdiv(strlen($mo), 2))],
            'last 3 bytes missing' => [static fn(string $mo): string => substr($mo, 0, -3)],
            // The last byte is the NUL terminator, which is not part of the stored length
            'last 2 bytes missing' => [static fn(string $mo): string => substr($mo, 0, -2)],
            'count beyond the file size' => [
                static fn(string $mo): string => substr($mo, 0, 8) . pack('V', 0x7fffffff) . substr($mo, 12),
            ],
            'originals index beyond the file size' => [
                static fn(string $mo): string => substr($mo, 0, 12) . pack('V', 0xfffffff0) . substr($mo, 16),
            ],
            'translations index beyond the file size' => [
                static fn(string $mo): string => substr($mo, 0, 16) . pack('V', strlen($mo)) . substr($mo, 20),
            ],
            // Entry 0 is the header - use entry 1's offset
            'string offset beyond the file size' => [
                static fn(string $mo): string => substr($mo, 0, 40) . pack('V', strlen($mo)) . substr($mo, 44),
            ],
            'string length beyond the file size' => [
                static fn(string $mo): string => substr($mo, 0, 36) . pack('V', strlen($mo)) . substr($mo, 40),
            ],
        ];
    }

    /**
     * Boundary: a string that ends exactly at the end of the file is valid (> not >=) - only the
     * terminating NUL, which is not part of the stored length, is missing.
     */
    public function testAStringEndingExactlyAtTheEndOfTheFileIsValid(): void
    {
        $mo = self::sampleCatalog()->toMoString();

        $this->assertSame($this->read($mo), $this->read(substr($mo, 0, -1)));
    }

    public function testThrowsForAMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read MO file/');
        TranslationCatalog::readMoTranslations($this->directory . '/missing.mo');
    }

    public function testThrowsForADirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read MO file/');
        TranslationCatalog::readMoTranslations($this->directory);
    }

    public function testThrowsForAnUnreadableFile(): void
    {
        $file = $this->directory . '/unreadable.mo';
        file_put_contents($file, self::sampleCatalog()->toMoString());
        chmod($file, 0000);
        if (is_readable($file)) {
            $this->markTestSkipped('File permissions are not enforced for this user (root).');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Could not read MO file/');
        TranslationCatalog::readMoTranslations($file);
    }

    public function testTheErrorHandlerIsRestoredAfterReading(): void
    {
        $handler = static fn(): bool => false;
        set_error_handler($handler);
        try {
            $this->read(self::sampleCatalog()->toMoString());
            try {
                $this->read(substr(self::sampleCatalog()->toMoString(), 0, -3));
                $this->fail('Expected a RuntimeException');
            } catch (RuntimeException) {
            }

            $current = set_error_handler(null);
            restore_error_handler();
            $this->assertSame($handler, $current);
        } finally {
            restore_error_handler();
        }
    }

    // --------------------------------------------- library errors (private)

    /**
     * The library's PHP warnings/notices say "this file is broken" and become a RuntimeException.
     * The adapter's structural pre-checks keep MoLoader from ever raising one for a real file, so
     * this is exercised directly on the private helper.
     */
    #[DataProvider('libraryWarnings')]
    public function testLibraryWarningsAndNoticesBecomeRuntimeExceptions(int $level): void
    {
        $helper = new ReflectionMethod(TranslationCatalog::class, 'withLibraryErrorsAsExceptions');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('library message');
        $helper->invoke(null, static function () use ($level): Translations {
            trigger_error('library message', $level);
            return Translations::create();
        });
    }

    public static function libraryWarnings(): array
    {
        return [
            'warning' => [E_USER_WARNING],
            'notice' => [E_USER_NOTICE],
        ];
    }

    /**
     * A deprecation only says something about the library on this PHP version, not about the file:
     * the load succeeds and the deprecation goes to the error handler registered before (ILIAS' own
     * or, in a test run, PHPUnit's) - which is active again afterward.
     */
    public function testLibraryDeprecationsArePassedOnToThePreviousHandlerAndDoNotFailTheLoad(): void
    {
        $helper = new ReflectionMethod(TranslationCatalog::class, 'withLibraryErrorsAsExceptions');
        $received = [];
        $previous_handler = static function (int $severity, string $message, string $file, int $line) use (&$received): bool {
            $received[] = [$severity, $message, $file, $line];
            return true;
        };
        set_error_handler($previous_handler);
        try {
            $result = $helper->invoke(null, static function (): Translations {
                trigger_error('library deprecation', E_USER_DEPRECATED);
                return Translations::create()->setDomain('loaded');
            });
            $trigger_line = __LINE__ - 3;

            $current = set_error_handler(null);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(Translations::class, $result);
        $this->assertSame('loaded', $result->getDomain());
        $this->assertSame([[E_USER_DEPRECATED, 'library deprecation', __FILE__, $trigger_line]], $received);
        $this->assertSame($previous_handler, $current, 'the previous handler is restored');
    }

    /**
     * A previous handler that declines (returns false) leaves the deprecation to PHP's standard
     * handling (here: the error log) - still without failing the load.
     */
    public function testALibraryDeprecationDeclinedByThePreviousHandlerGoesToPhpsStandardHandling(): void
    {
        $helper = new ReflectionMethod(TranslationCatalog::class, 'withLibraryErrorsAsExceptions');
        $log = $this->directory . '/error.log';
        $ini = ['error_log' => $log, 'log_errors' => '1', 'display_errors' => '0'];
        $previous = [];
        foreach ($ini as $name => $value) {
            $previous[$name] = ini_set($name, $value);
        }
        $previous_level = error_reporting(E_ALL);
        $calls = 0;
        set_error_handler(static function () use (&$calls): bool {
            $calls++;
            return false;
        });
        try {
            $result = $helper->invoke(null, static function (): Translations {
                trigger_error('declined library deprecation', E_USER_DEPRECATED);
                return Translations::create()->setDomain('loaded');
            });
        } finally {
            restore_error_handler();
            error_reporting($previous_level);
            foreach ($previous as $name => $value) {
                ini_set($name, (string) $value);
            }
        }

        $this->assertSame('loaded', $result->getDomain());
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('declined library deprecation', (string) @file_get_contents($log));
    }

    /**
     * Passing deprecations on must not pass on warnings: those still become a RuntimeException and
     * never reach the previous handler.
     */
    public function testLibraryWarningsDoNotReachThePreviousHandler(): void
    {
        $helper = new ReflectionMethod(TranslationCatalog::class, 'withLibraryErrorsAsExceptions');
        $calls = 0;
        set_error_handler(static function () use (&$calls): bool {
            $calls++;
            return true;
        });
        try {
            $helper->invoke(null, static function (): Translations {
                trigger_error('library warning', E_USER_WARNING);
                return Translations::create();
            });
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('library warning', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, $calls);
    }

    public function testThrowablesOfTheLibraryBecomeRuntimeExceptions(): void
    {
        $helper = new ReflectionMethod(TranslationCatalog::class, 'withLibraryErrorsAsExceptions');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('type error');
        $helper->invoke(null, static function (): Translations {
            throw new \TypeError('type error');
        });
    }
}

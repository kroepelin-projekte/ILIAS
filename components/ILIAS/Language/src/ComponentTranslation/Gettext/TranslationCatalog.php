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

namespace ILIAS\Language\ComponentTranslation\Gettext;

use ErrorException;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\MoLoader;
use Gettext\Loader\StrictPoLoader;
use Gettext\Translation;
use Gettext\Translations;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * An in-memory gettext catalog (the header block of a `.po`/`.mo` file plus its messages) and the
 * only place of the language component that reads or writes the gettext file formats.
 *
 * Narrow adapter around the gettext/gettext library (Gettext\Translations, Loader\StrictPoLoader,
 * Loader\MoLoader, Generator\PoGenerator, Generator\MoGenerator): no other class of the component
 * imports anything from the Gettext namespace, so a change of the library version stays local to
 * this class and TranslationEntry. The library's Scanner part (extracting messages from source
 * code) is deliberately not used.
 *
 * On top of the library, this adapter guarantees what the component relies on:
 * - A `.po` is read with StrictPoLoader, which follows the rules of the GNU gettext tools and
 *   throws for a syntactically broken file instead of silently returning what it could guess (as
 *   the tolerant PoLoader does). Every failure - unreadable file, broken syntax, a PHP warning or
 *   error raised by the library - surfaces as a RuntimeException. Comment text is not trimmed, only
 *   the one space after the comment marker is removed. A leading UTF-8 byte order mark is skipped.
 *   Obsolete messages (`#~ ...`) are dropped on load - nothing in ILIAS writes or reads them.
 * - The value "0": PoGenerator and MoGenerator treat a translation as absent when it is falsy, so
 *   "0" would be written as an empty `msgstr` and left out of the `.mo`. Both generators are fed
 *   "0\0" instead: PoGenerator strips the NUL while encoding (the `.po` holds exactly `"0"`), the
 *   `.mo` holds the C string "0" (the NUL is where C gettext ends the string anyway, and
 *   readMoTranslations() cuts there as well). A context or `msgid_plural` of "" or "0" cannot be
 *   represented by the library at all; generating such a catalog throws instead of silently
 *   dropping it.
 * - Header values are escaped for the `.po` (PoGenerator writes them verbatim, so a quote or
 *   backslash would corrupt the file).
 * - A `.mo` is checked for being structurally sound (magic number, every index table and every
 *   string within the file) before MoLoader reads it, since MoLoader silently clamps out-of-range
 *   reads of a truncated file into shortened or missing messages.
 *
 * Remaining, documented differences to the GNU gettext tools: plural forms are written for exactly
 * as many forms as the "Plural-Forms" header declares (missing ones empty, surplus ones dropped),
 * and a missing plural form cannot be told apart from an empty one - ILIAS itself uses no plurals.
 */
final class TranslationCatalog
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";
    private const int MO_MAGIC = 0x950412de;
    private const int MO_HEADER_SIZE = 28;

    private Translations $translations;

    public function __construct()
    {
        $this->translations = Translations::create();
    }

    /**
     * @throws RuntimeException if $file cannot be read or is not a valid `.po` file
     */
    public static function fromPoFile(string $file): self
    {
        $content = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read PO file "%s".', $file));
        }

        try {
            return self::fromPoString($content);
        } catch (RuntimeException $e) {
            throw new RuntimeException(sprintf('Invalid PO file "%s": %s', $file, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws RuntimeException if $content is not valid `.po` data
     */
    public static function fromPoString(string $content): self
    {
        if (str_starts_with($content, self::UTF8_BOM)) {
            $content = substr($content, strlen(self::UTF8_BOM));
        }

        $loader = new StrictPoLoader();
        $loader->displayErrorLine = true;
        $translations = self::withLibraryErrorsAsExceptions(
            static fn(): Translations => $loader->loadString($content)
        );
        foreach ($translations->getHeaders()->toArray() as $name => $value) {
            // An escaped line break inside a header value: setHeader() refuses such a value, so a
            // file carrying one could be read but never written back - it counts as broken instead
            if (strpbrk($value, "\r\n") !== false) {
                throw new RuntimeException(sprintf('The value of header "%s" contains a line break.', $name));
            }
        }
        foreach ($translations->getTranslations() as $translation) {
            if ($translation->isDisabled()) {
                $translations->remove($translation);
            }
        }

        $catalog = new self();
        $catalog->translations = $translations;

        return $catalog;
    }

    /**
     * The (singular) translations of a `.mo` file, keyed by message id. The context is dropped: a
     * migrated module's `.mo` only ever holds messages of that one module, and ILIAS' txt() looks
     * messages up by identifier alone.
     *
     * @return array<string, string>
     * @throws RuntimeException if $file cannot be read or is not a valid `.mo` file
     */
    public static function readMoTranslations(string $file): array
    {
        $data = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
        if ($data === false) {
            throw new RuntimeException(sprintf('Could not read MO file "%s".', $file));
        }
        self::assertStructurallySoundMo($data, $file);

        $translations = self::withLibraryErrorsAsExceptions(
            static fn(): Translations => (new MoLoader())->loadString($data)
        );

        $result = [];
        foreach ($translations as $translation) {
            $result[$translation->getOriginal()] = explode("\0", $translation->getTranslation() ?? '', 2)[0];
        }

        return $result;
    }

    /**
     * @return array<string, string> header name => value, sorted by name
     */
    public function getHeaders(): array
    {
        return $this->translations->getHeaders()->toArray();
    }

    public function getHeader(string $name): ?string
    {
        return $this->translations->getHeaders()->get($name);
    }

    /**
     * The library trims header values.
     *
     * @throws InvalidArgumentException for a value containing a line break: the `.po` header block
     *         is split at line breaks when read, so such a value could not be read back unchanged
     */
    public function setHeader(string $name, string $value): void
    {
        if (strpbrk($value, "\r\n") !== false) {
            throw new InvalidArgumentException(sprintf('The value of header "%s" must not contain a line break.', $name));
        }
        $this->translations->getHeaders()->set($name, $value);
    }

    public function find(?string $context, string $id): ?TranslationEntry
    {
        $translation = $this->translations->find($context, $id);

        return $translation === null ? null : TranslationEntry::fromGettext($translation);
    }

    /**
     * Adds $entry, replacing an entry with the same context and id. $entry stays the same message:
     * changing it afterward changes it in this catalog, too.
     */
    public function add(TranslationEntry $entry): void
    {
        $this->translations->add($entry->toGettext());
    }

    /**
     * @return list<TranslationEntry> in the order they were added (or read)
     */
    public function getEntries(): array
    {
        return array_values(array_map(
            static fn(Translation $translation): TranslationEntry => TranslationEntry::fromGettext($translation),
            $this->translations->getTranslations()
        ));
    }

    /**
     * @throws RuntimeException if the catalog holds something the library cannot represent
     */
    public function toPoString(): string
    {
        $translations = $this->prepareForGenerator(false);
        $headers = $translations->getHeaders();
        foreach ($headers->toArray() as $name => $value) {
            $headers->set($name, substr(PoGenerator::encode($value), 1, -1));
        }

        return (new PoGenerator())->generateString($translations);
    }

    /**
     * Deterministic: the same catalog always compiles to the same bytes, so comparing an existing
     * `.mo` with this output tells whether it is current. Unlike `msgfmt`'s default, messages flagged
     * "fuzzy" are compiled too (for a module converted from the legacy `.lang` files, "fuzzy" only
     * marks a value never reviewed for that language, usually the English fallback - leaving it out
     * would make ILIAS show "-identifier-" instead). Messages without a translation are left out,
     * exactly like `msgfmt` does.
     *
     * @throws RuntimeException if the catalog holds something the library cannot represent
     */
    public function toMoString(): string
    {
        return (new MoGenerator())->includeHeaders(true)->generateString($this->prepareForGenerator(true));
    }

    /**
     * A copy of the catalog the generators write correctly, see the class docblock.
     */
    private function prepareForGenerator(bool $for_mo): Translations
    {
        $translations = clone $this->translations;
        foreach ($translations as $translation) {
            if (
                in_array($translation->getContext(), ['', '0'], true)
                || in_array($translation->getPlural(), ['', '0'], true)
            ) {
                throw new RuntimeException(sprintf(
                    'The message "%s" has a context or plural id gettext/gettext cannot write.',
                    $translation->getOriginal()
                ));
            }
            if ($translation->getTranslation() !== '0') {
                continue;
            }
            if ($for_mo && $translation->getPlural() !== null) {
                // In a plural message the NUL would separate an additional, empty plural form
                throw new RuntimeException(sprintf(
                    'The plural message "%s" has the singular translation "0", which gettext/gettext cannot compile.',
                    $translation->getOriginal()
                ));
            }
            $translation->translate("0\0");
        }

        return $translations;
    }

    /**
     * See https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
     */
    private static function assertStructurallySoundMo(string $data, string $file): void
    {
        $size = strlen($data);
        $format = match (true) {
            $size < self::MO_HEADER_SIZE => null,
            unpack('V', $data)[1] === self::MO_MAGIC => 'V',
            unpack('N', $data)[1] === self::MO_MAGIC => 'N',
            default => null,
        };
        if ($format === null) {
            throw new RuntimeException(sprintf('"%s" is not a valid MO file.', $file));
        }

        [
            'count' => $count,
            'originals' => $originals_offset,
            'translations' => $translations_offset
        ] = unpack($format . 'count/' . $format . 'originals/' . $format . 'translations', $data, 8);
        foreach ([$originals_offset, $translations_offset] as $table_offset) {
            if ($table_offset + $count * 8 > $size) {
                throw new RuntimeException(sprintf('"%s" is not a valid MO file (index out of bounds).', $file));
            }
            for ($i = 0; $i < $count; $i++) {
                ['length' => $length, 'offset' => $offset] = unpack(
                    $format . 'length/' . $format . 'offset',
                    $data,
                    $table_offset + $i * 8
                );
                if ($offset + $length > $size) {
                    throw new RuntimeException(sprintf('"%s" is not a valid MO file (string out of bounds).', $file));
                }
            }
        }
    }

    /**
     * Runs $load and turns everything the library raises - its own exceptions, PHP errors and, via
     * a temporary error handler, PHP warnings and notices - into a RuntimeException, so callers can
     * tell "broken file" from "valid file" with a single catch. Deprecations are passed on to the
     * error handler registered before (or PHP's own handling if there is none): they say something
     * about the library on this PHP version, not about the file.
     *
     * @param callable(): Translations $load
     */
    private static function withLibraryErrorsAsExceptions(callable $load): Translations
    {
        $previous_handler = null;
        $previous_handler = set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$previous_handler): bool {
                if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) === 0) {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                }

                return $previous_handler !== null && $previous_handler($severity, $message, $file, $line) !== false;
            }
        );
        try {
            return $load();
        } catch (Throwable $t) {
            throw new RuntimeException($t->getMessage(), 0, $t);
        } finally {
            restore_error_handler();
        }
    }
}

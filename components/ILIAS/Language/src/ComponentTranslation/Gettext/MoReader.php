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

use RuntimeException;

/**
 * Reads the binary gettext `.mo` format (both byte orders). Every offset and length is checked
 * against the file size, so a truncated or otherwise corrupt file raises a RuntimeException
 * instead of producing garbage or PHP warnings.
 */
final class MoReader
{
    private const int MAGIC_LITTLE_ENDIAN = 0x950412de;
    private const int MAGIC_BIG_ENDIAN = 0xde120495;
    private const int HEADER_SIZE = 28;

    /**
     * @return array<string, string> identifier => (singular) translation. The context is dropped:
     *         a migrated module's `.mo` only ever holds messages of that one module, and ILIAS'
     *         txt() looks messages up by identifier alone.
     */
    public static function readTranslations(string $file): array
    {
        $translations = [];
        foreach (self::readMessages($file) as $original => $translation) {
            $original = (string) $original;
            if ($original === '') {
                continue; // header
            }
            $parts = explode("\x04", $original, 2);
            $id = explode("\x00", $parts[1] ?? $parts[0], 2)[0];
            $translations[$id] = explode("\x00", $translation, 2)[0];
        }

        return $translations;
    }

    /**
     * @return array<string, string> raw original => raw translation, as stored in the file
     */
    public static function readMessages(string $file): array
    {
        $data = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($data === false) {
            throw new RuntimeException(sprintf('Could not read MO file "%s".', $file));
        }

        return self::parseString($data, $file);
    }

    /**
     * @return array<string, string>
     */
    public static function parseString(string $data, string $name = 'MO data'): array
    {
        $size = strlen($data);
        if ($size < self::HEADER_SIZE) {
            throw new RuntimeException(sprintf('"%s" is not a valid MO file (too short).', $name));
        }

        $magic = unpack('V', substr($data, 0, 4))[1];
        $format = match ($magic) {
            self::MAGIC_LITTLE_ENDIAN => 'V',
            self::MAGIC_BIG_ENDIAN => 'N',
            default => throw new RuntimeException(sprintf('"%s" is not a valid MO file (bad magic number).', $name)),
        };

        $header = unpack($format . '7', substr($data, 0, self::HEADER_SIZE));
        [, , $count, $originals_offset, $translations_offset] = array_values($header);

        if ($count < 0 || $originals_offset + $count * 8 > $size || $translations_offset + $count * 8 > $size) {
            throw new RuntimeException(sprintf('"%s" is not a valid MO file (index out of bounds).', $name));
        }

        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $original = self::readString($data, $format, $originals_offset + $i * 8, $size, $name);
            $messages[$original] = self::readString($data, $format, $translations_offset + $i * 8, $size, $name);
        }

        return $messages;
    }

    private static function readString(string $data, string $format, int $index_offset, int $size, string $name): string
    {
        ['length' => $length, 'offset' => $offset] = unpack(
            $format . 'length/' . $format . 'offset',
            substr($data, $index_offset, 8)
        );
        if ($offset + $length > $size) {
            throw new RuntimeException(sprintf('"%s" is not a valid MO file (string out of bounds).', $name));
        }

        return substr($data, $offset, $length);
    }
}

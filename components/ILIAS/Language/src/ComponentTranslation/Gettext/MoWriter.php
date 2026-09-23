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

/**
 * Compiles a Catalog into the binary gettext `.mo` format (little endian, revision 0, no hash
 * table - see https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html).
 *
 * Unlike `msgfmt`'s default, messages flagged "fuzzy" are compiled too: for a module converted from
 * the legacy `.lang` files, "fuzzy" only marks a value that was never reviewed for that language
 * (usually the English fallback), and leaving it out would make ILIAS show "-identifier-" instead.
 * Messages without a translation are left out, exactly like `msgfmt` does.
 */
final class MoWriter
{
    private const int MAGIC = 0x950412de;
    private const int HEADER_SIZE = 28;

    public static function toString(Catalog $catalog): string
    {
        $messages = [];

        $header_lines = [];
        foreach ($catalog->getHeaders() as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        if ($header_lines !== []) {
            $messages[''] = implode("\n", $header_lines);
        }

        foreach ($catalog->getEntries() as $entry) {
            if ($entry->getTranslation() === '') {
                continue;
            }
            $original = Catalog::key($entry->getContext(), $entry->getId());
            $translation = $entry->getTranslation();
            if ($entry->getPlural() !== null) {
                $original .= "\x00" . $entry->getPlural();
                $plurals = $entry->getPluralTranslations();
                $forms = [$translation];
                // MO stores the forms positionally - a form that was never set becomes empty
                for ($i = 0, $max = $plurals === [] ? -1 : max(array_keys($plurals)); $i <= $max; $i++) {
                    $forms[] = $plurals[$i] ?? '';
                }
                $translation = implode("\x00", $forms);
            }
            $messages[$original] = $translation;
        }

        // gettext looks messages up by binary search, which requires the originals to be sorted
        ksort($messages, SORT_STRING);

        $count = count($messages);
        $originals_index_offset = self::HEADER_SIZE;
        $translations_index_offset = $originals_index_offset + $count * 8;
        $strings_offset = $translations_index_offset + $count * 8;

        $originals_table = '';
        $translations_table = '';
        $originals_index = '';
        $translations_index = '';
        foreach ($messages as $original => $translation) {
            $original = (string) $original;
            $originals_index .= pack('VV', strlen($original), strlen($originals_table));
            $originals_table .= $original . "\x00";
            $translations_index .= pack('VV', strlen($translation), strlen($translations_table));
            $translations_table .= $translation . "\x00";
        }

        // the relative offsets collected above become absolute once the table sizes are known
        $originals_index = self::shiftOffsets($originals_index, $strings_offset);
        $translations_index = self::shiftOffsets($translations_index, $strings_offset + strlen($originals_table));

        return pack(
            'VVVVVVV',
            self::MAGIC,
            0,
            $count,
            $originals_index_offset,
            $translations_index_offset,
            0,
            $strings_offset
        ) . $originals_index . $translations_index . $originals_table . $translations_table;
    }

    private static function shiftOffsets(string $index, int $base): string
    {
        $shifted = '';
        foreach (str_split($index, 8) as $pair) {
            if ($pair === '') {
                continue;
            }
            ['length' => $length, 'offset' => $offset] = unpack('Vlength/Voffset', $pair);
            $shifted .= pack('VV', $length, $base + $offset);
        }

        return $shifted;
    }
}

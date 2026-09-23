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
 * Serializes a Catalog into the `.po` text format.
 *
 * The layout (header block, one blank line between messages, headers sorted by name, multi-line
 * strings split after every "\n") matches what the shipped `.po` files of already migrated modules
 * look like, so re-generating one of them produces no spurious diff.
 */
final class PoWriter
{
    public static function toString(Catalog $catalog): string
    {
        $lines = [];

        foreach ($catalog->getHeaderComments() as $comment) {
            $lines[] = self::comment('#', $comment);
        }
        if ($catalog->getHeaderFlags() !== []) {
            $lines[] = '#, ' . implode(', ', $catalog->getHeaderFlags());
        }

        $lines[] = 'msgid ""';
        $lines[] = 'msgstr ""';
        $headers = $catalog->getHeaders();
        ksort($headers, SORT_STRING);
        foreach ($headers as $name => $value) {
            $lines[] = self::encode($name . ': ' . $value . "\n");
        }
        $lines[] = '';

        foreach ($catalog->getEntries() as $entry) {
            foreach ($entry->getTranslatorComments() as $comment) {
                $lines[] = self::comment('#', $comment);
            }
            foreach ($entry->getExtractedComments() as $comment) {
                $lines[] = self::comment('#.', $comment);
            }
            foreach ($entry->getReferences() as $reference) {
                $lines[] = self::comment('#:', $reference);
            }
            if ($entry->getFlags() !== []) {
                $lines[] = '#, ' . implode(', ', $entry->getFlags());
            }
            if ($entry->getContext() !== null) {
                self::appendString($lines, 'msgctxt', $entry->getContext());
            }
            self::appendString($lines, 'msgid', $entry->getId());

            if ($entry->getPlural() !== null) {
                self::appendString($lines, 'msgid_plural', $entry->getPlural());
                self::appendString($lines, 'msgstr[0]', $entry->getTranslation());
                $plurals = $entry->getPluralTranslations();
                // every form up to the highest one set, so the msgstr[n] sequence has no gaps
                for ($i = 0, $max = $plurals === [] ? -1 : max(array_keys($plurals)); $i <= $max; $i++) {
                    self::appendString($lines, sprintf('msgstr[%d]', $i + 1), $plurals[$i] ?? '');
                }
            } else {
                self::appendString($lines, 'msgstr', $entry->getTranslation());
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * A comment is a single PO line - a line break inside it would end the comment and corrupt
     * the file, so it is replaced by a space.
     */
    private static function comment(string $marker, string $text): string
    {
        $text = str_replace(["\r\n", "\n", "\r"], ' ', $text);

        return $text === '' ? $marker : $marker . ' ' . $text;
    }

    /**
     * @param list<string> $lines
     */
    private static function appendString(array &$lines, string $keyword, string $value): void
    {
        $parts = explode("\n", $value);
        if (count($parts) === 1) {
            $lines[] = $keyword . ' ' . self::encode($value);
            return;
        }

        $lines[] = $keyword . ' ""';
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($index < $last) {
                $part .= "\n";
            }
            $lines[] = self::encode($part);
        }
    }

    private static function encode(string $value): string
    {
        return '"' . strtr($value, [
            "\x00" => '',
            '\\' => '\\\\',
            "\t" => '\t',
            "\r" => '\r',
            "\n" => '\n',
            '"' => '\\"',
        ]) . '"';
    }
}

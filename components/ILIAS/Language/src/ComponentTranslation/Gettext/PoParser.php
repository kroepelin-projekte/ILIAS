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
 * Reads a gettext `.po` file into a Catalog.
 *
 * Deliberately never trims the content of a line: only the line break itself, the one space that
 * conventionally follows a comment marker (`# `, `#. `, `#: `) and whitespace *outside* a quoted
 * string are removed. Comment text - in particular the "original: ..." bookkeeping comments written
 * by LocalChangeComments - therefore round-trips byte for byte, including leading/trailing
 * whitespace, which a trimming reader would silently change (and thereby turn an unchanged entry
 * into a seemingly locally changed one).
 *
 * Throws a RuntimeException for an unreadable file or a syntactically broken line instead of
 * guessing - callers decide whether that is fatal (conversion tool) or logged and skipped (runtime).
 */
final class PoParser
{
    private const string KEYWORD_PATTERN = '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(.*)$/s';

    public static function parseFile(string $file): Catalog
    {
        $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read PO file "%s".', $file));
        }

        return self::parseString($content);
    }

    public static function parseString(string $content): Catalog
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $catalog = new Catalog();
        $state = self::emptyState();
        $last_keyword = null;

        foreach (preg_split('/\r\n|\n|\r/', $content) as $index => $line) {
            $line_number = $index + 1;

            if (trim($line) === '') {
                self::flush($catalog, $state);
                $state = self::emptyState();
                $last_keyword = null;
                continue;
            }

            if ($line[0] === '#') {
                // A comment always starts a new message once the current one has a msgstr.
                if ($state['has_msgstr']) {
                    self::flush($catalog, $state);
                    $state = self::emptyState();
                    $last_keyword = null;
                }
                self::parseComment($line, $state);
                continue;
            }

            $trimmed = ltrim($line);
            if ($trimmed[0] === '"') {
                if ($last_keyword === null) {
                    throw new RuntimeException(sprintf('Unexpected string continuation in PO line %d.', $line_number));
                }
                self::append($state, $last_keyword, self::decode(rtrim($trimmed), $line_number));
                continue;
            }

            if (!preg_match(self::KEYWORD_PATTERN, $trimmed, $matches)) {
                throw new RuntimeException(sprintf('Invalid PO line %d.', $line_number));
            }
            $keyword = $matches[1];
            $value = self::decode(rtrim($matches[3]), $line_number);

            $starts_new_message = $state['has_msgstr'] && ($keyword === 'msgctxt' || $keyword === 'msgid');
            if ($starts_new_message) {
                self::flush($catalog, $state);
                $state = self::emptyState();
            }

            if ($matches[2] !== '') {
                $keyword = 'msgstr[' . (int) $matches[2] . ']';
            }
            if (str_starts_with($keyword, 'msgstr')) {
                $state['has_msgstr'] = true;
            }
            $state['strings'][$keyword] = $value;
            $last_keyword = $keyword;
        }

        self::flush($catalog, $state);

        return $catalog;
    }

    /**
     * @return array{strings: array<string, string>, translator_comments: list<string>,
     *     extracted_comments: list<string>, references: list<string>, flags: list<string>,
     *     has_msgstr: bool}
     */
    private static function emptyState(): array
    {
        return [
            'strings' => [],
            'translator_comments' => [],
            'extracted_comments' => [],
            'references' => [],
            'flags' => [],
            'has_msgstr' => false,
        ];
    }

    private static function parseComment(string $line, array &$state): void
    {
        $marker = substr($line, 0, 2);
        switch ($marker) {
            case '#~': // obsolete message
            case '#|': // previous message
                return;
            case '#,':
                foreach (explode(',', substr($line, 2)) as $flag) {
                    $flag = trim($flag);
                    if ($flag !== '') {
                        $state['flags'][] = $flag;
                    }
                }
                return;
            case '#.':
                $state['extracted_comments'][] = self::stripOneLeadingSpace(substr($line, 2));
                return;
            case '#:':
                $state['references'][] = self::stripOneLeadingSpace(substr($line, 2));
                return;
            default:
                $state['translator_comments'][] = self::stripOneLeadingSpace(substr($line, 1));
        }
    }

    private static function stripOneLeadingSpace(string $text): string
    {
        return str_starts_with($text, ' ') ? substr($text, 1) : $text;
    }

    private static function append(array &$state, string $keyword, string $value): void
    {
        $state['strings'][$keyword] = ($state['strings'][$keyword] ?? '') . $value;
    }

    private static function flush(Catalog $catalog, array $state): void
    {
        $strings = $state['strings'];
        if (!array_key_exists('msgid', $strings)) {
            // comments without a message (e.g. above an obsolete "#~" block) carry nothing to keep
            return;
        }

        $context = $strings['msgctxt'] ?? null;
        $id = $strings['msgid'];

        if ($context === null && $id === '') {
            self::parseHeader($catalog, $strings['msgstr'] ?? '', $state);
            return;
        }

        $entry = new Entry($context, $id);
        $entry->translate($strings['msgstr'] ?? $strings['msgstr[0]'] ?? '');
        if (isset($strings['msgid_plural'])) {
            $entry->setPlural($strings['msgid_plural']);
        }
        foreach ($strings as $keyword => $value) {
            if (preg_match('/^msgstr\[(\d+)\]$/', $keyword, $matches) && (int) $matches[1] > 0) {
                $entry->setPluralTranslation((int) $matches[1], $value);
            }
        }
        foreach ($state['translator_comments'] as $comment) {
            $entry->addTranslatorComment($comment);
        }
        foreach ($state['extracted_comments'] as $comment) {
            $entry->addExtractedComment($comment);
        }
        foreach ($state['references'] as $reference) {
            $entry->addReference($reference);
        }
        foreach ($state['flags'] as $flag) {
            $entry->addFlag($flag);
        }

        $catalog->add($entry);
    }

    private static function parseHeader(Catalog $catalog, string $header, array $state): void
    {
        foreach ($state['translator_comments'] as $comment) {
            $catalog->addHeaderComment($comment);
        }
        foreach ($state['flags'] as $flag) {
            $catalog->addHeaderFlag($flag);
        }

        $name = null;
        foreach (explode("\n", $header) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([\w-]+):\s?(.*)$/s', $line, $matches)) {
                $name = $matches[1];
                $catalog->setHeader($name, $matches[2]);
                continue;
            }
            if ($name !== null) {
                $catalog->setHeader($name, $catalog->getHeader($name) . $line);
            }
        }
    }

    /**
     * Decodes one quoted PO string token (including its surrounding quotes).
     */
    private static function decode(string $token, int $line_number): string
    {
        $length = strlen($token);
        $without_closing_quote = substr($token, 0, -1);
        $escaped_closing_quote = $length >= 2
            && (strlen($without_closing_quote) - strlen(rtrim($without_closing_quote, '\\'))) % 2 === 1;
        if ($length < 2 || $token[0] !== '"' || $token[$length - 1] !== '"' || $escaped_closing_quote) {
            throw new RuntimeException(sprintf('Invalid quoted string in PO line %d.', $line_number));
        }

        return preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|x([0-9a-fA-F]{1,2})|(.))/s',
            static function (array $matches): string {
                if (($matches[1] ?? '') !== '') {
                    return chr(octdec($matches[1]) & 0xFF);
                }
                if (($matches[2] ?? '') !== '') {
                    return chr(hexdec($matches[2]));
                }
                return match ($matches[3]) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    'a' => "\x07",
                    'b' => "\x08",
                    'f' => "\x0c",
                    'v' => "\x0b",
                    default => $matches[3],
                };
            },
            substr($token, 1, -1)
        );
    }
}

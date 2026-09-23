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
 * An in-memory gettext catalog: the header block of a `.po`/`.mo` file plus its messages, keyed by
 * context and id exactly like gettext itself does ("<context>\x04<id>").
 */
final class Catalog
{
    /** @var array<string, string> */
    private array $headers = [];
    /** @var list<string> */
    private array $header_comments = [];
    /** @var list<string> */
    private array $header_flags = [];
    /** @var array<string, Entry> */
    private array $entries = [];

    /**
     * @return array<string, string> header name => value, in the order they were set
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * @return list<string>
     */
    public function getHeaderComments(): array
    {
        return $this->header_comments;
    }

    public function addHeaderComment(string $comment): void
    {
        $this->header_comments[] = $comment;
    }

    /**
     * @return list<string>
     */
    public function getHeaderFlags(): array
    {
        return $this->header_flags;
    }

    public function addHeaderFlag(string $flag): void
    {
        if ($flag !== '' && !in_array($flag, $this->header_flags, true)) {
            $this->header_flags[] = $flag;
        }
    }

    public function find(?string $context, string $id): ?Entry
    {
        return $this->entries[self::key($context, $id)] ?? null;
    }

    /**
     * Adds $entry, replacing an entry with the same context and id.
     */
    public function add(Entry $entry): void
    {
        $this->entries[self::key($entry->getContext(), $entry->getId())] = $entry;
    }

    public function remove(Entry $entry): void
    {
        unset($this->entries[self::key($entry->getContext(), $entry->getId())]);
    }

    /**
     * @return list<Entry>
     */
    public function getEntries(): array
    {
        return array_values($this->entries);
    }

    public static function key(?string $context, string $id): string
    {
        return $context === null ? $id : $context . "\x04" . $id;
    }
}

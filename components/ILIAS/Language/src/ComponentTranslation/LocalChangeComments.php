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

namespace ILIAS\Language\ComponentTranslation;

use DateTimeImmutable;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;

/**
 * PO/MO pilot: tracks, per overlay entry, whether the current value still matches the value the
 * shipped `.po` carries for it - the same distinction `lng_data.local_change` draws, now carried on
 * the entry itself instead of in a separate table.
 *
 * Stored as plain gettext translator comments (`# ...`), the only place this can live: the compiled
 * `.mo` format has no comment section at all, so this metadata is `.po`-only, exactly mirroring the
 * pre-existing `lng_data` (has metadata) vs. `lng_modules` (flat runtime cache, no metadata) split.
 *
 * - "original" is written once, by MigratedLanguageFileSync::sync(), when a module+language's overlay
 *   is first created - seeded from the value the shipped `.po` (itself undecorated, see
 *   convert_module_to_po.php) carries for that entry at that moment. It is left untouched by every
 *   ordinary write afterward (an admin-GUI edit must never silently move an entry's local-change
 *   baseline out from under it) - with one deliberate exception: sync()'s own
 *   $refresh_original_from_shipped flag lets a caller that can vouch $entries reflects the current
 *   SHIPPED content (a Setup install/update run, the admin GUI's "refresh already-installed language"
 *   action, or an explicit "reset to shipped defaults" import) re-check "original" against what the
 *   shipped `.po` says now, and update it - but only when it actually changed, e.g. because an ILIAS
 *   update revised a translation for a language that was already installed. See sync()'s own docblock
 *   for exactly which callers set that flag.
 * - "local_change" is maintained solely by refresh(), called by MigratedLanguageFileSync::sync() on every write:
 *   present, with the write's timestamp, whenever the current value differs from "original" (or there
 *   is no "original" at all - a key added after migration never had one to begin with); absent
 *   whenever it matches. Saving the original value again therefore clears it on its own, without a
 *   dedicated "reset" action - mirroring _deleteLangData()'s "local_change IS NULL means unmodified".
 * - A plural message keeps one "original" per form, as `original[<form>]: ` (or
 *   `original_escaped[<form>]: `), and one "local_change" for the whole message, present whenever
 *   any form differs from its "original" (see refreshForms()).
 * - "remark" is the administrator's remark on the entry (the counterpart of lng_data.remarks, one
 *   per identifier - also for a plural message), stored like "original": verbatim as `remark: `,
 *   or reversibly encoded as `remark_escaped: ` if it contains a line break or a backslash.
 */
final class LocalChangeComments
{
    private const string ORIGINAL_PREFIX = 'original: ';
    private const string ESCAPED_ORIGINAL_PREFIX = 'original_escaped: ';
    private const string LOCAL_CHANGE_PREFIX = 'local_change: ';
    private const string REMARK_PREFIX = 'remark: ';
    private const string ESCAPED_REMARK_PREFIX = 'remark_escaped: ';

    /**
     * Does not touch "local_change" - refresh() is always called right after and recomputes it
     * against whatever "original" now holds.
     *
     * A translator comment is a single PO line, so a value containing a line break or a backslash
     * is stored escaped under its own prefix ("original_escaped: ", see escape()); every other value
     * is stored verbatim under "original: " - byte-identical to what earlier versions wrote, so
     * existing overlays keep being read exactly as before.
     */
    public static function setOriginal(TranslationEntry $entry, string $value, ?int $form = null): void
    {
        self::setEscapable($entry, self::originalPrefix(false, $form), self::originalPrefix(true, $form), $value);
    }

    /**
     * With $form `null` the "original" of a singular message, otherwise the one of that plural form.
     */
    public static function removeOriginal(TranslationEntry $entry, ?int $form = null): void
    {
        self::replace($entry, self::originalPrefix(false, $form), null);
        self::replace($entry, self::originalPrefix(true, $form), null);
    }

    /**
     * Removes the "original" of every plural form (`original[<form>]: `) - e.g. when a plural
     * message becomes a singular one.
     */
    public static function removeFormOriginals(TranslationEntry $entry): void
    {
        foreach ($entry->getTranslatorComments() as $comment) {
            if (preg_match('/\Aoriginal(?:_escaped)?\[\d+\]: /', $comment, $matches) === 1) {
                $entry->removeTranslatorCommentsStartingWith($matches[0]);
            }
        }
    }

    /**
     * The decoded "original" value, exactly as it was passed to setOriginal(). An "original: "
     * comment written by an earlier version that flattened line breaks into spaces is returned
     * as stored - that loss cannot be undone; the next reconciling sync (Setup update, "remove
     * local changes") rewrites it from the shipped value.
     */
    public static function getOriginal(TranslationEntry $entry, ?int $form = null): ?string
    {
        return self::getEscapable($entry, self::originalPrefix(false, $form), self::originalPrefix(true, $form));
    }

    /**
     * How many characters of a remark are kept - like lng_data.remarks.
     */
    public const int MAX_REMARK_LENGTH = 250;

    /**
     * Sets the remark of $entry - `null` or '' removes it; cut to MAX_REMARK_LENGTH characters.
     *
     * @throws \InvalidArgumentException for a remark that is not valid UTF-8 (the overlay would
     *         become unreadable)
     */
    public static function setRemark(TranslationEntry $entry, ?string $remark): void
    {
        if ($remark !== null && !mb_check_encoding($remark, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf('The remark of "%s" is not valid UTF-8.', $entry->getId()));
        }
        $remark = $remark === null ? null : mb_substr($remark, 0, self::MAX_REMARK_LENGTH);
        if ($remark === null || $remark === '') {
            self::replace($entry, self::REMARK_PREFIX, null);
            self::replace($entry, self::ESCAPED_REMARK_PREFIX, null);
            return;
        }
        self::setEscapable($entry, self::REMARK_PREFIX, self::ESCAPED_REMARK_PREFIX, $remark);
    }

    /**
     * The decoded remark of $entry, exactly as it was passed to setRemark(), `null` without one.
     */
    public static function getRemark(TranslationEntry $entry): ?string
    {
        return self::getEscapable($entry, self::REMARK_PREFIX, self::ESCAPED_REMARK_PREFIX);
    }

    /**
     * @return string|null ISO-8601 UTC ('Y-m-d\TH:i:s\Z') or null if the entry is unmodified
     */
    public static function getLocalChange(TranslationEntry $entry): ?string
    {
        return self::find($entry, self::LOCAL_CHANGE_PREFIX);
    }

    /**
     * getLocalChange() in the database's "Y-m-d H:i:s" (UTC) format, as used by
     * lng_data.local_change, or null if unmodified or unparsable.
     */
    public static function getLocalChangeAsDatabaseTimestamp(TranslationEntry $entry): ?string
    {
        $change = self::getLocalChange($entry);
        if ($change === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $change, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d H:i:s');
    }

    /**
     * Called by MigratedLanguageFileSync::sync() for every entry it writes, after the new value has
     * been set. $previous_value is the entry's value just before this write, used only to avoid
     * bumping an already-current timestamp on an idempotent re-save of the same, already locally
     * changed value.
     */
    public static function refresh(
        TranslationEntry $entry,
        string $previous_value,
        string $value,
        DateTimeImmutable $now
    ): void {
        $original = self::getOriginal($entry);
        $is_local_change = $original === null || $original !== $value;

        if (!$is_local_change) {
            self::replace($entry, self::LOCAL_CHANGE_PREFIX, null);
            return;
        }

        if ($previous_value === $value && self::getLocalChange($entry) !== null) {
            return;
        }

        self::replace($entry, self::LOCAL_CHANGE_PREFIX, $now->format('Y-m-d\TH:i:s\Z'));
    }

    /**
     * refresh() for a plural message: $forms are its new forms, $previous_forms the ones before this
     * write. "local_change" is present whenever any form differs from its "original" (or has none).
     *
     * @param list<string> $previous_forms
     * @param list<string> $forms
     */
    public static function refreshForms(
        TranslationEntry $entry,
        array $previous_forms,
        array $forms,
        DateTimeImmutable $now
    ): void {
        $is_local_change = false;
        foreach ($forms as $form => $value) {
            $original = self::getOriginal($entry, $form);
            if ($original === null || $original !== $value) {
                $is_local_change = true;
                break;
            }
        }

        if (!$is_local_change) {
            self::replace($entry, self::LOCAL_CHANGE_PREFIX, null);
            return;
        }

        if ($previous_forms === $forms && self::getLocalChange($entry) !== null) {
            return;
        }

        self::replace($entry, self::LOCAL_CHANGE_PREFIX, $now->format('Y-m-d\TH:i:s\Z'));
    }

    /**
     * The comment prefix of an "original": "original: "/"original_escaped: " of a singular message,
     * "original[<form>]: "/"original_escaped[<form>]: " of a plural form.
     */
    private static function originalPrefix(bool $escaped, ?int $form): string
    {
        $prefix = $escaped ? self::ESCAPED_ORIGINAL_PREFIX : self::ORIGINAL_PREFIX;

        return $form === null ? $prefix : substr($prefix, 0, -2) . '[' . $form . ']: ';
    }

    /**
     * Stores $value under $prefix, or under $escaped_prefix if it needs escaping (see
     * needsEscaping()) - used by setOriginal() and setRemark(), which only differ in their prefixes.
     */
    private static function setEscapable(TranslationEntry $entry, string $prefix, string $escaped_prefix, string $value): void
    {
        [$prefix, $other_prefix, $stored] = self::needsEscaping($value)
            ? [$escaped_prefix, $prefix, self::escape($value)]
            : [$prefix, $escaped_prefix, $value];

        // Unchanged: keep the comment where it is, so re-running a sync produces identical output
        if (self::find($entry, $prefix) === $stored && self::find($entry, $other_prefix) === null) {
            return;
        }
        self::replace($entry, $other_prefix, null);
        self::replace($entry, $prefix, $stored);
    }

    /**
     * The value stored under $escaped_prefix (decoded) or $prefix, `null` if neither is present -
     * the read side of setEscapable().
     */
    private static function getEscapable(TranslationEntry $entry, string $prefix, string $escaped_prefix): ?string
    {
        $escaped = self::find($entry, $escaped_prefix);
        if ($escaped !== null) {
            return self::unescape($escaped);
        }

        return self::find($entry, $prefix);
    }

    private static function find(TranslationEntry $entry, string $prefix): ?string
    {
        foreach ($entry->getTranslatorComments() as $comment) {
            if (str_starts_with($comment, $prefix)) {
                return substr($comment, strlen($prefix));
            }
        }

        return null;
    }

    private static function replace(TranslationEntry $entry, string $prefix, ?string $value): void
    {
        $entry->removeTranslatorCommentsStartingWith($prefix);
        if ($value !== null) {
            $entry->addTranslatorComment($prefix . $value);
        }
    }

    private static function needsEscaping(string $value): bool
    {
        return strpbrk($value, "\\\n\r") !== false;
    }

    /**
     * Reversible single-line encoding: a backslash is doubled, a line feed becomes backslash + "n"
     * and a carriage return becomes backslash + "r".
     */
    private static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r']);
    }

    private static function unescape(string $value): string
    {
        return strtr($value, ['\\\\' => '\\', '\\n' => "\n", '\\r' => "\r"]);
    }
}

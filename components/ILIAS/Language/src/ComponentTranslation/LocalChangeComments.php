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
use ILIAS\Language\ComponentTranslation\Gettext\Entry;

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
 * - "local_change" is maintained solely by ilObjLanguage::syncMigratedLanguageFile() on every write:
 *   present, with the write's timestamp, whenever the current value differs from "original" (or there
 *   is no "original" at all - a key added after migration never had one to begin with); absent
 *   whenever it matches. Saving the original value again therefore clears it on its own, without a
 *   dedicated "reset" action - mirroring _deleteLangData()'s "local_change IS NULL means unmodified".
 */
final class LocalChangeComments
{
    private const string ORIGINAL_PREFIX = 'original: ';
    private const string LOCAL_CHANGE_PREFIX = 'local_change: ';

    /**
     * Does not touch "local_change" - refresh() is always called right after and recomputes it
     * against whatever "original" now holds.
     */
    public static function setOriginal(Entry $entry, string $value): void
    {
        // unchanged: keep the comment where it is, so re-running a sync produces identical output
        if (self::getOriginal($entry) === self::encode($value)) {
            return;
        }
        self::replace($entry, self::ORIGINAL_PREFIX, self::encode($value));
    }

    public static function removeOriginal(Entry $entry): void
    {
        self::replace($entry, self::ORIGINAL_PREFIX, null);
    }

    public static function getOriginal(Entry $entry): ?string
    {
        return self::find($entry, self::ORIGINAL_PREFIX);
    }

    /**
     * @return string|null ISO-8601 UTC ('Y-m-d\TH:i:s\Z') or null if the entry is unmodified
     */
    public static function getLocalChange(Entry $entry): ?string
    {
        return self::find($entry, self::LOCAL_CHANGE_PREFIX);
    }

    /**
     * getLocalChange() in the database's "Y-m-d H:i:s" (UTC) format, as used by
     * lng_data.local_change, or null if unmodified or unparsable.
     */
    public static function getLocalChangeAsDatabaseTimestamp(Entry $entry): ?string
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
        Entry $entry,
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

    private static function find(Entry $entry, string $prefix): ?string
    {
        foreach ($entry->getTranslatorComments() as $comment) {
            if (str_starts_with($comment, $prefix)) {
                return substr($comment, strlen($prefix));
            }
        }

        return null;
    }

    private static function replace(Entry $entry, string $prefix, ?string $value): void
    {
        $entry->removeTranslatorCommentsStartingWith($prefix);
        if ($value !== null) {
            $entry->addTranslatorComment($prefix . $value);
        }
    }

    // Comments are single PO lines; a value containing a newline would otherwise corrupt the file.
    // Not expected for the short UI strings these modules hold, but kept safe regardless.
    private static function encode(string $value): string
    {
        return str_replace(["\r\n", "\n", "\r"], ' ', $value);
    }
}

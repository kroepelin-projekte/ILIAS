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
use Gettext\Translation;

/**
 * PO/MO pilot (see components/ILIAS/Language/tools/po-migration/README.md, "Schreibpfad"): tracks,
 * per entry, whether the current value still matches the one shipped in the reference `.lang` file at
 * migration time - the same distinction `lng_data.local_change`/`remarks` draws against the DB's
 * merged default, now carried on the entry itself instead of in a separate table.
 *
 * Stored as plain gettext translator comments (`# ...`), the only place this can live: the compiled
 * `.mo` format has no comment section at all, so this metadata is `.po`-only, exactly mirroring the
 * pre-existing `lng_data` (has metadata) vs. `lng_modules` (flat runtime cache, no metadata) split.
 *
 * - "original" is written once, by the conversion tool, when a module is first converted from its
 *   `.lang` file - and never touched again afterward, by anything.
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
     * Called once by the conversion tool while building a module's initial `.po` from its `.lang`
     * file. Deliberately does not touch "local_change": a freshly migrated entry has none, same as a
     * freshly installed language's lng_data row has local_change IS NULL until actually edited.
     */
    public static function setOriginal(Translation $translation, string $value): void
    {
        self::replace($translation, self::ORIGINAL_PREFIX, self::encode($value));
    }

    public static function getOriginal(Translation $translation): ?string
    {
        return self::find($translation, self::ORIGINAL_PREFIX);
    }

    public static function getLocalChange(Translation $translation): ?string
    {
        return self::find($translation, self::LOCAL_CHANGE_PREFIX);
    }

    /**
     * Called by ilObjLanguage::syncMigratedLanguageFile() for every entry it writes, after the new
     * value has already been set via Translation::translate(). $previousValue is that same
     * translation's value just before this write, used only to avoid bumping an already-current
     * timestamp on an idempotent re-save of the same, already locally-changed value.
     */
    public static function refresh(
        Translation $translation,
        string $previousValue,
        string $value,
        DateTimeImmutable $now
    ): void {
        $original = self::getOriginal($translation);
        $isLocalChange = $original === null || $original !== $value;

        if (!$isLocalChange) {
            self::replace($translation, self::LOCAL_CHANGE_PREFIX, null);
            return;
        }

        if ($previousValue === $value && self::getLocalChange($translation) !== null) {
            return;
        }

        self::replace($translation, self::LOCAL_CHANGE_PREFIX, self::encode($now->format('Y-m-d\TH:i:s\Z')));
    }

    private static function find(Translation $translation, string $prefix): ?string
    {
        foreach ($translation->getComments() as $comment) {
            if (str_starts_with($comment, $prefix)) {
                return substr($comment, strlen($prefix));
            }
        }

        return null;
    }

    private static function replace(Translation $translation, string $prefix, ?string $value): void
    {
        $comments = $translation->getComments();
        foreach ($comments->toArray() as $comment) {
            if (str_starts_with($comment, $prefix)) {
                $comments->delete($comment);
            }
        }
        if ($value !== null) {
            $comments->add($prefix . $value);
        }
    }

    // Comments are single PO lines; a value containing a newline would otherwise corrupt the file.
    // Not expected for the short UI strings these modules hold, but kept safe regardless.
    private static function encode(string $value): string
    {
        return str_replace(["\r\n", "\n", "\r"], ' ', $value);
    }
}

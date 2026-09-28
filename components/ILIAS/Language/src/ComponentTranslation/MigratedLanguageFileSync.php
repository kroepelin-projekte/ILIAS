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
use DateTimeZone;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use RuntimeException;

/**
 * PO/MO pilot: reading the shipped `.po` of a migrated module and maintaining its per-installation
 * overlay `.po`/`.mo` pair.
 *
 * A module counts as "migrated" for a language when its component contributes a
 * LanguageFileDirectory with the module as prefix AND ships `<module>_<lang>.po` there. For such a
 * module:
 * - the shipped `.po` is the only source of the shipped values (the legacy `.lang` files are not
 *   consulted at all, see LanguageInstallationManager);
 * - the overlay (see MigratedLanguageFilePaths) holds the per-installation delta to the shipped
 *   state (see sync()), which ilLanguage applies on top of the shipped state at runtime. It only
 *   exists where there are local changes, and carries, per entry, the value in use plus the
 *   LocalChangeComments bookkeeping ("original" = shipped value it is tracked against,
 *   "local_change" = when it was changed locally).
 *
 * lng_data/lng_modules keep being written for migrated modules too (rollback-safe fallback); this
 * class only takes care of the files. Every method throws instead of logging - callers decide how
 * to report a failure.
 *
 * Plural messages: every identifier => value map this class takes or returns (sync(), the shipped
 * values, loadModuleTranslations(), loadLocalChanges()) holds a plural message as one entry per form,
 * keyed PluralFormKey::of() (`<identifier> [<form>]`), never under its identifier - so every
 * comparison (delta, "original", local change, the three-way comparison of an update) works per form.
 * The number of forms is the one of the file's "Plural-Forms" header (PluralForms, Germanic if missing
 * or invalid). A plain value for the identifier of a shipped plural message (a local change recorded
 * before the module had plurals, or read back from the database) stands for its default form, see
 * mapLegacyPluralValues(). The database only gets the identifier with its default value, see
 * collapsePluralForms().
 */
final class MigratedLanguageFileSync
{
    /**
     * How often acquireLock() retries when the lock file it locked was removed or replaced meanwhile.
     */
    private const int LOCK_ATTEMPTS = 5;

    /**
     * The lock files this request currently holds, see withOverlayLock().
     *
     * @var array<string, true>
     */
    private static array $held_locks = [];

    private static ?TranslationMarkupPolicy $markup_policy = null;

    /**
     * Parsed shipped `.po` files of this request, keyed by path, see readShippedPo().
     *
     * @var array<string, array{0: string, 1: TranslationCatalog}>
     */
    private static array $shipped_catalogs = [];

    /**
     * The language the files in $shipped_catalogs belong to.
     */
    private static string $shipped_catalogs_lang_key = '';

    /**
     * How many parsed shipped `.po` files of one language readShippedPo() keeps - more than the
     * modules of a language, so installing it parses each of its `.po` files once, not once per step.
     */
    private const int SHIPPED_CATALOG_CACHE_SIZE = 512;

    /**
     * Brings the overlay of $module/$lang_key in line with $entries, the complete, final content of
     * the module - but the overlay only holds the delta to the shipped `.po` (see belongsInDelta()):
     * the entries whose value differs from the value the shipped `.po` carries (unprocessed, as in
     * the file) or whose key it does not carry at all. Everything else is served from the shipped
     * state (see ShippedTranslations) and therefore not written. An entry of the overlay not in the
     * delta any more is removed; an overlay whose delta is empty is removed (`.po` and `.mo`; the
     * `.lock` stays, see withOverlayLock()). With an empty delta and no overlay,
     * nothing is written or locked at all - installing a language without local changes creates no
     * file. An existing full overlay of an earlier version shrinks to the delta the same way with
     * the next write (Setup update, reinstall).
     *
     * A no-op if $module is not a contributed directory or $client_data_dir is `null`. Without a
     * shipped `.po` for $lang_key (the module or the language was dropped from the shipped files,
     * possibly only temporarily) this is a no-op as well: an existing overlay is left untouched - it
     * is not read while the shipped `.po` is missing (see loadModuleTranslations(),
     * getMigratedModules(), ilLanguage), and once the `.po` is back the next reconciling write
     * compares against its preserved "original" values.
     *
     * The read-modify-write runs under the overlay lock (see withOverlayLock()), and the existing
     * overlay is read only after it was acquired.
     *
     * Per delta entry:
     * - "original" is taken from the shipped `.po` when the entry enters the overlay, and - only with
     *   $refresh_original_from_shipped - re-taken from it on every later call. An entry the shipped
     *   `.po` no longer contains keeps the "original" it had, so a later update can still tell an
     *   unchanged entry (dropped there) from a real local change (kept). Pass `true` only for
     *   reconciling writes whose $entries already went through the shipped/local decision (install,
     *   update, "remove local changes", "apply local changes" - see LanguageInstallationManager); an
     *   ordinary admin edit passes `false` so its baseline is never moved.
     * - "fuzzy" never applies: a delta value is by definition not the shipped one.
     * - "local_change" is recomputed via LocalChangeComments::refresh().
     *
     * Both files are written atomically (temporary file + rename). The `.po` only when its content
     * actually changed; the `.mo` whenever it does not hold exactly what TranslationCatalog::toMoString()
     * compiles from the resulting catalog - so a missing, truncated or otherwise stale `.mo` next to an
     * unchanged `.po` is rebuilt as well (that output is deterministic, so a byte comparison suffices
     * and no read-back of the `.mo` is needed).
     *
     * An overlay `.mo` without its `.po` (the `.po` carries the bookkeeping), or an overlay path that
     * is no regular file, is never replaced or removed: sync() throws instead, so a local change it
     * may hold is not lost silently.
     *
     * @param array<string, string> $entries identifier => value, the complete, final set for this
     *        module/language - exactly what the caller just wrote to lng_modules.
     * @param bool|null $expected_overlay_exists whether the caller decided on $entries knowing an
     *        overlay exists (see LanguageInstallationManager): with `false`, an overlay that exists
     *        once the lock is held was created concurrently after that decision (e.g. an admin edit
     *        during an installation) and is left untouched - sync() throws. `null`: no expectation.
     * Remarks (the administrator's remark of an identifier, the counterpart of lng_data.remarks, see
     * LocalChangeComments::setRemark()) are kept in the overlay, too: an entry with a remark belongs
     * into it even if its value is the shipped one (see belongsInDelta()) - as a "remark only" entry
     * with an empty msgstr, which the `.mo` leaves out, so it changes nothing that is served.
     *
     * @param array<string, string>|null $remarks identifier => remark (a plural message under its
     *        identifier), the complete remarks of the module; `null` (the default) keeps the
     *        remarks the overlay holds. A remark of an identifier that is neither shipped nor in
     *        $entries is dropped.
     * @throws RuntimeException if the shipped `.po` or the overlay cannot be read or written, see
     *         also above
     */
    public static function sync(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $module,
        array $entries,
        ?string $client_data_dir,
        bool $refresh_original_from_shipped = false,
        ?bool $expected_overlay_exists = null,
        ?array $remarks = null
    ): void {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return;
        }
        $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
        if (!is_file($shipped_po)) {
            // not migrated (any more) for this language: leave an existing overlay alone, see above
            return;
        }

        // A copy: the catalog of readShippedPo() is shared with the readers of this request
        $shipped = clone self::readShippedPo($shipped_po);
        $delta = self::deltaOf($shipped, $module, $entries);
        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        if (
            $delta === []
            && array_filter($remarks ?? [], static fn($remark): bool => (string) $remark !== '') === []
            && !self::hasOverlayFiles($overlay_base)
        ) {
            return;
        }

        self::lockAndRun(
            $directory,
            $lang_key,
            $client_data_dir,
            static fn() => self::syncLocked(
                $shipped,
                $overlay_base,
                $lang_key,
                $module,
                $delta,
                $client_data_dir,
                $refresh_original_from_shipped,
                $expected_overlay_exists,
                $remarks
            ),
            $ilias_absolute_path,
            false
        );
    }

    /**
     * The one place that decides what a locally written $value means for a key whose shipped value is
     * $shipped_value (`null`: the key is not shipped): the value that is actually kept. Used by the
     * overlay (belongsInDelta()) and by the database write of the admin GUI
     * (ilObjLanguageExt::_saveValues()), so both treat it the same way.
     *
     * An empty value resets the entry to its shipped value (the one place that decides it). An
     * empty value of a key that is not shipped stays empty.
     */
    public static function resolveLocalValue(?string $shipped_value, string $value): string
    {
        return $value === '' && $shipped_value !== null ? $shipped_value : $value;
    }

    /**
     * The values of $entries as they are written to lng_data/lng_modules (the database fallback of a
     * migrated module): an entry whose value is its unprocessed shipped value ($shipped_entries, as in
     * the shipped `.po`) gets the value the build compiles for it (TranslationMarkupPolicy::sanitize()),
     * so the fallback never serves markup the shipped state does not. Every other value (a local
     * change, already checked when it was written) is kept. Only for the database - the overlay
     * (sync()) keeps comparing with the unprocessed value, so this creates no delta.
     *
     * @param array<string|int, string> $entries identifier => value
     * @param array<string|int, string> $shipped_entries identifier => unprocessed shipped value
     * @return array<string|int, string>
     */
    public static function databaseValues(array $entries, array $shipped_entries): array
    {
        $policy = self::$markup_policy ??= new TranslationMarkupPolicy();
        foreach ($entries as $identifier => $value) {
            if (array_key_exists($identifier, $shipped_entries) && (string) $shipped_entries[$identifier] === (string) $value) {
                $entries[$identifier] = $policy->sanitize((string) $value);
            }
        }

        return $entries;
    }

    /**
     * databaseValues() for $module/$lang_key, reading its shipped `.po`, with every plural message
     * collapsed to its identifier and default value (see collapsePluralForms()) - $entries unchanged
     * if the module is not migrated for $lang_key or its shipped `.po` cannot be read (that is
     * reported by the overlay write).
     *
     * @param array<string|int, string> $entries identifier => value
     * @return array<string|int, string>
     */
    public static function databaseValuesOf(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $module,
        array $entries
    ): array {
        $shipped_po = self::findShippedModuleFiles($language_file_directory_manager, $ilias_absolute_path, $lang_key)[$module] ?? null;
        if ($shipped_po === null) {
            return $entries;
        }
        try {
            $shipped = array_map(
                static fn(array $entry): string => $entry['value'],
                self::loadShippedModuleEntries($shipped_po, $module)
            );
        } catch (RuntimeException) {
            return $entries;
        }

        return self::databaseValues(
            self::collapsePluralForms(self::mapLegacyPluralValues($entries, $shipped), $shipped),
            self::collapsePluralForms($shipped, $shipped)
        );
    }

    /**
     * The identifier of the plural message of $shipped_entries (shipped values, form keys included)
     * $key belongs to - as one of its form keys or as the identifier itself -, `null` for every other
     * key.
     *
     * @param array<string|int, mixed> $shipped_entries
     */
    public static function pluralMessageOf(string $key, array $shipped_entries): ?string
    {
        $identifier = PluralFormKey::parse($key)[0] ?? $key;

        return !array_key_exists($identifier, $shipped_entries)
            && array_key_exists(PluralFormKey::of($identifier, 0), $shipped_entries)
            ? $identifier
            : null;
    }

    /**
     * $entries (identifier => value, see the class docblock) as the database holds them: the forms
     * of every plural message of $shipped_entries (the shipped values, form keys included) replaced
     * by the message's identifier with the value of its default form (PluralForms, a form missing in
     * $entries counts with its shipped value), at the position of its first form. Everything else is
     * kept as it is.
     *
     * @param array<string|int, string> $entries
     * @param array<string|int, string> $shipped_entries
     * @return array<string|int, string>
     */
    public static function collapsePluralForms(array $entries, array $shipped_entries): array
    {
        $form_counts = self::pluralFormCounts($shipped_entries);
        if ($form_counts === []) {
            return $entries;
        }

        $collapsed = [];
        foreach ($entries as $key => $value) {
            $parsed = PluralFormKey::parse((string) $key);
            $identifier = $parsed[0] ?? (string) $key;
            if (!isset($form_counts[$identifier])) {
                $collapsed[$key] = $value;
                continue;
            }
            if ($parsed === null && self::hasAnyForm($entries, $identifier, $form_counts[$identifier])) {
                // a plain value next to the forms: the forms win
                continue;
            }
            if ($parsed !== null && !array_key_exists($identifier, $collapsed)) {
                $forms = [];
                for ($form = 0; $form < $form_counts[$identifier]; $form++) {
                    $form_key = PluralFormKey::of($identifier, $form);
                    $forms[] = (string) ($entries[$form_key] ?? $shipped_entries[$form_key] ?? '');
                }
                $collapsed[$identifier] = PluralForms::defaultValueForCount($forms, $form_counts[$identifier]);
            } elseif ($parsed === null) {
                $collapsed[$key] = $value;
            }
        }

        return $collapsed;
    }

    /**
     * $entries with a plain value of the identifier of a plural message of $shipped_entries (shipped
     * values, form keys included) moved to the key of its default form - unless that form is in
     * $entries already, then the plain value is dropped. Such a plain value is a local change recorded
     * before the module had plurals, or a value read back from the database (which only holds the
     * identifier with its default value, see collapsePluralForms()).
     *
     * @template T
     * @param array<string|int, T> $entries
     * @param array<string|int, mixed> $shipped_entries
     * @return array<string|int, T>
     */
    public static function mapLegacyPluralValues(array $entries, array $shipped_entries): array
    {
        $form_counts = self::pluralFormCounts($shipped_entries);
        foreach ($form_counts as $identifier => $count) {
            if (!array_key_exists($identifier, $entries)) {
                continue;
            }
            $default_key = PluralFormKey::of($identifier, PluralForms::defaultFormIndexForCount($count));
            if (!array_key_exists($default_key, $entries)) {
                $entries[$default_key] = $entries[$identifier];
            }
            unset($entries[$identifier]);
        }

        return $entries;
    }

    /**
     * The plural messages among $shipped_entries (keys as in the class docblock) with their number of
     * forms: identifiers that only appear as form keys, never as a key of their own.
     *
     * @param array<string|int, mixed> $shipped_entries
     * @return array<string, int>
     */
    private static function pluralFormCounts(array $shipped_entries): array
    {
        $counts = [];
        foreach (array_keys($shipped_entries) as $key) {
            $parsed = PluralFormKey::parse((string) $key);
            if ($parsed !== null && !array_key_exists($parsed[0], $shipped_entries)) {
                $counts[$parsed[0]] = max($counts[$parsed[0]] ?? 0, $parsed[1] + 1);
            }
        }

        return $counts;
    }

    /**
     * @param array<string|int, mixed> $entries
     */
    private static function hasAnyForm(array $entries, string $identifier, int $count): bool
    {
        for ($form = 0; $form < $count; $form++) {
            if (array_key_exists(PluralFormKey::of($identifier, $form), $entries)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The "Plural-Forms" rule of $catalog - the Germanic one if its header is missing or invalid.
     */
    public static function pluralFormsOf(TranslationCatalog $catalog): PluralForms
    {
        return PluralForms::fromHeaderOrGermanic($catalog->getHeader('Plural-Forms'));
    }

    /**
     * $entry as identifier => value (see the class docblock): a singular message under its
     * identifier, a plural one as exactly $plural_forms->getCount() form keys (missing forms empty,
     * surplus ones dropped).
     *
     * @return array<string, string>
     */
    public static function flatValuesOf(TranslationEntry $entry, PluralForms $plural_forms): array
    {
        if (!$entry->isPlural()) {
            return [$entry->getId() => $entry->getTranslation()];
        }
        $values = [];
        foreach (self::formsOf($entry, $plural_forms) as $form => $value) {
            $values[PluralFormKey::of($entry->getId(), $form)] = $value;
        }

        return $values;
    }

    /**
     * The forms of the plural message $entry, exactly $plural_forms->getCount() of them.
     *
     * @return list<string>
     */
    private static function formsOf(TranslationEntry $entry, PluralForms $plural_forms): array
    {
        $count = $plural_forms->getCount();

        return array_pad(array_slice($entry->getPluralTranslations(), 0, $count), $count, '');
    }

    /**
     * The values of the entries of $module in $catalog, see flatValuesOf().
     *
     * @return array<string, string>
     */
    private static function flatModuleValues(TranslationCatalog $catalog, string $module): array
    {
        $plural_forms = self::pluralFormsOf($catalog);
        $values = [];
        foreach (self::moduleEntries($catalog, $module) as $entry) {
            $values += self::shippedValuesOf($entry, $plural_forms);
        }

        return $values;
    }

    /**
     * flatValuesOf() for an entry of a shipped `.po` - nothing for an entry without translation
     * (a singular one with an empty msgstr, a plural one whose forms are all empty): the build leaves
     * it out of the compiled `.mo` (see TranslationCatalog::toMoString()), so it is not shipped at all
     * - not served by ilLanguage, not written to lng_data/lng_modules, not listed by the admin GUI,
     * exactly like a key the legacy `.lang` file of the language does not have.
     *
     * @return array<string, string>
     */
    private static function shippedValuesOf(TranslationEntry $entry, PluralForms $plural_forms): array
    {
        $values = self::flatValuesOf($entry, $plural_forms);

        return implode('', $values) === '' ? [] : $values;
    }

    /**
     * Whether $value belongs into the overlay (the delta) given the $shipped_value the shipped `.po`
     * carries for its key (`null`: the key is not shipped) - see resolveLocalValue(). An empty value
     * never does. With a $remark (not empty), an entry of a shipped key belongs into the overlay as
     * well, even with the shipped value - only for its remark (see sync(); the `.mo` stays unchanged).
     */
    public static function belongsInDelta(?string $shipped_value, string $value, ?string $remark = null): bool
    {
        $resolved = self::resolveLocalValue($shipped_value, $value);
        if ($resolved !== '' && $resolved !== $shipped_value) {
            return true;
        }

        return $remark !== null && $remark !== '' && ($shipped_value !== null || $resolved !== '');
    }

    /**
     * The remarks the overlay of $module/$lang_key holds (see LocalChangeComments::setRemark()),
     * identifier => remark - `[]` without overlay, `null` in the cases loadModuleTranslations() returns
     * `null` for.
     *
     * @param string|null $ilias_absolute_path see loadModuleTranslations()
     * @return array<string, string>|null
     * @throws RuntimeException see loadLocalChanges()
     */
    public static function loadRemarks(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir,
        ?string $ilias_absolute_path = null
    ): ?array {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if (
            $directory === null
            || $client_data_dir === null
            || !self::isShipped($ilias_absolute_path, $directory, $lang_key)
        ) {
            return null;
        }
        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        self::assertReadableOverlay($overlay_base);
        if (!is_file($overlay_base . '.po')) {
            return [];
        }

        return self::remarksOf(TranslationCatalog::fromPoFile($overlay_base . '.po'), $module);
    }

    /**
     * Changes remarks of $module/$lang_key in its overlay (the values stay as they are): identifier =>
     * remark, `null` or '' removes the remark. Remarks not in $changes are kept. A no-op like sync().
     *
     * @param array<string, ?string> $changes
     * @param string|null $ilias_absolute_path see loadModuleTranslations()
     * @throws RuntimeException see sync()
     */
    public static function setRemarks(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        array $changes,
        ?string $client_data_dir,
        ?string $ilias_absolute_path = null
    ): void {
        if ($changes === []) {
            return;
        }
        $ilias_absolute_path ??= defined('ILIAS_ABSOLUTE_PATH') ? (string) ILIAS_ABSOLUTE_PATH : dirname(__DIR__, 5);
        self::withOverlayLock(
            $language_file_directory_manager,
            $lang_key,
            $module,
            $client_data_dir,
            static function () use ($language_file_directory_manager, $lang_key, $module, $changes, $client_data_dir, $ilias_absolute_path): void {
                $current = self::loadModuleTranslations($language_file_directory_manager, $lang_key, $module, $client_data_dir, $ilias_absolute_path);
                $remarks = self::loadRemarks($language_file_directory_manager, $lang_key, $module, $client_data_dir, $ilias_absolute_path);
                if ($current === null || $remarks === null) {
                    return;
                }
                foreach ($changes as $identifier => $remark) {
                    $identifier = PluralFormKey::parse((string) $identifier)[0] ?? (string) $identifier;
                    if ($remark === null || $remark === '') {
                        unset($remarks[$identifier]);
                    } else {
                        $remarks[$identifier] = $remark;
                    }
                }
                self::sync(
                    $language_file_directory_manager,
                    $ilias_absolute_path,
                    $lang_key,
                    $module,
                    array_map(static fn(array $entry): string => $entry['value'], $current),
                    $client_data_dir,
                    false,
                    null,
                    $remarks
                );
            },
            $ilias_absolute_path
        );
    }

    /**
     * @return array<string, string> identifier => remark of the entries of $module in $catalog
     */
    private static function remarksOf(TranslationCatalog $catalog, string $module): array
    {
        $remarks = [];
        foreach (self::moduleEntries($catalog, $module) as $entry) {
            $remark = LocalChangeComments::getRemark($entry);
            if ($remark !== null && $remark !== '') {
                $remarks[$entry->getId()] = $remark;
            }
        }

        return $remarks;
    }

    /**
     * Whether $entry of an overlay only carries a remark (see sync()): no value of its own.
     */
    private static function isRemarkOnly(TranslationEntry $entry): bool
    {
        return implode('', $entry->getPluralTranslations()) === '';
    }

    /**
     * @param array<string, string> $entries
     * @return array<string, string> identifier => value, sorted by identifier
     */
    private static function deltaOf(TranslationCatalog $shipped, string $module, array $entries): array
    {
        $shipped_values = self::flatModuleValues($shipped, $module);
        $delta = [];
        foreach (self::mapLegacyPluralValues($entries, $shipped_values) as $identifier => $value) {
            $identifier = (string) $identifier;
            $value = (string) $value;
            if (self::belongsInDelta($shipped_values[$identifier] ?? null, $value)) {
                $delta[$identifier] = $value;
            }
        }
        ksort($delta, SORT_STRING);

        return $delta;
    }

    /**
     * Whether $module/$lang_key has an overlay (anything at the place of its `.po` or `.mo`), i.e.
     * local changes. `false` if $module is not a contributed directory or $client_data_dir is `null`.
     */
    public static function hasOverlay(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir
    ): bool {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return false;
        }

        return self::hasOverlayFiles(MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key));
    }

    /**
     * The entries of $catalog that belong to $module: those with the module as context (msgctxt)
     * and those without any context - a file of a single module need not repeat its name in every
     * entry (e.g. the `.po` of a plugin, which must survive the plugin's later move to a component
     * with another module name unchanged). An explicit empty context (`msgctxt ""`) is a context of
     * its own and, like every other context, does not belong to $module. For an identifier present
     * both with the module as context and without context, the entry with the context wins.
     *
     * @return array<string, TranslationEntry> identifier => entry, in catalog order
     */
    public static function moduleEntries(TranslationCatalog $catalog, string $module): array
    {
        $entries = [];
        foreach ($catalog->getEntries() as $entry) {
            $context = $entry->getContext();
            if ($context === $module || ($context === null && !isset($entries[$entry->getId()]))) {
                $entries[$entry->getId()] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The entry of $identifier that belongs to $module, see moduleEntries().
     */
    public static function findModuleEntry(TranslationCatalog $catalog, string $module, string $identifier): ?TranslationEntry
    {
        $entry = $catalog->find($module, $identifier);
        if ($entry !== null) {
            return $entry;
        }
        // gettext/gettext looks up "no context" and the empty context under the same id - only an
        // entry that really has no context belongs to the module
        $entry = $catalog->find(null, $identifier);

        return $entry !== null && $entry->getContext() === null ? $entry : null;
    }

    /**
     * Whether anything exists at the place of the overlay `.po` or `.mo` - also something that is no
     * regular file.
     */
    private static function hasOverlayFiles(string $overlay_base): bool
    {
        foreach (['.po', '.mo'] as $extension) {
            if (file_exists($overlay_base . $extension) || is_link($overlay_base . $extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws RuntimeException if the overlay at $overlay_base cannot be read as a whole: a `.po` or
     *         `.mo` that is no regular file, or a `.mo` without its `.po` (which carries the
     *         bookkeeping) - treating that as "no overlay" would let the next write remove it
     */
    private static function assertReadableOverlay(string $overlay_base): void
    {
        foreach (['.po', '.mo'] as $extension) {
            $file = $overlay_base . $extension;
            if ((file_exists($file) || is_link($file)) && (!is_file($file) || is_link($file))) {
                throw new RuntimeException(sprintf('The overlay file "%s" is no regular file.', $file));
            }
        }
        if (is_file($overlay_base . '.mo') && !is_file($overlay_base . '.po')) {
            throw new RuntimeException(sprintf(
                'The overlay "%s.mo" has no "%s.po" - it is left untouched.',
                $overlay_base,
                $overlay_base
            ));
        }
    }

    /**
     * sync() once the overlay lock is held.
     *
     * @param array<string, string> $delta see deltaOf()
     */
    private static function syncLocked(
        TranslationCatalog $shipped,
        string $overlay_base,
        string $lang_key,
        string $module,
        array $delta,
        string $client_data_dir,
        bool $refresh_original_from_shipped,
        ?bool $expected_overlay_exists,
        ?array $remarks = null
    ): void {
        $overlay_po = $overlay_base . '.po';
        $overlay_mo = $overlay_base . '.mo';
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_po);
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_mo);
        self::assertReadableOverlay($overlay_base);
        if ($expected_overlay_exists === false && self::hasOverlayFiles($overlay_base)) {
            throw new RuntimeException(sprintf(
                'The overlay "%s" was created after the values to write were determined - it is left untouched.',
                $overlay_po
            ));
        }

        $overlay_exists = is_file($overlay_po);
        $existing = null;
        if ($overlay_exists) {
            try {
                $existing = TranslationCatalog::fromPoFile($overlay_po);
            } catch (RuntimeException) {
                // A corrupt overlay is rebuilt like a missing one: $delta carries every value, and
                // "original"/"local_change" are recomputed against the shipped file - only the
                // previous local_change timestamps (and remarks) are lost.
                $overlay_exists = false;
            }
        }

        // The remarks: those given, or those the overlay holds - only of identifiers that are
        // shipped or in the delta (a plural message under its identifier)
        $remarks ??= $existing === null ? [] : self::remarksOf($existing, $module);
        $shipped_values = self::flatModuleValues($shipped, $module);
        $delta_identifiers = [];
        foreach (array_keys($delta) as $key) {
            $delta_identifiers[self::pluralMessageOf((string) $key, $shipped_values) ?? PluralFormKey::parse((string) $key)[0] ?? (string) $key] = true;
            $delta_identifiers[(string) $key] = true;
        }
        $kept_remarks = [];
        foreach ($remarks as $identifier => $remark) {
            $identifier = (string) $identifier;
            if (!mb_check_encoding((string) $remark, 'UTF-8')) {
                // would make the overlay unreadable - dropped, the rest is written
                self::logWarning(PlainLogText::of(sprintf('The remark of "%s" (module "%s") is not valid UTF-8 and was dropped.', $identifier, $module)));
                continue;
            }
            $is_plural = self::pluralMessageOf($identifier, $shipped_values) !== null;
            $shipped_value = $shipped_values[$identifier] ?? ($is_plural ? '' : null);
            if (
                (string) $remark !== ''
                && (isset($delta_identifiers[$identifier]) || self::belongsInDelta($shipped_value, $shipped_value ?? '', (string) $remark))
            ) {
                $kept_remarks[$identifier] = (string) $remark;
            }
        }

        if ($delta === [] && $kept_remarks === []) {
            self::removeOverlayFiles($overlay_base, $module, $lang_key, $client_data_dir);
            return;
        }
        // a stable order: the same content always gives the same file
        ksort($kept_remarks, SORT_STRING);

        $catalog = new TranslationCatalog();
        foreach ($shipped->getHeaders() as $name => $value) {
            $catalog->setHeader($name, $value);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $plural_forms = self::pluralFormsOf($shipped);
        [$delta, $plural_delta] = self::splitPluralDelta($shipped, $existing, $module, $delta);
        foreach ($plural_delta as $identifier => $forms) {
            $entry = self::overlayPluralEntry(
                $shipped,
                $existing,
                $module,
                (string) $identifier,
                $forms,
                $plural_forms,
                $refresh_original_from_shipped,
                $now
            );
            LocalChangeComments::setRemark($entry, $kept_remarks[(string) $identifier] ?? null);
            unset($kept_remarks[(string) $identifier]);
            $catalog->add($entry);
        }
        foreach ($delta as $identifier => $value) {
            $identifier = (string) $identifier;
            $shipped_entry = self::findModuleEntry($shipped, $module, $identifier);
            $existing_entry = $existing === null ? null : self::findModuleEntry($existing, $module, $identifier);
            if ($existing_entry?->isPlural() || ($existing_entry !== null && self::isRemarkOnly($existing_entry))) {
                // was a plural message in the overlay, is a singular one now - or only carried a
                // remark: rebuilt from scratch
                $existing_entry = null;
            }
            // Written without msgctxt, like the shipped files: the overlay belongs to one module. An
            // entry of an older overlay with the module as msgctxt loses it with this write.
            $entry = $existing_entry?->withContext(null) ?? new TranslationEntry(null, $identifier);
            // An entry not in the overlay so far had the shipped value (or did not exist)
            $previous_value = $existing_entry?->getTranslation() ?? $shipped_entry?->getTranslation() ?? '';

            // An entry the shipped .po no longer contains keeps its previous "original" - see
            // above and LanguageInstallationManager::resolveMigratedModule()
            if ($shipped_entry !== null && ($existing_entry === null || $refresh_original_from_shipped)) {
                LocalChangeComments::setOriginal($entry, $shipped_entry->getTranslation());
                $entry->setExtractedComments($shipped_entry->getExtractedComments());
            }

            $entry->translate($value);
            $entry->removeFlag('fuzzy');
            LocalChangeComments::refresh($entry, $previous_value, $value, $now);
            LocalChangeComments::setRemark($entry, $kept_remarks[$identifier] ?? null);
            unset($kept_remarks[$identifier]);
            $catalog->add($entry);
        }

        // The remaining remarks belong to entries with the shipped value: "remark only" entries
        // without a value of their own, left out of the .mo (empty msgstr)
        foreach ($kept_remarks as $identifier => $remark) {
            $identifier = (string) $identifier;
            $shipped_entry = self::findModuleEntry($shipped, $module, $identifier);
            $entry = new TranslationEntry(null, $identifier);
            if ($shipped_entry?->isPlural()) {
                $entry->setPlural((string) $shipped_entry->getPluralId(), array_fill(0, $plural_forms->getCount(), ''));
            }
            LocalChangeComments::setRemark($entry, $remark);
            $catalog->add($entry);
        }

        $po_content = $catalog->toPoString();
        if (!mb_check_encoding($po_content, 'UTF-8')) {
            // fromPoString() refuses such a file: writing it would lose every local change of the
            // module with the next read - nothing is written instead
            throw new RuntimeException(sprintf('The overlay "%s" would not be valid UTF-8 - it is left unchanged.', $overlay_po));
        }
        $mo_content = $catalog->toMoString();
        $po_unchanged = $overlay_exists && $po_content === file_get_contents($overlay_po);
        $mo_unchanged = is_file($overlay_mo) && $mo_content === @file_get_contents($overlay_mo);
        if ($po_unchanged && $mo_unchanged) {
            return;
        }

        self::ensureDirectoryExists(dirname($overlay_po), $client_data_dir);
        // The .po first: it carries the bookkeeping the .mo lacks, and ilLanguage only reads the
        // .mo - so a failure in between leaves the previous .mo being served (a .po that is newer
        // than its .mo is repaired by the next sync, see above), never a new .mo whose bookkeeping
        // got lost.
        if (!$po_unchanged) {
            AtomicFileWriter::write($overlay_po, $po_content, self::overlayRoot($client_data_dir));
        }
        AtomicFileWriter::write($overlay_mo, $mo_content, self::overlayRoot($client_data_dir));

        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
    }

    /**
     * $delta split into the singular entries and the forms of plural messages: a form key (see
     * PluralFormKey) whose identifier is a plural message of the shipped `.po` or of the existing
     * overlay belongs to that message, every other key is an ordinary identifier.
     *
     * @param array<string, string> $delta
     * @return array{0: array<string, string>, 1: array<string, array<int, string>>}
     */
    private static function splitPluralDelta(
        TranslationCatalog $shipped,
        ?TranslationCatalog $existing,
        string $module,
        array $delta
    ): array {
        $singular = [];
        $plural = [];
        foreach ($delta as $key => $value) {
            $parsed = PluralFormKey::parse((string) $key);
            if (
                $parsed !== null
                && (
                    self::findModuleEntry($shipped, $module, $parsed[0])?->isPlural()
                    || ($existing !== null && self::findModuleEntry($existing, $module, $parsed[0])?->isPlural())
                )
            ) {
                $plural[$parsed[0]][$parsed[1]] = $value;
                continue;
            }
            $singular[(string) $key] = $value;
        }

        return [$singular, $plural];
    }

    /**
     * The overlay entry of the plural message $identifier whose forms in the delta are $delta_forms:
     * every form - a form not in the delta has its shipped value (empty if the message is not shipped)
     * -, an "original" per form (see LocalChangeComments), with the same rules as a singular entry of
     * syncLocked().
     *
     * @param array<int, string> $delta_forms form => value
     */
    private static function overlayPluralEntry(
        TranslationCatalog $shipped,
        ?TranslationCatalog $existing,
        string $module,
        string $identifier,
        array $delta_forms,
        PluralForms $plural_forms,
        bool $refresh_original_from_shipped,
        DateTimeImmutable $now
    ): TranslationEntry {
        $shipped_entry = self::findModuleEntry($shipped, $module, $identifier);
        $shipped_forms = $shipped_entry?->isPlural() ? self::formsOf($shipped_entry, $plural_forms) : null;
        $existing_entry = $existing === null ? null : self::findModuleEntry($existing, $module, $identifier);
        if ($existing_entry !== null && (!$existing_entry->isPlural() || self::isRemarkOnly($existing_entry))) {
            // a singular message in the overlay, a plural one now - or only a remark: rebuilt from
            // scratch
            $existing_entry = null;
        }

        $forms = [];
        for ($form = 0; $form < $plural_forms->getCount(); $form++) {
            $forms[] = $delta_forms[$form] ?? $shipped_forms[$form] ?? '';
        }
        $previous_forms = $existing_entry !== null
            ? self::formsOf($existing_entry, $plural_forms)
            : ($shipped_forms ?? []);

        $entry = $existing_entry?->withContext(null) ?? new TranslationEntry(null, $identifier);
        if ($shipped_forms !== null && ($existing_entry === null || $refresh_original_from_shipped)) {
            LocalChangeComments::removeOriginal($entry);
            LocalChangeComments::removeFormOriginals($entry);
            foreach ($shipped_forms as $form => $value) {
                LocalChangeComments::setOriginal($entry, $value, $form);
            }
            $entry->setExtractedComments($shipped_entry->getExtractedComments());
        }

        $entry->setPlural(
            $shipped_entry?->getPluralId() ?? $existing_entry?->getPluralId() ?? $identifier . '_plural',
            $forms
        );
        $entry->removeFlag('fuzzy');
        LocalChangeComments::refreshForms($entry, $previous_forms, $forms, $now);

        return $entry;
    }

    /**
     * The shipped values of every module migrated for $lang_key.
     *
     * @return array<string, array<string, string>> module => identifier => shipped value
     */
    public static function loadShippedModules(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key
    ): array {
        $modules = [];
        foreach (self::findShippedModuleFiles($language_file_directory_manager, $ilias_absolute_path, $lang_key) as $module => $shipped_po) {
            $modules[$module] = array_map(
                static fn(array $entry): string => $entry['value'],
                self::loadShippedModuleEntries($shipped_po, $module)
            );
        }

        return $modules;
    }

    /**
     * Every module migrated for $lang_key - i.e. whose shipped `.po` exists, whether or not it can
     * be parsed - with the absolute path of that shipped `.po`.
     *
     * @return array<string, string> module => shipped `.po` path
     */
    public static function findShippedModuleFiles(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key
    ): array {
        $files = [];
        foreach ($language_file_directory_manager->getDirectories() as $directory) {
            $module = $directory->getPrefix();
            if ($module === '') {
                continue;
            }
            $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
            if (is_file($shipped_po)) {
                $files[$module] = $shipped_po;
            }
        }

        return $files;
    }

    /**
     * The entries of $module in the shipped `.po` $shipped_po (see findShippedModuleFiles(); a plural
     * message as its forms, see the class docblock), with
     * their extracted comments ("#.", the counterpart of a `.lang` file's "###" comment) joined into
     * one line - `null` if an entry has none.
     *
     * @return array<string, array{value: string, comment: ?string}> identifier => entry
     * @throws RuntimeException if the file cannot be read or parsed
     */
    public static function loadShippedModuleEntries(string $shipped_po, string $module): array
    {
        $entries = [];
        $catalog = self::readShippedPo($shipped_po);
        $plural_forms = self::pluralFormsOf($catalog);
        foreach (self::moduleEntries($catalog, $module) as $entry) {
            $comment = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', implode(' ', $entry->getExtractedComments())) ?? '');
            foreach (self::shippedValuesOf($entry, $plural_forms) as $identifier => $value) {
                $entries[$identifier] = [
                    'value' => $value,
                    'comment' => $comment === '' ? null : $comment,
                ];
            }
        }

        return $entries;
    }

    /**
     * The identifiers of $module in the shipped `.po` $shipped_po (as in loadShippedModuleEntries(),
     * form keys for a plural message) that are flagged "fuzzy" - not translated yet (for a module
     * converted from the legacy `.lang` files: the dated "... new variable" marker there, or a value
     * copied into several plural forms, see convert_module_to_po.php).
     *
     * @return array<string, true>
     * @throws RuntimeException if the file cannot be read or parsed
     */
    public static function loadShippedFuzzyIdentifiers(string $shipped_po, string $module): array
    {
        $catalog = self::readShippedPo($shipped_po);
        $plural_forms = self::pluralFormsOf($catalog);
        $fuzzy = [];
        foreach (self::moduleEntries($catalog, $module) as $entry) {
            if ($entry->hasFlag('fuzzy')) {
                foreach (array_keys(self::shippedValuesOf($entry, $plural_forms)) as $identifier) {
                    $fuzzy[$identifier] = true;
                }
            }
        }

        return $fuzzy;
    }

    /**
     * The directories the overlay of the modules migrated for any of $lang_keys would have to be
     * written to, but cannot: for every such overlay directory the closest existing path (the
     * directory itself or the first existing parent it would be created in) must be a writable
     * directory. Meant to be checked BEFORE writing, so a missing permission is reported up front -
     * typically because Setup is not run as the web server user (a mandatory requirement, the
     * overlay is written by the web server later on as well) or a file occupies the directory's
     * place. Empty if $client_data_dir is `null` (no overlay is maintained then at all).
     *
     * With $for_user_id the check is evaluated for that user (from the permission bits, owner and
     * group of each path) instead of for the current process - Setup passes the owner of the client
     * data directory (the web server user) when it runs as another user, e.g. root, for whom
     * is_writable() is always `true` and therefore meaningless. Without the POSIX extension the
     * current process is checked instead.
     *
     * @param list<string> $lang_keys
     * @return list<string> the affected overlay directories
     */
    public static function findUnwritableOverlayDirectories(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        array $lang_keys,
        ?string $client_data_dir,
        ?int $for_user_id = null
    ): array {
        if ($client_data_dir === null) {
            return [];
        }

        $unwritable = [];
        foreach ($lang_keys as $lang_key) {
            foreach ($language_file_directory_manager->getDirectories() as $directory) {
                if ($directory->getPrefix() === '') {
                    continue;
                }
                $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
                if (!is_file($shipped_po)) {
                    continue;
                }
                $overlay_directory = dirname(MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key));
                if (!self::isCreatableOrWritableDirectory($overlay_directory, $for_user_id)) {
                    $unwritable[$overlay_directory] = $overlay_directory;
                }
            }
        }

        return array_values($unwritable);
    }

    /**
     * Removes the overlay `.po`+`.mo` pair of $module/$lang_key and its `.lock` file, if present
     * (uninstalling a language or plugin). The lock file is deleted last, while its lock is still
     * held; a process waiting for it notices that and retries on a new lock file (see acquireLock()).
     * A no-op if $module is not a contributed directory or $client_data_dir is `null`.
     */
    public static function removeOverlay(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir
    ): void {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return;
        }

        $base = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        if (!is_file($base . '.mo') && !is_file($base . '.po') && !is_file($base . '.lock')) {
            return;
        }
        self::lockAndRun(
            $directory,
            $lang_key,
            $client_data_dir,
            static fn() => self::removeOverlayFiles($base, $module, $lang_key, $client_data_dir),
            null,
            true
        );
    }

    /**
     * Runs $callback while holding an exclusive lock (flock()) on `<overlay>.lock` next to the
     * overlay of $module/$lang_key, so concurrent writers (two administrators, Setup and the GUI)
     * cannot interleave their read-modify-write of the database row and the overlay files. Callers
     * that merge onto the current state must read that state inside $callback. Re-entrant within a
     * request (sync() and removeOverlay() take the lock themselves, also when called from inside
     * $callback).
     *
     * $callback runs without a lock if $language_file_directory_manager is `null` (the component
     * translation service is not available to the caller), $module is not a contributed directory,
     * $client_data_dir is `null`, the overlay directory does not exist and $module is not migrated
     * for $lang_key (no directory is created for a module that has no overlay), or the lock file
     * cannot be created/opened (e.g. a directory the current user cannot write to - the overlay
     * write itself then fails and is reported by the caller), or the lock file was removed or
     * replaced on each of LOCK_ATTEMPTS attempts (logged, see acquireLock()). The lock file is only
     * removed by removeOverlay() (uninstall), while holding its lock; acquireLock() detects such a
     * removal.
     *
     * @template T
     * @param \Closure():T $callback
     * @param string|null $ilias_absolute_path see loadModuleTranslations()
     * @return T
     */
    public static function withOverlayLock(
        ?LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir,
        \Closure $callback,
        ?string $ilias_absolute_path = null
    ): mixed {
        if ($language_file_directory_manager === null) {
            return $callback();
        }
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return $callback();
        }

        return self::lockAndRun($directory, $lang_key, $client_data_dir, $callback, $ilias_absolute_path, false);
    }

    /**
     * withOverlayLock() for a resolved $directory; with $remove_lock_file the lock file is deleted
     * after $callback succeeded, while the lock is still held - but only by the call that acquired
     * the lock, never by a nested (re-entrant) one, whose caller still relies on it.
     *
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    private static function lockAndRun(
        LanguageFileDirectory $directory,
        string $lang_key,
        string $client_data_dir,
        \Closure $callback,
        ?string $ilias_absolute_path,
        bool $remove_lock_file
    ): mixed {
        $lock_file = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key) . '.lock';
        if (isset(self::$held_locks[$lock_file])) {
            return $callback();
        }
        if (!is_dir(dirname($lock_file)) && !self::isShipped($ilias_absolute_path, $directory, $lang_key)) {
            return $callback();
        }

        $handle = self::acquireLock($lock_file, $client_data_dir);
        if ($handle === null) {
            return $callback();
        }
        self::$held_locks[$lock_file] = true;
        try {
            $result = $callback();
            if ($remove_lock_file) {
                self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $lock_file);
                if (!@unlink($lock_file) && file_exists($lock_file)) {
                    throw new RuntimeException(sprintf('Could not remove the lock file "%s".', $lock_file));
                }
            }
            return $result;
        } finally {
            unset(self::$held_locks[$lock_file]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Opens and exclusively locks $lock_file. A lock obtained on a file that removeOverlay()
     * deleted (or replaced) while this process waited for it does not exclude anybody - a newcomer
     * locks the new file at that path -, so after flock() the handle must still be the file at the
     * path (same inode and device, compared without following a link); otherwise it is released
     * and the whole attempt, including the symbolic link checks, is repeated.
     *
     * @return resource|null `null` if the lock file cannot be created or opened (the callback then
     *         runs unlocked; the overlay write itself fails for the same reason - missing permission,
     *         or a refused symbolic link - and is reported by the caller), and also - logged as a
     *         warning - if it was removed or replaced on every one of LOCK_ATTEMPTS attempts: like
     *         the other "no lock" cases the callback then runs unlocked instead of aborting a write
     *         whose `lng_data` part may already be done
     */
    private static function acquireLock(string $lock_file, string $client_data_dir)
    {
        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            try {
                self::ensureDirectoryExists(dirname($lock_file), $client_data_dir);
                self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $lock_file);
            } catch (RuntimeException) {
                return null;
            }
            // "r" as fallback: flock() needs no write access, so a lock file created by another user
            // (e.g. a Setup run as root) still serializes
            $handle = @fopen($lock_file, 'c') ?: @fopen($lock_file, 'r');
            if ($handle === false) {
                // e.g. missing permission or too many open files - the callback runs unlocked
                self::logWarning(sprintf(
                    'Could not open the lock file "%s" (%s) - continuing without the lock.',
                    $lock_file,
                    error_get_last()['message'] ?? 'unknown error'
                ));
                return null;
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                return null;
            }
            if (self::isHandleOfPath($handle, $lock_file)) {
                return $handle;
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::logWarning(sprintf(
            'Could not lock "%s": it was removed or replaced on each of %d attempts - continuing without the lock.',
            $lock_file,
            self::LOCK_ATTEMPTS
        ));

        return null;
    }

    /**
     * Logs to the `lang` component logger if the logging service is available (not in every Setup
     * context), to the PHP error log otherwise.
     */
    private static function logWarning(string $message): void
    {
        global $DIC;
        if ($DIC instanceof \ILIAS\DI\Container && $DIC->offsetExists('ilLoggerFactory')) {
            $DIC->logger()->forComponent('lang')->warning($message);
            return;
        }
        error_log($message);
    }

    /**
     * @param resource $handle
     */
    private static function isHandleOfPath($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $opened = fstat($handle);
        $current = @lstat($path);

        return $opened !== false
            && $current !== false
            && $opened['ino'] === $current['ino']
            && $opened['dev'] === $current['dev'];
    }


    private static function removeOverlayFiles(string $base, string $module, string $lang_key, string $client_data_dir): void
    {
        // unlink() of a path with a symlinked directory component would delete outside the overlay
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $base . '.po');
        $removed_anything = false;
        foreach ([$base . '.mo', $base . '.po'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (!unlink($file)) {
                throw new RuntimeException(sprintf('Could not remove overlay file "%s".', $file));
            }
            $removed_anything = true;
        }

        if ($removed_anything) {
            \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
        }
    }

    /**
     * The content of $module/$lang_key the way ilLanguage::txt() serves it - the shipped `.po` with
     * the overlay delta (see sync()) on top - or `null` if the module is not migrated for $lang_key
     * (no contributed directory, no shipped `.po`) or $client_data_dir is `null`. The shipped values
     * are the unprocessed ones of the `.po` (as they are written to lng_data), not the markup-cleaned
     * ones ilLanguage serves.
     *
     * An entry without overlay entry carries the shipped value, no local change, and the shipped
     * value as "original". An overlay entry is returned as the overlay holds it, also one whose key
     * is not shipped. The overlay's `.po` is read (it carries the bookkeeping); an overlay `.mo`
     * without it, or an overlay path that is no regular file, throws (see loadLocalChanges()).
     *
     * @param string|null $ilias_absolute_path where the shipped `.po` is looked up, defaults to
     *        ILIAS_ABSOLUTE_PATH (or this installation's root if that constant is not defined)
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     *         identifier => details; local_change_date in the database's "Y-m-d H:i:s" format
     * @throws RuntimeException if the shipped `.po` or the overlay `.po` cannot be read
     */
    public static function loadModuleTranslations(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir,
        ?string $ilias_absolute_path = null
    ): ?array {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return null;
        }
        $ilias_absolute_path ??= defined('ILIAS_ABSOLUTE_PATH') ? (string) ILIAS_ABSOLUTE_PATH : dirname(__DIR__, 5);
        $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
        if (!is_file($shipped_po)) {
            return null;
        }

        $result = [];
        foreach (self::flatModuleValues(self::readShippedPo($shipped_po), $module) as $identifier => $value) {
            $result[$identifier] = [
                'value' => $value,
                'local_change' => false,
                'local_change_date' => null,
                'original' => $value,
            ];
        }

        return array_replace(
            $result,
            // a singular overlay entry of a message shipped as a plural one stands for its default form
            self::mapLegacyPluralValues(
                self::readOverlayEntries(
                    MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key),
                    $module
                ),
                $result
            )
        );
    }

    /**
     * Only the overlay delta of $module/$lang_key (see sync()) - the entries loadModuleTranslations()
     * takes from the overlay, without reading the shipped `.po` -, `[]` without overlay, `null` in the
     * cases loadModuleTranslations() returns `null` for.
     *
     * @param string|null $ilias_absolute_path see loadModuleTranslations()
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     * @throws RuntimeException if the overlay cannot be read - also for an overlay `.mo` without its
     *         `.po` or an overlay path that is no regular file, which must not count as "no overlay"
     */
    public static function loadLocalChanges(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir,
        ?string $ilias_absolute_path = null
    ): ?array {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if (
            $directory === null
            || $client_data_dir === null
            || !self::isShipped($ilias_absolute_path, $directory, $lang_key)
        ) {
            return null;
        }

        return self::readOverlayEntries(
            MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key),
            $module
        );
    }

    /**
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>
     * @throws RuntimeException see loadLocalChanges()
     */
    private static function readOverlayEntries(string $overlay_base, string $module): array
    {
        self::assertReadableOverlay($overlay_base);
        if (!is_file($overlay_base . '.po')) {
            return [];
        }

        $result = [];
        $catalog = TranslationCatalog::fromPoFile($overlay_base . '.po');
        $plural_forms = self::pluralFormsOf($catalog);
        foreach (self::moduleEntries($catalog, $module) as $entry) {
            if (self::isRemarkOnly($entry)) {
                // carries a remark only (see sync()), no value - see loadRemarks()
                continue;
            }
            $is_local_change = LocalChangeComments::getLocalChange($entry) !== null;
            $local_change_date = LocalChangeComments::getLocalChangeAsDatabaseTimestamp($entry);
            if (!$entry->isPlural()) {
                $result[$entry->getId()] = [
                    'value' => $entry->getTranslation(),
                    'local_change' => $is_local_change,
                    'local_change_date' => $local_change_date,
                    'original' => LocalChangeComments::getOriginal($entry),
                ];
                continue;
            }
            // per form: only a form that differs from its "original" is a local change
            foreach (self::formsOf($entry, $plural_forms) as $form => $value) {
                $original = LocalChangeComments::getOriginal($entry, $form);
                $is_form_changed = $is_local_change && ($original === null || $original !== $value);
                $result[PluralFormKey::of($entry->getId(), $form)] = [
                    'value' => $value,
                    'local_change' => $is_form_changed,
                    'local_change_date' => $is_form_changed ? $local_change_date : null,
                    'original' => $original,
                ];
            }
        }

        return $result;
    }

    /**
     * Every module migrated for $lang_key (its shipped `.po` exists) - with or without an overlay,
     * which only exists for local changes. Empty if $client_data_dir is `null` (no overlay is
     * maintained, see loadModuleTranslations()).
     *
     * @param string|null $ilias_absolute_path see loadModuleTranslations()
     * @return list<string>
     */
    public static function getMigratedModules(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        ?string $client_data_dir,
        ?string $ilias_absolute_path = null
    ): array {
        if ($client_data_dir === null) {
            return [];
        }

        $modules = [];
        foreach ($language_file_directory_manager->getDirectories() as $directory) {
            if (
                $directory->getPrefix() !== ''
                && self::isShipped($ilias_absolute_path, $directory, $lang_key)
            ) {
                $modules[] = $directory->getPrefix();
            }
        }

        return $modules;
    }

    /**
     * TranslationCatalog::fromPoFile() for a shipped `.po`, parsed once per request as long as the
     * file content stays unchanged - an installation reads each shipped `.po` several times (shipped
     * values, overlay state, sync). Only the files of one language are kept: reading a file of
     * another language (the next language of an update) empties the cache first, so an update over
     * every language never holds more than the modules of one. The catalog is shared: callers only
     * read it (loadShippedModuleEntries() and loadModuleTranslations() copy the values into arrays),
     * sync() works on a clone.
     *
     * @throws RuntimeException see TranslationCatalog::fromPoFile()
     */
    private static function readShippedPo(string $shipped_po): TranslationCatalog
    {
        $lang_key = preg_match('/_([a-z]{2})\.po\z/', $shipped_po, $matches) === 1 ? $matches[1] : '';
        if ($lang_key !== self::$shipped_catalogs_lang_key) {
            self::$shipped_catalogs = [];
            self::$shipped_catalogs_lang_key = $lang_key;
        }
        // The content hash, not the modification time: that only has a resolution of seconds
        $version = is_file($shipped_po) ? (string) @hash_file('xxh128', $shipped_po) : '';
        $cached = self::$shipped_catalogs[$shipped_po] ?? null;
        if ($cached !== null && $cached[0] === $version && $version !== '') {
            return $cached[1];
        }

        $catalog = TranslationCatalog::fromPoFile($shipped_po);
        unset(self::$shipped_catalogs[$shipped_po]);
        if (count(self::$shipped_catalogs) >= self::SHIPPED_CATALOG_CACHE_SIZE) {
            array_shift(self::$shipped_catalogs);
        }
        self::$shipped_catalogs[$shipped_po] = [$version, $catalog];

        return $catalog;
    }

    private static function isShipped(?string $ilias_absolute_path, LanguageFileDirectory $directory, string $lang_key): bool
    {
        $ilias_absolute_path ??= defined('ILIAS_ABSOLUTE_PATH') ? (string) ILIAS_ABSOLUTE_PATH : dirname(__DIR__, 5);

        return is_file(MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po');
    }

    private static function findDirectory(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $module
    ): ?LanguageFileDirectory {
        if ($module === '') {
            return null;
        }
        foreach ($language_file_directory_manager->getDirectories() as $candidate) {
            if ($candidate->getPrefix() === $module) {
                return $candidate;
            }
        }

        return null;
    }

    private static function isCreatableOrWritableDirectory(string $directory, ?int $for_user_id): bool
    {
        $path = $directory;
        while (!file_exists($path)) {
            // A dangling symlink does not "exist", but mkdir() cannot create anything in its place
            if (is_link($path)) {
                return false;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }

        return is_dir($path) && self::isWritableDirectoryFor($path, $for_user_id);
    }

    /**
     * Whether $for_user_id may create and replace files in the existing directory $path (write and
     * search permission). Root may always. Only the user's primary group and the groups listing the
     * user as a member are taken into account.
     */
    private static function isWritableDirectoryFor(string $path, ?int $for_user_id): bool
    {
        if ($for_user_id === null || !function_exists('posix_getpwuid') || !function_exists('posix_getgrgid')) {
            return is_writable($path);
        }
        if ($for_user_id === 0) {
            return true;
        }
        $stat = @stat($path);
        if ($stat === false) {
            return false;
        }
        $mode = $stat['mode'];
        if ($stat['uid'] === $for_user_id) {
            return ($mode & 0300) === 0300;
        }

        $user = @posix_getpwuid($for_user_id);
        $group = @posix_getgrgid($stat['gid']);
        $in_group = is_array($user) && (
            $user['gid'] === $stat['gid']
            || (is_array($group) && in_array($user['name'], $group['members'], true))
        );
        if ($in_group) {
            return ($mode & 0030) === 0030;
        }

        return ($mode & 0003) === 0003;
    }

    /**
     * Creates $directory (below the overlay root of $client_data_dir) if missing - refusing to create
     * anything through a symbolic link, see assertNoSymbolicLinkBelowOverlayRoot().
     */
    private static function ensureDirectoryExists(string $directory, string $client_data_dir): void
    {
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $directory);
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
        }
        // mkdir() follows a link that appeared in the meantime - checked again afterwards
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $directory);
    }

    /**
     * `<client data dir>/lang`, the root below which every overlay file lives.
     */
    private static function overlayRoot(string $client_data_dir): string
    {
        return rtrim($client_data_dir, '/') . '/lang';
    }

    /**
     * Symbolic links are never followed below the overlay root (CWE-59): every existing path
     * component of $path below `<client data dir>/lang` - $path itself included - must not be a
     * link, so a link placed in the client data directory cannot redirect an overlay write, lock or
     * removal to a file outside of it. The overlay root itself (and everything above it) is the
     * administrator's configuration and may be a link.
     *
     * Accepted residual risk (TOCTOU, CWE-367): this is a check of path names, and PHP offers no
     * openat()/O_NOFOLLOW to bind the following fopen()/mkdir()/unlink() to the checked components.
     * A link swapped in between the check and that call is followed. Doing so needs write access
     * below the overlay root (i.e. the web server user); writes are re-checked afterwards (see
     * AtomicFileWriter, ensureDirectoryExists(), acquireLock()), but only on a best-effort basis:
     * - a link swapped in and restored again before such a re-check stays undetected;
     * - acquireLock()'s fopen($lock_file, 'c') follows a link swapped in after the check and can
     *   thereby create an empty file at the link target (the re-check afterwards only refuses to
     *   use it as lock).
     *
     * @throws RuntimeException for a link, or a $path outside the overlay root
     */
    private static function assertNoSymbolicLinkBelowOverlayRoot(string $client_data_dir, string $path): void
    {
        $root = self::overlayRoot($client_data_dir);
        if (!str_starts_with($path, $root . '/')) {
            throw new RuntimeException(sprintf('"%s" is not below the overlay directory "%s".', $path, $root));
        }

        $current = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                throw new RuntimeException(sprintf('"%s" must not contain "..".', $path));
            }
            $current .= '/' . $component;
            if (is_link($current)) {
                throw new RuntimeException(sprintf('Refusing to follow the symbolic link "%s".', $current));
            }
            if (!file_exists($current)) {
                return;
            }
        }
    }
}

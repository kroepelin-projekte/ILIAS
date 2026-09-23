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
use ILIAS\Language\ComponentTranslation\Gettext\AtomicFileWriter;
use ILIAS\Language\ComponentTranslation\Gettext\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Gettext\TranslationEntry;
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
 * - the overlay (see MigratedLanguageFilePaths) is the per-installation copy ilLanguage reads at
 *   runtime. It exists for every installed language and carries, per entry, the value in use plus
 *   the LocalChangeComments bookkeeping ("original" = shipped value it is tracked against,
 *   "local_change" = when it was changed locally).
 *
 * lng_data/lng_modules keep being written for migrated modules too (rollback-safe fallback); this
 * class only takes care of the files. Every method throws instead of logging - callers decide how
 * to report a failure.
 */
final class MigratedLanguageFileSync
{
    /**
     * Writes the overlay of $module/$lang_key so it holds exactly $entries ("replace" semantics:
     * entries not in $entries are removed). A no-op if $module is not migrated for $lang_key or
     * $client_data_dir is `null`; a missing overlay is created (the overlay reflects that the
     * language is installed).
     *
     * Per entry:
     * - "original" is taken from the shipped `.po` when the overlay is created, and - only with
     *   $refresh_original_from_shipped - re-taken from it on every later call (and removed for an
     *   entry the shipped `.po` no longer contains). Pass `true` only for reconciling writes whose
     *   $entries already went through the shipped/local decision (install, update, "remove local
     *   changes", "apply local changes" - see LanguageInstallationManager); an ordinary admin edit
     *   passes `false` so its baseline is never moved.
     * - "fuzzy" is kept exactly while the value is the shipped one: it is copied from the shipped
     *   entry on creation/refresh when the value equals the shipped value, and removed as soon as a
     *   different value is written.
     * - "local_change" is recomputed via LocalChangeComments::refresh().
     *
     * Both files are written atomically (temporary file + rename). The `.po` only when its content
     * actually changed; the `.mo` whenever it does not hold exactly what TranslationCatalog::toMoString()
     * compiles from the resulting catalog - so a missing, truncated or otherwise stale `.mo` next to an
     * unchanged `.po` is rebuilt as well (that output is deterministic, so a byte comparison suffices
     * and no read-back of the `.mo` is needed).
     *
     * @param array<string, string> $entries identifier => value, the complete, final set for this
     *        module/language - exactly what the caller just wrote to lng_modules.
     */
    public static function sync(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $module,
        array $entries,
        ?string $client_data_dir,
        bool $refresh_original_from_shipped = false
    ): void {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return;
        }
        $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
        if (!is_file($shipped_po)) {
            return;
        }

        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        $overlay_po = $overlay_base . '.po';
        $overlay_mo = $overlay_base . '.mo';

        $shipped = TranslationCatalog::fromPoFile($shipped_po);
        $overlay_exists = is_file($overlay_po);
        $existing = null;
        if ($overlay_exists) {
            try {
                $existing = TranslationCatalog::fromPoFile($overlay_po);
            } catch (RuntimeException) {
                // A corrupt overlay is rebuilt like a missing one: $entries carries every value, and
                // "original"/"local_change" are recomputed against the shipped file - only the
                // previous local_change timestamps are lost.
                $overlay_exists = false;
            }
        }
        // A separate instance even when seeding from the shipped file: the entries taken from it
        // are modified below, while $shipped must keep the shipped values for comparison
        $existing ??= TranslationCatalog::fromPoFile($shipped_po);

        $catalog = new TranslationCatalog();
        foreach ($shipped->getHeaders() as $name => $value) {
            $catalog->setHeader($name, $value);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        ksort($entries, SORT_STRING);
        foreach ($entries as $identifier => $value) {
            $identifier = (string) $identifier;
            $value = (string) $value;
            $shipped_entry = $shipped->find($module, $identifier);
            $entry = $existing->find($module, $identifier) ?? new TranslationEntry($module, $identifier);
            $previous_value = $entry->getTranslation();
            $reconcile = !$overlay_exists || $refresh_original_from_shipped;

            if ($reconcile) {
                if ($shipped_entry !== null) {
                    LocalChangeComments::setOriginal($entry, $shipped_entry->getTranslation());
                    $entry->setExtractedComments($shipped_entry->getExtractedComments());
                } else {
                    LocalChangeComments::removeOriginal($entry);
                }
            }

            $entry->translate($value);
            if ($reconcile) {
                $is_shipped_fuzzy = $shipped_entry !== null
                    && $shipped_entry->hasFlag('fuzzy')
                    && $shipped_entry->getTranslation() === $value;
                $is_shipped_fuzzy ? $entry->addFlag('fuzzy') : $entry->removeFlag('fuzzy');
            } elseif ($value !== $previous_value) {
                // A value someone actually wrote is, by definition, no longer an unreviewed placeholder
                $entry->removeFlag('fuzzy');
            }

            LocalChangeComments::refresh($entry, $previous_value, $value, $now);
            $catalog->add($entry);
        }

        $po_content = $catalog->toPoString();
        $mo_content = $catalog->toMoString();
        $po_unchanged = $overlay_exists && $po_content === file_get_contents($overlay_po);
        $mo_unchanged = is_file($overlay_mo) && $mo_content === @file_get_contents($overlay_mo);
        if ($po_unchanged && $mo_unchanged) {
            return;
        }

        self::ensureDirectoryExists(dirname($overlay_po));
        // The .po first: it carries the bookkeeping the .mo lacks, and ilLanguage only reads the
        // .mo - so a failure in between leaves the previous .mo being served (a .po that is newer
        // than its .mo is repaired by the next sync, see above), never a new .mo whose bookkeeping
        // got lost.
        if (!$po_unchanged) {
            AtomicFileWriter::write($overlay_po, $po_content);
        }
        AtomicFileWriter::write($overlay_mo, $mo_content);

        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
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
     * The entries of $module in the shipped `.po` $shipped_po (see findShippedModuleFiles()), with
     * their extracted comments ("#.", the counterpart of a `.lang` file's "###" comment) joined into
     * one line - `null` if an entry has none.
     *
     * @return array<string, array{value: string, comment: ?string}> identifier => entry
     * @throws RuntimeException if the file cannot be read or parsed
     */
    public static function loadShippedModuleEntries(string $shipped_po, string $module): array
    {
        $entries = [];
        foreach (TranslationCatalog::fromPoFile($shipped_po)->getEntries() as $entry) {
            if ($entry->getContext() !== $module) {
                continue;
            }
            $comment = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', implode(' ', $entry->getExtractedComments())) ?? '');
            $entries[$entry->getId()] = [
                'value' => $entry->getTranslation(),
                'comment' => $comment === '' ? null : $comment,
            ];
        }

        return $entries;
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
     * @param list<string> $lang_keys
     * @return list<string> the affected overlay directories
     */
    public static function findUnwritableOverlayDirectories(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        array $lang_keys,
        ?string $client_data_dir
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
                if (!self::isCreatableOrWritableDirectory($overlay_directory)) {
                    $unwritable[$overlay_directory] = $overlay_directory;
                }
            }
        }

        return array_values($unwritable);
    }

    /**
     * Removes the overlay `.po`+`.mo` pair of $module/$lang_key, if present (uninstalling a language
     * or plugin). A no-op if $module is not a contributed directory or $client_data_dir is `null`.
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
     * The overlay content of $module/$lang_key - what ilLanguage::txt() serves for it - or `null`
     * if there is no complete overlay (both `.po` and `.mo`) for it.
     *
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     *         identifier => details; local_change_date in the database's "Y-m-d H:i:s" format
     */
    public static function loadModuleTranslations(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir
    ): ?array {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null || $client_data_dir === null) {
            return null;
        }

        $base_path = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        if (!is_file($base_path . '.mo') || !is_file($base_path . '.po')) {
            return null;
        }

        $result = [];
        foreach (TranslationCatalog::fromPoFile($base_path . '.po')->getEntries() as $entry) {
            if ($entry->getContext() !== $module) {
                continue;
            }
            $local_change_date = LocalChangeComments::getLocalChangeAsDatabaseTimestamp($entry);
            $result[$entry->getId()] = [
                'value' => $entry->getTranslation(),
                'local_change' => LocalChangeComments::getLocalChange($entry) !== null,
                'local_change_date' => $local_change_date,
                'original' => LocalChangeComments::getOriginal($entry),
            ];
        }

        return $result;
    }

    /**
     * Every module that has a complete overlay for $lang_key.
     *
     * @return list<string>
     */
    public static function getMigratedModules(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        ?string $client_data_dir
    ): array {
        if ($client_data_dir === null) {
            return [];
        }

        $modules = [];
        foreach ($language_file_directory_manager->getDirectories() as $directory) {
            $base_path = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
            if ($directory->getPrefix() !== '' && is_file($base_path . '.mo') && is_file($base_path . '.po')) {
                $modules[] = $directory->getPrefix();
            }
        }

        return $modules;
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

    private static function isCreatableOrWritableDirectory(string $directory): bool
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

        return is_dir($path) && is_writable($path);
    }

    private static function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
        }
    }
}

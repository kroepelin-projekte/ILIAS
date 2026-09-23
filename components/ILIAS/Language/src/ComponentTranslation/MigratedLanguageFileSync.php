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
use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\Gettext\Entry;
use ILIAS\Language\ComponentTranslation\Gettext\MoWriter;
use ILIAS\Language\ComponentTranslation\Gettext\PoParser;
use ILIAS\Language\ComponentTranslation\Gettext\PoWriter;
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
     * Both files are written atomically (temporary file + rename) and only when the `.po` content
     * actually changed or the `.mo` is missing.
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

        $shipped = PoParser::parseFile($shipped_po);
        $overlay_exists = is_file($overlay_po);
        $existing = null;
        if ($overlay_exists) {
            try {
                $existing = PoParser::parseFile($overlay_po);
            } catch (RuntimeException) {
                // A corrupt overlay is rebuilt like a missing one: $entries carries every value, and
                // "original"/"local_change" are recomputed against the shipped file - only the
                // previous local_change timestamps are lost.
                $overlay_exists = false;
            }
        }
        // a separate instance even when seeding from the shipped file: the entries taken from it
        // are modified below, while $shipped must keep the shipped values for comparison
        $existing ??= PoParser::parseFile($shipped_po);

        $catalog = new Catalog();
        foreach ($shipped->getHeaders() as $name => $value) {
            $catalog->setHeader($name, $value);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        ksort($entries, SORT_STRING);
        foreach ($entries as $identifier => $value) {
            $identifier = (string) $identifier;
            $value = (string) $value;
            $shipped_entry = $shipped->find($module, $identifier);
            $entry = $existing->find($module, $identifier) ?? new Entry($module, $identifier);
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
                // a value someone actually wrote is, by definition, no longer an unreviewed placeholder
                $entry->removeFlag('fuzzy');
            }

            LocalChangeComments::refresh($entry, $previous_value, $value, $now);
            $catalog->add($entry);
        }

        $po_content = PoWriter::toString($catalog);
        $po_unchanged = $overlay_exists && $po_content === file_get_contents($overlay_po);
        if ($po_unchanged && is_file($overlay_mo)) {
            return;
        }

        self::ensureDirectoryExists(dirname($overlay_po));
        // .po first: it carries the bookkeeping the .mo lacks, and ilLanguage only reads the .mo -
        // so a failure in between leaves a stale-but-consistent .mo being served, never a new .mo
        // whose bookkeeping got lost.
        if (!$po_unchanged) {
            AtomicFileWriter::write($overlay_po, $po_content);
        }
        AtomicFileWriter::write($overlay_mo, MoWriter::toString($catalog));

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
        foreach ($language_file_directory_manager->getDirectories() as $directory) {
            $module = $directory->getPrefix();
            if ($module === '') {
                continue;
            }
            $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
            if (!is_file($shipped_po)) {
                continue;
            }
            $modules[$module] = [];
            foreach (PoParser::parseFile($shipped_po)->getEntries() as $entry) {
                if ($entry->getContext() === $module) {
                    $modules[$module][$entry->getId()] = $entry->getTranslation();
                }
            }
        }

        return $modules;
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
        foreach (PoParser::parseFile($base_path . '.po')->getEntries() as $entry) {
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

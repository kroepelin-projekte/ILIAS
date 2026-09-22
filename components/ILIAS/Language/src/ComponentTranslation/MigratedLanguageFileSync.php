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
use Gettext\Loader\PoLoader;
use Gettext\Generator\PoGenerator;
use Gettext\Generator\MoGenerator;
use Gettext\Translation;
use RuntimeException;

/**
 * PO/MO pilot (see components/ILIAS/Language/tools/po-migration/README.md, "Schreibpfad"): mirrors a
 * migrated module's complete identifier => value map into its `.po`/`.mo` files, with the exact same
 * "full replace" semantics the caller just applied to lng_modules - entries no longer present are
 * removed, not just left stale.
 *
 * Extracted from what used to be ilObjLanguage::syncMigratedLanguageFile() so that both DIC-based
 * legacy write paths (ilObjLanguage::replaceLangModule(), ilObjLanguageExt::importLanguageFile()) and
 * the DI-based ILIAS\Language\Setup\LanguageInstallationManager - which never had access to
 * ilObjLanguage's private helper and, unlike it, does not use global $DIC at all - can call the same
 * logic instead of maintaining separate copies. The three call sites still each decide for themselves
 * whether/how to log a failure; this method always throws instead of swallowing, since it has no
 * logger of its own to swallow into.
 */
final class MigratedLanguageFileSync
{
    /**
     * A pure no-op for any module that hasn't contributed a LanguageFileDirectory, or that has no
     * `.po` file for $lang_key at all - this never creates a new migrated module or a new language on
     * its own. Gated on the `.po` file (the shipped, version-controlled source of truth - see
     * tools/po-migration/README.md - exactly like the legacy `.lang` files before it), not the
     * compiled `.mo`: the `.mo` is a derived runtime artifact that $create_missing_mo below controls.
     *
     * $create_missing_mo distinguishes an install action (a language installed for the first time, via
     * ILIAS Setup or the language administration GUI) from an update or edit action (refreshing an
     * already-installed language, or an ordinary admin-GUI translation edit): only an install may
     * compile a `.mo` that doesn't exist yet. An update leaves a still-missing `.mo` missing - it only
     * recompiles a `.mo` that is already there. This also preserves the pilot's "roll back one
     * language" lever (see README, "Rollback"): removing only the `.mo` file stays rolled back across
     * ordinary update/edit calls, and is only ever undone by an explicit re-install of that language.
     *
     * $ilias_absolute_path is taken as a parameter rather than read from the ILIAS_ABSOLUTE_PATH
     * global constant: this class also runs from Setup contexts (LanguageInstallationManager, which
     * is constructed before a full DIC/bootstrap exists and is itself given its base path via
     * constructor injection - see ilSetupLanguage, which computes it the same, constant-free way),
     * where that constant is not reliably defined yet.
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
        bool $create_missing_mo = false
    ): void {
        $directory = null;
        foreach ($language_file_directory_manager->getDirectories() as $candidate) {
            if ($candidate->getPrefix() === $module) {
                $directory = $candidate;
                break;
            }
        }
        if ($directory === null) {
            return;
        }

        $base_path = rtrim($ilias_absolute_path, '/') . '/' . ltrim($directory->getPath(), '/')
            . $module . '_' . $lang_key;
        $po_file = $base_path . '.po';
        if (!is_file($po_file)) {
            return;
        }
        $mo_file = $base_path . '.mo';
        $mo_is_missing = !is_file($mo_file);
        if ($mo_is_missing && !$create_missing_mo) {
            return;
        }

        $translations = new PoLoader()->loadFile($po_file);

        $stale = [];
        foreach ($translations->getTranslations() as $translation) {
            if (
                $translation->getContext() === $module
                && !array_key_exists($translation->getOriginal(), $entries)
            ) {
                $stale[] = $translation;
            }
        }
        foreach ($stale as $translation) {
            $translations->remove($translation);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($entries as $identifier => $value) {
            $value = (string) $value;
            $translation = $translations->find($module, $identifier);
            $previous_value = $translation?->getTranslation() ?? '';
            if ($translation === null) {
                $translation = Translation::create($module, $identifier);
                $translations->add($translation);
            }
            // an explicit write via this path always provides a real value, so it is by
            // definition no longer just an untranslated placeholder
            $translation->translate($value);
            $translation->getFlags()->delete('fuzzy');

            // "locally changed" tracking (see LocalChangeComments): compares this write against
            // the value the conversion tool shipped at migration time, not against $previous_value
            // - that one is only used to avoid bumping an already-current timestamp on a no-op
            // re-save of an already locally-changed value.
            LocalChangeComments::refresh($translation, $previous_value, $value, $now);
        }

        // Byte-for-byte no-op guard: a caller that already matches the file (e.g. every
        // subsequent Setup update run, or a "remove local changes" pass on an already-clean
        // language) would otherwise still rewrite both files and invalidate the cache on every
        // single call - harmless for a one-off GUI edit, but LanguageInstallationManager calls this
        // once per module per language on every install/update run. Comparing the generated .po
        // content (not just $stale/$entries) also covers every side effect above - "original"
        // comments, fuzzy flags, LocalChangeComments::refresh()'s local_change timestamp - not just
        // the translated value, without duplicating that logic here. A bootstrap install still needs
        // to compile the .mo even when the .po content itself didn't change - $mo_is_missing keeps
        // that case from being swallowed by this guard.
        $po_content = new PoGenerator()->generateString($translations);
        $po_unchanged = $po_content === file_get_contents($po_file);
        if ($po_unchanged && !$mo_is_missing) {
            return;
        }

        if (!$po_unchanged && file_put_contents($po_file, $po_content) === false) {
            throw new RuntimeException(sprintf('Could not write PO file "%s".', $po_file));
        }
        // Written strictly after the .po succeeds: if only the .mo turns out to be unwritable
        // (e.g. that one file, not the directory, is read-only), the two files would otherwise be
        // left in different states - .po updated, .mo stale - both silently, since generateFile()
        // reports failure via a return value, not an exception.
        if (!new MoGenerator()->includeHeaders(true)->generateFile($translations, $mo_file)) {
            throw new RuntimeException(sprintf('Could not write MO file "%s".', $mo_file));
        }

        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
    }

    /**
     * The uninstall counterpart to sync()'s $create_missing_mo: removes this migrated module's
     * compiled `.mo` file for $lang_key, if one exists, deliberately leaving the `.po` untouched - the
     * `.po` ships like the legacy `.lang` file did (see tools/po-migration/README.md), the `.mo` is the
     * derived runtime artifact tied to whether the language is actually installed. A pure no-op for a
     * module that hasn't contributed a LanguageFileDirectory, or that has no `.mo` file for $lang_key
     * to begin with - same posture as sync() above, including "always throws instead of swallowing",
     * since it has no logger of its own; the caller decides whether/how to log a failure, exactly like
     * sync()'s three call sites already do.
     *
     * This is exactly Rollback-Ebene 1 from the README ("nur die .mo-Datei entfernen"), now applied
     * automatically whenever a language is uninstalled instead of only ever being a manual lever -
     * without it, a stale `.mo` kept serving a migrated module's now-uninstalled content forever (e.g.
     * via ilLanguage::txtlng(), which never checks whether $lang_key is still installed).
     *
     * One module per call, mirroring sync()'s contract - a caller uninstalling a language iterates
     * every contributed directory itself (see ilObjLanguage::removeMigratedMoFiles()), the same way
     * insertLanguage() iterates every module before calling sync() once per module.
     */
    public static function removeMoFile(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $module
    ): void {
        $directory = null;
        foreach ($language_file_directory_manager->getDirectories() as $candidate) {
            if ($candidate->getPrefix() === $module) {
                $directory = $candidate;
                break;
            }
        }
        if ($directory === null) {
            return;
        }

        $mo_file = rtrim($ilias_absolute_path, '/') . '/' . ltrim($directory->getPath(), '/')
            . $module . '_' . $lang_key . '.mo';
        if (!is_file($mo_file)) {
            return;
        }
        if (!unlink($mo_file)) {
            throw new RuntimeException(sprintf('Could not remove MO file "%s".', $mo_file));
        }

        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
    }
}

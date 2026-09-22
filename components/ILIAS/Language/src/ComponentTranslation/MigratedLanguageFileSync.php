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
 * PO/MO pilot: mirrors a migrated module's complete identifier => value map into a per-instance
 * "overlay" `.po`/`.mo` pair kept entirely outside the git-tracked component tree, with the exact
 * same "full replace" semantics the caller just applied to lng_modules - entries no longer present
 * are removed, not just left stale.
 *
 * Where the shipped `.po` (e.g. components/ILIAS/TermsOfService/lang/tos_de.po) and this overlay
 * live is a deliberate split:
 * - the shipped `.po`/`.pot` is written exactly once, by tools/po-migration/convert_module_to_po.php,
 *   and never again by anything at runtime - it ships like the legacy `.lang` file did, and stays
 *   git-clean forever after that initial conversion.
 * - the overlay - one complete `.po`+`.mo` pair per migrated module+language, under
 *   CLIENT_DATA_DIR . '/lang/' . <the same path the shipped file lives at, relative to the ILIAS root> -
 *   is what every install/update/admin-GUI edit actually writes, and what ilLanguage actually reads at
 *   runtime. It mirrors exactly what `lng_modules` already did for the legacy DB path: one full,
 *   per-installation, per-language materialized copy, kept outside version control (CLIENT_DATA_DIR is
 *   never part of the git-tracked ILIAS_ABSOLUTE_PATH tree - see ClientIdReadObjective and
 *   ilFileSystemComponentDataDirectoryCreatedObjective for the same convention used elsewhere).
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
     * A pure no-op for any module that hasn't contributed a LanguageFileDirectory, that has no `.po`
     * file for $lang_key at all (the shipped, version-controlled source of truth - exactly like the
     * legacy `.lang` files before it), or for which
     * $client_data_dir cannot (yet) be resolved by the caller - this never creates a new migrated
     * module or a new language on its own, and never falls back to writing into the shipped directory
     * instead (that would reintroduce exactly the git-dirtying problem the overlay exists to avoid).
     *
     * $create_missing_mo distinguishes an install action (a language installed for the first time, via
     * ILIAS Setup or the language administration GUI) from an update or edit action (refreshing an
     * already-installed language, or an ordinary admin-GUI translation edit): only an install may
     * compile an overlay `.mo` that doesn't exist yet. An update leaves a still-missing overlay `.mo`
     * missing - it only recompiles one that is already there. This also preserves the pilot's "roll
     * back one language" lever: removing only the overlay files stays rolled back across ordinary
     * update/edit calls, and is only ever undone by an explicit re-install of that language.
     *
     * $ilias_absolute_path is taken as a parameter rather than read from the ILIAS_ABSOLUTE_PATH
     * global constant: this class also runs from Setup contexts (LanguageInstallationManager, which
     * is constructed before a full DIC/bootstrap exists and is itself given its base path via
     * constructor injection - see ilSetupLanguage, which computes it the same, constant-free way),
     * where that constant is not reliably defined yet. $client_data_dir is `null` whenever a caller
     * cannot (yet) resolve one the same way - see ilSetupLanguage::resolveClientDataDir() for the one
     * context where that can genuinely happen (Setup, before any client exists at all).
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
        bool $create_missing_mo = false,
        ?string $client_data_dir = null
    ): void {
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null) {
            return;
        }

        $shipped_po = self::shippedBasePath($ilias_absolute_path, $directory, $module, $lang_key) . '.po';
        if (!is_file($shipped_po)) {
            return;
        }
        if ($client_data_dir === null) {
            return;
        }

        $overlay_base = self::overlayBasePath($client_data_dir, $directory, $module, $lang_key);
        $overlay_po = $overlay_base . '.po';
        $overlay_mo = $overlay_base . '.mo';
        $overlay_mo_missing = !is_file($overlay_mo);
        if ($overlay_mo_missing && !$create_missing_mo) {
            return;
        }

        // The overlay, once it exists, is the base for every further write - not the shipped file -
        // so that an already-set "local_change" timestamp (see LocalChangeComments) survives an
        // unrelated write to a different identifier of the same module/language instead of being
        // spuriously bumped on every single sync() call. Only the very first sync for a module+
        // language (overlay does not exist yet) seeds from the shipped `.po`, which is the only place
        // carrying every identifier's "original" comment to begin with.
        $translations = new PoLoader()->loadFile(is_file($overlay_po) ? $overlay_po : $shipped_po);

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
        // to compile the .mo even when the .po content itself didn't change - $overlay_mo_missing
        // keeps that case from being swallowed by this guard.
        $po_content = new PoGenerator()->generateString($translations);
        $po_unchanged = is_file($overlay_po) && $po_content === file_get_contents($overlay_po);
        if ($po_unchanged && !$overlay_mo_missing) {
            return;
        }

        self::ensureDirectoryExists(dirname($overlay_po));

        if (!$po_unchanged && file_put_contents($overlay_po, $po_content) === false) {
            throw new RuntimeException(sprintf('Could not write PO file "%s".', $overlay_po));
        }
        // Written strictly after the .po succeeds: if only the .mo turns out to be unwritable
        // (e.g. that one file, not the directory, is read-only), the two files would otherwise be
        // left in different states - .po updated, .mo stale - both silently, since generateFile()
        // reports failure via a return value, not an exception.
        if (!new MoGenerator()->includeHeaders(true)->generateFile($translations, $overlay_mo)) {
            throw new RuntimeException(sprintf('Could not write MO file "%s".', $overlay_mo));
        }

        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);
    }

    /**
     * The uninstall counterpart to sync()'s $create_missing_mo: removes this migrated module's
     * overlay `.po`+`.mo` pair for $lang_key, if present. Unlike the shipped `.po` (which never gets
     * removed - it ships like the legacy `.lang` file did), the overlay is purely derived, per-instance
     * state with no "precious source" status of its own, so both files are removed together rather
     * than keeping one of them around the way the old (pre-overlay) design kept the shipped `.po`.
     * A pure no-op for a module that hasn't contributed a LanguageFileDirectory, that has no overlay
     * for $lang_key to begin with, or for which $client_data_dir cannot be resolved - same posture as
     * sync() above, including "always throws instead of swallowing", since it has no logger of its
     * own; the caller decides whether/how to log a failure, exactly like sync()'s call sites already
     * do.
     *
     * This is exactly the "remove only the overlay files for one language" rollback lever, now
     * applied automatically whenever a language is uninstalled instead of only ever being a manual
     * lever - without it, a stale overlay kept serving a migrated module's now-uninstalled content
     * forever (e.g. via ilLanguage::txtlng(), which never checks whether $lang_key is still
     * installed).
     *
     * One module per call, mirroring sync()'s contract - a caller uninstalling a language iterates
     * every contributed directory itself (see ilObjLanguage::removeMigratedMoFiles()), the same way
     * insertLanguage() iterates every module before calling sync() once per module.
     */
    public static function removeOverlay(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir
    ): void {
        if ($client_data_dir === null) {
            return;
        }
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null) {
            return;
        }

        $base = self::overlayBasePath($client_data_dir, $directory, $module, $lang_key);
        $removed_anything = false;
        foreach ([$base . '.po', $base . '.mo'] as $file) {
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
     * The read-side counterpart to sync(): for a module+language this pilot has migrated, the overlay
     * `.po` file - not `lng_data` - is now the authoritative content, exactly matching what
     * ilLanguage::txt() itself
     * would serve (gated on the overlay `.mo` existing too, same as ilLanguage's own
     * loadFromMigratedLanguageFile()). Used by ilObjLanguageExt's admin-GUI listing methods
     * (_getValues(), _getModules()) to source a migrated module's values instead of the (still
     * dual-written, but no longer authoritative) `lng_data` row.
     *
     * A pure no-op (returns `null`) for any module that hasn't contributed a LanguageFileDirectory,
     * that has no compiled overlay `.mo` file for $lang_key yet, or for which $client_data_dir cannot
     * be resolved.
     *
     * @return array<string, array{value: string, local_change: bool}>|null identifier => details, or
     *         `null` if this module+language isn't (yet) migrated.
     */
    public static function loadModuleTranslations(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $lang_key,
        string $module,
        ?string $client_data_dir
    ): ?array {
        if ($client_data_dir === null) {
            return null;
        }
        $directory = self::findDirectory($language_file_directory_manager, $module);
        if ($directory === null) {
            return null;
        }

        $base_path = self::overlayBasePath($client_data_dir, $directory, $module, $lang_key);
        if (!is_file($base_path . '.mo') || !is_file($base_path . '.po')) {
            return null;
        }

        $result = [];
        foreach (new PoLoader()->loadFile($base_path . '.po')->getTranslations() as $translation) {
            if ($translation->getContext() !== $module) {
                continue;
            }
            $result[$translation->getOriginal()] = [
                'value' => $translation->getTranslation() ?? '',
                'local_change' => LocalChangeComments::getLocalChange($translation) !== null,
            ];
        }

        return $result;
    }

    /**
     * Every module currently migrated for $lang_key - i.e. every contributed LanguageFileDirectory
     * that also has a compiled overlay `.mo` for it (see loadModuleTranslations()'s docblock for why
     * `.mo`, not just `.po`, is the gate). Used by ilObjLanguageExt::_getModules() to list a migrated
     * module even on the (today purely hypothetical, thanks to the dual-write) chance that it has no
     * `lng_data` row at all.
     *
     * Returns an empty list (not every contributed module) when $client_data_dir cannot be resolved -
     * with no overlay location, nothing can be "migrated" from this pilot's point of view yet.
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
            $module = $directory->getPrefix();
            $base_path = self::overlayBasePath($client_data_dir, $directory, $module, $lang_key);
            if (is_file($base_path . '.mo') && is_file($base_path . '.po')) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    private static function findDirectory(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $module
    ): ?LanguageFileDirectory {
        foreach ($language_file_directory_manager->getDirectories() as $candidate) {
            if ($candidate->getPrefix() === $module) {
                return $candidate;
            }
        }

        return null;
    }

    private static function shippedBasePath(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $module,
        string $lang_key
    ): string {
        return rtrim($ilias_absolute_path, '/') . '/' . ltrim($directory->getPath(), '/')
            . $module . '_' . $lang_key;
    }

    /**
     * Mirrors the shipped file's own location one-to-one, just rooted under the per-instance
     * $client_data_dir instead of the git-tracked ILIAS root - see this class' own docblock for why.
     * $directory->getPath() already returns the exact, correctly-cased path segment (e.g.
     * "components/ILIAS/TermsOfService/lang/"), so no separate vendor/component-name bookkeeping is
     * needed here - CLIENT_DATA_DIR itself is the same base ilObjLanguageExt::getDataPath() already
     * uses for its own "lang_data" subdirectory.
     */
    private static function overlayBasePath(
        string $client_data_dir,
        LanguageFileDirectory $directory,
        string $module,
        string $lang_key
    ): string {
        return rtrim($client_data_dir, '/') . '/lang/' . ltrim($directory->getPath(), '/')
            . $module . '_' . $lang_key;
    }

    private static function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $directory));
        }
    }
}

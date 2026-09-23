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
     * How often acquireLock() retries when the lock file it locked was removed or replaced meanwhile.
     */
    private const int LOCK_ATTEMPTS = 5;

    /**
     * The lock files this request currently holds, see withOverlayLock().
     *
     * @var array<string, true>
     */
    private static array $held_locks = [];

    /**
     * Writes the overlay of $module/$lang_key so it holds exactly $entries ("replace" semantics:
     * entries not in $entries are removed). A no-op if $module is not a contributed directory or
     * $client_data_dir is `null`; a missing overlay is created (the overlay reflects that the
     * language is installed). Without a shipped `.po` for $lang_key (the module or the language was
     * dropped from the shipped files, possibly only temporarily) this is a no-op as well: an existing
     * overlay is left untouched - it is not read while the shipped `.po` is missing (see
     * loadModuleTranslations(), getMigratedModules(), ilLanguage), and once the `.po` is back the
     * next reconciling write compares against its preserved "original" values. An overlay is only
     * ever removed when its language is uninstalled (removeOverlay()).
     *
     * The whole read-modify-write runs under the overlay lock (see withOverlayLock()), and the
     * existing overlay is read only after it was acquired.
     *
     * Per entry:
     * - "original" is taken from the shipped `.po` when the overlay is created, and - only with
     *   $refresh_original_from_shipped - re-taken from it on every later call. An entry the shipped
     *   `.po` no longer contains keeps the "original" it had, so a later update can still tell an
     *   unchanged entry (dropped there) from a real local change (kept). Pass `true` only for
     *   reconciling writes whose $entries already went through the shipped/local decision (install, update, "remove local
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

        self::withOverlayLock(
            $language_file_directory_manager,
            $lang_key,
            $module,
            $client_data_dir,
            static fn() => self::syncLocked(
                $language_file_directory_manager,
                $ilias_absolute_path,
                $directory,
                $lang_key,
                $module,
                $entries,
                $client_data_dir,
                $refresh_original_from_shipped
            ),
            $ilias_absolute_path
        );
    }

    /**
     * sync() once the overlay lock is held.
     *
     * @param array<string, string> $entries
     */
    private static function syncLocked(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key,
        string $module,
        array $entries,
        string $client_data_dir,
        bool $refresh_original_from_shipped
    ): void {
        $shipped_po = MigratedLanguageFilePaths::shippedBasePath($ilias_absolute_path, $directory, $lang_key) . '.po';
        if (!is_file($shipped_po)) {
            // not migrated (any more) for this language: leave an existing overlay alone, see above
            return;
        }

        $overlay_base = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        $overlay_po = $overlay_base . '.po';
        $overlay_mo = $overlay_base . '.mo';
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_po);
        self::assertNoSymbolicLinkBelowOverlayRoot($client_data_dir, $overlay_mo);

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
                // An entry the shipped .po no longer contains keeps its previous "original" - see
                // above and LanguageInstallationManager::resolveMigratedModule()
                if ($shipped_entry !== null) {
                    LocalChangeComments::setOriginal($entry, $shipped_entry->getTranslation());
                    $entry->setExtractedComments($shipped_entry->getExtractedComments());
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
     * The overlay content of $module/$lang_key - what ilLanguage::txt() serves for it - or `null`
     * if there is no complete overlay (both `.po` and `.mo`) for it, or the module is no longer
     * migrated for $lang_key (no shipped `.po`, see sync()).
     *
     * @param string|null $ilias_absolute_path where the shipped `.po` is looked up, defaults to
     *        ILIAS_ABSOLUTE_PATH (or this installation's root if that constant is not defined)
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     *         identifier => details; local_change_date in the database's "Y-m-d H:i:s" format
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

        $base_path = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
        if (
            !is_file($base_path . '.mo')
            || !is_file($base_path . '.po')
            || !self::isShipped($ilias_absolute_path, $directory, $lang_key)
        ) {
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
     * Every module that has a complete overlay for $lang_key and is still migrated for it (its
     * shipped `.po` exists, see sync()).
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
            $base_path = MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key);
            if (
                $directory->getPrefix() !== ''
                && is_file($base_path . '.mo')
                && is_file($base_path . '.po')
                && self::isShipped($ilias_absolute_path, $directory, $lang_key)
            ) {
                $modules[] = $directory->getPrefix();
            }
        }

        return $modules;
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

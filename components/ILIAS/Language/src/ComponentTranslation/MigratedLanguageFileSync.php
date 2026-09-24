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
        ?bool $expected_overlay_exists = null
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
        if ($delta === [] && !self::hasOverlayFiles($overlay_base)) {
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
                $expected_overlay_exists
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
     * An empty value resets the entry to its shipped value (provisional decision, to be confirmed;
     * change it here only). An empty value of a key that is not shipped stays empty.
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
     * databaseValues() for $module/$lang_key, reading its shipped `.po` - $entries unchanged if the
     * module is not migrated for $lang_key or its shipped `.po` cannot be read (that is reported by
     * the overlay write).
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

        return self::databaseValues($entries, $shipped);
    }

    /**
     * Whether $value belongs into the overlay (the delta) given the $shipped_value the shipped `.po`
     * carries for its key (`null`: the key is not shipped) - see resolveLocalValue(). An empty value
     * never does.
     */
    public static function belongsInDelta(?string $shipped_value, string $value): bool
    {
        $value = self::resolveLocalValue($shipped_value, $value);

        return $value !== '' && $value !== $shipped_value;
    }

    /**
     * @param array<string, string> $entries
     * @return array<string, string> identifier => value, sorted by identifier
     */
    private static function deltaOf(TranslationCatalog $shipped, string $module, array $entries): array
    {
        $delta = [];
        foreach ($entries as $identifier => $value) {
            $identifier = (string) $identifier;
            $value = (string) $value;
            if (self::belongsInDelta($shipped->find($module, $identifier)?->getTranslation(), $value)) {
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
        ?bool $expected_overlay_exists
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

        if ($delta === []) {
            self::removeOverlayFiles($overlay_base, $module, $lang_key, $client_data_dir);
            return;
        }

        $overlay_exists = is_file($overlay_po);
        $existing = null;
        if ($overlay_exists) {
            try {
                $existing = TranslationCatalog::fromPoFile($overlay_po);
            } catch (RuntimeException) {
                // A corrupt overlay is rebuilt like a missing one: $delta carries every value, and
                // "original"/"local_change" are recomputed against the shipped file - only the
                // previous local_change timestamps are lost.
                $overlay_exists = false;
            }
        }

        $catalog = new TranslationCatalog();
        foreach ($shipped->getHeaders() as $name => $value) {
            $catalog->setHeader($name, $value);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($delta as $identifier => $value) {
            $identifier = (string) $identifier;
            $shipped_entry = $shipped->find($module, $identifier);
            $existing_entry = $existing?->find($module, $identifier);
            $entry = $existing_entry ?? new TranslationEntry($module, $identifier);
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
        foreach (self::readShippedPo($shipped_po)->getEntries() as $entry) {
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
        foreach (self::readShippedPo($shipped_po)->getEntries() as $entry) {
            if ($entry->getContext() !== $module) {
                continue;
            }
            $result[$entry->getId()] = [
                'value' => $entry->getTranslation(),
                'local_change' => false,
                'local_change_date' => null,
                'original' => $entry->getTranslation(),
            ];
        }

        return array_replace(
            $result,
            self::readOverlayEntries(
                MigratedLanguageFilePaths::overlayBasePath($client_data_dir, $directory, $lang_key),
                $module
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
        foreach (TranslationCatalog::fromPoFile($overlay_base . '.po')->getEntries() as $entry) {
            if ($entry->getContext() !== $module) {
                continue;
            }
            $result[$entry->getId()] = [
                'value' => $entry->getTranslation(),
                'local_change' => LocalChangeComments::getLocalChange($entry) !== null,
                'local_change_date' => LocalChangeComments::getLocalChangeAsDatabaseTimestamp($entry),
                'original' => LocalChangeComments::getOriginal($entry),
            ];
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

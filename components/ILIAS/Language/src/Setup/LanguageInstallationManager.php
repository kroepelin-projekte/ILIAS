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

namespace ILIAS\Language\Setup;

use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy;
use ILIAS\Language\ComponentTranslation\PlainLogText;
use ILIAS\Language\ComponentTranslation\PluralFormKey;

/**
 * Write access to the language installation domain: installing, flushing
 * and registering languages in the database. This is the "management" half
 * of what used to be bundled into ilSetupLanguage - see its class docblock
 * and docs/development/repository-pattern.md.
 *
 * Read access (which languages/files are known or valid) lives in
 * InstalledLanguageRepository instead; this class uses it internally rather
 * than duplicating queries or filesystem-checks.
 */
class LanguageInstallationManager
{
    use LanguageFileParsing;

    private const string SEPARATOR = "#:#";
    private const string COMMENT_SEPARATOR = "###";

    /**
     * @var array<string, array<string, list<string>>> see getSkippedInvalidMarkupEntries()
     */
    private array $skipped_invalid_markup_entries = [];
    private ?TranslationMarkupPolicy $markup_policy = null;

    /**
     * @param \ilDBInterface|\Closure():\ilDBInterface $db see
     *        InstalledLanguageDatabaseRepository for why this is accepted lazily.
     * @param (\Closure():\DateTimeImmutable)|null $now Injectable clock, defaults to
     *        the current UTC time. Exists so change/update timestamps can be
     *        asserted in tests without depending on wall-clock time.
     * @param (\Closure():?string)|null $client_data_dir_resolver Resolved lazily and re-read on every
     *        call, exactly like $db (no client may exist yet at construction time). Production callers
     *        pass MigratedLanguageFilePaths::resolveClientDataDir() or a Setup-environment based
     *        variant of it (see ilSetupLanguage). `null` (the default) means "no overlay can be
     *        maintained" - the database is written regardless.
     * @param (\Closure(string):void)|null $language_cache_invalidator Called with the language key after
     *        lng_modules was rewritten for it, so the global language cache (ilCachedLanguage) does
     *        not keep serving the previous content. `null` where no such cache exists (CLI Setup).
     */
    public function __construct(
        private readonly \ilDBInterface|\Closure $db,
        private readonly LanguageFileDirectoryManager $language_file_directory_manager,
        private readonly string $absolute_path,
        private readonly InstalledLanguageRepository $repository,
        private readonly ?\Closure $now = null,
        private readonly ?\Closure $client_data_dir_resolver = null,
        private readonly ?\Closure $language_cache_invalidator = null,
    ) {
    }

    /**
     * The entries of the customizing/local files not applied by the insertLanguageFor...() calls of
     * this instance so far, because their value contains markup TranslationMarkupPolicy does not
     * allow - for the caller to report.
     *
     * @return array<string, array<string, list<string>>> language key => module#:#identifier => violations
     */
    public function getSkippedInvalidMarkupEntries(): array
    {
        return $this->skipped_invalid_markup_entries;
    }

    private function markupPolicy(): TranslationMarkupPolicy
    {
        return $this->markup_policy ??= new TranslationMarkupPolicy();
    }

    /**
     * The violations of a customizing $value - only if it changes something, i.e. differs from the
     * value before this run ($current) and from the shipped one ($shipped), see
     * TranslationMarkupPolicy::findInvalidChangedValues().
     *
     * @return list<string>
     */
    private function invalidMarkupOfCustomizingValue(string $value, ?string $current, ?string $shipped): array
    {
        return $this->markupPolicy()->findInvalidChangedValues(
            ['value' => $value],
            $current === null ? [] : ['value' => $current],
            $shipped === null ? [] : ['value' => $shipped]
        )['value'] ?? [];
    }

    private function db(): \ilDBInterface
    {
        return $this->db instanceof \Closure ? ($this->db)() : $this->db;
    }

    private function clientDataDir(): ?string
    {
        return $this->client_data_dir_resolver !== null ? ($this->client_data_dir_resolver)() : null;
    }

    private function utcTimestamp(?int $unix_timestamp = null): string
    {
        if ($unix_timestamp !== null) {
            return gmdate("Y-m-d H:i:s", $unix_timestamp);
        }
        $now = $this->now !== null ? ($this->now)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:s');
    }

    /**
     * Install the given languages, uninstall/flush all others that were
     * previously known. Mirrors ilSetupLanguage::installLanguages().
     *
     * @param list<string> $lang_keys
     * @param list<string> $local_keys unused, kept for backwards compatibility - see
     *        ilSetupLanguage::installLanguages(), which never used it either;
     *        local languages are always looked up via the repository instead.
     * @return list<string>|bool list of language keys that failed validation, or true
     */
    public function installLanguages(array $lang_keys, array $local_keys = []): array|bool
    {
        $ilDB = $this->db();

        $err_lang = [];
        $db_langs = $this->repository->getAvailableLanguages();
        $local_langs = $this->repository->getLocalLanguages();

        foreach ($lang_keys as $lang_key) {
            if ($this->repository->checkLanguage($lang_key)) {
                $this->flushLanguage($lang_key, "keep_local");
                $this->insertLanguageForInstallation($lang_key);

                // register language first time install; an already-known
                // language's status is (re-)synced below instead.
                if (!array_key_exists($lang_key, $db_langs)) {
                    $this->registerInstalledLanguage($lang_key, $db_langs, $local_langs);
                }
            } else {
                $err_lang[] = $lang_key;
            }
        }

        foreach ($db_langs as $key => $val) {
            if (!in_array($key, $err_lang, true)) {
                if (in_array($key, $lang_keys, true)) {
                    $this->registerInstalledLanguage($key, $db_langs, $local_langs);
                } else {
                    $this->flushLanguage($key, "all");
                    $this->removeOverlays((string) $key);

                    if (strpos($val["status"], "installed") === 0) {
                        $query = "UPDATE object_data SET " .
                            "description = " . $ilDB->quote("not_installed", "text") . ", " .
                            "last_update = " . $ilDB->quote($this->utcTimestamp(), "timestamp") . " " .
                            "WHERE obj_id = " . $ilDB->quote($val["obj_id"], "integer") . " " .
                            "AND type = " . $ilDB->quote("lng", "text");
                        $ilDB->manipulate($query);
                    }
                }
            }
        }

        return ($err_lang) ?: true;
    }

    /**
     * Registers or updates the object_data bookkeeping row for a language
     * that has just been flushed/(re-)inserted, deciding "installed" vs.
     * "installed_local" and INSERTing a fresh row or UPDATEing the existing
     * one accordingly. This is the single source of truth for that
     * bookkeeping - both installLanguages() and
     * \ILIAS\Language\Activities\InstallLanguage::perform() call this
     * instead of duplicating the object_data INSERT/UPDATE.
     *
     * @param array<string, array{obj_id:int, status:string}> $known_languages result of
     *        InstalledLanguageRepository::getAvailableLanguages()
     * @param list<string> $local_language_keys result of
     *        InstalledLanguageRepository::getLocalLanguages()
     */
    public function registerInstalledLanguage(
        string $lang_key,
        array $known_languages,
        array $local_language_keys
    ): void {
        $db = $this->db();
        $installation_type = in_array($lang_key, $local_language_keys, true) ? "installed_local" : "installed";

        if (!array_key_exists($lang_key, $known_languages)) {
            $language_id = $db->nextId("object_data");
            $query = "INSERT INTO object_data " .
                    "(obj_id,type,title,description,owner,create_date,last_update) " .
                    "VALUES " .
                    "(" .
                    $db->quote($language_id, "integer") . "," .
                    $db->quote("lng", "text") . "," .
                    $db->quote($lang_key, "text") . "," .
                    $db->quote($installation_type, "text") . "," .
                    $db->quote("-1", "integer") . "," .
                    $db->now() . "," .
                    $db->now() .
                    ")";
            $db->manipulate($query);
            return;
        }

        $query = "UPDATE object_data SET " .
                "description = " . $db->quote($installation_type, "text") . ", " .
                "last_update = " . $db->quote($this->utcTimestamp(), "timestamp") . " " .
                "WHERE obj_id = " . $db->quote($known_languages[$lang_key]["obj_id"], "integer") . " " .
                "AND type = " . $db->quote("lng", "text");
        $db->manipulate($query);
    }

    /**
     * The overlay counterpart of flushLanguage($lang_key, "all") for a language that is no longer
     * installed - see ilObjLanguage::uninstall(). A failure is logged and does not undo the flush.
     */
    private function removeOverlays(string $lang_key): void
    {
        $client_data_dir = $this->clientDataDir();
        foreach ($this->language_file_directory_manager->getDirectories() as $directory) {
            $module = $directory->getPrefix();
            try {
                MigratedLanguageFileSync::removeOverlay(
                    $this->language_file_directory_manager,
                    $lang_key,
                    $module,
                    $client_data_dir
                );
            } catch (\Throwable $t) {
                error_log(sprintf(
                    'Could not remove the overlay of migrated module "%s", language "%s": %s',
                    $module,
                    $lang_key,
                    $t->getMessage()
                ));
            }
        }
    }

    public function flushLanguageForInstallation(string $lang_key): void
    {
        $this->flushLanguage($lang_key, "keep_local");
    }

    public function flushLanguageForUninstallation(string $lang_key): void
    {
        $this->flushLanguage($lang_key, "all");
    }

    /**
     * @param string $mode either "all" or "keep_local"
     */
    private function flushLanguage(string $lang_key, string $mode = "all"): void
    {
        $this->deleteLangData($lang_key, ($mode === "keep_local"));

        if ($mode === "all") {
            $this->db()->manipulate("DELETE FROM lng_modules WHERE lang_key = " .
                $this->db()->quote($lang_key, "text"));
        }
    }

    private function deleteLangData(string $lang_key, bool $keep_local_change): void
    {
        $ilDB = $this->db();

        if (!$keep_local_change) {
            $ilDB->manipulate("DELETE FROM lng_data WHERE lang_key = " .
                $ilDB->quote($lang_key, "text"));
        } else {
            $ilDB->manipulate("DELETE FROM lng_data WHERE lang_key = " .
                $ilDB->quote($lang_key, "text") .
                " AND local_change IS NULL");
        }
    }

    /**
     * Install or update (refresh) a language: every directory the LanguageFileDirectoryManager knows
     * about, including the customizing/local one - so an existing custom language file is
     * (re-)applied automatically - merged on top of whatever local changes are already recorded.
     *
     * For a module migrated to PO/MO the shipped `.po` is the only source of shipped values, and the
     * shipped/local decision is a three-way comparison per entry (see mergeShippedMigratedModules()):
     * only entries whose shipped value changed are taken over, local changes are kept.
     *
     * @return list<string> the migrated modules whose overlay could not be written (see
     *         insertLanguage()) - empty if every overlay is in sync
     */
    public function insertLanguageForInstallation(string $lang_key): array
    {
        return $this->insertLanguage(
            $lang_key,
            $this->language_file_directory_manager->getAllDirectories(),
            $this->repository->getLocalChanges($lang_key),
            true,
            true
        );
    }

    /**
     * Re-seed a language purely from the shipped global/component language files (`.lang`, and the
     * shipped `.po` of migrated modules), deliberately leaving out the customizing/local directory and
     * every local change - the write path behind "remove local changes". Going through
     * insertLanguageForInstallation() instead would immediately re-apply the customizing file.
     *
     * This also removes the overlay of every migrated module (the overlay only holds the delta to the
     * shipped `.po`, see MigratedLanguageFileSync::sync(), and none is left): locally changed values
     * are reset, entries added locally ("add new variable") are removed - the overlay equivalent of
     * the flush the caller performs on lng_data/lng_modules.
     *
     * @return list<string> see insertLanguageForInstallation()
     */
    public function insertLanguageForRemovingLocalChanges(string $lang_key): array
    {
        return $this->insertLanguage(
            $lang_key,
            $this->language_file_directory_manager->getDirectories(),
            [],
            true,
            false
        );
    }

    /**
     * (Re-)apply only the customizing/local directory's file on top of an
     * already installed language, without touching the base/global data at
     * all - the opposite split from insertLanguageForRemovingLocalChanges().
     *
     * This is the write path behind the "Install local" GUI command applied
     * to an already-installed language (see ilObjLanguageFolderGUI and
     * \ILIAS\Language\Activities\InstallLanguage::perform()): unlike
     * insertLanguageForInstallation(), the caller does not flush anything
     * first, and the base/global directories are never read here either -
     * so there is no re-parsing of the (usually much larger) base language
     * files, and no risk of the base data being touched.
     *
     * Because nothing here re-reads the base directories to fill in what the
     * customizing file does not cover, the seed passed to insertLanguage()
     * must already contain every entry currently stored for the language -
     * both base data and any previously recorded local changes - not just
     * the local changes the way insertLanguageForInstallation()'s seed does.
     * insertLanguage() rebuilds the lng_modules cache row for every module
     * it sees an entry for, from scratch, using exactly the seed plus
     * whatever it reads from the given directories; a narrower seed (e.g.
     * only previously local entries) would make any base entry not
     * overridden by the customizing file silently disappear from that
     * module's cache row.
     *
     * A module migrated to PO/MO is the exception: it is reconciled with its shipped `.po` exactly
     * like in insertLanguageForInstallation() (see resolveMigratedModule()) - its seed is only its
     * local changes, its not locally changed lng_data rows are rewritten from the shipped `.po`, and
     * the customizing file's lines for it are applied on top as local changes. Seeding it with every
     * stored entry instead would turn a stale stored value into a permanent "local change" and lose
     * the shipped update.
     *
     * @return list<string> see insertLanguageForInstallation()
     */
    public function insertLanguageForApplyingLocalChanges(string $lang_key): array
    {
        $entries = $this->repository->getLanguageEntries($lang_key);
        $local_changes = $this->repository->getLocalChanges($lang_key);
        $migrated_modules = array_keys(MigratedLanguageFileSync::findShippedModuleFiles(
            $this->language_file_directory_manager,
            $this->absolute_path,
            $lang_key
        ));
        foreach ($migrated_modules as $module) {
            // an empty seed is fine: insertLanguage() drops a module that ends up without entries
            $entries[$module] = $local_changes[$module] ?? [];
        }

        return $this->insertLanguage(
            $lang_key,
            $this->language_file_directory_manager->getCustomizingDirectories(),
            $entries,
            true,
            true,
            true
        );
    }


    /**
     * The overlay directories of the modules migrated for any of $lang_keys that cannot be written
     * in the client data directory this instance maintains the overlay in - see
     * MigratedLanguageFileSync::findUnwritableOverlayDirectories(). Meant to be checked before
     * installing/updating, so a missing permission is reported up front instead of only being
     * logged per module afterwards.
     *
     * @param list<string> $lang_keys
     * @param int|null $for_user_id see MigratedLanguageFileSync::findUnwritableOverlayDirectories()
     * @return list<string>
     */
    public function findUnwritableOverlayDirectories(array $lang_keys, ?int $for_user_id = null): array
    {
        return MigratedLanguageFileSync::findUnwritableOverlayDirectories(
            $this->language_file_directory_manager,
            $this->absolute_path,
            $lang_keys,
            $this->clientDataDir(),
            $for_user_id
        );
    }

    /**
     * Whether any module is migrated to PO/MO for any of $lang_keys, i.e. an overlay may have to be
     * maintained for it (for its local changes).
     *
     * @param list<string> $lang_keys
     */
    public function hasMigratedModules(array $lang_keys): bool
    {
        foreach ($lang_keys as $lang_key) {
            if (MigratedLanguageFileSync::findShippedModuleFiles(
                $this->language_file_directory_manager,
                $this->absolute_path,
                $lang_key
            ) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes whatever data the given $directories/$lang_array seed produce - which directories to read
     * and what to seed with is decided by the three callers above.
     *
     * For every module migrated to PO/MO (see MigratedLanguageFileSync) whose shipped `.po` is merged
     * ($merge_shipped_migrated_modules), the module's lines in the global/component `.lang` files are
     * ignored - the shipped `.po` is the only source of its shipped values - and each shipped entry is
     * reconciled with the local state via resolveMigratedModule(). Lines for such a module in the
     * customizing/local file are still applied, as local changes.
     *
     * After lng_data/lng_modules are written, the stored module arrays are verified (collation
     * problems, see mantis #20046/#19140), the language cache is invalidated and every migrated
     * module's overlay is brought in line with the final content - which writes only the delta to the
     * shipped `.po` (customizing values and real local changes): a module without local changes gets
     * no overlay file, and an overlay of an earlier version holding every entry shrinks to the delta
     * (see MigratedLanguageFileSync::sync()).
     *
     * Concurrent writes (an admin edit during the run): every migrated module that has an overlay is
     * locked (MigratedLanguageFileSync::withOverlayLock()) for the whole run, so its overlay is read,
     * reconciled and written back on one consistent state. A module without overlay is not locked (no
     * lock file is created for it); if an overlay appears for it meanwhile, its sync() is refused and
     * the module reported instead of discarding that overlay (see sync()'s
     * $expected_overlay_exists).
     *
     * @param iterable<LanguageFileDirectory> $directories
     * @param array<string, array<string, string>> $lang_array module => identifier => value
     * @param bool $keep_local_changes whether local changes recorded only in a migrated module's
     *        overlay count as local changes (false for "remove local changes")
     * @param bool $delete_unchanged_migrated_rows whether the not locally changed lng_data rows of the
     *        migrated modules are deleted before they are rewritten from the shipped `.po` - needed
     *        where the caller did not flush the language first ("apply local changes")
     * @return list<string> the migrated modules whose overlay could not be written - the database
     *         write is not undone for them, see syncMigratedModules()
     */
    private function insertLanguage(
        string $lang_key,
        iterable $directories,
        array $lang_array,
        bool $merge_shipped_migrated_modules,
        bool $keep_local_changes,
        bool $delete_unchanged_migrated_rows = false
    ): array {
        // Reported per call (see getSkippedInvalidMarkupEntries()): only what this run left out
        unset($this->skipped_invalid_markup_entries[$lang_key]);
        $client_data_dir = $this->clientDataDir();
        $shipped_migrated_modules = $merge_shipped_migrated_modules
            ? MigratedLanguageFileSync::loadShippedModules(
                $this->language_file_directory_manager,
                $this->absolute_path,
                $lang_key
            )
            : [];

        $locked_modules = [];
        foreach (array_keys($shipped_migrated_modules) as $module) {
            if (MigratedLanguageFileSync::hasOverlay(
                $this->language_file_directory_manager,
                $lang_key,
                (string) $module,
                $client_data_dir
            )) {
                $locked_modules[] = (string) $module;
            }
        }
        // Invariant for every caller that holds more than one overlay lock at a time: the locks are
        // always acquired in this one global order (module names, byte-wise) - never in the order of
        // getDirectories(), which differs between entry points (Setup, GUI). Two runs locking the same
        // modules in different orders would deadlock (flock() has no timeout).
        sort($locked_modules, SORT_STRING);

        $write = fn(): array => $this->writeLanguage(
            $lang_key,
            $directories,
            $lang_array,
            $shipped_migrated_modules,
            $locked_modules,
            $keep_local_changes,
            $delete_unchanged_migrated_rows,
            $client_data_dir
        );
        // Built inside out, so the outermost closure acquires the first lock of $locked_modules
        foreach (array_reverse($locked_modules) as $module) {
            $locked = $write;
            $write = fn(): array => MigratedLanguageFileSync::withOverlayLock(
                $this->language_file_directory_manager,
                $lang_key,
                $module,
                $client_data_dir,
                $locked,
                $this->absolute_path
            );
        }

        return $write();
    }

    /**
     * insertLanguage() once the overlays of $locked_modules are locked.
     *
     * @param iterable<LanguageFileDirectory> $directories
     * @param array<string, array<string, string>> $lang_array
     * @param array<string, array<string, string>> $shipped_migrated_modules
     * @param list<string> $locked_modules
     * @return list<string> see insertLanguage()
     */
    private function writeLanguage(
        string $lang_key,
        iterable $directories,
        array $lang_array,
        array $shipped_migrated_modules,
        array $locked_modules,
        bool $keep_local_changes,
        bool $delete_unchanged_migrated_rows,
        ?string $client_data_dir
    ): array {
        $ilDB = $this->db();
        $working_dir = getcwd();

        $values_sql = [];
        // The rows of the migrated modules are buffered and written once their final content is known:
        // the database holds a plural message only under its identifier with its default value, not
        // per form (see MigratedLanguageFileSync::collapsePluralForms() and writeMigratedRows())
        $migrated_rows = [];
        $sql_row = static function (
            string $module,
            string $identifier,
            string $value,
            ?string $local_change,
            ?string $remarks
        ) use (&$values_sql, $ilDB, $lang_key): void {
            $values_sql[] = sprintf(
                "(%s,%s,%s,%s,%s,%s)",
                $ilDB->quote($module, "text"),
                $ilDB->quote($identifier, "text"),
                $ilDB->quote($lang_key, "text"),
                $ilDB->quote($value, "text"),
                $ilDB->quote($local_change, "timestamp"),
                $ilDB->quote($remarks, "text")
            );
        };
        $add_row = static function (
            string $module,
            string $identifier,
            string $value,
            ?string $local_change,
            ?string $remarks
        ) use (&$migrated_rows, $sql_row, $shipped_migrated_modules): void {
            if (!isset($shipped_migrated_modules[$module])) {
                $sql_row($module, $identifier, $value, $local_change, $remarks);
                return;
            }
            // a later row of the same key replaces an earlier one, like the INSERT below does
            unset($migrated_rows[$module][$identifier]);
            $migrated_rows[$module][$identifier] = [$value, $local_change, $remarks];
        };

        // Every exit path below - including an exception thrown out of a DB
        // call - must restore the working directory. This method chdir()s
        // into each language directory in turn to read its file with a
        // relative path; PHP-FPM/mod_php worker processes are long-lived and
        // reused across unrelated requests, so a cwd left dangling here (e.g.
        // inside lang/customizing/ after an error) would silently corrupt
        // relative-path filesystem checks for every later request handled by
        // that worker.
        try {
            $customized = [];
            $customized_remarks = [];
            $duplicates = [];
            // For the markup check of the customizing file: the values before this run (the seed)
            // and the shipped values read from the global/component files
            $current_values = $lang_array;
            $shipped_line_values = [];
            $shipped_line_comments = [];

            foreach ($directories as $directory) {
                $lang_file = "ilias_" . $lang_key . ".lang" . $directory->getSuffix();
                $path = $this->absoluteDirectoryPath($this->absolute_path, $directory);

                if (!is_dir($path)) {
                    continue;
                }
                chdir($path);

                if (!is_file($lang_file)) {
                    continue;
                }

                $content = $this->cutHeader(file($lang_file));
                if (!$content) {
                    continue;
                }

                $prefix = $directory->getPrefix();
                $is_local = $directory->isLocal();

                // Both of these depend only on the language and on this
                // directory's file - never on the individual entry - so they
                // are resolved once per directory instead of once per line.
                $change_date = null;
                $newer_db_changes = [];
                if ($is_local) {
                    // A local file overwrites, unless the database holds an
                    // even newer change for that entry.
                    $newer_db_changes = $this->repository->getLocalChanges(
                        $lang_key,
                        $this->utcTimestamp(filemtime($lang_file))
                    );
                    // One import timestamp for every entry of this file.
                    $change_date = $this->utcTimestamp();
                }

                $seen_in_file = [];
                foreach ($content as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $separated = explode(self::SEPARATOR, $line);

                    if (!empty($prefix)) {
                        array_unshift($separated, $prefix);
                    }

                    $pos = strpos($separated[2], self::COMMENT_SEPARATOR);
                    if ($pos !== false) {
                        $separated[3] = substr($separated[2], $pos + strlen(self::COMMENT_SEPARATOR));
                        $separated[2] = substr($separated[2], 0, $pos);
                    }

                    $module = $separated[0];
                    $identifier = $separated[1];
                    $value = $separated[2];

                    if (isset($seen_in_file[$module][$identifier])) {
                        $duplicates[] = $path . $lang_file . ': ' . $module . self::SEPARATOR . $identifier;
                        continue;
                    }
                    $seen_in_file[$module][$identifier] = true;

                    if (!$is_local) {
                        $shipped_line_values[$module][$identifier] ??= $value;
                        if (($separated[3] ?? '') !== '') {
                            // the shipped "###" comment - lng_data.remarks held it before the module
                            // was migrated, see migratedModuleRemarks()
                            $shipped_line_comments[$module][$identifier] ??= $separated[3];
                        }
                        if (isset($shipped_migrated_modules[$module])) {
                            // Migrated: the shipped .po is the only source, see resolveMigratedModule()
                            continue;
                        }
                        // Respect local changes already recorded in the
                        // database, and let an earlier directory win.
                        if (isset($lang_array[$module][$identifier])) {
                            continue;
                        }
                    } elseif (isset($newer_db_changes[$module][$identifier])) {
                        $lang_array[$module][$identifier] = $newer_db_changes[$module][$identifier];
                        continue;
                    } elseif (($violations = $this->invalidMarkupOfCustomizingValue(
                        $value,
                        $current_values[$module][$identifier] ?? null,
                        isset($shipped_migrated_modules[$module])
                            ? ($shipped_migrated_modules[$module][$identifier] ?? null)
                            : ($shipped_line_values[$module][$identifier] ?? null)
                    )) !== []) {
                        // A customizing value with markup that is not allowed is left out (and
                        // reported), not the whole installation aborted: the language is still
                        // installed, only this local override is not applied
                        $this->skipped_invalid_markup_entries[$lang_key][$module . self::SEPARATOR . $identifier] = $violations;
                        continue;
                    }

                    $add_row($module, $identifier, $value, $change_date, $separated[3] ?? null);
                    $lang_array[$module][$identifier] = $value;
                    if ($is_local) {
                        $customized[$module][$identifier] = true;
                        if (isset($shipped_migrated_modules[$module]) && ($separated[3] ?? '') !== '') {
                            $customized_remarks[$module][$identifier] = $separated[3];
                        }
                    }
                }
            }

            $skipped_for_language = $this->skipped_invalid_markup_entries[$lang_key] ?? [];
            if ($skipped_for_language !== []) {
                error_log(PlainLogText::of(sprintf(
                    'Customizing entries of language "%s" not applied - they contain markup that is not allowed: %s',
                    $lang_key,
                    implode(', ', array_keys($skipped_for_language))
                )));
            }

            if ($duplicates !== []) {
                // The pre-Component-Revision GUI path aborted on a duplicate entry, Setup silently
                // let one occurrence win. Shipped files do contain duplicates (e.g. ilias_fa.lang,
                // ilias_nl.lang), so aborting would make such a language impossible to update - the
                // first occurrence wins (the second is not written) and the duplicates are reported.
                error_log(sprintf(
                    'Duplicate language entries for language "%s" (first occurrence used): %s',
                    $lang_key,
                    implode(', ', $duplicates)
                ));
            }

            // The remarks of the migrated modules, kept in their overlay as well (see
            // MigratedLanguageFileSync::sync()): lng_data (still holding them from before the
            // overlay kept remarks), the overlay, the customizing file - later ones win
            $migrated_remarks = $keep_local_changes
                ? $this->migratedModuleRemarks($lang_key, $shipped_migrated_modules, $customized_remarks, $shipped_line_comments, $client_data_dir)
                : [];

            $dropped_identifiers = [];
            foreach ($shipped_migrated_modules as $module => $shipped_entries) {
                $overlay = $keep_local_changes ? $this->loadOverlay($lang_key, (string) $module, $client_data_dir) : null;

                // A plain value of a plural message (recorded in lng_data, which holds plural messages
                // only under their identifier, or a customizing line) is its default form
                $lang_array[$module] = $this->resolveMigratedModule(
                    MigratedLanguageFileSync::mapLegacyPluralValues($lang_array[$module] ?? [], $shipped_entries),
                    MigratedLanguageFileSync::mapLegacyPluralValues($customized[$module] ?? [], $shipped_entries),
                    $shipped_entries,
                    MigratedLanguageFileSync::mapLegacyPluralValues($overlay ?? [], $shipped_entries),
                    // the database gets the shipped values as the build serves them, see
                    // MigratedLanguageFileSync::databaseValues()
                    static fn(string $identifier, string $value, ?string $local_change) =>
                        $add_row(
                            $module,
                            $identifier,
                            MigratedLanguageFileSync::databaseValues([$identifier => $value], $shipped_entries)[$identifier],
                            $local_change,
                            null
                        ),
                    static function (string $identifier) use (&$dropped_identifiers, $module): void {
                        $dropped_identifiers[$module][] = $identifier;
                    }
                );
                if ($lang_array[$module] === []) {
                    unset($lang_array[$module]);
                }
            }
            foreach ($migrated_rows as $module => $rows) {
                foreach (self::databaseRowsOfMigratedModule($rows, $lang_array[$module] ?? [], $shipped_migrated_modules[$module], $migrated_remarks[$module] ?? []) as $identifier => [$value, $local_change, $remarks]) {
                    $sql_row((string) $module, (string) $identifier, $value, $local_change, $remarks);
                }
            }

            if ($delete_unchanged_migrated_rows && $shipped_migrated_modules !== []) {
                $ilDB->manipulate(sprintf(
                    "DELETE FROM lng_data WHERE lang_key = %s AND local_change IS NULL AND %s",
                    $ilDB->quote($lang_key, "text"),
                    $ilDB->in('module', array_map('strval', array_keys($shipped_migrated_modules)), false, 'text')
                ));
            }

            if ($values_sql !== []) {
                $query = "INSERT INTO lng_data (module,identifier,lang_key,value,local_change,remarks) VALUES "
                    . implode(',', $values_sql)
                    . " ON DUPLICATE KEY UPDATE value=VALUES(value),remarks=VALUES(remarks),local_change=VALUES(local_change);";
                $ilDB->manipulate($query);
            }

            foreach ($dropped_identifiers as $module => $identifiers) {
                $ilDB->manipulate(sprintf(
                    "DELETE FROM lng_data WHERE lang_key = %s AND module = %s AND %s",
                    $ilDB->quote($lang_key, "text"),
                    $ilDB->quote((string) $module, "text"),
                    $ilDB->in('identifier', $identifiers, false, 'text')
                ));
            }

            if ($lang_array === []) {
                return [];
            }

            $modules = array_map('strval', array_keys($lang_array));
            $inModulesToDelete = $ilDB->in('module', $modules, false, 'text');
            $ilDB->manipulate(sprintf(
                "DELETE FROM lng_modules WHERE lang_key = %s AND $inModulesToDelete",
                $ilDB->quote($lang_key, "text")
            ));

            $modulesValuesSql = [];
            foreach ($lang_array as $module => $lang_arr) {
                // $lang_array itself stays unprocessed: sync() below compares with the raw .po
                if (isset($shipped_migrated_modules[$module])) {
                    $lang_arr = MigratedLanguageFileSync::databaseValues(
                        MigratedLanguageFileSync::collapsePluralForms($lang_arr, $shipped_migrated_modules[$module]),
                        MigratedLanguageFileSync::collapsePluralForms($shipped_migrated_modules[$module], $shipped_migrated_modules[$module])
                    );
                }
                $modulesValuesSql[] = sprintf(
                    "(%s,%s,%s)",
                    $ilDB->quote((string) $module, "text"),
                    $ilDB->quote($lang_key, "text"),
                    $ilDB->quote(serialize($lang_arr), "clob")
                );
            }

            $query = "INSERT INTO lng_modules (module, lang_key, lang_array) VALUES "
                . implode(',', $modulesValuesSql)
                . ";";
            $ilDB->manipulate($query);

            $this->assertModulesCorrectlySaved($lang_key, $modules);
            $this->invalidateLanguageCache($lang_key);

            // A migrated module that was not locked had no overlay when this run decided on its
            // values - see insertLanguage()
            $expected_overlays = [];
            foreach (array_keys($shipped_migrated_modules) as $module) {
                $expected_overlays[(string) $module] = in_array((string) $module, $locked_modules, true) ? null : false;
            }

            // Without keeping local changes ("remove local changes"), the remarks go as well
            $remarks = [];
            foreach (array_keys($shipped_migrated_modules) as $module) {
                $remarks[(string) $module] = $keep_local_changes ? ($migrated_remarks[(string) $module] ?? null) : [];
            }

            return $this->syncMigratedModules($lang_key, $lang_array, $client_data_dir, $expected_overlays, $remarks);
        } finally {
            chdir($working_dir);
        }
    }

    /**
     * The lng_data rows of a migrated module from the rows buffered for it while this run decided on
     * its content: the rows of the forms of a plural message (and a plain row of its identifier)
     * become one row of the identifier, with the default value of the final forms $final_entries
     * (MigratedLanguageFileSync::collapsePluralForms(), cleaned like every shipped value, see
     * MigratedLanguageFileSync::databaseValues()), the first remark of those rows, and - only if that
     * default value differs from the shipped default form - the first local_change of those rows: the
     * row stands for the default value alone (a local change of another form lives in the overlay
     * only), so after a rollback it does not hold back updates of the legacy `.lang` files.
     * Every other row is kept.
     *
     * @param array<string, array{0: string, 1: ?string, 2: ?string}> $rows identifier => value,
     *        local_change, remarks
     * @param array<string, string> $final_entries the module's final identifier => value map
     * @param array<string, string> $shipped_entries
     * @param array<string, string> $remarks identifier => remark of the module (see
     *        migratedModuleRemarks()) - written into its rows
     * @return array<string, array{0: string, 1: ?string, 2: ?string}>
     */
    private static function databaseRowsOfMigratedModule(array $rows, array $final_entries, array $shipped_entries, array $remarks = []): array
    {
        $values = MigratedLanguageFileSync::collapsePluralForms($final_entries, $shipped_entries);
        $shipped_defaults = MigratedLanguageFileSync::collapsePluralForms($shipped_entries, $shipped_entries);
        $database_values = MigratedLanguageFileSync::databaseValues($values, $shipped_defaults);
        $result = [];
        foreach ($rows as $identifier => [$value, $local_change, $row_remarks]) {
            $identifier = (string) $identifier;
            $plural_identifier = MigratedLanguageFileSync::pluralMessageOf($identifier, $shipped_entries);
            if ($plural_identifier === null) {
                $result[$identifier] = [$value, $local_change, $remarks[$identifier] ?? $row_remarks];
                continue;
            }
            if (!isset($database_values[$plural_identifier])) {
                continue;
            }
            $previous = $result[$plural_identifier] ?? null;
            $is_local_change = (string) $values[$plural_identifier] !== (string) ($shipped_defaults[$plural_identifier] ?? '');
            $result[$plural_identifier] = [
                (string) $database_values[$plural_identifier],
                $is_local_change ? ($previous[1] ?? $local_change) : null,
                $remarks[$plural_identifier] ?? $previous[2] ?? $row_remarks,
            ];
        }

        return $result;
    }

    /**
     * The shipped/local decision for one migrated module - a three-way comparison per shipped entry
     * between
     *   L = the local value ($local_entries: local changes recorded in lng_data, the
     *       customizing/local file, or - with $overlay - a local change recorded only in the overlay),
     *   O = the shipped value the overlay tracked the entry against so far ("original"), and
     *   S = the value the shipped `.po` carries now:
     *
     * - no L                       -> S (a new or unchanged entry; also every entry of a new language)
     * - L === S                    -> S, no longer marked as local change
     * - L === O (so S changed)     -> S: the "local" value only was the previously shipped one
     * - otherwise                  -> L: a real local change is never overwritten - also not when the
     *                                 shipped value changed as well (then only "original" moves to S,
     *                                 so "remove local changes" resets to the current shipped value)
     *
     * A value from the customizing/local file ($customized) always stays local, exactly like for a
     * not migrated module. Overlay entries that are neither shipped nor local are dropped. A local
     * entry without a shipped counterpart (the key was removed from the shipped `.po`, or added via
     * "add new variable"):
     * - L === O -> dropped (from the module, lng_data - via $delete_row - and the overlay): it only
     *              carries the value the key was last shipped with;
     * - otherwise, and whenever O is unknown -> kept as a local change, with its local_change date.
     * The legacy `.lang` update keeps a locally changed key that was removed from the shipped file
     * the same way (its lng_data row survives the "keep_local" flush and is re-seeded); an unchanged
     * one disappears with the flush.
     *
     * @param array<string, string> $local_entries identifier => value
     * @param array<string, true> $customized identifiers that came from the customizing/local file
     * @param array<string, string> $shipped_entries identifier => shipped value
     * @param array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}> $overlay
     * @param \Closure(string, string, ?string):void $write_row persists one lng_data row
     * @param \Closure(string):void $delete_row removes one lng_data row (after all rows were written)
     * @return array<string, string> the module's final identifier => value map
     */
    private function resolveMigratedModule(
        array $local_entries,
        array $customized,
        array $shipped_entries,
        array $overlay,
        \Closure $write_row,
        \Closure $delete_row
    ): array {
        foreach ($overlay as $identifier => $state) {
            $identifier = (string) $identifier;
            if ($state['local_change'] && !isset($local_entries[$identifier])) {
                $local_entries[$identifier] = $state['value'];
                $write_row($identifier, $state['value'], $state['local_change_date'] ?? $this->utcTimestamp());
            }
        }

        foreach ($shipped_entries as $identifier => $shipped_value) {
            $identifier = (string) $identifier;
            if (isset($local_entries[$identifier]) && !isset($customized[$identifier])) {
                $local_value = $local_entries[$identifier];
                $previously_shipped_value = $overlay[$identifier]['original'] ?? null;
                if ($local_value !== $shipped_value && $local_value !== $previously_shipped_value) {
                    continue;
                }
            } elseif (isset($local_entries[$identifier])) {
                continue;
            }

            $local_entries[$identifier] = $shipped_value;
            $write_row($identifier, $shipped_value, null);
        }

        foreach ($local_entries as $identifier => $local_value) {
            $identifier = (string) $identifier;
            if (isset($shipped_entries[$identifier]) || isset($customized[$identifier])) {
                continue;
            }
            $previously_shipped_value = $overlay[$identifier]['original'] ?? null;
            if ($previously_shipped_value !== null && $local_value === $previously_shipped_value) {
                unset($local_entries[$identifier]);
                $delete_row($identifier);
            }
        }

        return $local_entries;
    }

    /**
     * The overlay state of a migrated module, or null. An unreadable overlay must not abort a
     * reinstall whose data the caller has already flushed: it is reported and treated as absent - the
     * local changes recorded in lng_data still apply, and sync() rewrites the overlay afterwards.
     * Returns only the overlay delta (MigratedLanguageFileSync::loadLocalChanges()): the entries not in
     * it carry the shipped value, which resolveMigratedModule() takes from the shipped `.po` anyway.
     * An overlay `.mo` without its `.po` is reported here as unreadable, and its sync() fails
     * instead of removing it.
     *
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     */
    private function loadOverlay(string $lang_key, string $module, ?string $client_data_dir): ?array
    {
        try {
            return MigratedLanguageFileSync::loadLocalChanges(
                $this->language_file_directory_manager,
                $lang_key,
                $module,
                $client_data_dir,
                $this->absolute_path
            );
        } catch (\Throwable $t) {
            error_log(sprintf(
                'Could not read the overlay of migrated module "%s", language "%s" - ignoring it: %s',
                $module,
                $lang_key,
                $t->getMessage()
            ));
            return null;
        }
    }

    /**
     * Restores the check the pre-Component-Revision GUI write path (ilObjLanguageDBAccess) did after
     * writing lng_modules: a wrong table collation silently corrupts the serialized module arrays,
     * which would otherwise only surface as missing translations everywhere.
     *
     * @param list<string> $modules
     */
    private function assertModulesCorrectlySaved(string $lang_key, array $modules): void
    {
        $ilDB = $this->db();
        $result = $ilDB->query(sprintf(
            "SELECT module, lang_array FROM lng_modules WHERE lang_key = %s AND %s",
            $ilDB->quote($lang_key, "text"),
            $ilDB->in('module', $modules, false, 'text')
        ));

        while ($row = $ilDB->fetchAssoc($result)) {
            if (!is_array(@unserialize((string) $row["lang_array"], ["allowed_classes" => false]))) {
                throw new LanguageDataNotSavedException((string) $row["module"], $lang_key);
            }
        }
    }

    private function invalidateLanguageCache(string $lang_key): void
    {
        if ($this->language_cache_invalidator === null) {
            return;
        }
        try {
            ($this->language_cache_invalidator)($lang_key);
        } catch (\Throwable $t) {
            // A cache that cannot be invalidated must not undo the successful database write
            error_log(sprintf('Could not invalidate the language cache for "%s": %s', $lang_key, $t->getMessage()));
        }
    }

    /**
     * The remarks of every migrated module (identifier => remark, a plural message under its
     * identifier): those still in lng_data, overridden by those of the overlay, overridden by those
     * of the customizing file. A module whose overlay cannot be read is left out (its sync() then
     * keeps the remarks the overlay holds, see MigratedLanguageFileSync::sync()).
     *
     * Before a module was migrated, lng_data.remarks held the shipped "###" comment of its `.lang`
     * line (mostly the dated "... new variable" marker) - that is no remark of an administrator: a
     * lng_data remark is only taken over if it differs from the shipped "###" comment
     * ($shipped_line_comments), from the `#.` note of the shipped `.po`, and is no dated "new
     * variable" marker. A remark an administrator edited before the migration is taken over.
     *
     * @param array<string, array<string, string>> $shipped_migrated_modules
     * @param array<string, array<string, string>> $customized_remarks
     * @param array<string, array<string, string>> $shipped_line_comments module => identifier =>
     *        "###" comment of the shipped `.lang` lines
     * @return array<string, array<string, string>>
     */
    /**
     * A dated "not translated yet" marker of the legacy `.lang` files ("28 08 2012 new variable"),
     * the same pattern convert_module_to_po.php turns into "fuzzy".
     */
    private const string FUZZY_MARKER_PATTERN = '/^\s*(\d{1,2}\s+\d{1,2}\s+\d{4}|\d{1,2}\s+[A-Za-z]{3}\s+\d{4}|\d{4}-\d{1,2}-\d{1,2})\b.*\bnew variable\b/i';

    private function migratedModuleRemarks(
        string $lang_key,
        array $shipped_migrated_modules,
        array $customized_remarks,
        array $shipped_line_comments,
        ?string $client_data_dir
    ): array {
        if ($shipped_migrated_modules === []) {
            return [];
        }
        $ilDB = $this->db();
        $database_remarks = [];
        $result = $ilDB->query(sprintf(
            "SELECT module, identifier, remarks FROM lng_data WHERE lang_key = %s AND remarks IS NOT NULL AND %s",
            $ilDB->quote($lang_key, "text"),
            $ilDB->in('module', array_map('strval', array_keys($shipped_migrated_modules)), false, 'text')
        ));
        while ($row = $ilDB->fetchAssoc($result)) {
            if (isset($row['module'], $row['identifier'], $row['remarks']) && (string) $row['remarks'] !== '') {
                $database_remarks[(string) $row['module']][(string) $row['identifier']] = (string) $row['remarks'];
            }
        }

        $shipped_files = MigratedLanguageFileSync::findShippedModuleFiles(
            $this->language_file_directory_manager,
            $this->absolute_path,
            $lang_key
        );
        $remarks = [];
        foreach ($shipped_migrated_modules as $module => $shipped_entries) {
            $module = (string) $module;
            try {
                $po_notes = isset($shipped_files[$module])
                    ? array_filter(array_map(
                        static fn(array $entry): string => (string) $entry['comment'],
                        MigratedLanguageFileSync::loadShippedModuleEntries($shipped_files[$module], $module)
                    ), static fn(string $note): bool => $note !== '')
                    : [];
            } catch (\Throwable) {
                $po_notes = [];
            }
            foreach ($database_remarks[$module] ?? [] as $identifier => $remark) {
                $plural_identifier = MigratedLanguageFileSync::pluralMessageOf((string) $identifier, $shipped_entries);
                $shipped_comments = [
                    $shipped_line_comments[$module][$identifier] ?? null,
                    $po_notes[$identifier] ?? null,
                    $plural_identifier === null ? null : ($po_notes[PluralFormKey::of($plural_identifier, 0)] ?? null),
                ];
                if (
                    in_array(trim($remark), array_map(static fn(?string $c): ?string => $c === null ? null : trim($c), $shipped_comments), true)
                    || preg_match(self::FUZZY_MARKER_PATTERN, $remark) === 1
                ) {
                    unset($database_remarks[$module][$identifier]);
                }
            }
            try {
                $overlay_remarks = MigratedLanguageFileSync::loadRemarks(
                    $this->language_file_directory_manager,
                    $lang_key,
                    $module,
                    $client_data_dir,
                    $this->absolute_path
                ) ?? [];
            } catch (\Throwable) {
                // reported by loadOverlay() - the sync keeps what the overlay holds
                continue;
            }
            $module_remarks = [];
            foreach ([$database_remarks[$module] ?? [], $overlay_remarks, $customized_remarks[$module] ?? []] as $source) {
                foreach ($source as $identifier => $remark) {
                    $identifier = (string) $identifier;
                    $module_remarks[MigratedLanguageFileSync::pluralMessageOf($identifier, $shipped_entries) ?? $identifier] = (string) $remark;
                }
            }
            $remarks[$module] = $module_remarks;
        }

        return $remarks;
    }

    /**
     * @param array<string, array<string, string>> $lang_array
     * @param array<string, bool|null> $expected_overlays module => $expected_overlay_exists of
     *        MigratedLanguageFileSync::sync()
     * @param array<string, array<string, string>|null> $remarks module => $remarks of
     *        MigratedLanguageFileSync::sync() (`null`/missing: keep the overlay's remarks)
     * @return list<string> the modules whose overlay could not be written
     */
    private function syncMigratedModules(
        string $lang_key,
        array $lang_array,
        ?string $client_data_dir,
        array $expected_overlays = [],
        array $remarks = []
    ): array {
        $failed_modules = [];
        foreach ($lang_array as $module => $entries) {
            try {
                MigratedLanguageFileSync::sync(
                    $this->language_file_directory_manager,
                    $this->absolute_path,
                    $lang_key,
                    (string) $module,
                    $entries,
                    $client_data_dir,
                    // every caller of insertLanguage() reconciles the language with the shipped
                    // files, see MigratedLanguageFileSync::sync()
                    true,
                    $expected_overlays[(string) $module] ?? null,
                    $remarks[(string) $module] ?? null
                );
            } catch (\Throwable $t) {
                // No injected logger here - this class also runs in Setup contexts before a
                // logging service exists; error_log() works everywhere. The lng_modules write above
                // already succeeded and must not be undone by a problem with the file mirror - the
                // failure is returned so the caller can report it visibly.
                error_log(sprintf(
                    'Could not sync migrated language file for module "%s", language "%s": %s',
                    $module,
                    $lang_key,
                    $t->getMessage()
                ));
                $failed_modules[] = (string) $module;
            }
        }

        return $failed_modules;
    }
}

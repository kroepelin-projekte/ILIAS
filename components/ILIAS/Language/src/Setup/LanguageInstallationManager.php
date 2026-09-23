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
     */
    public function insertLanguageForInstallation(string $lang_key): void
    {
        $this->insertLanguage(
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
     * This also rebuilds the overlay of every migrated module from the shipped `.po` ("replace"
     * semantics): locally changed values are reset, entries added locally ("add new variable") are
     * removed - the overlay equivalent of the flush the caller performs on lng_data/lng_modules.
     */
    public function insertLanguageForRemovingLocalChanges(string $lang_key): void
    {
        $this->insertLanguage(
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
     */
    public function insertLanguageForApplyingLocalChanges(string $lang_key): void
    {
        // The seed already holds every stored entry (including those of migrated modules), so the
        // shipped `.po` files are not merged in again here.
        $this->insertLanguage(
            $lang_key,
            $this->language_file_directory_manager->getCustomizingDirectories(),
            $this->repository->getLanguageEntries($lang_key),
            false,
            true
        );
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
     * module's overlay is brought in line with the final content.
     *
     * @param iterable<LanguageFileDirectory> $directories
     * @param array<string, array<string, string>> $lang_array module => identifier => value
     * @param bool $keep_local_changes whether local changes recorded only in a migrated module's
     *        overlay count as local changes (false for "remove local changes")
     */
    private function insertLanguage(
        string $lang_key,
        iterable $directories,
        array $lang_array,
        bool $merge_shipped_migrated_modules,
        bool $keep_local_changes
    ): void {
        $ilDB = $this->db();
        $working_dir = getcwd();
        $client_data_dir = $this->clientDataDir();
        $shipped_migrated_modules = $merge_shipped_migrated_modules
            ? MigratedLanguageFileSync::loadShippedModules(
                $this->language_file_directory_manager,
                $this->absolute_path,
                $lang_key
            )
            : [];

        $values_sql = [];
        $add_row = static function (
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
            $duplicates = [];

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
                        if (isset($shipped_migrated_modules[$module])) {
                            // migrated: the shipped .po is the only source, see resolveMigratedModule()
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
                    }

                    $add_row($module, $identifier, $value, $change_date, $separated[3] ?? null);
                    $lang_array[$module][$identifier] = $value;
                    if ($is_local) {
                        $customized[$module][$identifier] = true;
                    }
                }
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

            foreach ($shipped_migrated_modules as $module => $shipped_entries) {
                $overlay = $keep_local_changes ? $this->loadOverlay($lang_key, (string) $module, $client_data_dir) : null;

                $lang_array[$module] = $this->resolveMigratedModule(
                    $lang_array[$module] ?? [],
                    $customized[$module] ?? [],
                    $shipped_entries,
                    $overlay ?? [],
                    static fn(string $identifier, string $value, ?string $local_change) =>
                        $add_row($module, $identifier, $value, $local_change, null)
                );
                if ($lang_array[$module] === []) {
                    unset($lang_array[$module]);
                }
            }

            if ($values_sql !== []) {
                $query = "INSERT INTO lng_data (module,identifier,lang_key,value,local_change,remarks) VALUES "
                    . implode(',', $values_sql)
                    . " ON DUPLICATE KEY UPDATE value=VALUES(value),remarks=VALUES(remarks),local_change=VALUES(local_change);";
                $ilDB->manipulate($query);
            }

            if ($lang_array === []) {
                return;
            }

            $modules = array_map('strval', array_keys($lang_array));
            $inModulesToDelete = $ilDB->in('module', $modules, false, 'text');
            $ilDB->manipulate(sprintf(
                "DELETE FROM lng_modules WHERE lang_key = %s AND $inModulesToDelete",
                $ilDB->quote($lang_key, "text")
            ));

            $modulesValuesSql = [];
            foreach ($lang_array as $module => $lang_arr) {
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
            $this->syncMigratedModules($lang_key, $lang_array, $client_data_dir);
        } finally {
            chdir($working_dir);
        }
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
     * not migrated module. Local entries without a shipped counterpart (added via "add new variable")
     * are kept; overlay entries that are neither shipped nor local are dropped.
     *
     * @param array<string, string> $local_entries identifier => value
     * @param array<string, true> $customized identifiers that came from the customizing/local file
     * @param array<string, string> $shipped_entries identifier => shipped value
     * @param array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}> $overlay
     * @param \Closure(string, string, ?string):void $write_row persists one lng_data row
     * @return array<string, string> the module's final identifier => value map
     */
    private function resolveMigratedModule(
        array $local_entries,
        array $customized,
        array $shipped_entries,
        array $overlay,
        \Closure $write_row
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

        return $local_entries;
    }

    /**
     * The overlay state of a migrated module, or null. An unreadable overlay must not abort a
     * reinstall whose data the caller has already flushed: it is reported and treated as absent - the
     * local changes recorded in lng_data still apply, and sync() rewrites the overlay afterwards.
     *
     * @return array<string, array{value: string, local_change: bool, local_change_date: ?string, original: ?string}>|null
     */
    private function loadOverlay(string $lang_key, string $module, ?string $client_data_dir): ?array
    {
        try {
            return MigratedLanguageFileSync::loadModuleTranslations(
                $this->language_file_directory_manager,
                $lang_key,
                $module,
                $client_data_dir
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
            // a cache that cannot be invalidated must not undo the successful database write
            error_log(sprintf('Could not invalidate the language cache for "%s": %s', $lang_key, $t->getMessage()));
        }
    }

    /**
     * @param array<string, array<string, string>> $lang_array
     */
    private function syncMigratedModules(string $lang_key, array $lang_array, ?string $client_data_dir): void
    {
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
                    true
                );
            } catch (\Throwable $t) {
                // No injected logger here - this class also runs in Setup contexts before a
                // logging service exists; error_log() works everywhere. The lng_modules write above
                // already succeeded and must not be undone by a problem with the file mirror.
                error_log(sprintf(
                    'Could not sync migrated language file for module "%s", language "%s": %s',
                    $module,
                    $lang_key,
                    $t->getMessage()
                ));
            }
        }
    }
}

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

use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;

/**
* Class ilObjLanguageExt
*
* @author Fred Neumann <fred.neumann@fim.uni-erlangen.de>
* @version $Id: class.ilObjLanguageExt.php $
*
* @ingroup ServicesLanguage
*/
class ilObjLanguageExt extends ilObjLanguage
{
    /**
     * @var array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>}|null
     */
    private ?array $shipped_migrated_modules = null;

    /**
    * Read and get the global language file as an object
    *
    * Its lines of a module maintained in PO files are not that module's source - see
    * getShippedValues()/getShippedComments() for reading the shipped values.
    */
    public function getGlobalLanguageFile(): ilLanguageFile
    {
        return ilLanguageFile::_getGlobalLanguageFile($this->key);
    }

    /**
    * Set the local status of the language
    *
    * $a_local       local status (true/false)
    */
    public function setLocal(bool $a_local = true): void
    {
        if ($this->isInstalled()) {
            if ($a_local) {
                $this->setDescription("installed_local");
            } else {
                $this->setDescription("installed");
            }
            $this->update();
        }
    }


    /**
    * Get the full language description
    *
    * Return       description
    */
    public function getLongDescription(): string
    {
        return $this->lng->txt($this->desc);
    }


    /**
     * Return the path for language data written by ILIAS
     */
    public function getDataPath(): string
    {
        if (!is_dir(CLIENT_DATA_DIR . "/lang_data")) {
            ilFileUtils::makeDir(CLIENT_DATA_DIR . "/lang_data");
        }
        return CLIENT_DATA_DIR . "/lang_data";
    }

    /**
    * Get the language files path
    *
    * Return path of language files folder
    */
    public function getLangPath(): string
    {
        return $this->lang_path;
    }

    /**
    * Get the customized language files path
    *
    * Return path of customized language files folder
    */
    public function getCustLangPath(): string
    {
        return $this->cust_lang_path;
    }

    /**
    * Get all remarks from the database
    *
    * DB-only, unlike the value getters below: a migrated module's PO overlay has no "remarks"
    * equivalent (see _getRemarks()'s docblock) - remarks for a migrated module are therefore always
    * missing here, not merely stale.
    *
    * Return array  module.separator.topic => remark
    */
    public function getAllRemarks(): array
    {
        return self::_getRemarks($this->key);
    }

    /**
    * Get all values from the database - and, for a module migrated to the PO/MO pilot, from its
    * overlay instead (see _getValues()'s docblock below); "the database" is only accurate for a
    * module that hasn't migrated yet.
    *
    * $a_modules       list of modules
    * $a_pattern       search pattern
    * $a_topics        list of topics
    * Return array     module.separator.topic => value
    */
    public function getAllValues(array $a_modules = array(), string $a_pattern = "", array $a_topics = array()): array
    {
        return self::_getValues($this->key, $a_modules, $a_topics, $a_pattern);
    }


    /**
    * Get only the changed values from the database - and, for a module migrated to the PO/MO pilot,
    * from its overlay instead (see _getValues()'s docblock) - which differ from the original
    * language file.
    *
    * $a_modules       list of modules
    * $a_pattern       search pattern
    * $a_topics        list of topics
    * Return array     module.separator.topic => value
    */
    public function getChangedValues(array $a_modules = array(), string $a_pattern = "", array $a_topics = array()): array
    {
        return self::_getValues($this->key, $a_modules, $a_topics, $a_pattern, "changed");
    }


    /**
    * Get only the unchanged values from the database - and, for a module migrated to the PO/MO
    * pilot, from its overlay instead (see _getValues()'s docblock) - which are equal to the
    * original language file.
    *
    * Return array    module.separator.topic => value
    */
    public function getUnchangedValues(array $a_modules = array(), string $a_pattern = "", array $a_topics = array()): array
    {
        return self::_getValues($this->key, $a_modules, $a_topics, $a_pattern, "unchanged");
    }

    /**
    * Get only the entries which don't exist in the shipped language files (see getShippedValues()) -
    * read the same way as getAllValues() (DB, or a migrated module's overlay - see _getValues()'s
    * docblock)
    *
    * $a_modules       list of modules
    * $a_pattern       search pattern
    * $a_topics        list of topics
    * Return array     module.separator.topic => value
    */
    public function getAddedValues(array $a_modules = array(), string $a_pattern = '', array $a_topics = array()): array
    {
        $local_values = self::_getValues($this->key, $a_modules, $a_topics, $a_pattern);

        return array_diff_key($local_values, $this->getShippedValues());
    }


    /**
    * Get all values for which the shipped language files have a comment (see getShippedComments()) -
    * read the same way as getAllValues() (DB, or a migrated module's overlay - see _getValues()'s
    * docblock)
    *
    * Note: This function checks the comments in the shipped language files,
    *       not the remarks in the database!
    *
    * $a_modules         list of modules
    * $a_pattern         search pattern
    * $a_topics          list of topics
    * Return   array     module.separator.topic => value
    */
    public function getCommentedValues(array $a_modules = array(), string $a_pattern = "", array $a_topics = array()): array
    {
        $local_values = self::_getValues($this->key, $a_modules, $a_topics, $a_pattern);

        return array_intersect_key($local_values, $this->getShippedComments());
    }


    /**
    * Get the local values merged into the shipped values (see getShippedValues()) - read the same way
    * as getAllValues() (DB, or a migrated module's overlay - see _getValues()'s docblock)
    *
    * The returned array contains:
    * 1. all entries that exist in the shipped language files, with their local values,
    *    ordered like in the global language file (modules maintained in PO files last)
    * 2. all additional local entries,
    *    ordered by module and identifier
    *
    * Not for writing the global language file - see mergeLocalChangesIntoGlobalLanguageFile().
    *
    * Return   array       module.separator.topic => value
    */
    public function getMergedValues(): array
    {
        return array_merge($this->getShippedValues(), self::_getValues($this->key));
    }

    /**
    * Get the local remarks merged into the comments of the shipped language files (see
    * getShippedComments())
    *
    * DB-only, same caveat as getAllRemarks(): a migrated module has no remarks to contribute here
    * (see _getRemarks()'s docblock).
    *
    * The returned array contains:
    * 1. all comments that exist in the shipped language files, with their local values,
    *    ordered like in the global language file (modules maintained in PO files last)
    * 2. all additional local remarks,
    *    ordered by module and identifier
    *
    * Return   array       module.separator.topic => value
    */
    public function getMergedRemarks(): array
    {
        // Get remarks including empty remarks for local changes
        $local_remarks = self::_getRemarks($this->key, true);

        return array_merge($this->getShippedComments(), $local_remarks);
    }

    /**
     * The shipped values of this language: the global language file (lang/ilias_<key>.lang) - except
     * for every module maintained in PO files, whose lines there are not its source: for such a
     * module the values of its shipped .po are used instead (see LanguageInstallationManager). A
     * shipped .po that cannot be read keeps that module's .lang lines as the best available
     * approximation - logged, and reported by getUnreadableShippedPoModules() so a GUI can show a
     * warning.
     *
     * @return array<string, string> module.separator.topic => value
     */
    public function getShippedValues(): array
    {
        return self::shippedValuesAndComments($this->getGlobalLanguageFile(), $this->shippedMigratedModules())[0];
    }

    /**
     * The comments of the shipped language files - the counterpart of getShippedValues(): a module
     * maintained in PO files contributes the extracted comments of its shipped .po instead of its
     * .lang comments.
     *
     * @return array<string, string> module.separator.topic => comment
     */
    public function getShippedComments(): array
    {
        return self::shippedValuesAndComments($this->getGlobalLanguageFile(), $this->shippedMigratedModules())[1];
    }

    /**
     * Every module maintained in PO files for this language (its shipped .po exists).
     *
     * @return list<string>
     */
    public function getModulesMaintainedInPoFiles(): array
    {
        return $this->shippedMigratedModules()['modules'];
    }

    /**
     * The modules maintained in PO files whose shipped .po cannot be read - see getShippedValues().
     *
     * @return list<string>
     */
    public function getUnreadableShippedPoModules(): array
    {
        return $this->shippedMigratedModules()['unreadable_modules'];
    }

    /**
     * Merges the local changes back into the global language file (lang/ilias_<key>.lang) - the
     * "merge" maintenance action of the developer mode (LANGMODE). A module maintained in PO files is
     * skipped entirely: its source is the shipped .po, so neither its local values nor its local
     * remarks are written, no line is added for it, and every line it already has in the file is
     * written back exactly as it was read (see ilLanguageFile::keepOriginalLines()).
     *
     * @return list<string> the skipped modules maintained in PO files, to be reported to the user
     */
    public function mergeLocalChangesIntoGlobalLanguageFile(): array
    {
        $skipped_modules = $this->getModulesMaintainedInPoFiles();
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_values = $global_file_obj->getAllValues();

        $global_file_obj->setAllValues(array_merge(
            $global_values,
            self::withoutModules(self::_getValues($this->key), $skipped_modules, $this->separator)
        ));
        $global_file_obj->setAllComments(array_merge(
            $global_file_obj->getAllComments(),
            self::withoutModules(self::_getRemarks($this->key, true), $skipped_modules, $this->separator)
        ));
        $global_file_obj->keepOriginalLines(array_keys(array_diff_key(
            $global_values,
            self::withoutModules($global_values, $skipped_modules, $this->separator)
        )));
        $global_file_obj->write();

        return $skipped_modules;
    }

    /**
     * @return array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>}
     */
    private function shippedMigratedModules(): array
    {
        return $this->shipped_migrated_modules ??= self::readShippedMigratedModules($this->key);
    }

    /**
    * Import a language file into the ilias database
    *
    * $a_mode_existing      handling of existing values
    *                       ('keepall','keepnew','replace','delete')
    * $refreshOriginalFromShipped   Whether $a_file IS the shipped core language file for this
    *                       component (i.e. this import represents "reset to shipped defaults", not
    *                       importing an admin-uploaded or customizing/local file that merely happens
    *                       to have the same format) - forwarded to _saveValues()/replaceLangModule()
    *                       and to syncMigratedFilesAfterDeleteModeImport() (see their own docblocks).
    *                       Defaults to `false`: an uploaded or customizing file is never assumed to be
    *                       the shipped baseline, even under "replace" mode. When `true`, the values
    *                       of every module migrated to PO/MO are taken from its shipped .po instead
    *                       of $a_file (see getShippedValues()) - and nothing at all is imported if
    *                       one of these shipped .po files cannot be read.
    *
    * @return list<string> the modules maintained in PO files whose PO/MO overlay could not be
    *         written - the database was written regardless; empty if every overlay is in sync
    * @throws ilLanguageException with $refreshOriginalFromShipped, if the shipped .po of a module
    *         maintained in PO files cannot be read - thrown before anything is changed
    */
    public function importLanguageFile(
        string $a_file,
        string $a_mode_existing = "keepnew",
        bool $refreshOriginalFromShipped = false
    ): array {
        global $DIC;
        $ilDB = $DIC->database();
        /** @var ilErrorHandling $ilErr */
        $ilErr = $DIC["ilErr"];

        // Read the new language file
        $import_file_obj = new ilLanguageFile($a_file);
        if (!$import_file_obj->read()) {
            $ilErr->raiseError($import_file_obj->getErrorMessage(), $ilErr->MESSAGE);
        }

        $shipped = null;
        if ($refreshOriginalFromShipped) {
            // Checked before anything is changed: resetting to the shipped values without the
            // shipped values of a migrated module would silently reset it to its outdated .lang lines.
            // Read through the instance cache, so a caller reporting getUnreadableShippedPoModules()
            // afterwards neither parses nor logs the same files again.
            $shipped = $this->shippedMigratedModules();
            if ($shipped['unreadable_modules'] !== []) {
                throw new ilLanguageException(sprintf(
                    'The shipped PO files of the following modules cannot be read: %s',
                    implode(', ', $shipped['unreadable_modules'])
                ));
            }
        }

        // Only "delete" wipes lng_data/lng_modules for the whole language up front, independently of
        // what the imported file actually contains - see below. The other three modes never remove a
        // module's DB row without _saveValues() also (re-)writing it from $to_save in the same request,
        // so they can never drift from a migrated module's .po/.mo mirror; only "delete" needed the
        // modules-before snapshot at all.
        $modules_before_delete = ($a_mode_existing === "delete") ? self::_getModules($this->key) : [];

        switch ($a_mode_existing) {
            // keep all existing entries
            case "keepall":
                $to_keep = $this->getAllValues();
                break;

                // keep existing online changes
            case "keepnew":
                $to_keep = $this->getChangedValues();
                break;

                // replace all existing definitions
            case "replace":
                $to_keep = array();
                break;

                // delete all existing entries
            case "delete":
                ilObjLanguage::_deleteLangData($this->key, false);
                $ilDB->manipulate("DELETE FROM lng_modules WHERE lang_key = " .
                    $ilDB->quote($this->key, "text"));
                $to_keep = array();
                break;

            default:
                return [];
        }

        $import_values = $import_file_obj->getAllValues();
        if ($shipped !== null) {
            // $a_file is the shipped .lang file - for a migrated module the shipped .po is the only
            // source of its shipped values, not the module's (possibly outdated) .lang lines
            $import_values = array_merge(
                self::withoutModules($import_values, $shipped['modules'], $this->separator),
                $shipped['values']
            );
        }

        // process the values of the import file
        $to_save = array();
        foreach ($import_values as $key => $value) {
            if (!isset($to_keep[$key])) {
                $to_save[$key] = $value;
            }
        }
        $modules_with_unwritten_overlay = self::_saveValues(
            $this->key,
            $to_save,
            $import_file_obj->getAllComments(),
            $refreshOriginalFromShipped
        );

        if ($a_mode_existing === "delete") {
            $modules_with_unwritten_overlay = array_merge(
                $modules_with_unwritten_overlay,
                $this->syncMigratedFilesAfterDeleteModeImport($modules_before_delete, $to_save, $refreshOriginalFromShipped)
            );
        }

        return array_values(array_unique($modules_with_unwritten_overlay));
    }

    /**
     * "delete" mode above wipes lng_modules for the entire language before _saveValues() runs.
     * _saveValues()'s own module loop (`class.ilObjLanguageExt.php::_saveValues()`) now reaches
     * replaceLangModule() (and, through it, the ordinary PO/MO sync) again for every module present
     * in $to_save - a previously pre-existing, unrelated bug that made it skip that write entirely
     * for every module after a "delete" import is fixed. That still leaves one gap this method
     * covers: _saveValues() only iterates modules present in $to_save, so a module that existed
     * before the delete but has no entries at all in the imported file is never visited by it, and
     * its now-orphaned PO/MO mirror (for a migrated module) would otherwise keep serving stale
     * values forever. This method does not rely on _saveValues() having synced anything, migrated or
     * not: it independently syncs every module this language previously had any lng_data row for,
     * using exactly the entries $to_save just wrote back for it (or an empty map for a module the
     * imported file did not mention at all) - "replace" semantics, matching every other write path.
     * A module that has no entries in $to_save has none in $modules_before_delete either counted
     * twice; array_unique below only matters for a module present in both sets (the common case: it
     * already existed and is still in the file). For that common case, this duplicates the PO/MO sync
     * replaceLangModule() already performed inside _saveValues() with the same entries - harmless
     * (MigratedLanguageFileSync::sync() is idempotent, see its own docblock) but redundant; left as is
     * since removing it would require distinguishing the two cases here without changing behavior.
     *
     * @param list<string> $modules_before_delete every module this language had any lng_data row for,
     *        captured before the raw DELETE above ran.
     * @param array<string, string> $to_save module.separator.topic => value, exactly what was just
     *        written via _saveValues() - the complete, final DB content for this language now.
     * @param bool $refreshOriginalFromShipped forwarded verbatim to MigratedLanguageFileSync::sync()
     *        below - see importLanguageFile()'s docblock for what it means here.
     * @return list<string> the modules whose overlay could not be written
     */
    private function syncMigratedFilesAfterDeleteModeImport(
        array $modules_before_delete,
        array $to_save,
        bool $refreshOriginalFromShipped = false
    ): array {
        global $DIC;

        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return [];
        }

        $entries_by_module = [];
        foreach ($to_save as $key => $value) {
            $parts = explode($this->separator, $key);
            if (count($parts) === 2) {
                $entries_by_module[$parts[0]][$parts[1]] = $value;
            }
        }

        $manager = $DIC[LanguageFileDirectoryManager::class];
        $client_data_dir = MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH);
        $failed_modules = [];
        foreach (array_unique(array_merge($modules_before_delete, array_keys($entries_by_module))) as $module) {
            try {
                MigratedLanguageFileSync::sync(
                    $manager,
                    ILIAS_ABSOLUTE_PATH,
                    $this->key,
                    (string) $module,
                    $entries_by_module[$module] ?? [],
                    $client_data_dir,
                    $refreshOriginalFromShipped
                );
            } catch (\Throwable $t) {
                $DIC->logger()->forComponent('lang')->warning(sprintf(
                    'Could not sync migrated language file for module "%s", language "%s" after a' .
                    ' "delete"-mode import: %s',
                    $module,
                    $this->key,
                    $t->getMessage()
                ));
                $failed_modules[] = (string) $module;
            }
        }

        return $failed_modules;
    }

    /**
    * Get all modules of a language
    *
    * $a_lang_key      language key
    * Return list of modules
    */
    public static function _getModules(string $a_lang_key): array
    {
        global $DIC;
        $ilDB = $DIC->database();

        $q = "SELECT DISTINCT module FROM lng_data WHERE " .
            " lang_key = " . $ilDB->quote($a_lang_key, "text") . " order by module";
        $set = $ilDB->query($q);

        $modules = array();
        while ($rec = $set->fetchRow(ilDBConstants::FETCHMODE_ASSOC)) {
            $modules[] = $rec["module"];
        }

        // A migrated module is included even if - unlike today's dual-write guarantee - lng_data
        // ever stopped holding a row for it: its .po/.mo file is now its authoritative source,
        // independent of lng_data's content.
        if ($DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            $migrated_modules = MigratedLanguageFileSync::getMigratedModules(
                $DIC[LanguageFileDirectoryManager::class],
                $a_lang_key,
                MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH)
            );
            $modules = array_unique(array_merge($modules, $migrated_modules));
            sort($modules);
        }

        return $modules;
    }


    /**
    * Get all remarks of a language
    *
    * Always reads lng_data, unlike _getValues()/_getModules() below - deliberately not extended to
    * a migrated module's PO overlay: a free-text remark has no representation in the .po format the
    * overlay uses, so there is simply no file-based source to read it from. A migrated module
    * therefore never contributes a remark here, dual-write or not.
    *
    * $a_lang_key          language key
    * $a_all_changed       include empty remarks for local changes
    * Return   array       module.separator.topic => remarks
    */
    public static function _getRemarks(string $a_lang_key, bool $a_all_changed = false): array
    {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        $q = "SELECT module, identifier, remarks"
        . " FROM lng_data"
        . " WHERE lang_key = " . $ilDB->quote($a_lang_key, "text");

        if ($a_all_changed) {
            $q .= " AND (remarks IS NOT NULL OR local_change IS NOT NULL)";
        } else {
            $q .= " AND remarks IS NOT NULL";
        }

        $result = $ilDB->query($q);

        $remarks = array();
        while ($row = $ilDB->fetchAssoc($result)) {
            $remarks[$row["module"] . $lng->separator . $row["identifier"]] = $row["remarks"];
        }
        return $remarks;
    }


    /**
    * Get the translations of specified topics
    *
    * $a_lang_key         language key
    * $a_modules          list of modules
    * $a_topics           list of topics
    * $a_pattern          search pattern
    * $a_state            local change state ('changed', 'unchanged', '')
    * Return   array      module.separator.topic => value
    */
    public static function _getValues(
        string $a_lang_key,
        array $a_modules = array(),
        array $a_topics = array(),
        string $a_pattern = '',
        string $a_state = ''
    ): array {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        // Migrated modules are read from their .po file, not lng_data - it is now their
        // authoritative source, exactly matching what ilLanguage::txt() itself would serve for
        // them. Every filter below ($a_topics/$a_pattern/$a_state) is
        // re-applied in PHP against this file-sourced data, mirroring the SQL WHERE clauses further
        // down for the still DB-backed, non-migrated modules.
        $migrated_values = [];
        $migrated_modules_found = [];
        if ($DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            $manager = $DIC[LanguageFileDirectoryManager::class];
            $client_data_dir = MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH);
            $candidate_modules = $a_modules !== []
                ? $a_modules
                : MigratedLanguageFileSync::getMigratedModules($manager, $a_lang_key, $client_data_dir);

            foreach ($candidate_modules as $module) {
                try {
                    $translations = MigratedLanguageFileSync::loadModuleTranslations(
                        $manager,
                        $a_lang_key,
                        $module,
                        $client_data_dir
                    );
                } catch (\Throwable $t) {
                    // an unreadable overlay: show the (dual-written) lng_data rows instead
                    $DIC->logger()->forComponent('lang')->warning(sprintf(
                        'Could not read migrated language file for module "%s", language "%s": %s',
                        $module,
                        $a_lang_key,
                        $t->getMessage()
                    ));
                    $translations = null;
                }
                if ($translations === null) {
                    continue;
                }
                $migrated_modules_found[] = $module;

                foreach ($translations as $identifier => $entry) {
                    $identifier = (string) $identifier;
                    if ($a_topics !== [] && !in_array($identifier, $a_topics, true)) {
                        continue;
                    }
                    if ($a_pattern !== '' && !self::matchesLikePattern($entry['value'], $a_pattern)) {
                        continue;
                    }
                    if ($a_state === 'changed' && !$entry['local_change']) {
                        continue;
                    }
                    if ($a_state === 'unchanged' && $entry['local_change']) {
                        continue;
                    }
                    $migrated_values[$module . $lng->separator . $identifier] = $entry['value'];
                }
            }
        }

        $q = "SELECT module, identifier, value FROM lng_data WHERE" .
            " lang_key = " . $ilDB->quote($a_lang_key, "text") . " ";

        if ($migrated_modules_found !== []) {
            // Excluded entirely, not merely overridden below: lng_data still holds a dual-written
            // copy of a migrated module's row, but it must not also surface here and produce a
            // duplicate, stale-if-ever-diverged entry alongside the file-sourced one above.
            $q .= " AND " . $ilDB->in("module", $migrated_modules_found, true, "text");
        }
        if (is_array($a_modules) && count($a_modules) > 0) {
            $q .= " AND " . $ilDB->in("module", $a_modules, false, "text");
        }
        if (is_array($a_topics) && count($a_topics) > 0) {
            $q .= " AND " . $ilDB->in("identifier", $a_topics, false, "text");
        }
        if ($a_pattern !== '') {
            $q .= " AND " . $ilDB->like("value", "text", "%" . $a_pattern . "%");
        }
        if ($a_state === "changed") {
            $q .= " AND NOT local_change IS NULL ";
        }
        if ($a_state === "unchanged") {
            $q .= " AND local_change IS NULL ";
        }
        $q .= " ORDER BY module, identifier";

        $set = $ilDB->query($q);

        $values = array();
        while ($rec = $set->fetchRow(ilDBConstants::FETCHMODE_ASSOC)) {
            $values[$rec["module"] . $lng->separator . $rec["identifier"]] = $rec["value"];
        }

        $values = array_merge($values, $migrated_values);
        // Re-establishes the query's ORDER BY module, identifier for the merged-in migrated modules:
        // case-insensitive like the *_unicode_ci collation, and since the separator's "#" sorts
        // before every character of a module name, "module#:#identifier" sorts by module first
        ksort($values, SORT_STRING | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * The shipped values and comments of every module maintained in PO files for $a_lang_key, read
     * from their shipped .po (the only source of a migrated module's shipped values, see
     * LanguageInstallationManager). A shipped .po that cannot be read is logged and listed in
     * "unreadable_modules" (its module stays in "modules"); callers decide whether that is fatal
     * (importLanguageFile()) or only worth a warning (the readers above). Everything empty if no
     * LanguageFileDirectoryManager is registered.
     *
     * @return array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>}
     *         values/comments keyed module.separator.identifier
     */
    private static function readShippedMigratedModules(string $a_lang_key): array
    {
        global $DIC;

        $result = ['values' => [], 'comments' => [], 'modules' => [], 'unreadable_modules' => []];
        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return $result;
        }

        $separator = $DIC->language()->separator;
        $shipped_files = MigratedLanguageFileSync::findShippedModuleFiles(
            $DIC[LanguageFileDirectoryManager::class],
            ILIAS_ABSOLUTE_PATH,
            $a_lang_key
        );
        foreach ($shipped_files as $module => $shipped_po) {
            $module = (string) $module;
            $result['modules'][] = $module;
            try {
                $entries = MigratedLanguageFileSync::loadShippedModuleEntries($shipped_po, $module);
            } catch (\Throwable $t) {
                $DIC->logger()->forComponent('lang')->warning(sprintf(
                    'Could not read the shipped PO file of module "%s", language "%s": %s',
                    $module,
                    $a_lang_key,
                    $t->getMessage()
                ));
                $result['unreadable_modules'][] = $module;
                continue;
            }
            foreach ($entries as $identifier => $entry) {
                $key = $module . $separator . $identifier;
                $result['values'][$key] = $entry['value'];
                if ($entry['comment'] !== null) {
                    $result['comments'][$key] = $entry['comment'];
                }
            }
        }

        return $result;
    }

    /**
     * The values and comments of $global_file with every readable module maintained in PO files
     * replaced by its shipped .po content - see getShippedValues().
     *
     * @param array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>} $shipped
     * @return array{0: array<string, string>, 1: array<string, string>} values, comments
     */
    private static function shippedValuesAndComments(ilLanguageFile $global_file, array $shipped): array
    {
        global $DIC;

        $separator = $DIC->language()->separator;
        $replaced_modules = array_values(array_diff($shipped['modules'], $shipped['unreadable_modules']));

        return [
            array_merge(self::withoutModules($global_file->getAllValues(), $replaced_modules, $separator), $shipped['values']),
            array_merge(self::withoutModules($global_file->getAllComments(), $replaced_modules, $separator), $shipped['comments']),
        ];
    }

    /**
     * @param array<string, string|null> $values module.separator.identifier => value
     * @param list<string> $modules
     * @return array<string, string|null> $values without the entries of $modules
     */
    private static function withoutModules(array $values, array $modules, string $separator): array
    {
        if ($modules === []) {
            return $values;
        }
        $excluded = array_flip($modules);

        return array_filter(
            $values,
            static fn(int|string $key): bool => !isset($excluded[explode($separator, (string) $key, 2)[0]]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The PHP counterpart of the `UPPER(value) LIKE UPPER('%<pattern>%')` condition _getValues()
     * applies to lng_data (see ilDBInterface::like()), so searching a migrated module behaves like
     * searching any other one: case-insensitive, "%" matches any sequence and "_" any single
     * character, "\" escapes the next character (MySQL's default LIKE escape), and the pattern may
     * match anywhere in the value. Not replicated: accent-insensitivity of an *_unicode_ci collation.
     * Consecutive "%" are collapsed into a single ".*" - equivalent, but without the needless
     * backtracking a run of ".*" would cause.
     */
    private static function matchesLikePattern(string $value, string $pattern): bool
    {
        $regex = '';
        $length = mb_strlen($pattern);
        $previous_was_any_sequence = false;
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($pattern, $i, 1);
            $is_any_sequence = $char === '%';
            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote(mb_substr($pattern, ++$i, 1), '/');
            } elseif ($is_any_sequence) {
                $regex .= $previous_was_any_sequence ? '' : '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
            $previous_was_any_sequence = $is_any_sequence;
        }

        return preg_match('/' . $regex . '/isu', $value) === 1;
    }

    /**
    * Save a set of translation in the database
    *
    * $a_lang_key      language key
    * $a_values        module.separator.topic => value
    * $a_remarks       module.separator.topic => remarks
    * $refreshOriginalFromShipped forwarded verbatim to ilObjLanguage::replaceLangModule() - see its
    *      own docblock. `false` (the default) for an ordinary form-save; `true` only when the caller
    *      can vouch that $a_values reflects the current shipped content (see importLanguageFile()).
    *
    * @return list<string> the modules maintained in PO files whose PO/MO overlay could not be
    *         written - the database was written regardless; empty if every overlay is in sync
    */
    public static function _saveValues(
        string $a_lang_key,
        array $a_values = array(),
        array $a_remarks = array(),
        bool $refreshOriginalFromShipped = false
    ): array {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        $save_array = [];
        $save_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        // Read and get the shipped values - for a module maintained in PO files from its shipped .po
        // (an unreadable one keeps its .lang lines for this comparison, see getShippedValues())
        [$file_values, $file_comments] = self::shippedValuesAndComments(
            ilLanguageFile::_getGlobalLanguageFile($a_lang_key),
            self::readShippedMigratedModules($a_lang_key)
        );
        $db_values = self::_getValues($a_lang_key);
        $db_comments = self::_getRemarks($a_lang_key);
        $global_values = array_merge($db_values, $file_values);
        $global_comments = array_merge($db_comments, $file_comments);

        // save the single translations in lng_data
        foreach ($a_values as $key => $value) {
            $keys = explode($lng->separator, $key);

            if (count($keys) !== 2) {
                continue;
            }

            list($module, $topic) = $keys;
            $save_array[$module][$topic] = $value;

            $are_comments_set = array_key_exists($key, $global_comments) && array_key_exists($key, $a_remarks);
            $are_changes_made = (isset($global_values[$key]) ? $global_values[$key] != $value : true) || (isset($db_values[$key]) ? $db_values[$key] != $value : true);
            if ($are_changes_made || ($are_comments_set ? $global_comments[$key] != $a_remarks[$key] : $are_comments_set)) {
                $local_change = (isset($db_values[$key]) ? $db_values[$key] == $value : true) || (isset($global_values[$key]) ? $global_values[$key] != $value : true) ? $save_date : null;
                ilObjLanguage::replaceLangEntry(
                    $module,
                    $topic,
                    $a_lang_key,
                    $value,
                    $local_change,
                    $a_remarks[$key] ?? null
                );
            }
        }

        // save the serialized module entries in lng_modules
        $modules_with_unwritten_overlay = [];
        foreach ($save_array as $module => $entries) {
            $set = $ilDB->query(sprintf(
                "SELECT lang_array FROM lng_modules " .
                "WHERE lang_key = %s AND module = %s",
                $ilDB->quote($a_lang_key, "text"),
                $ilDB->quote($module, "text")
            ));
            $row = $ilDB->fetchAssoc($set);

            // No existing lng_modules row for this module (e.g. after a "delete"-mode import wiped
            // it, see importLanguageFile()) - _mergeLanguageEntriesFromRow() treats a missing row as
            // "nothing to merge" and returns $entries unchanged, so replaceLangModule() below still
            // creates the row from scratch. Its own INSERT never depended on a prior row existing.
            $entries = self::_mergeLanguageEntriesFromRow($row ?: null, $entries);

            if (!ilObjLanguage::replaceLangModule($a_lang_key, (string) $module, $entries, $refreshOriginalFromShipped)) {
                $modules_with_unwritten_overlay[] = (string) $module;
            }
        }

        ilCachedLanguage::getInstance($a_lang_key)->flush();

        return $modules_with_unwritten_overlay;
    }

    /**
     * Merge language entries from a database row with existing entries
     *
     * $databaseRow     associative array representing a row from the database, may be null
     * $entries         array of existing language entries to be merged
     * Return array     merged array of language entries
     */
    private static function _mergeLanguageEntriesFromRow(?array $databaseRow, array $entries): array
    {
        if ($databaseRow === null || !isset($databaseRow["lang_array"])) {
            return $entries;
        }

        $languageEntries = unserialize($databaseRow["lang_array"], ["allowed_classes" => false]);
        if (!is_array($languageEntries)) {
            return $entries;
        }

        return array_merge($languageEntries, $entries);
    }


    /**
    * Delete a set of translation in the database
    *
    * $a_lang_key       language key
    * $a_values         module.separator.topic => value
    *
    * @return list<string> see _saveValues()
    */
    public static function _deleteValues(string $a_lang_key, array $a_values = array()): array
    {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        $delete_array = array();
        $modules_with_unwritten_overlay = [];

        // save the single translations in lng_data
        foreach ($a_values as $key => $value) {
            $keys = explode($lng->separator, $key);
            if (count($keys) === 2) {
                $module = $keys[0];
                $topic = $keys[1];
                $delete_array[$module][$topic] = $value;

                ilObjLanguage::deleteLangEntry($module, $topic, $a_lang_key);
            }
        }

        // save the serialized module entries in lng_modules
        foreach ($delete_array as $module => $entries) {
            $set = $ilDB->query(sprintf(
                "SELECT lang_array FROM lng_modules " .
                "WHERE lang_key = %s AND module = %s",
                $ilDB->quote($a_lang_key, "text"),
                $ilDB->quote($module, "text")
            ));
            $row = $ilDB->fetchAssoc($set);

            // Without a (readable) lng_modules row there is nothing left to keep - the entries to
            // delete must not be written back as the module's content instead
            $arr = is_array($row) ? unserialize((string) $row["lang_array"], ["allowed_classes" => false]) : null;
            $entries = is_array($arr) ? array_diff_key($arr, $entries) : [];
            if (!ilObjLanguage::replaceLangModule($a_lang_key, (string) $module, $entries)) {
                $modules_with_unwritten_overlay[] = (string) $module;
            }
        }

        return $modules_with_unwritten_overlay;
    }
} // END class.ilObjLanguageExt

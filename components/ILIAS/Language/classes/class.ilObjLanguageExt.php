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
    * Read and get the global language file as an object
    * @return  object  global language file
    */
    public function getGlobalLanguageFile(): object
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
    * Get only the entries which don't exist in the global language file - read the same way as
    * getAllValues() (DB, or a migrated module's overlay - see _getValues()'s docblock)
    *
    * $a_modules       list of modules
    * $a_pattern       search pattern
    * $a_topics        list of topics
    * Return array     module.separator.topic => value
    */
    public function getAddedValues(array $a_modules = array(), string $a_pattern = '', array $a_topics = array()): array
    {
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_values = $global_file_obj->getAllValues();
        $local_values = self::_getValues($this->key, $a_modules, $a_topics, $a_pattern);

        return array_diff_key($local_values, $global_values);
    }


    /**
    * Get all values for which the global language file has a comment - read the same way as
    * getAllValues() (DB, or a migrated module's overlay - see _getValues()'s docblock)
    *
    * Note: This function checks the comments in the globel lang file,
    *       not the remarks in the database!
    *
    * $a_modules         list of modules
    * $a_pattern         search pattern
    * $a_topics          list of topics
    * Return   array     module.separator.topic => value
    */
    public function getCommentedValues(array $a_modules = array(), string $a_pattern = "", array $a_topics = array()): array
    {
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_comments = $global_file_obj->getAllComments();
        $local_values = self::_getValues($this->key, $a_modules, $a_topics, $a_pattern);

        return array_intersect_key($local_values, $global_comments);
    }


    /**
    * Get the local values merged into the values of the global language file - read the same way as
    * getAllValues() (DB, or a migrated module's overlay - see _getValues()'s docblock)
    *
    * The returned array contains:
    * 1. all entries that exist globally, with their local values,
    *    ordered like in the global language file
    * 2. all additional local entries,
    *    ordered by module and identifier
    *
    * Return   array       module.separator.topic => value
    */
    public function getMergedValues(): array
    {
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_values = $global_file_obj->getAllValues();
        $local_values = self::_getValues($this->key);

        return array_merge($global_values, $local_values);
    }

    /**
    * Get the local remarks merged into the remarks of the global language file
    *
    * DB-only, same caveat as getAllRemarks(): a migrated module has no remarks to contribute here
    * (see _getRemarks()'s docblock).
    *
    * The returned array contains:
    * 1. all remarks that exist globally, with their local values,
    *    ordered like in the global language file
    * 2. all additional local remarks,
    *    ordered by module and identifier
    *
    * Return   array       module.separator.topic => value
    */
    public function getMergedRemarks(): array
    {
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_comments = $global_file_obj->getAllComments();

        // get remarks including empty remarks for local changes
        $local_remarks = self::_getRemarks($this->key, true);

        return array_merge($global_comments, $local_remarks);
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
    *                       the shipped baseline, even under "replace" mode.
    */
    public function importLanguageFile(
        string $a_file,
        string $a_mode_existing = "keepnew",
        bool $refreshOriginalFromShipped = false
    ): void {
        global $DIC;
        $ilDB = $DIC->database();
        /** @var ilErrorHandling $ilErr */
        $ilErr = $DIC["ilErr"];

        // read the new language file
        $import_file_obj = new ilLanguageFile($a_file);
        if (!$import_file_obj->read()) {
            $ilErr->raiseError($import_file_obj->getErrorMessage(), $ilErr->MESSAGE);
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
                return;
        }

        // process the values of the import file
        $to_save = array();
        foreach ($import_file_obj->getAllValues() as $key => $value) {
            if (!isset($to_keep[$key])) {
                $to_save[$key] = $value;
            }
        }
        self::_saveValues($this->key, $to_save, $import_file_obj->getAllComments(), $refreshOriginalFromShipped);

        if ($a_mode_existing === "delete") {
            $this->syncMigratedFilesAfterDeleteModeImport($modules_before_delete, $to_save, $refreshOriginalFromShipped);
        }
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
     */
    private function syncMigratedFilesAfterDeleteModeImport(
        array $modules_before_delete,
        array $to_save,
        bool $refreshOriginalFromShipped = false
    ): void {
        global $DIC;

        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return;
        }

        $entries_by_module = [];
        foreach ($to_save as $key => $value) {
            $parts = explode($this->separator, $key);
            if (count($parts) === 2) {
                $entries_by_module[$parts[0]][$parts[1]] = $value;
            }
        }

        $manager = $DIC[LanguageFileDirectoryManager::class];
        $client_data_dir = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : null;
        foreach (array_unique(array_merge($modules_before_delete, array_keys($entries_by_module))) as $module) {
            try {
                MigratedLanguageFileSync::sync(
                    $manager,
                    ILIAS_ABSOLUTE_PATH,
                    $this->key,
                    $module,
                    $entries_by_module[$module] ?? [],
                    false,
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
            }
        }
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
                defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : null
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
            $client_data_dir = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : null;
            $candidate_modules = $a_modules !== []
                ? $a_modules
                : MigratedLanguageFileSync::getMigratedModules($manager, $a_lang_key, $client_data_dir);

            foreach ($candidate_modules as $module) {
                $translations = MigratedLanguageFileSync::loadModuleTranslations(
                    $manager,
                    $a_lang_key,
                    $module,
                    $client_data_dir
                );
                if ($translations === null) {
                    continue;
                }
                $migrated_modules_found[] = $module;

                foreach ($translations as $identifier => $entry) {
                    if ($a_topics !== [] && !in_array($identifier, $a_topics, true)) {
                        continue;
                    }
                    if ($a_pattern !== '' && !str_contains($entry['value'], $a_pattern)) {
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
        if ($a_pattern) {
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
        ksort($values);

        return $values;
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
    */
    public static function _saveValues(
        string $a_lang_key,
        array $a_values = array(),
        array $a_remarks = array(),
        bool $refreshOriginalFromShipped = false
    ): void {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        if (!is_array($a_values)) {
            return;
        }
        $save_array = [];
        $save_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        // read and get the global values
        $global_file_obj = ilLanguageFile::_getGlobalLanguageFile($a_lang_key);
        $file_values = $global_file_obj->getAllValues();
        $file_comments = $global_file_obj->getAllComments();
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

            ilObjLanguage::replaceLangModule($a_lang_key, $module, $entries, $refreshOriginalFromShipped);
        }

        ilCachedLanguage::getInstance($a_lang_key)->flush();
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
    */
    public static function _deleteValues(string $a_lang_key, array $a_values = array()): void
    {
        global $DIC;
        $ilDB = $DIC->database();
        $lng = $DIC->language();

        if (!is_array($a_values)) {
            return;
        }
        $delete_array = array();

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

            $arr = unserialize($row["lang_array"], ["allowed_classes" => false]);
            if (is_array($arr)) {
                $entries = array_diff_key($arr, $entries);
            }
            ilObjLanguage::replaceLangModule($a_lang_key, $module, $entries);
        }
    }
} // END class.ilObjLanguageExt

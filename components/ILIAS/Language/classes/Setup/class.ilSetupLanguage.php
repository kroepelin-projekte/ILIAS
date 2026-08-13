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
use ILIAS\Language\ComponentTranslation\ComponentLanguageFileDirectory;

/**
 * language handling for setup
 *
 * this class offers the language handling for an application.
 * the constructor is called with a small language abbreviation
 * e.g. $lng = new Language("en");
 * the constructor reads the single-languagefile en.lang and puts this into an array.
 * with
 * e.g. $lng->txt("user_updated");
 * you can translate a lang-topic into the actual language
 *
 * @author Peter Gabriel <pgabriel@databay.de>
 * @version $Id$
 *
 *
 * @todo The DATE field is not set correctly on changes of a language (update, install, your stable).
 *  The format functions do not belong in class.Language. Those are also applicable elsewhere.
 *  Therefore, they would be better placed in class.Format
 * @todo This somehow needs to be reconciled with the base class and most probably be factored
 *  into two classes, one for management, one for retrieval.
 */
class ilSetupLanguage extends ilLanguage
{
    private string $absolute_path;
    public array $text;
    public string $lang_default = "en";
    public string $lang_key;
    public string $separator = "#:#";
    public string $comment_separator = "###";
    protected ilDBInterface $db;

    public function __construct(
        string $a_lang_key,
        private readonly LanguageFileDirectoryManager $language_file_directory_manager,
    ) {
        $this->lang_key = $a_lang_key ?: $this->lang_default;
        $this->absolute_path = realpath(__DIR__ . "/../../../../../");
        $this->cust_lang_path = $this->absolute_path . "/lang/customizing";
    }

    /**
     * gets the text for a given topic
     *
     * if the topic is not in the list, the topic itself with "-" will be returned
     *
     * $a_topic    topic
     */
    public function txt(string $a_topic, string $a_default_lang_fallback_mod = ''): string
    {
        global $log;

        if (empty($a_topic)) {
            return "";
        }

        $translation = $this->text[$a_topic] ?? '';

        //get position of the comment_separator
        $pos = strpos($translation, $this->comment_separator);

        if ($pos !== false) {
            // remove comment
            $translation = substr($translation, 0, $pos);
        }

        if ($translation === "") {
            $log->writeLanguageLog($a_topic, $this->lang_key);
            return "-" . $a_topic . "-";
        }

        return $translation;
    }

    /**
     * install languages
     *
     * $a_lang_keys    array with lang_keys of languages to install
     *
     * @return array|bool
     */
    public function installLanguages(array $a_lang_keys, array $a_local_keys)
    {
        global $ilDB;

        if (empty($a_lang_keys)) {
            $a_lang_keys = array();
        }

        if (empty($a_local_keys)) {
            $a_local_keys = array();
        }

        $err_lang = array();

        $db_langs = $this->getAvailableLanguages();

        foreach ($a_lang_keys as $lang_key) {
            if ($this->checkLanguage($lang_key)) {
                $this->flushLanguage($lang_key, "keep_local");
                $this->insertLanguage($lang_key);

                if (in_array($lang_key, $a_local_keys, true) && is_dir($this->cust_lang_path)) {
                    if ($this->checkLanguage($lang_key, "local")) {
                        $this->insertLanguage($lang_key, "local");
                    } else {
                        $err_lang[] = $lang_key;
                    }
                }

                // register language first time install
                if (!array_key_exists($lang_key, $db_langs)) {
                    if (in_array($lang_key, $a_local_keys, true)) {
                        $itype = "installed_local";
                    } else {
                        $itype = "installed";
                    }
                    $lid = $ilDB->nextId("object_data");
                    $query = "INSERT INTO object_data " .
                            "(obj_id,type,title,description,owner,create_date,last_update) " .
                            "VALUES " .
                            "(" .
                            $ilDB->quote($lid, "integer") . "," .
                            $ilDB->quote("lng", "text") . "," .
                            $ilDB->quote($lang_key, "text") . "," .
                            $ilDB->quote($itype, "text") . "," .
                            $ilDB->quote("-1", "integer") . "," .
                            $ilDB->now() . "," .
                            $ilDB->now() .
                            ")";
                    $ilDB->manipulate($query);
                }
            } else {
                $err_lang[] = $lang_key;
            }
        }

        foreach ($db_langs as $key => $val) {
            if (!in_array($key, $err_lang, true)) {
                if (in_array($key, $a_lang_keys, true)) {
                    if (in_array($key, $a_local_keys, true)) {
                        $ld = "installed_local";
                    } else {
                        $ld = "installed";
                    }
                    $query = "UPDATE object_data SET " .
                            "description = " . $ilDB->quote($ld, "text") . ", " .
                            "last_update = " . $ilDB->quote(gmdate("Y-m-d H:i:s"), "timestamp") . " " .
                            "WHERE obj_id = " . $ilDB->quote($val["obj_id"], "integer") . " " .
                            "AND type = " . $ilDB->quote("lng", "text");
                    $ilDB->manipulate($query);
                } else {
                    $this->flushLanguage($key, "all");

                    if (strpos($val["status"], "installed") === 0) {
                        $query = "UPDATE object_data SET " .
                            "description = " . $ilDB->quote("not_installed", "text") . ", " .
                            "last_update = " . $ilDB->quote(gmdate("Y-m-d H:i:s"), "timestamp") . " " .
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
     * get already installed languages (in db)
     */
    public function getInstalledLanguages(): array
    {
        global $ilDB;

        $arr = [];
        if ($ilDB instanceof ilDBInterface) {
            $query = "SELECT * FROM object_data " .
                "WHERE type = " . $ilDB->quote("lng", "text") . " " .
                "AND " . $ilDB->like("description", "text", "installed%");
            $r = $ilDB->query($query);

            while ($row = $ilDB->fetchObject($r)) {
                $arr[] = $row->title;
            }
        }
        return $arr;
    }

    /**
     * get already installed local languages (in db)
     */
    public function getInstalledLocalLanguages(): array
    {
        global $ilDB;

        $arr = [];
        if ($ilDB instanceof ilDBInterface) {
            $query = "SELECT * FROM object_data " .
                "WHERE type = " . $ilDB->quote("lng", "text") . " " .
                "AND description = " . $ilDB->quote("installed_local", "text");
            $r = $ilDB->query($query);

            while ($row = $ilDB->fetchObject($r)) {
                $arr[] = $row->title;
            }
        }
        return $arr;
    }

    /**
     * get already registered languages (in db)
     */
    protected function getAvailableLanguages(): array
    {
        global $ilDB;

        $arr = array();

        $query = "SELECT * FROM object_data " .
                "WHERE type = " . $ilDB->quote("lng", "text");
        $r = $ilDB->query($query);

        while ($row = $ilDB->fetchObject($r)) {
            $arr[$row->title]["obj_id"] = $row->obj_id;
            $arr[$row->title]["status"] = $row->description;
        }

        return $arr;
    }

    /**
     * validate the logical structure of a lang-file
     *
     * This function checks if a lang-file of a given lang_key exists,
     * the file has a header, and each lang-entry consists of exactly
     * three elements (module, identifier, value).
     *
     * $a_lang_key     international language key (2 digits)
     * $scope          empty (global) or "local"
     * $info_text      message about results of check OR "1" if all checks successfully passed
     */
    protected function checkLanguage(string $a_lang_key, string $scope = ""): bool
    {
        $normalized_scope = ($scope === 'global') ? '' : $scope;
        $scope_extension = ($normalized_scope !== '') ? ('.' . $normalized_scope) : '';
        $lang_file = "ilias_" . $a_lang_key . ".lang" . $scope_extension;

        $working_dir = getcwd();

        foreach ($this->getDirectoriesToValidate($normalized_scope) as $directory) {
            $path = $this->getAbsoluteDirectoryPath($directory);

            try {
                chdir($path);

                if (!is_file($lang_file)) {
                    return false;
                }

                $content = $this->cut_header(file($lang_file));
                if ($content === false) {
                    return false;
                }

                $prefix = $directory->getPrefix();

                foreach ($content as $line) {
                    $parts = explode($this->separator, trim($line));

                    if (!empty($prefix)) {
                        // preprend $directory->getPrefix() as first element of the array
                        array_unshift($parts, $prefix);
                    }
                    if (count($parts) !== 3) {
                        return false;
                    }
                }
            } finally {
                chdir($working_dir);
            }
        }

        return true;
    }

    /**
     * @return \Generator|\ILIAS\Language\ComponentTranslation\LanguageFileDirectory[]
     */
    private function getDirectoriesToValidate(string $scope): \Generator
    {
        if ($scope === 'local') {
            yield from $this->language_file_directory_manager->getCustomizingDirectories();
        } else {
            yield from $this->language_file_directory_manager->getDirectories();
        }
    }

    private function getAbsoluteDirectoryPath(\ILIAS\Language\ComponentTranslation\LanguageFileDirectory $directory): string
    {
        $this->lang_path = rtrim($this->absolute_path, '/') . '/' . ltrim($directory->getPath(), '/');
        return $this->lang_path;
    }

    /**
     * Remove *.lang header information from '$content'.
     *
     * This function seeks for a special keyword where the language information starts.
     * If found it returns the plain language information; otherwise returns false.
     *
     * $content    expect an ILIAS lang-file
     *
     * @return bool|string[]
     */
    protected function cut_header(array $content)
    {
        foreach ($content as $key => $val) {
            if (trim($val) === "<!-- language file start -->") {
                return array_slice($content, $key + 1);
            }
        }
        return false;
    }

    /**
     * remove language data from database
     * $a_lang_key     language key
     * $a_mode        "all" or "keep_local"
     */
    protected function flushLanguage(string $a_lang_key, string $a_mode = "all"): void
    {
        global $ilDB;

        self::_deleteLangData($a_lang_key, ($a_mode === "keep_local"));

        if ($a_mode === "all") {
            $ilDB->manipulate("DELETE FROM lng_modules WHERE lang_key = " .
                $ilDB->quote($a_lang_key, "text"));
        }
    }

    /**
    * Delete languge data
    *
    * $a_lang_key lang key
    */
    public static function _deleteLangData(string $a_lang_key, bool $a_keep_local_change): void
    {
        global $ilDB;

        if (!$a_keep_local_change) {
            $ilDB->manipulate("DELETE FROM lng_data WHERE lang_key = " .
                $ilDB->quote($a_lang_key, "text"));
        } else {
            $ilDB->manipulate("DELETE FROM lng_data WHERE lang_key = " .
                $ilDB->quote($a_lang_key, "text") .
                " AND local_change IS NULL");
        }
    }

    /**
    * get locally changed language entries
    * $a_lang_key language key
    * $a_min_date minimum change date "yyyy-mm-dd hh:mm:ss"
    * $a_max_date maximum change date "yyyy-mm-dd hh:mm:ss"
    * Returned value       [module][identifier] => value
    */
    public function getLocalChanges(string $a_lang_key, string $a_min_date = "", string $a_max_date = ""): array
    {
        global $ilDB;

        if ($a_min_date === "") {
            $a_min_date = "1980-01-01 00:00:00";
        }
        if ($a_max_date === "") {
            $a_max_date = "2200-01-01 00:00:00";
        }

        $q = sprintf(
            "SELECT * FROM lng_data WHERE lang_key = %s " .
            "AND local_change >= %s AND local_change <= %s",
            $ilDB->quote($a_lang_key, "text"),
            $ilDB->quote($a_min_date, "timestamp"),
            $ilDB->quote($a_max_date, "timestamp")
        );
        $result = $ilDB->query($q);

        $changes = array();
        while ($row = $result->fetchRow(ilDBConstants::FETCHMODE_ASSOC)) {
            $changes[$row["module"]][$row["identifier"]] = $row["value"];
        }
        return $changes;
    }

    /**
     * insert language data from file in database
     *
     * $lang_key   international language key (2 digits)
     * $scope      empty (global) or "local"
     */
    protected function insertLanguage(string $a_lang_key, string $scope = ""): void
    {
        global $ilDB;

        $normalized_scope = ($scope === 'global') ? '' : $scope;
        $scope_extension = ($normalized_scope !== '') ? ('.' . $normalized_scope) : '';
        $lang_file = "ilias_" . $a_lang_key . ".lang" . $scope_extension;

        $working_dir = getcwd();

        // initialize variables
        $change_date = null;
        $values_sql = [];
        $lang_array = [];
        $local_changes = [];

        foreach ($this->getDirectoriesToValidate($normalized_scope) as $directory) {
            $path = $this->getAbsoluteDirectoryPath($directory);

            chdir($path);

            if (!is_file($lang_file)) {
                continue;
            }

            // remove header first
            $content = $this->cut_header(file($lang_file));
            if (!$content) {
                continue;
            }

            // get the local changes from the database
            if ($normalized_scope === '') {
                $local_changes = $this->getLocalChanges($a_lang_key);
            } elseif ($normalized_scope === 'local') {
                $change_date = gmdate("Y-m-d H:i:s", time());
                $min_date = gmdate("Y-m-d H:i:s", filemtime($lang_file));
                $local_changes = $this->getLocalChanges($a_lang_key, $min_date);
            }

            $prefix = $directory->getPrefix();

            foreach ($content as $line) {
                // split the line of the language file
                // [0]: module
                // [1]: identifier
                // [2]: value
                // [3]: comment (optional)
                $separated = explode($this->separator, trim($line));

                if (!empty($prefix)) {
                    // prepend $directory->getPrefix() as first element of the array
                    array_unshift($separated, $prefix);
                }

                //get position of the comment_separator
                $pos = strpos($separated[2], $this->comment_separator);

                if ($pos !== false) {
                    //cut comment of
                    $separated[2] = substr($separated[2], 0, $pos);
                }

                $module = $separated[0];
                $identifier = $separated[1];
                $value = $separated[2];

                // check if the value has a local change
                $local_value = $local_changes[$module][$identifier] ?? "";

                if ($normalized_scope === '') {
                    if ($local_value !== "" && $local_value !== $value) {
                        $lang_array[$module][$identifier] = $local_value;
                        continue;
                    }
                } elseif ($normalized_scope === 'local') {
                    if ($local_value !== "") {
                        $lang_array[$module][$identifier] = $local_value;
                        continue;
                    }
                }

                $values_sql[] = sprintf(
                    "(%s,%s,%s,%s,%s,%s)",
                    $ilDB->quote($module, "text"),
                    $ilDB->quote($identifier, "text"),
                    $ilDB->quote($a_lang_key, "text"),
                    $ilDB->quote($value, "text"),
                    $ilDB->quote($change_date, "timestamp"),
                    $ilDB->quote($separated[3] ?? null, "text")
                );

                $lang_array[$module][$identifier] = $value;
            }
        }

        if ($values_sql !== []) {
            $query = "INSERT INTO lng_data (module,identifier,lang_key,value,local_change,remarks) VALUES "
                . implode(',', $values_sql)
                . " ON DUPLICATE KEY UPDATE value=VALUES(value),remarks=VALUES(remarks);";
            $ilDB->manipulate($query);
        }

        if ($lang_array === []) {
            return;
        }

        $modules = array_keys($lang_array);
        if ($normalized_scope === 'local') {
            $inModules = $ilDB->in('module', $modules, false, 'text');
            $query = "SELECT module, lang_array FROM lng_modules WHERE lang_key = "
                . $ilDB->quote($a_lang_key, "text")
                . " AND $inModules";

            $set = $ilDB->query($query);
            $existing = [];
            while ($row = $ilDB->fetchAssoc($set)) {
                $existing[$row['module']] = $row['lang_array'];
            }

            foreach ($lang_array as $module => $lang_arr) {
                if (!isset($existing[$module])) {
                    unset($lang_array[$module]);
                    continue;
                }

                $arr2 = unserialize($existing[$module], ["allowed_classes" => false]);
                if (is_array($arr2)) {
                    $lang_array[$module] = array_merge($arr2, $lang_arr);
                }
            }

            if ($lang_array === []) {
                return;
            }

            $modules = array_keys($lang_array);
        }

        $inModulesToDelete = $ilDB->in('module', $modules, false, 'text');
        $ilDB->manipulate(sprintf(
            "DELETE FROM lng_modules WHERE lang_key = %s AND $inModulesToDelete",
            $ilDB->quote($a_lang_key, "text")
        ));

        $modulesValuesSql = [];
        foreach ($lang_array as $module => $lang_arr) {
            $modulesValuesSql[] = sprintf(
                "(%s,%s,%s)",
                $ilDB->quote($module, "text"),
                $ilDB->quote($a_lang_key, "text"),
                $ilDB->quote(serialize($lang_arr), "clob")
            );
        }

        $query = "INSERT INTO lng_modules (module, lang_key, lang_array) VALUES "
            . implode(',', $modulesValuesSql)
            . ";";
        $ilDB->manipulate($query);

        chdir($working_dir);
    }

    /**
     * Searches for the existence of *.lang.local files.
     * Returns array with language keys
     */
    public function getLocalLanguages(): array
    {
        $local_langs = array();
        if (is_dir($this->cust_lang_path)) {
            $d = dir($this->cust_lang_path);
            $tmpPath = getcwd();
            chdir($this->cust_lang_path);

            // get available .lang.local files
            while ($entry = $d->read()) {
                if (is_file($entry) && (preg_match("~(^ilias_.{2}\.lang.local$)~", $entry))) {
                    $lang_key = substr($entry, 6, 2);
                    $local_langs[] = $lang_key;
                }
            }

            chdir($tmpPath);
        }

        return $local_langs;
    }

    /**
     * Return installable languages
     */
    public function getInstallableLanguages(): array
    {
        $d = dir($this->lang_path);
        $tmpPath = getcwd();
        chdir($this->lang_path);

        $installableLanguages = [];
        // get available lang-files
        while ($entry = $d->read()) {
            if (is_file($entry) && (preg_match("~(^ilias_.{2}\.lang$)~", $entry))) {
                $lang_key = substr($entry, 6, 2);
                $installableLanguages[] = $lang_key;
            }
        }

        chdir($tmpPath);

        return $installableLanguages;
    }

    /**
     * set db handler object
     * @string   object      db handler
     * Return true on success
     */
    public function setDbHandler(ilDBInterface $a_db_handler): bool
    {
        $this->db = &$a_db_handler;
        return true;
    }

    public function loadLanguageModule(string $a_module): void
    {
    }
}

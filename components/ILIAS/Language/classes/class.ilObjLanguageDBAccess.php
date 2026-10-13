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

class ilObjLanguageDBAccess
{
    protected ilDBInterface $ilDB;
    protected string $key;
    protected array $content;
    protected string $scope;
    protected array $local_changes;
    protected ?string $change_date = null;
    protected string $separator;
    protected string $comment_separator;

    public function __construct(ilDBInterface $ilDB, string $key, array $content, array $local_changes, string $scope = "", string $separator = "#:#", string $comment_separator = "###")
    {
        $this->ilDB = $ilDB;
        $this->key = $key;
        $this->content = $content;
        $this->local_changes = $local_changes;
        $this->scope = $scope;
        if ($scope === "local") {
            $this->change_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s');
        }
        $this->separator = $separator;
        $this->comment_separator = $comment_separator;
    }

    public function insertLangEntries(string $lang_file): array
    {
        $entries = self::collectEntries(
            $this->content,
            $this->local_changes,
            $this->scope,
            $this->separator,
            $this->comment_separator
        );

        if ($entries["duplicates"] !== []) {
            global $DIC;
            $DIC->ui()->mainTemplate()->setOnScreenMessage(
                'failure',
                "Duplicate Language Entry in $lang_file:\n" . $entries["duplicates"][0],
                true
            );
            $DIC->ctrl()->redirectByClass(ilobjlanguagefoldergui::class, 'view');
        }

        $rows = [];
        foreach ($entries["rows"] as $row) {
            $rows[] = sprintf(
                "(%s,%s,%s,%s,%s,%s)",
                $this->ilDB->quote($row["module"], "text"),
                $this->ilDB->quote($row["identifier"], "text"),
                $this->ilDB->quote($this->key, "text"),
                $this->ilDB->quote($row["value"], "text"),
                $this->ilDB->quote($this->change_date, "timestamp"),
                $this->ilDB->quote($row["remarks"], "text")
            );
        }
        if ($rows !== []) {
            $this->ilDB->manipulate(
                "INSERT INTO lng_data (module,identifier,lang_key,value,local_change,remarks) VALUES " .
                implode(",", $rows) .
                " ON DUPLICATE KEY UPDATE identifier=VALUES(identifier),value=VALUES(value),local_change=VALUES(local_change),remarks=VALUES(remarks);"
            );
        }

        return $entries["lang_array"];
    }

    /**
     * Splits the lines of a language file into the values for lng_modules and the rows for lng_data.
     *
     * lng_data compares identifiers case-insensitively, so identifiers differing only in case share one row:
     * the last line of the file wins, and a local change of that row protects all its spellings.
     * lng_modules keeps every spelling with its own value; a kept local change replaces the value of its own
     * spelling or, if the file no longer contains that spelling, the value of the last spelling.
     * Identifiers are grouped with strtolower(), which folds ASCII only; the collation utf8mb3_general_ci also folds
     * non-ASCII letters and ignores trailing spaces. The language files currently contain no such identifiers.
     * Remarks are cut to the 250 characters of lng_data.remarks.
     *
     * @param array<string, array<string, ?string>> $local_changes module => identifier => value
     * @return array{lang_array: array<string, array<string, string>>, rows: list<array{module: string, identifier: string, value: string, remarks: ?string}>, duplicates: list<string>}
     */
    public static function collectEntries(
        array $content,
        array $local_changes,
        string $scope = "",
        string $separator = "#:#",
        string $comment_separator = "###"
    ): array {
        $folded_local_changes = [];
        foreach ($local_changes as $module => $identifiers) {
            foreach ($identifiers as $identifier => $value) {
                $folded_local_changes[strtolower($module . $separator . $identifier)] = [
                    "identifier" => (string) $identifier,
                    "value" => (string) $value,
                ];
            }
        }

        $lang_array = ["common" => []];
        $rows = [];
        $kept = [];
        $local_spelling_found = [];
        $seen = [];
        $duplicates = [];
        foreach ($content as $line) {
            // [0]: module, [1]: identifier, [2]: value, [3]: comment (optional)
            $separated = explode($separator, trim($line));
            $module = $separated[0];
            $identifier = $separated[1];
            $value = $separated[2];
            $remarks = null;
            $pos = strpos($value, $comment_separator);
            if ($pos !== false) {
                $remarks = mb_substr(substr($value, $pos + strlen($comment_separator)), 0, 250);
                $value = substr($value, 0, $pos);
            }

            if (isset($seen[$module][$identifier])) {
                $duplicates[] = $line;
            }
            $seen[$module][$identifier] = true;

            $folded_key = strtolower($module . $separator . $identifier);
            $local = $folded_local_changes[$folded_key] ?? null;
            if ($local !== null && $local["identifier"] === $identifier) {
                $local_spelling_found[$folded_key] = true;
                if (self::keepsLocalValue($local["value"], $value, $scope)) {
                    $lang_array[$module][$identifier] = $local["value"];
                    $kept[$folded_key] = true;
                    continue;
                }
            }

            $lang_array[$module][$identifier] = $value;
            $rows[$folded_key] = [
                "module" => $module,
                "identifier" => $identifier,
                "value" => $value,
                "remarks" => $remarks,
            ];
        }

        foreach ($rows as $folded_key => $row) {
            if (isset($kept[$folded_key])) {
                unset($rows[$folded_key]);
                continue;
            }
            $local = $folded_local_changes[$folded_key] ?? null;
            if ($local !== null
                && !isset($local_spelling_found[$folded_key])
                && self::keepsLocalValue($local["value"], $row["value"], $scope)
            ) {
                // the spelling of the local change is no longer in the file: it applies to the last spelling
                $lang_array[$row["module"]][$row["identifier"]] = $local["value"];
                unset($rows[$folded_key]);
            }
        }

        return [
            "lang_array" => $lang_array,
            "rows" => array_values($rows),
            "duplicates" => $duplicates,
        ];
    }

    private static function keepsLocalValue(string $local_value, string $file_value, string $scope): bool
    {
        if ($local_value === "") {
            return false;
        }
        if ($scope === "") {
            // import of a global language file: keep an existing and different local value
            return $local_value !== $file_value;
        }
        // import of a local language file: keep a locally changed value that is newer than the file
        return $scope === "local";
    }

    public function replaceLangModules(array $lang_array): void
    {
        // avoid flushing the whole cache (see mantis #28818)
        ilCachedLanguage::getInstance($this->key)->deleteInCache();

        $query = "INSERT INTO lng_modules (module, lang_key, lang_array) VALUES ";
        $modules_to_delete = [];
        foreach ($lang_array as $module => $lang_arr) {
            if ($this->scope === "local") {
                $q = "SELECT * FROM lng_modules WHERE " .
                    " lang_key = " . $this->ilDB->quote($this->key, "text") .
                    " AND module = " . $this->ilDB->quote($module, "text");
                $set = $this->ilDB->query($q);
                $row = $this->ilDB->fetchAssoc($set);
                if ($row === null) {
                    continue;
                }
                $arr2 = unserialize($row["lang_array"], ["allowed_classes" => false]);
                if (is_array($arr2)) {
                    $lang_arr = array_merge($arr2, $lang_arr);
                }
            }
            $query .= sprintf(
                "(%s,%s,%s),",
                $this->ilDB->quote($module, "text"),
                $this->ilDB->quote($this->key, "text"),
                $this->ilDB->quote(serialize($lang_arr), "clob"),
            );
            $modules_to_delete[] = $module;
        }

        $inModulesToDelete = $this->ilDB->in('module', $modules_to_delete, false, 'text');
        $this->ilDB->manipulate(sprintf(
            "DELETE FROM lng_modules WHERE lang_key = %s AND $inModulesToDelete",
            $this->ilDB->quote($this->key, "text")
        ));

        $query = rtrim($query, ",") . ";";
        $this->ilDB->manipulate($query);

        // check if the module is correctly saved
        // see mantis #20046 and #19140
        $this->checkModules();
    }

    protected function checkModules(): void
    {
        $result = $this->ilDB->queryF(
            "SELECT module, lang_array FROM lng_modules WHERE lang_key = %s",
            array("text"),
            array($this->key)
        );

        foreach ($this->ilDB->fetchAll($result) as $module) {
            $unserialied = unserialize($module["lang_array"], ["allowed_classes" => false]);
            if (!is_array($unserialied)) {
                global $DIC;
                $DIC->ui()->mainTemplate()->setOnScreenMessage(
                    'failure',
                    "Data for module '" . $module["module"] . "' of  language '" . $this->key . "' is not correctly saved. " .
                    "Please check the collation of your database tables lng_data and lng_modules. It must be utf8_unicode_ci.",
                    true
                );
                $DIC->ctrl()->redirectByClass(ilobjlanguagefoldergui::class, 'view');
            }
        }
    }
}

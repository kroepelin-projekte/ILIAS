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
use ILIAS\Language\ComponentTranslation\PlainLogText;
use ILIAS\Language\ComponentTranslation\PluralFormKey;
use ILIAS\Language\ComponentTranslation\ShippedPoMerger;
use ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy;

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
     * How the admin GUI marks an entry of a module maintained in PO files that is not translated yet
     * (flagged fuzzy in its shipped .po) - the wording of the dated "... new variable" comments of the
     * legacy .lang files, which carried that information before. Not a language variable (yet).
     */
    private const string SHIPPED_FUZZY_MARKER = 'new variable';

    /**
     * @var array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>, fuzzy: list<string>}|null
     */
    private ?array $shipped_migrated_modules = null;

    /**
     * @var array<string, list<string>> see getSkippedInvalidMarkupValues()
     */
    private array $skipped_invalid_markup_values = [];

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
    * shipped .po with the overlay delta on top instead (see _getValues()'s docblock below); "the database" is only accurate for a
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
    * from its shipped .po with the overlay delta on top instead (see _getValues()'s docblock) - which differ from the original
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
    * pilot, from its shipped .po with the overlay delta on top instead (see _getValues()'s docblock) - which are equal to the
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
    * read the same way as getAllValues() (DB, or a migrated module's shipped .po plus overlay delta - see _getValues()'s
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
    * read the same way as getAllValues() (DB, or a migrated module's shipped .po plus overlay delta - see _getValues()'s
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
    * as getAllValues() (DB, or a migrated module's shipped .po plus overlay delta - see _getValues()'s docblock)
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
     * getShippedComments() for display only (the admin GUI): additionally with the "not translated
     * yet" marker for every fuzzy entry of a module maintained in PO files (see withFuzzyMarkers()).
     * The marker is no comment - it is neither exported, nor filtered for, nor compared on saving.
     *
     * @return array<string, string> module.separator.topic => comment
     */
    public function getShippedCommentsForDisplay(): array
    {
        return self::withFuzzyMarkers($this->getShippedComments(), $this->shippedMigratedModules());
    }

    /**
     * For display only (like getShippedCommentsForDisplay()): the shipped comments of the modules
     * maintained in PO files for $a_lang_key - their `#.` notes, with the "not translated yet" marker
     * for a fuzzy entry -, e.g. of a compare language in the admin GUI. With $a_modules only those
     * modules' shipped .po are read.
     *
     * @param list<string> $a_modules
     * @return array<string, string> module.separator.topic => comment
     */
    public static function _getShippedMigratedComments(string $a_lang_key, array $a_modules = []): array
    {
        $shipped = self::readShippedMigratedModules($a_lang_key, $a_modules);

        return self::withFuzzyMarkers($shipped['comments'], $shipped);
    }

    /**
     * $comments with the marker SHIPPED_FUZZY_MARKER for every entry of a module maintained in PO
     * files that is flagged fuzzy (not translated yet) - appended to its `#.` note, if any. The
     * legacy `.lang` files carried the same information as a dated "... new variable" comment.
     *
     * @param array<string, string> $comments
     * @param array{fuzzy: list<string>} $shipped see readShippedMigratedModules()
     * @return array<string, string>
     */
    private static function withFuzzyMarkers(array $comments, array $shipped): array
    {
        foreach ($shipped['fuzzy'] as $key) {
            $comments[$key] = isset($comments[$key]) && $comments[$key] !== ''
                ? $comments[$key] . ' - ' . self::SHIPPED_FUZZY_MARKER
                : self::SHIPPED_FUZZY_MARKER;
        }

        return $comments;
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
     * Merges the local changes back into the shipped language files - the "merge" maintenance action
     * of the developer mode (LANGMODE):
     * - every module not maintained in PO files into the global language file
     *   (lang/ilias_<key>.lang), as before; the lines a module maintained in PO files has there are
     *   written back exactly as they were read (see ilLanguageFile::keepOriginalLines());
     * - every module maintained in PO files with local changes into its shipped .po (and a locally
     *   added entry into its template .pot), see ShippedPoMerger - the shipped .po is backed up into
     *   the customizing directory before (like the global language file by the caller). Afterwards
     *   the taken over entries are no local changes any more: they leave the overlay, and their
     *   lng_data rows lose local_change - and their remarks where these became `#.` comments of the
     *   shipped entry (the database stays in line with the files, see mergeIntoDatabase()).
     *
     * @return array{
     *     written: list<string>,
     *     skipped: list<string>,
     *     invalid_markup: array<string, list<string>>,
     *     not_merged: list<string>,
     *     unwritten_overlay: list<string>,
     *     unwritten_database: list<string>
     * } for the modules maintained in PO files: written - the shipped .po files written (relative to
     *   the ILIAS directory); skipped - the modules left out as a whole (e.g. not writable, logged
     *   with the reason); invalid_markup - module.separator.identifier => violations of values not
     *   taken over because of markup that is not allowed; not_merged - module.separator.identifier of
     *   entries that could not be taken over (see ShippedPoMerger, logged); unwritten_overlay -
     *   modules whose overlay could not be reconciled; unwritten_database - modules whose lng_data
     *   rows could not be updated (logged; their files are written)
     */
    public function mergeLocalChangesIntoGlobalLanguageFile(): array
    {
        $po_modules = $this->getModulesMaintainedInPoFiles();
        $global_file_obj = $this->getGlobalLanguageFile();
        $global_values = $global_file_obj->getAllValues();

        $global_file_obj->setAllValues(array_merge(
            $global_values,
            self::withoutModules(self::_getValues($this->key), $po_modules, $this->separator)
        ));
        $global_file_obj->setAllComments(array_merge(
            $global_file_obj->getAllComments(),
            self::withoutModules(self::_getRemarks($this->key, true), $po_modules, $this->separator)
        ));
        $global_file_obj->keepOriginalLines(array_keys(array_diff_key(
            $global_values,
            self::withoutModules($global_values, $po_modules, $this->separator)
        )));
        $global_file_obj->write();

        $result = $this->mergeLocalChangesIntoShippedPoFiles();
        // the shipped values read so far are outdated now
        $this->shipped_migrated_modules = null;

        return $result;
    }

    /**
     * The part of mergeLocalChangesIntoGlobalLanguageFile() for the modules maintained in PO files.
     *
     * @return array{written: list<string>, skipped: list<string>, invalid_markup: array<string, list<string>>, not_merged: list<string>, unwritten_overlay: list<string>, unwritten_database: list<string>}
     */
    private function mergeLocalChangesIntoShippedPoFiles(): array
    {
        global $DIC;

        $result = ['written' => [], 'skipped' => [], 'invalid_markup' => [], 'not_merged' => [], 'unwritten_overlay' => [], 'unwritten_database' => []];
        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return $result;
        }
        $lang_key = $this->key;
        $merged = ShippedPoMerger::merge(
            $DIC[LanguageFileDirectoryManager::class],
            ILIAS_ABSOLUTE_PATH,
            $lang_key,
            MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
            $this->getCustLangPath(),
            static function (string $module, array $identifiers, array $remark_identifiers) use ($lang_key): void {
                self::mergeIntoDatabase($lang_key, $module, $identifiers, $remark_identifiers);
            }
        );

        $logger = $DIC->logger()->forComponent('lang');
        $root = rtrim(ILIAS_ABSOLUTE_PATH, '/') . '/';
        foreach ($merged['written'] as $file) {
            $result['written'][] = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        }
        foreach ($merged['skipped'] as $module => $reason) {
            $result['skipped'][] = (string) $module;
            $logger->warning(PlainLogText::of(sprintf(
                'The local changes of module "%s", language "%s" were not merged into its shipped PO file: %s',
                $module,
                $lang_key,
                $reason
            )));
        }
        foreach ($merged['invalid_markup'] as $module => $invalid) {
            foreach ($invalid as $identifier => $violations) {
                $result['invalid_markup'][$module . $this->separator . $identifier] = $violations;
            }
        }
        foreach ($merged['not_merged'] as $module => $identifiers) {
            foreach ($identifiers as $identifier) {
                $result['not_merged'][] = $module . $this->separator . $identifier;
            }
        }
        if ($result['not_merged'] !== []) {
            $logger->warning(PlainLogText::of(sprintf(
                'Language entries not merged into their shipped PO file (a plural message with an empty first form next to other forms, a plural message for a singular shipped one, or a new identifier in the form of a plural form): %s',
                implode(', ', $result['not_merged'])
            )));
        }
        $result['unwritten_overlay'] = $merged['unwritten_overlay'];
        foreach ($merged['unwritten_database'] as $module => $reason) {
            $result['unwritten_database'][] = (string) $module;
            $logger->error(PlainLogText::of(sprintf(
                'The shipped PO file of module "%s", language "%s" was merged, but its lng_data rows could not be updated (local_change/remarks stay set): %s',
                $module,
                $lang_key,
                $reason
            )));
        }
        foreach ($merged['stale_artifacts'] as $module) {
            $logger->warning(sprintf(
                'The build artifact of module "%s", language "%s" could neither be dated back nor removed after merging - run "php cli/setup.php build".',
                $module,
                $lang_key
            ));
        }
        if ($result['written'] !== []) {
            $logger->info(PlainLogText::of(sprintf(
                'Merged the local changes of language "%s" into (now owned by the web server user, run "setup build" to update the artifacts): %s',
                $lang_key,
                implode(', ', $result['written'])
            )));
        }

        return $result;
    }

    /**
     * The database part of merging $identifiers (a plural message under its identifier) of the module
     * maintained in PO files $module into its shipped .po (see mergeLocalChangesIntoGlobalLanguageFile()):
     * their lng_data rows are no local change any more (their value already is the one shipped now)
     * - unlike the global language file, where a merged row keeps its local_change: for a module
     * maintained in PO files the next update compares a local change with the shipped value and the
     * overlay's "original" (see LanguageInstallationManager::resolveMigratedModule()), a remaining
     * local_change would bring the value back as a local change once the shipped one changes.
     * The remarks of $remark_identifiers became `#.` comments of the shipped entry: removed, like a
     * newly installed module has no remark (see LanguageInstallationManager::migratedModuleRemarks()).
     * lng_modules keeps its content - its values do not change.
     *
     * @param list<string> $identifiers
     * @param list<string> $remark_identifiers
     */
    private static function mergeIntoDatabase(string $a_lang_key, string $module, array $identifiers, array $remark_identifiers): void
    {
        global $DIC;
        $ilDB = $DIC->database();

        foreach (['local_change' => $identifiers, 'remarks' => $remark_identifiers] as $column => $keys) {
            if ($keys === []) {
                continue;
            }
            $ilDB->manipulate(sprintf(
                "UPDATE lng_data SET %s = NULL WHERE lang_key = %s AND module = %s AND %s",
                $column,
                $ilDB->quote($a_lang_key, "text"),
                $ilDB->quote($module, "text"),
                $ilDB->in("identifier", $keys, false, "text")
            ));
        }
    }

    /**
     * Copies the shipped .po of every module maintained in PO files into $directory - the backup the
     * filter "conflicts" compares with (see getShippedChangesSinceBackup()), written by the
     * "save_dist" maintenance action next to the backup of the global language file.
     *
     * @return list<string> the modules whose .po could not be copied
     */
    public function backupShippedPoFiles(string $directory): array
    {
        global $DIC;

        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return [];
        }

        return ShippedPoMerger::backupShippedPoFiles(
            $DIC[LanguageFileDirectoryManager::class],
            ILIAS_ABSOLUTE_PATH,
            $this->key,
            $directory,
            // e.g. lang_data below the client data directory: no symbolic link out of it
            defined('CLIENT_DATA_DIR') ? (string) CLIENT_DATA_DIR : $directory
        );
    }

    /**
     * What the shipped language files changed since their backup (the filter "conflicts"): the
     * entries of the global language file that differ from $former_file (its backup) - without the
     * modules maintained in PO files, whose lines there are not their source -, and the entries of
     * the shipped .po of every module maintained in PO files that differ from its backup in
     * $po_backup_directory (see backupShippedPoFiles(); a module without backup contributes nothing).
     *
     * @return array<string, string> module.separator.identifier => current shipped value
     */
    public function getShippedChangesSinceBackup(ilLanguageFile $former_file, string $po_backup_directory): array
    {
        global $DIC;

        $changes = self::withoutModules(
            array_diff_assoc($this->getGlobalLanguageFile()->getAllValues(), $former_file->getAllValues()),
            $this->getModulesMaintainedInPoFiles(),
            $this->separator
        );
        if (!$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return $changes;
        }
        $po_changes = ShippedPoMerger::findShippedChangesSinceBackup(
            $DIC[LanguageFileDirectoryManager::class],
            ILIAS_ABSOLUTE_PATH,
            $this->key,
            $po_backup_directory
        );
        foreach ($po_changes['changes'] as $module => $values) {
            foreach ($values as $identifier => $value) {
                $changes[$module . $this->separator . $identifier] = $value;
            }
        }

        return $changes;
    }

    /**
     * The values of $values (module.separator.identifier => value) a save into $a_lang_key would
     * change and that contain markup TranslationMarkupPolicy does not allow - see
     * TranslationMarkupPolicy::findInvalidChangedValues(): compared with the current values (database,
     * or shipped .po plus overlay of a migrated module) and the shipped values.
     *
     * @param array<string, string> $values
     * @return array<string, list<string>> key => violations
     */
    public static function findInvalidMarkupOfChangedValues(string $a_lang_key, array $values): array
    {
        // The current and shipped values are only read if a value breaks the rules at all
        $policy = new TranslationMarkupPolicy();
        $candidates = array_intersect_key($values, $policy->findInvalidValues($values));
        if ($candidates === []) {
            return [];
        }
        [$shipped_values] = self::shippedValuesAndComments(
            ilLanguageFile::_getGlobalLanguageFile($a_lang_key),
            self::readShippedMigratedModules($a_lang_key)
        );

        return $policy->findInvalidChangedValues($candidates, self::_getValues($a_lang_key), $shipped_values);
    }

    /**
     * The values the last importLanguageFile() with $skipInvalidMarkup left out because of markup
     * that is not allowed.
     *
     * @return array<string, list<string>> module.separator.identifier => violations
     */
    public function getSkippedInvalidMarkupValues(): array
    {
        return $this->skipped_invalid_markup_values;
    }

    /**
     * @return array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>, fuzzy: list<string>}
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
    * An uploaded or customizing/local file - its values are local ones (resetting a language to its
    * shipped state is RemoveLocalLanguageChanges, see ilObjLanguage::removeLocalChanges()).
    *
    * $a_mode_existing      handling of existing values
    *                       ('keepall','keepnew','replace','delete')
    * $skipInvalidMarkup    Every value the import changes (see findInvalidMarkupOfChangedValues()) is
    *                       checked by TranslationMarkupPolicy before anything is changed.
    *                       `false` (an upload): one value with markup that is not allowed rejects the
    *                       whole import (ilLanguageInvalidMarkupException). `true` (the customizing
    *                       file, applied like at installation): such values are left out, the others
    *                       imported - see getSkippedInvalidMarkupValues().
    *
    * @return list<string> the modules maintained in PO files whose PO/MO overlay could not be
    *         written - the database was written regardless; empty if every overlay is in sync
    * @throws ilLanguageInvalidMarkupException see $skipInvalidMarkup - thrown before anything is
    *         changed
    */
    public function importLanguageFile(
        string $a_file,
        string $a_mode_existing = "keepnew",
        bool $skipInvalidMarkup = false
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

        // A plain line of a plural message is its default form - before the modes compare and the
        // markup check runs (see withPluralFormKeys())
        $file_values = self::withPluralFormKeys($import_file_obj->getAllValues(), $this->shippedMigratedModules(), $this->separator);
        $file_comments = self::withPluralFormKeys($import_file_obj->getAllComments(), $this->shippedMigratedModules(), $this->separator);

        $this->skipped_invalid_markup_values = [];
        // Only values the import actually changes (neither the current nor the shipped value) are
        // checked - an exported file imported again must not be rejected for shipped texts
        // - and no value the mode keeps (the existing value stays, the imported one is not written)
        $invalid_values = [];
        $policy = new TranslationMarkupPolicy();
        $kept_by_mode = match ($a_mode_existing) {
            "keepall" => $this->getAllValues(),
            "keepnew" => $this->getChangedValues(),
            default => [],
        };
        $written = array_diff_key($file_values, $kept_by_mode);
        $candidates = array_intersect_key($written, $policy->findInvalidValues($written));
        if ($candidates !== []) {
            $invalid_values = $policy->findInvalidChangedValues($candidates, $this->getAllValues(), $this->getShippedValues());
        }
        if ($invalid_values !== [] && !$skipInvalidMarkup) {
            throw new ilLanguageInvalidMarkupException($invalid_values);
        }
        $this->skipped_invalid_markup_values = $invalid_values;

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

        $import_values = array_diff_key($file_values, $invalid_values);

        // process the values of the import file
        $to_save = array();
        foreach ($import_values as $key => $value) {
            if (!isset($to_keep[$key])) {
                $to_save[$key] = $value;
            }
        }
        // "delete" wiped every module above: $to_save is each module's complete content then, it is
        // not merged onto the (for a migrated module: still existing) overlay
        $modules_with_unwritten_overlay = self::saveValues(
            $this->key,
            $to_save,
            $file_comments,
            $a_mode_existing !== "delete"
        );

        if ($a_mode_existing === "delete") {
            $modules_with_unwritten_overlay = array_merge(
                $modules_with_unwritten_overlay,
                $this->syncMigratedFilesAfterDeleteModeImport($modules_before_delete, $to_save)
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
     * @return list<string> the modules whose overlay could not be written
     */
    private function syncMigratedFilesAfterDeleteModeImport(
        array $modules_before_delete,
        array $to_save
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
                    $client_data_dir
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

        // A module maintained in PO files keeps its remarks in its overlay as well (see
        // MigratedLanguageFileSync::sync()) - they win; a remark only lng_data holds (written before
        // the overlay kept remarks) is shown until the next reconciling write takes it over
        if ($DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            $manager = $DIC[LanguageFileDirectoryManager::class];
            $client_data_dir = MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH);
            foreach (MigratedLanguageFileSync::getMigratedModules($manager, $a_lang_key, $client_data_dir) as $module) {
                try {
                    $overlay_remarks = MigratedLanguageFileSync::loadRemarks($manager, $a_lang_key, $module, $client_data_dir) ?? [];
                } catch (\Throwable $t) {
                    $DIC->logger()->forComponent('lang')->warning(sprintf(
                        'Could not read the remarks of migrated module "%s", language "%s" - using lng_data: %s',
                        $module,
                        $a_lang_key,
                        $t->getMessage()
                    ));
                    continue;
                }
                foreach ($overlay_remarks as $identifier => $remark) {
                    $remarks[$module . $lng->separator . $identifier] = $remark;
                }
            }
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
                    // a plural message is listed as its forms (see PluralFormKey) - a topic selects
                    // all of them, like it selects the row of a singular message
                    if (
                        $a_topics !== []
                        && !in_array($identifier, $a_topics, true)
                        && !in_array(PluralFormKey::parse($identifier)[0] ?? null, $a_topics, true)
                    ) {
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
     * @return array{values: array<string, string>, comments: array<string, string>, modules: list<string>, unreadable_modules: list<string>, fuzzy: list<string>}
     *         values/comments/fuzzy keyed module.separator.identifier ("fuzzy": not translated yet)
     * @param list<string> $only_modules only these modules (all if empty)
     */
    private static function readShippedMigratedModules(string $a_lang_key, array $only_modules = []): array
    {
        global $DIC;

        $result = ['values' => [], 'comments' => [], 'modules' => [], 'unreadable_modules' => [], 'fuzzy' => []];
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
            if ($only_modules !== [] && !in_array($module, $only_modules, true)) {
                continue;
            }
            $result['modules'][] = $module;
            try {
                $entries = MigratedLanguageFileSync::loadShippedModuleEntries($shipped_po, $module);
                foreach (array_keys(MigratedLanguageFileSync::loadShippedFuzzyIdentifiers($shipped_po, $module)) as $identifier) {
                    $result['fuzzy'][] = $module . $separator . $identifier;
                }
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
    *
    * @return list<string> the modules maintained in PO files whose PO/MO overlay could not be
    *         written - the database was written regardless; empty if every overlay is in sync
    */
    public static function _saveValues(
        string $a_lang_key,
        array $a_values = array(),
        array $a_remarks = array()
    ): array {
        return self::saveValues($a_lang_key, $a_values, $a_remarks, true);
    }

    /**
     * _saveValues(); with $merge_onto_current_content `false` the saved entries are each module's
     * complete new content (a "delete"-mode import, which wiped the language before).
     *
     * @return list<string> see _saveValues()
     */
    private static function saveValues(
        string $a_lang_key,
        array $a_values,
        array $a_remarks,
        bool $merge_onto_current_content
    ): array {
        global $DIC;
        $lng = $DIC->language();

        $save_array = [];
        $save_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
        $a_remarks = self::normalizedRemarks($a_remarks);

        // Read and get the shipped values - for a module maintained in PO files from its shipped .po
        // (an unreadable one keeps its .lang lines for this comparison, see getShippedValues())
        $shipped_migrated = self::readShippedMigratedModules($a_lang_key);
        // a plain value of a plural message is its default form (see withPluralFormKeys())
        $a_values = self::withPluralFormKeys($a_values, $shipped_migrated, $lng->separator);
        $a_remarks = self::withPluralFormKeys($a_remarks, $shipped_migrated, $lng->separator);
        [$file_values, $file_comments] = self::shippedValuesAndComments(
            ilLanguageFile::_getGlobalLanguageFile($a_lang_key),
            $shipped_migrated
        );
        $db_values = self::_getValues($a_lang_key);
        $db_comments = self::_getRemarks($a_lang_key);
        $global_values = array_merge($db_values, $file_values);
        $global_comments = array_merge($db_comments, $file_comments);

        // The forms of a plural message of a module maintained in PO files have no lng_data row of
        // their own: the row of the message is written once the module's content is saved, see below
        $plural_rows = [];
        $plural_changed = [];
        $plural_remarks = [];
        // module => identifier => remark ('' removes it) to be written into the overlay
        $remark_changes = [];
        $shipped_by_module = [];

        // save the single translations in lng_data
        foreach ($a_values as $key => $value) {
            $keys = explode($lng->separator, $key);

            if (count($keys) !== 2) {
                continue;
            }

            list($module, $topic) = $keys;
            if (in_array($module, $shipped_migrated['modules'], true)) {
                $shipped_by_module[$module] ??= self::shippedModuleValues($shipped_migrated, $module, $lng->separator);
                // A module maintained in PO files: the value is what its overlay keeps, e.g. an
                // empty value resets to the shipped one (see resolveLocalValue()) - written the same
                // way here, so lng_data does not diverge from what is served
                $value = MigratedLanguageFileSync::resolveLocalValue($shipped_migrated['values'][$key] ?? null, (string) $value);
                $plural_identifier = MigratedLanguageFileSync::pluralMessageOf(
                    $topic,
                    $shipped_by_module[$module]
                );
                if ($plural_identifier !== null) {
                    if (PluralFormKey::parse($topic) !== null && !array_key_exists($topic, $shipped_by_module[$module])) {
                        // a form the shipped message does not have - it would only be an orphan
                        continue;
                    }
                    $save_array[$module][$topic] = $value;
                    // like for every other entry, the row is only written if something changes
                    if (($db_values[$key] ?? null) !== $value) {
                        $plural_changed[$module][$plural_identifier] = true;
                    }
                    // Remarks belong to the identifier (lng_data has no row per form): the first form
                    // whose remark differs from the stored one wins ('' removes it), see below
                    $stored_remark = (string) ($db_comments[$module . $lng->separator . $plural_identifier] ?? '');
                    if (
                        array_key_exists($key, $a_remarks)
                        && (string) $a_remarks[$key] !== $stored_remark
                        && !array_key_exists($plural_identifier, $plural_remarks[$module] ?? [])
                    ) {
                        $plural_remarks[$module][$plural_identifier] = (string) $a_remarks[$key];
                    }
                    continue;
                }
            }
            $save_array[$module][$topic] = $value;

            // A module maintained in PO files keeps its remarks in its overlay, too - see below
            $is_remark_changed = in_array($module, $shipped_migrated['modules'], true)
                && array_key_exists($key, $a_remarks)
                && (string) $a_remarks[$key] !== (string) ($db_comments[$key] ?? '');
            if ($is_remark_changed) {
                $remark_changes[$module][$topic] = (string) $a_remarks[$key];
            }

            $are_comments_set = array_key_exists($key, $global_comments) && array_key_exists($key, $a_remarks);
            $is_comment_changed = $are_comments_set ? $global_comments[$key] != $a_remarks[$key] : $are_comments_set;
            $are_changes_made = (isset($global_values[$key]) ? $global_values[$key] != $value : true) || (isset($db_values[$key]) ? $db_values[$key] != $value : true);
            if (!$are_changes_made && !$is_comment_changed && $is_remark_changed) {
                // only the remark changed: lng_data gets it as well (dual write), the value stays
                self::updateRemark($module, $topic, $a_lang_key, (string) $a_remarks[$key]);
            } elseif ($are_changes_made || $is_comment_changed) {
                $local_change = (isset($db_values[$key]) ? $db_values[$key] == $value : true) || (isset($global_values[$key]) ? $global_values[$key] != $value : true) ? $save_date : null;
                ilObjLanguage::replaceLangEntry(
                    $module,
                    $topic,
                    $a_lang_key,
                    // a migrated module's shipped value as the build serves it (database fallback),
                    // see MigratedLanguageFileSync::databaseValues()
                    in_array($module, $shipped_migrated['modules'], true)
                        ? MigratedLanguageFileSync::databaseValues([$key => $value], $shipped_migrated['values'])[$key]
                        : $value,
                    $local_change,
                    $a_remarks[$key] ?? null
                );
            }
        }

        // The row of a plural message: written if a form's value or the remark changed; without a
        // requested change the stored remark is kept
        foreach (array_keys($plural_changed + $plural_remarks) as $module) {
            $identifiers = array_keys(($plural_changed[$module] ?? []) + ($plural_remarks[$module] ?? []));
            foreach ($identifiers as $plural_identifier) {
                $remark = array_key_exists($plural_identifier, $plural_remarks[$module] ?? [])
                    ? $plural_remarks[$module][$plural_identifier]
                    : ($db_comments[$module . $lng->separator . $plural_identifier] ?? null);
                $plural_rows[$module][$plural_identifier] = $remark === '' ? null : $remark;
                if (array_key_exists($plural_identifier, $plural_remarks[$module] ?? [])) {
                    $remark_changes[$module][$plural_identifier] = $plural_remarks[$module][$plural_identifier];
                }
            }
        }

        // save the serialized module entries in lng_modules
        $modules_with_unwritten_overlay = [];
        foreach ($save_array as $module => $entries) {
            $module = (string) $module;
            $module_plural_rows = $plural_rows[$module] ?? [];
            $shipped_module_values = $shipped_by_module[$module] ?? [];
            $module_remark_changes = $remark_changes[$module] ?? [];
            // Read and written under the overlay lock, so a concurrent write in between is not lost
            $written = self::withModuleLock(
                $a_lang_key,
                $module,
                static function () use ($a_lang_key, $module, $entries, $merge_onto_current_content, $module_plural_rows, $shipped_module_values, $save_date, $module_remark_changes): bool {
                    // Without a current content (e.g. no lng_modules row of a module that is not
                    // maintained in PO files) the entries are written as they are - replaceLangModule()
                    // then creates the row from scratch
                    $current = $merge_onto_current_content ? self::currentModuleContent($a_lang_key, $module) : [];
                    $content = array_merge($current, $entries);
                    $written = ilObjLanguage::replaceLangModule($a_lang_key, $module, $content);
                    self::writePluralRows($a_lang_key, $module, $content, $shipped_module_values, $module_plural_rows, $save_date);
                    return self::setOverlayRemarks($a_lang_key, $module, $module_remark_changes) && $written;
                }
            );
            if (!$written) {
                $modules_with_unwritten_overlay[] = $module;
            }
        }

        ilCachedLanguage::getInstance($a_lang_key)->flush();

        return $modules_with_unwritten_overlay;
    }

    /**
     * $entries (module.separator.identifier => value or remark) with the plain identifier of a
     * plural message of a module maintained in PO files replaced by the key of its default form (the
     * last one, see MigratedLanguageFileSync::defaultFormKeyOf()) - the form its lng_data row stands
     * for. Where $entries has both, the later one wins. Used for every input (import file, saved
     * values, remarks), before anything is compared, kept or checked.
     *
     * @param array<string, mixed> $entries
     * @param array{values: array<string, string>, modules: list<string>} $shipped_migrated see
     *        readShippedMigratedModules()
     * @return array<string, mixed>
     */
    private static function withPluralFormKeys(array $entries, array $shipped_migrated, string $separator): array
    {
        if ($shipped_migrated['modules'] === []) {
            return $entries;
        }
        $shipped_by_module = [];
        $result = [];
        foreach ($entries as $key => $value) {
            $keys = explode($separator, (string) $key);
            if (count($keys) === 2 && in_array($keys[0], $shipped_migrated['modules'], true)) {
                $form_key = MigratedLanguageFileSync::defaultFormKeyOf(
                    $keys[1],
                    $shipped_by_module[$keys[0]] ??= self::shippedModuleValues($shipped_migrated, $keys[0], $separator)
                );
                if ($form_key !== null) {
                    $key = $keys[0] . $separator . $form_key;
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * The shipped values of $module out of readShippedMigratedModules()' values (keyed
     * module.separator.identifier), as identifier => value.
     *
     * @param array{values: array<string, string>} $shipped_migrated
     * @return array<string, string>
     */
    private static function shippedModuleValues(array $shipped_migrated, string $module, string $separator): array
    {
        $prefix = $module . $separator;
        $values = [];
        foreach ($shipped_migrated['values'] as $key => $value) {
            if (str_starts_with((string) $key, $prefix)) {
                $values[substr((string) $key, strlen($prefix))] = $value;
            }
        }
        return $values;
    }

    /**
     * Writes the remarks $changes (identifier => remark, '' removes it) into the overlay of the module
     * maintained in PO files $module (see MigratedLanguageFileSync::setRemarks()). `false` if that
     * failed (logged) - lng_data holds them regardless.
     *
     * @param array<string, string> $changes
     */
    private static function setOverlayRemarks(string $a_lang_key, string $module, array $changes): bool
    {
        global $DIC;

        if ($changes === [] || !$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return true;
        }
        try {
            MigratedLanguageFileSync::setRemarks(
                $DIC[LanguageFileDirectoryManager::class],
                $a_lang_key,
                $module,
                $changes,
                MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
                ILIAS_ABSOLUTE_PATH
            );
        } catch (\Throwable $t) {
            $DIC->logger()->forComponent('lang')->warning(sprintf(
                'Could not write the remarks of migrated module "%s", language "%s": %s',
                $module,
                $a_lang_key,
                $t->getMessage()
            ));
            return false;
        }

        return true;
    }

    /**
     * $a_remarks as they are stored - once, the same for lng_data and the overlay: cut to 250
     * characters (not bytes, which could split a character), a remark that is not valid UTF-8 is
     * dropped (logged) - it would make the overlay unreadable.
     *
     * @param array<string, mixed> $a_remarks
     * @return array<string, mixed>
     */
    private static function normalizedRemarks(array $a_remarks): array
    {
        global $DIC;

        foreach ($a_remarks as $key => $remark) {
            if (!is_string($remark)) {
                continue;
            }
            if (!mb_check_encoding($remark, 'UTF-8')) {
                $DIC->logger()->forComponent('lang')->warning(PlainLogText::of(sprintf(
                    'The remark of "%s" is not valid UTF-8 and was not saved.',
                    $key
                )));
                unset($a_remarks[$key]);
                continue;
            }
            $a_remarks[$key] = mb_substr($remark, 0, 250);
        }

        return $a_remarks;
    }

    /**
     * Whether $module is maintained in PO files for $a_lang_key (its shipped .po exists).
     */
    private static function isMaintainedInPoFiles(string $a_lang_key, string $module): bool
    {
        global $DIC;

        return $DIC->offsetExists(LanguageFileDirectoryManager::class)
            && isset(MigratedLanguageFileSync::findShippedModuleFiles(
                $DIC[LanguageFileDirectoryManager::class],
                ILIAS_ABSOLUTE_PATH,
                $a_lang_key
            )[$module]);
    }

    /**
     * Sets only the remark of an existing lng_data row ('' removes it), like replaceLangEntry() cuts
     * it to 250 characters.
     */
    private static function updateRemark(string $module, string $identifier, string $a_lang_key, string $remark): void
    {
        global $DIC;
        $ilDB = $DIC->database();

        $ilDB->manipulate(sprintf(
            "UPDATE lng_data SET remarks = %s WHERE module = %s AND identifier = %s AND lang_key = %s",
            $ilDB->quote($remark === '' ? null : mb_substr($remark, 0, 250), "text"),
            $ilDB->quote($module, "text"),
            $ilDB->quote($identifier, "text"),
            $ilDB->quote($a_lang_key, "text")
        ));
    }

    /**
     * Writes the lng_data row of every plural message in $plural_rows (identifier => remarks) of a
     * module maintained in PO files whose content is now $content (identifier => value, the forms as
     * form keys, see PluralFormKey): the database holds a plural message only under its identifier,
     * with the value of its default form (see MigratedLanguageFileSync::collapsePluralForms()), marked
     * as local change only if that value differs from the shipped default form - the row stands for
     * that value alone (a local change of another form lives in the overlay only), so a later update
     * of the legacy `.lang` files is not held back by it after a rollback.
     *
     * @param array<string, string> $content
     * @param array<string, string> $shipped_values identifier => shipped value of the module
     * @param array<string, ?string> $plural_rows
     */
    private static function writePluralRows(
        string $a_lang_key,
        string $module,
        array $content,
        array $shipped_values,
        array $plural_rows,
        string $save_date
    ): void {
        if ($plural_rows === []) {
            return;
        }
        $content = MigratedLanguageFileSync::mapLegacyPluralValues($content, $shipped_values);
        $values = MigratedLanguageFileSync::collapsePluralForms($content, $shipped_values);
        $shipped_defaults = MigratedLanguageFileSync::collapsePluralForms($shipped_values, $shipped_values);
        $database_values = MigratedLanguageFileSync::databaseValues($values, $shipped_defaults);
        foreach ($plural_rows as $identifier => $remarks) {
            if (!isset($database_values[$identifier])) {
                ilObjLanguage::deleteLangEntry($module, (string) $identifier, $a_lang_key);
                continue;
            }
            ilObjLanguage::replaceLangEntry(
                $module,
                (string) $identifier,
                $a_lang_key,
                (string) $database_values[$identifier],
                (string) $values[$identifier] !== (string) ($shipped_defaults[$identifier] ?? '') ? $save_date : null,
                $remarks
            );
        }
    }

    /**
     * The current content of $module/$lang_key a partial save/delete is applied to:
     * - for a module maintained in PO files its shipped state with the overlay delta on top - what
     *   ilLanguage serves, see MigratedLanguageFileSync::loadModuleTranslations() -, falling back to
     *   the lng_modules row if that cannot be read and to the module's lng_data rows if there is
     *   neither: applying the partial change to nothing would turn every not saved entry into
     *   "reset to shipped";
     * - for every other module the lng_modules row, `[]` if there is no (readable) row.
     *
     * Must be called under the module's overlay lock (see withModuleLock()).
     *
     * @return array<string, string> identifier => value
     */
    private static function currentModuleContent(string $a_lang_key, string $module): array
    {
        global $DIC;

        $manager = null;
        $is_migrated = false;
        if ($DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            $manager = $DIC[LanguageFileDirectoryManager::class];
            $is_migrated = isset(MigratedLanguageFileSync::findShippedModuleFiles($manager, ILIAS_ABSOLUTE_PATH, $a_lang_key)[$module]);
        }
        if ($manager !== null && $is_migrated) {
            try {
                $overlay = MigratedLanguageFileSync::loadModuleTranslations(
                    $manager,
                    $a_lang_key,
                    $module,
                    MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
                    ILIAS_ABSOLUTE_PATH
                );
            } catch (\Throwable $t) {
                $DIC->logger()->forComponent('lang')->warning(sprintf(
                    'Could not read migrated language file for module "%s", language "%s" - using lng_modules: %s',
                    $module,
                    $a_lang_key,
                    $t->getMessage()
                ));
                $overlay = null;
            }
            if ($overlay !== null) {
                return array_map(static fn(array $entry): string => $entry['value'], $overlay);
            }
        }

        $ilDB = $DIC->database();
        $set = $ilDB->query(sprintf(
            "SELECT lang_array FROM lng_modules WHERE lang_key = %s AND module = %s",
            $ilDB->quote($a_lang_key, "text"),
            $ilDB->quote($module, "text")
        ));
        $row = $ilDB->fetchAssoc($set);
        $content = is_array($row) && isset($row["lang_array"])
            ? unserialize((string) $row["lang_array"], ["allowed_classes" => false])
            : null;
        if (is_array($content) || !$is_migrated) {
            return is_array($content) ? $content : [];
        }

        // A module maintained in PO files whose state cannot be read and without lng_modules row:
        // rebuilt from lng_data (always dual-written), so the sync gets the module's entries and not
        // just the ones saved now
        $content = [];
        $set = $ilDB->query(sprintf(
            "SELECT identifier, value FROM lng_data WHERE lang_key = %s AND module = %s",
            $ilDB->quote($a_lang_key, "text"),
            $ilDB->quote($module, "text")
        ));
        while ($row = $ilDB->fetchAssoc($set)) {
            $content[(string) $row["identifier"]] = (string) $row["value"];
        }

        return $content;
    }

    /**
     * Runs $callback under the overlay lock of $module/$lang_key, see
     * MigratedLanguageFileSync::withOverlayLock().
     *
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    private static function withModuleLock(string $a_lang_key, string $module, \Closure $callback): mixed
    {
        global $DIC;

        return MigratedLanguageFileSync::withOverlayLock(
            $DIC->offsetExists(LanguageFileDirectoryManager::class) ? $DIC[LanguageFileDirectoryManager::class] : null,
            $a_lang_key,
            $module,
            MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
            $callback,
            ILIAS_ABSOLUTE_PATH
        );
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
        $lng = $DIC->language();

        $delete_array = array();
        $modules_with_unwritten_overlay = [];
        $shipped_migrated = null;
        $shipped_by_module = [];
        $plural_rows = [];
        $db_comments = null;
        $remark_changes = [];

        // save the single translations in lng_data
        foreach ($a_values as $key => $value) {
            $keys = explode($lng->separator, $key);
            if (count($keys) === 2) {
                $module = $keys[0];
                $topic = $keys[1];
                $delete_array[$module][$topic] = $value;

                // A form of a plural message of a module maintained in PO files has no row of its
                // own - the row of the message is rewritten once the module is saved, see below
                if (PluralFormKey::parse($topic) !== null) {
                    $shipped_migrated ??= self::readShippedMigratedModules($a_lang_key);
                    $shipped_by_module[$module] ??= self::shippedModuleValues($shipped_migrated, $module, $lng->separator);
                }
                $plural_identifier = isset($shipped_by_module[$module])
                    ? MigratedLanguageFileSync::pluralMessageOf($topic, $shipped_by_module[$module])
                    : null;
                if ($plural_identifier !== null) {
                    // the remark of the identifier is kept
                    $db_comments ??= self::_getRemarks($a_lang_key);
                    $plural_rows[$module][$plural_identifier] = $db_comments[$module . $lng->separator . $plural_identifier] ?? null;
                    continue;
                }
                ilObjLanguage::deleteLangEntry($module, $topic, $a_lang_key);
                // the remark goes with the row - for a module maintained in PO files also from its
                // overlay
                if (self::isMaintainedInPoFiles($a_lang_key, $module)) {
                    $remark_changes[$module][$topic] = '';
                }
            }
        }

        // save the serialized module entries in lng_modules
        $save_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        foreach ($delete_array as $module => $entries) {
            $module = (string) $module;
            $module_plural_rows = $plural_rows[$module] ?? [];
            $shipped_module_values = $shipped_by_module[$module] ?? [];
            $module_remark_changes = $remark_changes[$module] ?? [];
            $written = self::withModuleLock(
                $a_lang_key,
                $module,
                static function () use ($a_lang_key, $module, $entries, $module_plural_rows, $shipped_module_values, $save_date, $module_remark_changes): bool {
                    // Without a current content there is nothing left to keep - the entries to
                    // delete must not be written back as the module's content instead
                    $current = self::currentModuleContent($a_lang_key, $module);
                    $content = array_diff_key($current, $entries);
                    $written = ilObjLanguage::replaceLangModule($a_lang_key, $module, $content);
                    self::writePluralRows($a_lang_key, $module, $content, $shipped_module_values, $module_plural_rows, $save_date);
                    return self::setOverlayRemarks($a_lang_key, $module, $module_remark_changes) && $written;
                }
            );
            if (!$written) {
                $modules_with_unwritten_overlay[] = $module;
            }
        }

        return $modules_with_unwritten_overlay;
    }
} // END class.ilObjLanguageExt

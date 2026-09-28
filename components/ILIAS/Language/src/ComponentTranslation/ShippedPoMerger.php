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

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use RuntimeException;

/**
 * The "merge" maintenance action of the developer mode (LANGMODE) for the modules maintained in PO
 * files - the counterpart of merging the local changes into `lang/ilias_<lang>.lang`: the local
 * changes of a module (its overlay, see MigratedLanguageFileSync) are written into its shipped `.po`,
 * the only time a shipped file is written at runtime. Plus the backups of the shipped `.po` files
 * the filter "conflicts" of the admin GUI compares with (see findShippedChangesSinceBackup()).
 *
 * Per module with overlay (in the order of the module names, each under its overlay lock):
 * - a value (every form of a plural message) becomes the msgstr of its entry, the "fuzzy" flag goes;
 *   a singular value of a plural message is its default form (PluralForms). An entry the shipped
 *   `.po` does not have (added locally) is inserted in id order - into the template (`.pot`) as
 *   well, with an empty msgstr (a plural message with the msgid_plural of the template or
 *   "<identifier>_plural"); the `.po` of the other languages stay unchanged;
 * - a remark (LocalChangeComments::setRemark()) becomes an extracted comment (`#.`) of its entry,
 *   added after the existing ones (an identical one is not added twice) - also for an entry that only
 *   carries a remark. "original"/"local_change" never go into a shipped file;
 * - a value with markup TranslationMarkupPolicy does not allow, a plural message that could not be
 *   compiled (an empty msgstr[0] next to other forms, or a plural message in the overlay of an entry
 *   shipped as singular one) and a new identifier in the form "<identifier> [<n>]" (it would be read
 *   as a plural form, see PluralFormKey) are not taken over and stay in the overlay (reported);
 * - before writing, the shipped `.po` is copied into the backup directory (named like the shipped
 *   file), then the template and the `.po` are written atomically (AtomicFileWriter, confined to the
 *   ILIAS directory: a symbolic link out of it is refused). The template is written under a lock of
 *   its own (MigratedLanguageFileSync::withTemplateLock(), taken after the overlay lock), since a
 *   merge of another language may write it at the same time. A module whose shipped `.po`, its
 *   directory, the backup directory or - for a new entry - the template is not writable is skipped
 *   as a whole (reported). The written files belong to the web server user afterwards (a rename of a
 *   new file - owner and group of the previous one are not taken over);
 * - the build artifact of the written `.po` is dated back (or removed), so it is never served in
 *   place of the newer `.po`, see invalidateArtifact();
 * - afterwards the overlay is reconciled with the new shipped state (MigratedLanguageFileSync::sync()
 *   with $refresh_original_from_shipped): the taken over entries and remarks leave it, an empty
 *   overlay is removed.
 * A `setup build` is not needed for the result to be served, but recommended: until then the
 * runtime compiles the `.po` itself on every request (ShippedTranslations::readCompiled()).
 *
 * lng_data/lng_modules are not written here (the database belongs to the legacy classes): the
 * caller gets the taken over identifiers per module, see merge().
 */
final class ShippedPoMerger
{
    /**
     * @param \Closure(string $module, list<string> $merged_identifiers, list<string> $merged_remark_identifiers): void|null $after_module
     *        called for every module whose shipped `.po` was written, still under its overlay lock -
     *        with the identifiers whose value (a plural message under its identifier) and whose remark
     *        were taken over, so the caller can bring the database in line
     * @return array{
     *     written: array<string, string>,
     *     skipped: array<string, string>,
     *     invalid_markup: array<string, array<string, list<string>>>,
     *     not_merged: array<string, list<string>>,
     *     unwritten_overlay: list<string>,
     *     unwritten_database: array<string, string>,
     *     stale_artifacts: list<string>
     * } written: module => path of the written shipped `.po`; skipped: module => reason; invalid_markup:
     *   module => identifier (a form key for a plural form) => violations; not_merged: module =>
     *   identifiers that could not be taken over (see the class docblock); unwritten_overlay: modules whose
     *   shipped `.po` was written but whose overlay could not be reconciled; unwritten_database: module
     *   => reason, $after_module failed (the files are written); stale_artifacts: modules whose build
     *   artifact could neither be dated back nor removed (see invalidateArtifact())
     */
    public static function merge(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        ?string $client_data_dir,
        string $backup_directory,
        ?\Closure $after_module = null
    ): array {
        $result = [
            'written' => [],
            'skipped' => [],
            'invalid_markup' => [],
            'not_merged' => [],
            'unwritten_overlay' => [],
            'unwritten_database' => [],
            'stale_artifacts' => [],
        ];
        if ($client_data_dir === null) {
            return $result;
        }

        $shipped_files = MigratedLanguageFileSync::findShippedModuleFiles($language_file_directory_manager, $ilias_absolute_path, $lang_key);
        $backup_names = self::backupFileNames($shipped_files);
        // the fixed lock order, see MigratedLanguageFileSync::withOverlayLock()
        ksort($shipped_files, SORT_STRING);
        foreach ($shipped_files as $module => $shipped_po) {
            $module = (string) $module;
            if (!MigratedLanguageFileSync::hasOverlay($language_file_directory_manager, $lang_key, $module, $client_data_dir)) {
                continue;
            }
            if ($backup_names[$module] === null) {
                $result['skipped'][$module] = sprintf('The shipped file name "%s" is not unique among the modules, it cannot be backed up.', basename($shipped_po));
                continue;
            }
            $outcome = MigratedLanguageFileSync::withOverlayLock(
                $language_file_directory_manager,
                $lang_key,
                $module,
                $client_data_dir,
                static function () use ($language_file_directory_manager, $ilias_absolute_path, $lang_key, $module, $shipped_po, $client_data_dir, $backup_directory, $backup_names, $after_module): array {
                    try {
                        // the template is shared by all languages: its own lock, after the overlay lock
                        $outcome = MigratedLanguageFileSync::withTemplateLock(
                            $language_file_directory_manager,
                            $module,
                            $client_data_dir,
                            static fn(): array => self::mergeModule(
                                $language_file_directory_manager,
                                $ilias_absolute_path,
                                $lang_key,
                                $module,
                                $shipped_po,
                                $client_data_dir,
                                rtrim($backup_directory, '/') . '/' . $backup_names[$module]
                            )
                        );
                    } catch (RuntimeException|\InvalidArgumentException $e) {
                        return ['skipped' => $e->getMessage()];
                    }
                    if ($outcome['written'] && $after_module !== null) {
                        // the files are written - a failing database write must not stop the others
                        try {
                            $after_module($module, $outcome['merged'], $outcome['merged_remarks']);
                        } catch (\Throwable $t) {
                            $outcome['database_error'] = $t->getMessage();
                        }
                    }
                    return $outcome;
                },
                $ilias_absolute_path
            );

            if (isset($outcome['skipped'])) {
                $result['skipped'][$module] = $outcome['skipped'];
                continue;
            }
            if ($outcome['invalid_markup'] !== []) {
                $result['invalid_markup'][$module] = $outcome['invalid_markup'];
            }
            if ($outcome['not_merged'] !== []) {
                $result['not_merged'][$module] = $outcome['not_merged'];
            }
            if ($outcome['written']) {
                $result['written'][$module] = $shipped_po;
            }
            if (!$outcome['overlay_written']) {
                $result['unwritten_overlay'][] = $module;
            }
            if (isset($outcome['database_error'])) {
                $result['unwritten_database'][$module] = $outcome['database_error'];
            }
            if ($outcome['stale_artifact']) {
                $result['stale_artifacts'][] = $module;
            }
        }

        return $result;
    }

    /**
     * Copies the shipped `.po` of every module migrated for $lang_key into $directory, named like the
     * shipped file - the counterpart of the backup of `lang/ilias_<lang>.lang` the "save_dist"
     * maintenance action writes, for findShippedChangesSinceBackup().
     *
     * @param string $confine_to_directory $directory must resolve to it or below (no symbolic link
     *        out of it, see AtomicFileWriter), e.g. the client data directory
     * @return list<string> the modules whose `.po` could not be copied (also a shipped file name
     *         that is not unique among the modules)
     */
    public static function backupShippedPoFiles(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $directory,
        string $confine_to_directory
    ): array {
        $shipped_files = MigratedLanguageFileSync::findShippedModuleFiles($language_file_directory_manager, $ilias_absolute_path, $lang_key);
        $failed = [];
        foreach (self::backupFileNames($shipped_files) as $module => $name) {
            try {
                if ($name === null) {
                    throw new RuntimeException('file name not unique');
                }
                self::backup($shipped_files[$module], rtrim($directory, '/') . '/' . $name, $confine_to_directory);
            } catch (RuntimeException) {
                $failed[] = (string) $module;
            }
        }

        return $failed;
    }

    /**
     * What the shipped `.po` of every module migrated for $lang_key changed since its backup in
     * $directory (see backupShippedPoFiles()) - the counterpart of comparing `lang/ilias_<lang>.lang`
     * with its backup: every entry (a plural message as its forms, see PluralFormKey) whose shipped
     * value is new or differs from the one in the backup. A module without readable backup (or whose
     * `.po` cannot be read) is listed in `without_backup` instead.
     *
     * @return array{changes: array<string, array<string, string>>, without_backup: list<string>}
     *         changes: module => identifier => current shipped value
     */
    public static function findShippedChangesSinceBackup(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $directory
    ): array {
        $shipped_files = MigratedLanguageFileSync::findShippedModuleFiles($language_file_directory_manager, $ilias_absolute_path, $lang_key);
        $result = ['changes' => [], 'without_backup' => []];
        foreach (self::backupFileNames($shipped_files) as $module => $name) {
            $module = (string) $module;
            $backup = $name === null ? null : rtrim($directory, '/') . '/' . $name;
            try {
                if ($backup === null || !is_file($backup) || !is_readable($backup)) {
                    throw new RuntimeException('no backup');
                }
                $former = self::valuesOf(MigratedLanguageFileSync::loadShippedModuleEntries($backup, $module));
                $current = self::valuesOf(MigratedLanguageFileSync::loadShippedModuleEntries($shipped_files[$module], $module));
            } catch (RuntimeException) {
                $result['without_backup'][] = $module;
                continue;
            }
            $changes = array_diff_assoc($current, $former);
            if ($changes !== []) {
                $result['changes'][$module] = $changes;
            }
        }

        return $result;
    }

    /**
     * merge() for one module, under its overlay lock.
     *
     * Runs under the overlay lock of the module/language and the template lock of the module.
     *
     * @return array{written: bool, overlay_written: bool, stale_artifact: bool, merged: list<string>, merged_remarks: list<string>, invalid_markup: array<string, list<string>>, not_merged: list<string>}
     * @throws RuntimeException|\InvalidArgumentException if the module has to be skipped as a whole
     */
    private static function mergeModule(
        LanguageFileDirectoryManager $language_file_directory_manager,
        string $ilias_absolute_path,
        string $lang_key,
        string $module,
        string $shipped_po,
        string $client_data_dir,
        string $backup_file
    ): array {
        $outcome = [
            'written' => false,
            'overlay_written' => true,
            'stale_artifact' => false,
            'merged' => [],
            'merged_remarks' => [],
            'invalid_markup' => [],
            'not_merged' => [],
        ];
        // read under the lock - it may have changed since hasOverlay()
        $overlay = MigratedLanguageFileSync::readOverlayCatalog($language_file_directory_manager, $lang_key, $module, $client_data_dir, $ilias_absolute_path);
        $directory = MigratedLanguageFileSync::findDirectory($language_file_directory_manager, $module);
        if ($overlay === null || $directory === null) {
            return $outcome;
        }
        $shipped = TranslationCatalog::fromPoFile($shipped_po);
        $overlay_entries = MigratedLanguageFileSync::moduleEntries($overlay, $module);

        // Everything that has to be writable, checked before anything is written
        self::assertWritableFile($shipped_po, $ilias_absolute_path);
        self::assertWritableFile($backup_file, $ilias_absolute_path);
        $template_file = MigratedLanguageFilePaths::shippedTemplatePath($ilias_absolute_path, $directory);
        $template = null;
        foreach ($overlay_entries as $identifier => $entry) {
            if (
                !MigratedLanguageFileSync::isRemarkOnly($entry)
                && PluralFormKey::parse((string) $identifier) === null
                && MigratedLanguageFileSync::findModuleEntry($shipped, $module, (string) $identifier) === null
            ) {
                if (!is_file($template_file)) {
                    throw new RuntimeException(sprintf('The template "%s" for the entries added locally is missing.', $template_file));
                }
                self::assertWritableFile($template_file, $ilias_absolute_path);
                $template = TranslationCatalog::fromPoFile($template_file);
                break;
            }
        }

        $plural_forms = MigratedLanguageFileSync::pluralFormsOf($shipped);
        $policy = new TranslationMarkupPolicy();
        $remaining_remarks = [];
        $template_changed = false;
        foreach ($overlay_entries as $identifier => $overlay_entry) {
            $identifier = (string) $identifier;
            $shipped_entry = MigratedLanguageFileSync::findModuleEntry($shipped, $module, $identifier);
            $remark = LocalChangeComments::getRemark($overlay_entry);
            $has_value = !MigratedLanguageFileSync::isRemarkOnly($overlay_entry);

            if ($has_value) {
                // A new identifier that looks like the form of a plural message (see PluralFormKey)
                // would be read as one - it is not added to a shipped file
                $message = $shipped_entry === null && PluralFormKey::parse($identifier) !== null
                    ? null
                    : self::mergedMessage($overlay_entry, $shipped_entry, $template, $module, $plural_forms);
                if ($message === null) {
                    $outcome['not_merged'][] = $identifier;
                    self::keepRemark($remaining_remarks, $identifier, $remark);
                    continue;
                }
                // only a value the shipped .po does not carry yet is checked, see findInvalidChangedValues()
                $shipped_values = $shipped_entry === null ? [] : MigratedLanguageFileSync::flatValuesOf($shipped_entry, $plural_forms);
                $invalid = $policy->findInvalidChangedValues(self::flatValues($identifier, $message), $shipped_values, $shipped_values);
                if ($invalid !== []) {
                    $outcome['invalid_markup'] += $invalid;
                    self::keepRemark($remaining_remarks, $identifier, $remark);
                    continue;
                }

                $target = $shipped_entry ?? new TranslationEntry(null, $identifier);
                if ($message['plural_id'] === null) {
                    $target->translate($message['forms'][0]);
                } else {
                    $target->setPlural($message['plural_id'], $message['forms']);
                }
                $target->removeFlag('fuzzy');
                if ($shipped_entry === null) {
                    $shipped->addInIdOrder($target);
                    $template_changed = self::addToTemplate($template, $module, $identifier, $message['plural_id']) || $template_changed;
                }
                $outcome['merged'][] = $identifier;
                $shipped_entry = $target;
            }

            if ($remark !== null && $remark !== '') {
                if ($shipped_entry === null) {
                    // the remark of an entry without value that is not shipped - nothing to put it on
                    self::keepRemark($remaining_remarks, $identifier, $remark);
                    continue;
                }
                $shipped_entry->addExtractedComment($remark);
                $outcome['merged_remarks'][] = $identifier;
            }
        }

        if ($outcome['merged'] === [] && $outcome['merged_remarks'] === []) {
            return $outcome;
        }

        $po_content = self::checkedPoContent($shipped, true);
        $template_content = $template_changed && $template !== null ? self::checkedPoContent($template, false) : null;
        $previous_content = @file_get_contents($shipped_po);
        if ($previous_content === false) {
            throw new RuntimeException(sprintf('Could not read "%s".', $shipped_po));
        }
        // Confined to the ILIAS directory: a shipped directory that is a symbolic link out of it is
        // refused (AtomicFileWriter), nothing is written
        self::backup($shipped_po, $backup_file, $ilias_absolute_path, $previous_content);
        if ($template_content !== null) {
            // the template first: an additional entry there is harmless should the .po fail
            AtomicFileWriter::write($template_file, $template_content, $ilias_absolute_path);
        }
        AtomicFileWriter::write($shipped_po, $po_content, $ilias_absolute_path);
        $outcome['written'] = true;
        $outcome['stale_artifact'] = !self::invalidateArtifact($ilias_absolute_path, $directory, $lang_key, $shipped_po);
        \ilLanguage::invalidateMigratedLanguageFileCache($module, $lang_key);

        // The overlay: the entries taken over equal the shipped state now and leave the delta, like
        // the remarks taken over - "original" moves to the new shipped state
        try {
            $content = MigratedLanguageFileSync::loadModuleTranslations($language_file_directory_manager, $lang_key, $module, $client_data_dir, $ilias_absolute_path) ?? [];
            MigratedLanguageFileSync::sync(
                $language_file_directory_manager,
                $ilias_absolute_path,
                $lang_key,
                $module,
                array_map(static fn(array $entry): string => $entry['value'], $content),
                $client_data_dir,
                true,
                null,
                $remaining_remarks
            );
        } catch (RuntimeException|\InvalidArgumentException) {
            $outcome['overlay_written'] = false;
        }

        return $outcome;
    }

    /**
     * The message $overlay_entry makes of its shipped entry: a singular value, or every form of a
     * plural message (a singular value of a shipped plural message is its default form, the others
     * keep their shipped value). `null` if that cannot be written as a compilable plural message: a
     * plural message in the overlay for a singular shipped entry, or an empty msgstr[0] next to other
     * forms (see TranslationCatalog::toMoString()).
     *
     * @return array{plural_id: ?string, forms: list<string>}|null
     */
    private static function mergedMessage(
        TranslationEntry $overlay_entry,
        ?TranslationEntry $shipped_entry,
        ?TranslationCatalog $template,
        string $module,
        PluralForms $plural_forms
    ): ?array {
        if (!$overlay_entry->isPlural() && !$shipped_entry?->isPlural()) {
            return ['plural_id' => null, 'forms' => [$overlay_entry->getTranslation()]];
        }
        if ($overlay_entry->isPlural() && $shipped_entry !== null && !$shipped_entry->isPlural()) {
            return null;
        }

        if ($overlay_entry->isPlural()) {
            $forms = array_values(MigratedLanguageFileSync::flatValuesOf($overlay_entry, $plural_forms));
        } else {
            $forms = array_values(MigratedLanguageFileSync::flatValuesOf($shipped_entry, $plural_forms));
            $forms[$plural_forms->defaultFormIndex()] = $overlay_entry->getTranslation();
        }
        if ($forms[0] === '' && implode('', $forms) !== '') {
            return null;
        }
        $template_entry = $template === null ? null : MigratedLanguageFileSync::findModuleEntry($template, $module, $overlay_entry->getId());

        return [
            'plural_id' => $shipped_entry?->getPluralId()
                ?? $template_entry?->getPluralId()
                ?? $overlay_entry->getId() . '_plural',
            'forms' => $forms,
        ];
    }

    /**
     * Adds the entry $identifier (added locally) to the template - no msgstr (a plural message with
     * two empty forms, like the conversion tool writes it) -, unless it has it already.
     *
     * @return bool whether the template changed
     */
    private static function addToTemplate(?TranslationCatalog $template, string $module, string $identifier, ?string $plural_id): bool
    {
        if ($template === null || MigratedLanguageFileSync::findModuleEntry($template, $module, $identifier) !== null) {
            return false;
        }
        $entry = new TranslationEntry(null, $identifier);
        if ($plural_id !== null) {
            $entry->setPlural($plural_id, ['', '']);
        }
        $template->addInIdOrder($entry);

        return true;
    }

    /**
     * @param array{plural_id: ?string, forms: list<string>} $message
     * @return array<string, string> identifier => value, a plural message as its form keys
     */
    private static function flatValues(string $identifier, array $message): array
    {
        if ($message['plural_id'] === null) {
            return [$identifier => $message['forms'][0]];
        }
        $values = [];
        foreach ($message['forms'] as $form => $value) {
            $values[PluralFormKey::of($identifier, $form)] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, string> $remarks
     */
    private static function keepRemark(array &$remarks, string $identifier, ?string $remark): void
    {
        if ($remark !== null && $remark !== '') {
            $remarks[$identifier] = $remark;
        }
    }

    /**
     * $catalog as `.po` content, after making sure it can be read back and - for a `.po` of a
     * language - compiled like the build does.
     *
     * @throws RuntimeException otherwise
     */
    private static function checkedPoContent(TranslationCatalog $catalog, bool $compile): string
    {
        $content = $catalog->toPoString();
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('The merged file would not be valid UTF-8.');
        }
        TranslationCatalog::fromPoString($content);
        if ($compile) {
            $catalog->toMoString();
        }

        return $content;
    }

    /**
     * Makes sure the build artifact of the written $shipped_po is never served any more: the runtime
     * serves an artifact that is not older than its `.po` (ShippedTranslations::readCompiled(), mtime
     * in seconds), so one built in the same second as this write would win. The artifact is dated
     * back before the `.po` - the runtime then compiles the `.po` itself, silently, until the next
     * build -, or removed if that is not possible (the runtime logs a notice per request then).
     *
     * @return bool `false` if neither worked (e.g. the artifact belongs to another user)
     */
    private static function invalidateArtifact(string $ilias_absolute_path, LanguageFileDirectory $directory, string $lang_key, string $shipped_po): bool
    {
        try {
            $artifact = MigratedLanguageFilePaths::shippedArtifactFile($ilias_absolute_path, $directory, $lang_key);
        } catch (\InvalidArgumentException) {
            return true;
        }
        clearstatcache(true, $artifact);
        if (!is_file($artifact) || is_link($artifact)) {
            return true;
        }
        clearstatcache(true, $shipped_po);
        $po_modified = @filemtime($shipped_po);
        if ($po_modified !== false && @touch($artifact, $po_modified - 1)) {
            return true;
        }

        return @unlink($artifact);
    }

    /**
     * Copies $shipped_po (with $content: that content) to $backup_file, atomically, confined to
     * $confine_to_directory (see AtomicFileWriter).
     *
     * @throws RuntimeException
     */
    private static function backup(string $shipped_po, string $backup_file, string $confine_to_directory, ?string $content = null): void
    {
        $content ??= @file_get_contents($shipped_po);
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read "%s".', $shipped_po));
        }
        if (!is_dir(dirname($backup_file))) {
            throw new RuntimeException(sprintf('The backup directory "%s" does not exist.', dirname($backup_file)));
        }
        AtomicFileWriter::write($backup_file, $content, $confine_to_directory);
    }

    /**
     * @throws RuntimeException unless $file can be replaced atomically: its directory resolves to
     *         $confine_to_directory or below (checked again by AtomicFileWriter), is writable, and so
     *         is the file itself if it exists (a web server may not write the shipped files at all) -
     *         checked for every file before anything is written
     */
    private static function assertWritableFile(string $file, string $confine_to_directory): void
    {
        $root = realpath($confine_to_directory);
        $directory = realpath(dirname($file));
        if (
            $root === false
            || $directory === false
            || ($directory !== $root && !str_starts_with($directory, rtrim($root, '/') . '/'))
            || !is_dir(dirname($file))
            || !is_writable(dirname($file))
            || is_link($file)
            || (file_exists($file) && (!is_file($file) || !is_writable($file)))
        ) {
            throw new RuntimeException(sprintf('"%s" is not writable or does not resolve to a path below "%s".', $file, $confine_to_directory));
        }
    }

    /**
     * The name of the backup of each shipped `.po` of $shipped_files (module => path): the shipped
     * file name - `null` where two modules have the same one.
     *
     * @param array<string, string> $shipped_files
     * @return array<string, ?string>
     */
    private static function backupFileNames(array $shipped_files): array
    {
        $counts = array_count_values(array_map('basename', $shipped_files));
        $names = [];
        foreach ($shipped_files as $module => $file) {
            $names[(string) $module] = $counts[basename($file)] === 1 ? basename($file) : null;
        }

        return $names;
    }

    /**
     * @param array<string, array{value: string, comment: ?string}> $entries
     * @return array<string, string>
     */
    private static function valuesOf(array $entries): array
    {
        return array_map(static fn(array $entry): string => $entry['value'], $entries);
    }
}

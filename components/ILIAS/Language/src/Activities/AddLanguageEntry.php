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

namespace ILIAS\Language\Activities;

use ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy;
use ILIAS\Data\Description;
use ILIAS\Data\Text;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use ILIAS\Language\ComponentTranslation\PluralFormKey;
use ILIAS\Language\ComponentTranslation\PluralForms;
use ILIAS\Language\Language;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Component\Input\Field\Factory as FieldFactory;

class AddLanguageEntry extends LanguageActivity
{
    private readonly \Closure $replace_lang_entry;
    private readonly \Closure $update_module_cache;
    private readonly \Closure $user_login;

    /**
     * @param \Closure|null $replace_lang_entry (string $module, string $identifier, string $lang_key,
     *        string $value, string $local_change, string $remarks): bool
     * @param \Closure|null $update_module_cache (string $lang_key, string $module, string $identifier,
     *        string $value, string $remarks = ''): ?bool - `false` if the entry reached the database,
     *        but the PO/MO overlay of the module (if it is maintained in PO files) could not be
     *        written; `true`/`null` otherwise. $remarks (the author's login, like in lng_data) is
     *        kept in the overlay as the entry's remark.
     * @param \Closure|null $user_login (int $usr_id): string
     * @param \ilDBInterface|\Closure $db (): \ilDBInterface
     */
    public function __construct(
        RefineryFactory $refinery,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        private readonly InstalledLanguageRepository $installed_language_repository,
        \ilDBInterface|\Closure $db,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $replace_lang_entry = null,
        ?\Closure $update_module_cache = null,
        ?\Closure $user_login = null,
    ) {
        parent::__construct($refinery, $language, $rbac_system, $language_folder_ref_id);
        $this->replace_lang_entry = $replace_lang_entry
            ?? static fn(
                string $module,
                string $identifier,
                string $lang_key,
                string $value,
                string $local_change,
                string $remarks
            ): bool => \ilObjLanguage::replaceLangEntry($module, $identifier, $lang_key, $value, $local_change, $remarks);
        $db_resolver = $db instanceof \Closure ? $db : static fn(): \ilDBInterface => $db;
        $this->update_module_cache = $update_module_cache
            ?? static function (
                string $lang_key,
                string $module,
                string $identifier,
                string $value,
                string $remarks = ''
            ) use ($db_resolver): bool {
                global $DIC;

                $write = static function () use ($db_resolver, $lang_key, $module, $identifier, $value, $remarks): bool {
                    global $DIC;

                    $entries = self::currentModuleEntries($db_resolver(), $lang_key, $module);
                    if ($entries === null) {
                        // as before: no lng_modules row of a module not maintained in PO files -
                        // nothing to update (lng_data holds the entry)
                        return true;
                    }
                    $entries[self::entryKeyOf($entries, $identifier)] = $value;
                    $written = \ilObjLanguage::replaceLangModule($lang_key, $module, $entries);
                    if ($remarks === '' || !isset($DIC) || !$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
                        return $written;
                    }
                    // the remark lng_data got (see replace_lang_entry) - in the overlay as well
                    try {
                        MigratedLanguageFileSync::setRemarks(
                            $DIC[LanguageFileDirectoryManager::class],
                            $lang_key,
                            $module,
                            [$identifier => $remarks],
                            MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
                            ILIAS_ABSOLUTE_PATH
                        );
                    } catch (\Throwable) {
                        return false;
                    }
                    return $written;
                };
                $manager = isset($DIC) && $DIC->offsetExists(LanguageFileDirectoryManager::class)
                    ? $DIC[LanguageFileDirectoryManager::class]
                    : null;

                // read and written under the overlay lock, so a concurrent write is not lost
                return MigratedLanguageFileSync::withOverlayLock(
                    $manager,
                    $lang_key,
                    $module,
                    MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
                    $write,
                    ILIAS_ABSOLUTE_PATH
                );
            };
        $this->user_login = $user_login
            ?? static fn(int $usr_id): string => \ilObjUser::_lookupLogin($usr_id);
    }

    /**
     * The key $identifier takes in $entries (a module's content, see currentModuleEntries()): for a
     * plural message of a module maintained in PO files (listed as its forms, see PluralFormKey) the
     * key of its default form - the one the database row of the identifier stands for (see
     * PluralForms) -, otherwise $identifier itself.
     *
     * @param array<string|int, string> $entries
     */
    private static function entryKeyOf(array $entries, string $identifier): string
    {
        if (MigratedLanguageFileSync::pluralMessageOf($identifier, $entries) !== $identifier) {
            return $identifier;
        }
        $count = 0;
        while (array_key_exists(PluralFormKey::of($identifier, $count), $entries)) {
            $count++;
        }

        return PluralFormKey::of($identifier, PluralForms::defaultFormIndexForCount($count));
    }

    /**
     * The languages of $lang_keys for which $identifier is a form key (see PluralFormKey) of a plural
     * message of $module's shipped `.po` that this message does not have (e.g. `poll_population [2]`
     * in a language with two forms). Empty for every other identifier, and where the shipped `.po`
     * cannot be read (the entry is then written as before).
     *
     * @param list<string|int> $lang_keys
     * @return list<string>
     */
    private static function languagesWithoutPluralForm(string $module, string $identifier, array $lang_keys): array
    {
        global $DIC;

        if (PluralFormKey::parse($identifier) === null || !isset($DIC) || !$DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            return [];
        }
        $languages = [];
        foreach ($lang_keys as $lang_key) {
            $lang_key = (string) $lang_key;
            $shipped_po = MigratedLanguageFileSync::findShippedModuleFiles(
                $DIC[LanguageFileDirectoryManager::class],
                ILIAS_ABSOLUTE_PATH,
                $lang_key
            )[$module] ?? null;
            if ($shipped_po === null) {
                continue;
            }
            try {
                $shipped = MigratedLanguageFileSync::loadShippedModuleEntries($shipped_po, $module);
            } catch (\Throwable) {
                continue;
            }
            if (MigratedLanguageFileSync::pluralMessageOf($identifier, $shipped) !== null && !array_key_exists($identifier, $shipped)) {
                $languages[] = $lang_key;
            }
        }

        return $languages;
    }

    /**
     * The content of $module/$lang_key the new entry is added to: for a module maintained in PO files
     * its shipped state with the overlay delta on top (what ilLanguage serves, see
     * MigratedLanguageFileSync::loadModuleTranslations()), otherwise - or if that cannot be read -
     * the lng_modules row. Without a readable row, only the module array of a module maintained in
     * PO files is rebuilt from lng_data (which already holds the new entry), so the entry never
     * stays invisible behind its overlay; for any other module `null` is
     * returned and nothing is written, as before - the module name is free input, and no
     * lng_modules row must be created for an arbitrary one.
     *
     * @return array<string, string>|null identifier => value
     */
    private static function currentModuleEntries(\ilDBInterface $db, string $lang_key, string $module): ?array
    {
        global $DIC;

        $is_migrated = false;
        if (isset($DIC) && $DIC->offsetExists(LanguageFileDirectoryManager::class)) {
            $is_migrated = isset(MigratedLanguageFileSync::findShippedModuleFiles(
                $DIC[LanguageFileDirectoryManager::class],
                ILIAS_ABSOLUTE_PATH,
                $lang_key
            )[$module]);
        }
        if ($is_migrated) {
            try {
                $overlay = MigratedLanguageFileSync::loadModuleTranslations(
                    $DIC[LanguageFileDirectoryManager::class],
                    $lang_key,
                    $module,
                    MigratedLanguageFilePaths::resolveClientDataDir(ILIAS_ABSOLUTE_PATH),
                    ILIAS_ABSOLUTE_PATH
                );
            } catch (\Throwable) {
                // an unreadable overlay is rebuilt by the sync - the database content is the base then
                $overlay = null;
            }
            if ($overlay !== null) {
                return array_map(static fn(array $entry): string => $entry['value'], $overlay);
            }
        }

        $set = $db->query(
            'SELECT lang_array FROM lng_modules WHERE lang_key = '
            . $db->quote($lang_key, 'text') . ' AND module = ' . $db->quote($module, 'text')
        );
        $row = $db->fetchAssoc($set);
        $entries = is_string($row['lang_array'] ?? null)
            ? unserialize($row['lang_array'], ['allowed_classes' => false])
            : null;
        if (is_array($entries)) {
            return $entries;
        }
        if (!$is_migrated) {
            return null;
        }

        $entries = [];
        $set = $db->query(
            'SELECT identifier, value FROM lng_data WHERE lang_key = '
            . $db->quote($lang_key, 'text') . ' AND module = ' . $db->quote($module, 'text')
        );
        while ($row = $db->fetchAssoc($set)) {
            $entries[(string) $row['identifier']] = (string) $row['value'];
        }

        return $entries;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Adds one new "adjust language variables" entry (a module/identifier pair) to
every currently installed language for which a value was given. An installed
language for which no value (or only a blank one) was given is left
completely untouched.

"de" and "en" are mandatory whenever they are installed: a missing or blank
value for either rejects the request as a whole - nothing is written for any
language, not even ones with a perfectly valid value given.
MARKDOWN
        );
    }

    /**
     * Must keep exactly `Activity::getInputDescription(FieldFactory $f): FormInput`'s signature.
     * An earlier version added an optional `?array $installed_language_keys` parameter here to
     * avoid a second repository round-trip; while PHP itself permits an optional extra parameter,
     * a *subclass* overriding the method with the plain interface signature then has FEWER
     * parameters than its own parent, which PHP rejects as a variance fatal error at class-load
     * time (reproduced with a minimal subclass under PHP 8.5.4). Hence this resolves the
     * installed-language snapshot itself, on every call, via the repository directly.
     *
     * Consequence: getInputDescription() and perform() can observe different installed-language
     * snapshots if a language is installed/uninstalled between the two calls within one
     * maybePerformAs() call. A newly installed optional language just ends up in
     * `skipped_empty_language_keys`; a newly installed "de"/"en" makes perform() reject the whole
     * request (fail-closed, since no value could have been submitted for it); a language
     * uninstalled in the meantime has its submitted value silently dropped rather than written or
     * reported. No case crashes or writes partial data.
     */
    public function getInputDescription(FieldFactory $f): FormInput
    {
        $installed_language_keys = $this->installed_language_repository->getInstalledLanguages();

        // Field labels use $this->lng->txt('meta_l_' . $lang_key), which requires the caller to
        // have already loaded the "meta" language module. This method deliberately does not load
        // it itself: loadLanguageModule() merges keys unnamespaced into the shared $lng instance,
        // which could clobber another module's keys if this ran generically alongside other
        // Activities. The GUI caller already loads it; a caller that doesn't gets the raw,
        // untranslated placeholder (e.g. "-meta_l_de-") instead of a crash.
        $module = $f->text(
            'Module',
            'Name of the language module the new entry belongs to.'
        )->withRequired(true)->withDedicatedName('module');

        $identifier = $f->text(
            'Identifier',
            'Identifier (topic) of the new language entry.'
        )->withRequired(true)->withDedicatedName('identifier');

        $translation_fields = [];
        foreach ($installed_language_keys as $lang_key) {
            $is_mandatory = in_array($lang_key, ['de', 'en'], true);
            $translation_fields[$lang_key] = $f->text(
                $this->lng->txt('meta_l_' . $lang_key)
            )->withRequired($is_mandatory)->withDedicatedName($lang_key);
        }

        $translations = $f->group(
            $translation_fields,
            'Translations',
            'Value of the new entry for each installed language; "de" and "en" are mandatory ' .
            '(if installed) - a missing/blank value for either rejects the whole request, ' .
            'nothing is written for any language. Every other installed language is optional ' .
            'and simply skipped if left blank.'
        )->withDedicatedName('translations');

        return $f->group([
            'module' => $module,
            'identifier' => $identifier,
            'translations' => $translations,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of adding the language entry.'),
            [
                'module' => $f->string($this->markdown('Language module the new entry was added to.')),
                'identifier' => $f->string($this->markdown('Identifier of the new language entry.')),
                'added_language_keys' => $f->list(
                    $this->markdown(
                        'Installed languages for which a non-blank value was given - the entry was ' .
                        'written for them.'
                    ),
                    $f->string($this->markdown('Language key of a language the entry was added for.'))
                ),
                'skipped_empty_language_keys' => $f->list(
                    $this->markdown(
                        'Installed languages for which no value (or only a blank one) was given - ' .
                        'left completely untouched, since there is nothing to write for them.'
                    ),
                    $f->string($this->markdown('Language key of a language with no given value.'))
                ),
                'overlay_write_failed_language_keys' => $this->overlayWriteFailedOutputField($f),
            ]
        );
    }

    /**
     * @param mixed $parameters may additionally carry a `usr_id` (int) key, merged in by
     *        LanguageActivity::maybePerformAs() via additionalPerformParameters() (never part of
     *        getInputDescription(), so it can never be spoofed via form data): if given, it is
     *        recorded as the author of the local change made to every written entry. It is
     *        optional - a generic caller following the plain Activity contract never supplies it,
     *        and the entries are then written without an attributed author instead.
     */
    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new InvalidInputException('Parameters must be an array.');
        }

        $module = $parameters['module'] ?? null;
        $identifier = $parameters['identifier'] ?? null;
        $translations = $parameters['translations'] ?? null;
        $usr_id = $parameters['usr_id'] ?? null;

        if (!is_string($module) || $module === ''
            || !is_string($identifier) || $identifier === ''
            || !is_array($translations)
            || ($usr_id !== null && !is_int($usr_id))
        ) {
            throw new InvalidInputException(
                'The module, identifier and translations parameters are required; usr_id, if given, must be an int.'
            );
        }

        $installed_language_keys = $this->installed_language_repository->getInstalledLanguages();

        $missing_mandatory_language_keys = [];
        foreach (['de', 'en'] as $mandatory_lang_key) {
            if (!in_array($mandatory_lang_key, $installed_language_keys, true)) {
                continue;
            }

            $value = $translations[$mandatory_lang_key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            if (!$value) {
                $missing_mandatory_language_keys[] = $mandatory_lang_key;
            }
        }

        if ($missing_mandatory_language_keys !== []) {
            throw new InvalidInputException(
                'A value is required for: ' . implode(', ', $missing_mandatory_language_keys) . '.'
            );
        }

        // Checked for every language before anything is written: one value with markup that is not
        // allowed (TranslationMarkupPolicy) rejects the whole request. Only the language keys are
        // named, never the submitted values.
        $values = [];
        foreach ($installed_language_keys as $lang_key) {
            $value = $translations[$lang_key] ?? '';
            $values[$lang_key] = is_string($value) ? trim($value) : '';
        }
        $invalid_values = (new TranslationMarkupPolicy())->findInvalidValues($values);
        if ($invalid_values !== []) {
            throw new InvalidInputException(
                'The value contains markup that is not allowed for: ' . implode(', ', array_keys($invalid_values)) . '.'
            );
        }

        // A form of a plural message (see PluralFormKey) the shipped message of a language does not
        // have would be dropped from the module's content - rejected instead of reporting success
        $languages_without_form = self::languagesWithoutPluralForm($module, $identifier, array_keys(array_filter($values)));
        if ($languages_without_form !== []) {
            throw new InvalidInputException(
                'The plural message has no such form for: ' . implode(', ', $languages_without_form) . '.'
            );
        }

        // $login is '' when usr_id is absent; replaceLangEntry() treats '' as "no remarks", so
        // the entry is written without an attributed author rather than the request failing.
        $login = is_int($usr_id) ? ($this->user_login)($usr_id) : '';
        $local_change = gmdate('Y-m-d H:i:s');

        $added_language_keys = [];
        $skipped_empty_language_keys = [];
        $overlay_write_failed_language_keys = [];

        // Not transactional across multiple languages: a failure partway through (e.g. a
        // database error) leaves languages processed so far written.
        foreach ($installed_language_keys as $lang_key) {
            $value = $translations[$lang_key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            // A value of exactly "0" is treated as blank too, matching the legacy behaviour.
            if (!$value) {
                $skipped_empty_language_keys[] = $lang_key;
                continue;
            }

            ($this->replace_lang_entry)($module, $identifier, $lang_key, $value, $local_change, $login);
            if (($this->update_module_cache)($lang_key, $module, $identifier, $value, $login) === false) {
                $overlay_write_failed_language_keys[] = $lang_key;
            }

            $added_language_keys[] = $lang_key;
        }

        return [
            'module' => $module,
            'identifier' => $identifier,
            'added_language_keys' => $added_language_keys,
            'skipped_empty_language_keys' => $skipped_empty_language_keys,
            'overlay_write_failed_language_keys' => $overlay_write_failed_language_keys,
        ];
    }

    /**
     * Threads the trusted, server-resolved `usr_id` into perform()'s $parameters via
     * LanguageActivity's template-method hook - see LanguageActivity::maybePerformAs()'s
     * array_merge() for why this wins over a same-named key from form input.
     *
     * @return array{usr_id: int}
     */
    protected function additionalPerformParameters(int $usr_id): array
    {
        return ['usr_id' => $usr_id];
    }

    /**
     * @param array{module: string, identifier: string, translations: array<string, string>} $grind_result
     * @return array{module: string, identifier: string, translations: array<string, string>}
     */
    protected function normalizeParameters(array $grind_result): array
    {
        return [
            'module' => $this->toNonEmptyString($grind_result['module'], 'module'),
            'identifier' => $this->toNonEmptyString($grind_result['identifier'], 'identifier'),
            'translations' => $grind_result['translations'],
        ];
    }

    private function toNonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            // getInputDescription()'s withRequired(true) only rejects an entirely empty raw
            // string, not a whitespace-only one - reject that here too.
            throw new InvalidInputException("The $field parameter must be a non-empty string.");
        }

        return trim($value);
    }
}

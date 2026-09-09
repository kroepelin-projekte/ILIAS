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

use ILIAS\Component\Activities\ActivityImpl;
use ILIAS\Component\Activities\ActivityType;
use ILIAS\Data\Description;
use ILIAS\Data\Result;
use ILIAS\Data\Text;
use ILIAS\Data\Text\Shape\SimpleDocumentMarkdown as SimpleDocumentMarkdownShape;
use ILIAS\Language\Language;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Factory as UIFactory;

/**
 * Adds one new "adjust language variables" entry (a module/identifier pair
 * not yet present in any installed language) - the value given for each
 * currently installed language is written to that language's lng_data, and
 * the according lng_modules serialization cache is refreshed to match. An
 * installed language for which no value was given (or only a blank one) is
 * left completely untouched and reported separately; use UpdateLanguage/
 * RemoveLocalLanguageChanges if an *existing* entry needs to be changed back
 * to the shipped default instead - this Activity only ever adds/overwrites
 * the given module/identifier pair with the given values.
 *
 * This is a separate Activity from InstallLanguage/UpdateLanguage/
 * UninstallLanguage/RemoveLocalLanguageChanges on purpose: "add a language
 * entry" acts on a single module/identifier pair across every installed
 * language, not on whole languages by key, and has no notion of installing,
 * refreshing, uninstalling, or discarding local customizations of a
 * language. Like UninstallLanguage/RemoveLocalLanguageChanges, this has no
 * Setup counterpart - Setup never edits individual language entries - which
 * is why this class deliberately has no `forSetup()` factory.
 *
 * Unlike every other Activity in this component, this one needs to record
 * *who* made the change: `ilObjLanguage::replaceLangEntry()` stores the
 * acting user's login in its `remarks` column, exactly as the extracted GUI
 * code (`ilObjLanguageExtGUI::saveNewEntryObject()`) did before. The Activity
 * interface only ever hands the acting `$usr_id` to `isAllowedToPerform()`,
 * not to `perform()` - to still let `perform()` record the correct login,
 * `maybePerformAs()` (which is the only caller of `perform()` here that ever
 * gets a `$usr_id`) adds it into the parameter array under the reserved key
 * `usr_id` before calling `perform()`. A caller that invokes
 * `isAllowedToPerform()`/`perform()` directly instead of `maybePerformAs()`
 * (see README.md, "As User of a Specific Activity") MUST supply the same
 * `usr_id` key itself - it is deliberately not part of
 * `getInputDescription()`, so that the recorded login always reflects the
 * authenticated acting user and can never be spoofed by a caller-supplied
 * form value.
 *
 * Which languages a value can be given for (`getInputDescription()`) and
 * which of them actually receive a write (`perform()`) both come from
 * `InstalledLanguageRepository::getInstalledLanguages()` - the exact same
 * set the extracted GUI code read via `$this->lng->getInstalledLanguages()`.
 * Unlike UninstallLanguage/RemoveLocalLanguageChanges, this Activity has no
 * need to construct an `ilObjLanguage` for a specific language key, so no
 * such closure is offered here.
 *
 * Two further legacy access points are wrapped in closures, purely so tests
 * can substitute fakes without bootstrapping the legacy global $DIC:
 *  - `replaceLangEntry()`, defaulting to the exact
 *    `\ilObjLanguage::replaceLangEntry()` call the extracted GUI code used
 *    directly, and
 *  - `updateModuleCache()`, defaulting to the exact "read the serialized
 *    lng_modules row for this language/module, decode it, set the new entry,
 *    write it back via `\ilObjLanguage::replaceLangModule()`" sequence the
 *    extracted GUI code used directly (silently doing nothing if the row is
 *    missing or not decodable, exactly as before).
 *
 * Deliberately NOT reproduced here: the session-based restriction of `module`/
 * `identifier` to previously listed "missing entries"
 * (`ilObjLanguageAccess::_getSavedModules()`/`_getSavedTopics()`). That
 * restriction is a GUI workflow guard tied to the acting session's "missing
 * entries" listing, not a business rule of the domain action itself - it
 * remains solely the caller's (GUI's) responsibility, exactly as before this
 * extraction.
 *
 * Known limitations, inherited from the legacy domain code rather than
 * introduced by this extraction:
 *  - perform() is not transactional: if a database error occurs partway
 *    through a multi-language request, languages processed before the
 *    failing one remain changed while the whole call is still reported as a
 *    single Result\Error, with no indication of which languages that were.
 *  - updateModuleCache()'s default silently does nothing if the lng_modules
 *    row for a language/module is missing or not a serialized array -
 *    exactly the same silent behaviour the extracted GUI code had (it never
 *    surfaced this case to the user either).
 *  - a translation value of exactly "0" is treated as if it were blank and
 *    silently skipped, the same as an empty or whitespace-only value - the
 *    extracted GUI code decided via `if ($trans) {...}` on the trimmed
 *    string, which is false for "0" in PHP; perform() deliberately
 *    reproduces this exact truthiness check rather than a stricter
 *    `$value === ''` comparison, to not change this pre-existing behaviour
 *    as a side effect of the extraction.
 */
class AddLanguageEntry extends ActivityImpl
{
    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;
    private readonly \Closure $replace_lang_entry;
    private readonly \Closure $update_module_cache;
    private readonly \Closure $user_login;

    /**
     * @param \Closure|null $replace_lang_entry (string $module, string $identifier, string $lang_key,
     *        string $value, string $local_change, string $remarks) => bool
     *        Writes a single language entry. Defaults to `\ilObjLanguage::replaceLangEntry()`.
     * @param \Closure|null $update_module_cache (string $lang_key, string $module, string $identifier,
     *        string $value) => void
     *        Refreshes the lng_modules serialization cache for a single language/module to reflect
     *        one changed entry. Defaults to the same read-decode-write sequence the extracted GUI
     *        code used directly (see class docblock).
     * @param \Closure|null $user_login (int $usr_id) => string
     *        Resolves the login of the acting user for the audit trail. Defaults to
     *        `\ilObjUser::_lookupLogin()`.
     */
    public function __construct(
        private readonly RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        private readonly InstalledLanguageRepository $installed_language_repository,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $replace_lang_entry = null,
        ?\Closure $update_module_cache = null,
        ?\Closure $user_login = null,
    ) {
        $this->lng = $language;
        $this->ui_factory = $ui_factory instanceof \Closure
            ? $ui_factory
            : static fn(): UIFactory => $ui_factory;
        $this->rbac_system = $rbac_system instanceof \Closure
            ? $rbac_system
            : static fn(): \ilRbacSystem => $rbac_system;
        $this->language_folder_ref_id = $language_folder_ref_id instanceof \Closure
            ? $language_folder_ref_id
            : static fn(): int => $language_folder_ref_id;
        $this->replace_lang_entry = $replace_lang_entry
            ?? static fn(
                string $module,
                string $identifier,
                string $lang_key,
                string $value,
                string $local_change,
                string $remarks
            ): bool => \ilObjLanguage::replaceLangEntry($module, $identifier, $lang_key, $value, $local_change, $remarks);
        $this->update_module_cache = $update_module_cache
            ?? static function (string $lang_key, string $module, string $identifier, string $value): void {
                $db = $GLOBALS['DIC']->database();

                $set = $db->query(
                    'SELECT lang_array FROM lng_modules WHERE lang_key = '
                    . $db->quote($lang_key, 'text') . ' AND module = ' . $db->quote($module, 'text')
                );
                $row = $db->fetchAssoc($set);
                if ($row === null) {
                    return;
                }

                $entries = unserialize($row['lang_array'], ['allowed_classes' => false]);
                if (!is_array($entries)) {
                    return;
                }

                $entries[$identifier] = $value;
                \ilObjLanguage::replaceLangModule($lang_key, $module, $entries);
            };
        $this->user_login = $user_login
            ?? static fn(int $usr_id): string => \ilObjUser::_lookupLogin($usr_id);
    }

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Adds one new "adjust language variables" entry (a module/identifier pair) to
every currently installed language for which a value was given. An installed
language for which no value (or only a blank one) was given is left
completely untouched.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $module = $ui_factory->input()->field()->text(
            'Module',
            'Name of the language module the new entry belongs to.'
        )->withRequired(true)->withDedicatedName('module');

        $identifier = $ui_factory->input()->field()->text(
            'Identifier',
            'Identifier (topic) of the new language entry.'
        )->withRequired(true)->withDedicatedName('identifier');

        $translation_fields = [];
        foreach ($this->installed_language_repository->getInstalledLanguages() as $lang_key) {
            $translation_fields[$lang_key] = $ui_factory->input()->field()->text(
                $this->lng->txt('meta_l_' . $lang_key)
            )->withRequired(in_array($lang_key, ['de', 'en'], true))->withDedicatedName($lang_key);
        }

        $translations = $ui_factory->input()->field()->group(
            $translation_fields,
            'Translations',
            'Value of the new entry for each installed language; "de" and "en" are required, all ' .
            'others are optional - an installed language left blank is skipped entirely.'
        )->withDedicatedName('translations');

        return $ui_factory->input()->field()->group([
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
            ]
        );
    }

    private function markdown(string $raw): Text\SimpleDocumentMarkdown
    {
        return new Text\SimpleDocumentMarkdown(
            new SimpleDocumentMarkdownShape(
                $this->refinery->string()->markdown()
            ),
            $raw
        );
    }

    public function isAllowedToPerform(int $usr_id, mixed $parameters): bool
    {
        return ($this->rbac_system)()->checkAccessOfUser(
            $usr_id,
            'write',
            ($this->language_folder_ref_id)()
        );
    }

    /**
     * @param mixed $parameters must additionally carry a `usr_id` (int) key - see class docblock for
     *        why this is not part of getInputDescription()/normalizeParameters().
     */
    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new \InvalidArgumentException('Parameters must be an array.');
        }

        $module = $parameters['module'] ?? null;
        $identifier = $parameters['identifier'] ?? null;
        $translations = $parameters['translations'] ?? null;
        $usr_id = $parameters['usr_id'] ?? null;

        if (!is_string($module) || $module === ''
            || !is_string($identifier) || $identifier === ''
            || !is_array($translations)
            || !is_int($usr_id)
        ) {
            throw new \InvalidArgumentException(
                'The module, identifier, translations and usr_id parameters are required.'
            );
        }

        $login = ($this->user_login)($usr_id);
        $local_change = gmdate('Y-m-d H:i:s');

        $added_language_keys = [];
        $skipped_empty_language_keys = [];

        foreach ($this->installed_language_repository->getInstalledLanguages() as $lang_key) {
            $value = $translations[$lang_key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            // A truthy check on purpose, not `$value === ''`: the extracted
            // GUI code did `if ($trans) {...}` on the trimmed string, so a
            // value of exactly "0" was (and still is here) silently skipped
            // too - see class docblock, "Known limitations".
            if (!$value) {
                $skipped_empty_language_keys[] = $lang_key;
                continue;
            }

            ($this->replace_lang_entry)($module, $identifier, $lang_key, $value, $local_change, $login);
            ($this->update_module_cache)($lang_key, $module, $identifier, $value);

            $added_language_keys[] = $lang_key;
        }

        return [
            'module' => $module,
            'identifier' => $identifier,
            'added_language_keys' => $added_language_keys,
            'skipped_empty_language_keys' => $skipped_empty_language_keys,
        ];
    }

    public function maybePerformAs(int $usr_id, array $raw_parameters): Result
    {
        try {
            $parameters = $this->normalizeParameters($raw_parameters);
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform($parameters + ['usr_id' => $usr_id]));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * @param mixed $raw_parameters
     * @return array{module: string, identifier: string, translations: array<string, string>}
     */
    private function normalizeParameters(mixed $raw_parameters): array
    {
        if (!is_array($raw_parameters)
            || !array_key_exists('module', $raw_parameters)
            || !array_key_exists('identifier', $raw_parameters)
            || !array_key_exists('translations', $raw_parameters)
        ) {
            throw new \InvalidArgumentException(
                'The module, identifier and translations parameters are required.'
            );
        }

        return [
            'module' => $this->toNonEmptyString($raw_parameters['module'], 'module'),
            'identifier' => $this->toNonEmptyString($raw_parameters['identifier'], 'identifier'),
            'translations' => $this->toTranslations($raw_parameters['translations']),
        ];
    }

    private function toNonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("The $field parameter must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private function toTranslations(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('translations must be an array of language key to value.');
        }

        $translations = [];
        foreach ($value as $lang_key => $translation) {
            if (!is_string($lang_key) || !is_string($translation)) {
                throw new \InvalidArgumentException(
                    'translations must be an array of language key to value.'
                );
            }
            $translations[$lang_key] = $translation;
        }

        return $translations;
    }
}

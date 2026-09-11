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

use ILIAS\Data\Description;
use ILIAS\Data\Result;
use ILIAS\Data\Text;
use ILIAS\Language\Language;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Factory as UIFactory;

/**
 * Adds one new "adjust language variables" entry (a module/identifier pair
 * not yet present in any installed language) - the value given for each
 * currently installed language is written to that language's lng_data, and
 * the lng_modules serialization cache is refreshed to match. An installed
 * language with no (or only a blank) value given is left untouched and
 * reported separately - EXCEPT "de"/"en" (see the mandatory-language check
 * in perform()), which reject the WHOLE request instead of being merely
 * skipped, mirroring the legacy `ilPropertyFormGUI`'s `setRequired(true)` on
 * these two fields.
 *
 * Separate Activity from Install/Update/Uninstall/RemoveLocalLanguageChanges
 * on purpose: this acts on a single module/identifier pair across every
 * installed language, not on whole languages by key. Has no Setup
 * counterpart (Setup never edits individual language entries), hence no
 * `forSetup()` factory.
 *
 * Unlike every other Activity here, this records *who* made the change:
 * `replaceLangEntry()` stores the acting user's login. Since the Activity
 * interface only hands `$usr_id` to `isAllowedToPerform()`, not `perform()`,
 * `maybePerformAs()` injects it into the parameter array under the reserved
 * `usr_id` key before calling `perform()` - a direct perform()/
 * isAllowedToPerform() caller bypassing maybePerformAs() must supply it
 * itself; it is deliberately not part of getInputDescription(), so it can
 * never be spoofed by a caller-supplied form value. `maybePerformAs()`
 * likewise injects the already-resolved `installed_language_keys` snapshot,
 * so perform() reuses the exact list getInputDescription() was built from
 * (avoiding both a redundant DB round trip and a TOCTOU risk).
 *
 * `isAllowedToPerform()` requires "write" access to the language folder
 * ref_id, exactly like every sibling Activity - this re-affirms an
 * already-enforced rule rather than introducing one, for the same
 * `ilObjLanguageAccess::_checkMaintenance()` precedent documented in
 * SetLanguageTranslationEnabled's class docblock.
 *
 * Deliberately NOT reproduced: the session-based restriction of `module`/
 * `identifier` to previously listed "missing entries"
 * (`ilObjLanguageAccess::_getSavedModules()`/`_getSavedTopics()`) - a GUI
 * workflow guard, not a business rule of the domain action, and so remains
 * solely the caller's responsibility.
 */
class AddLanguageEntry extends LanguageActivity
{
    // grind() is inherited from LanguageActivity (which itself `use
    // GrindsFormInput`, see there) - protected there specifically so this
    // class's own maybePerformAs() override below (see class docblock for
    // why it needs one) can call it directly without re-mixing the trait in
    // here too.

    private readonly \Closure $replace_lang_entry;
    private readonly \Closure $update_module_cache;
    private readonly \Closure $user_login;

    /**
     * @param \ilDBInterface|\Closure $db () => \ilDBInterface Database connection the DEFAULT
     *        `updateModuleCache()` uses to read the `lng_modules` row it refreshes - see class
     *        docblock. A real connection is required even if the caller supplies its own
     *        `$update_module_cache` (which never needs it) - this class has no way of knowing
     *        upfront whether the default will ever actually be used.
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
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        private readonly InstalledLanguageRepository $installed_language_repository,
        \ilDBInterface|\Closure $db,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $replace_lang_entry = null,
        ?\Closure $update_module_cache = null,
        ?\Closure $user_login = null,
    ) {
        parent::__construct($refinery, $ui_factory, $language, $rbac_system, $language_folder_ref_id);
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
                string $value
            ) use ($db_resolver): void {
                $db = $db_resolver();

                $set = $db->query(
                    'SELECT lang_array FROM lng_modules WHERE lang_key = '
                    . $db->quote($lang_key, 'text') . ' AND module = ' . $db->quote($module, 'text')
                );
                $row = $db->fetchAssoc($set);
                // A missing row, or a 'lang_array' column that is not a
                // (serialized) string, leaves nothing decodable to update -
                // silently do nothing, exactly as before. The is_string()
                // guard additionally protects unserialize() itself: since
                // declare(strict_types=1), passing anything but a string to
                // it now raises a \TypeError instead of the silent-failure
                // deprecation a non-string value (e.g. null, from a missing
                // column) used to produce.
                if ($row === null || !is_string($row['lang_array'] ?? null)) {
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

    public function getInputDescription(): FormInput
    {
        return $this->buildInputDescription($this->installed_language_repository->getInstalledLanguages());
    }

    /**
     * Builds the FormInput tree getInputDescription() describes, given an
     * already-resolved list of installed languages - factored out so
     * maybePerformAs() can resolve InstalledLanguageRepository::getInstalledLanguages()
     * exactly ONCE per call and reuse that same snapshot both to build this
     * description and, via perform()'s 'installed_language_keys' parameter
     * (see its own docblock), to decide which languages are actually
     * written - rather than reading it twice (once here, once in perform())
     * and risking the two calls observing different, possibly inconsistent
     * snapshots (a TOCTOU risk, however unlikely in practice) on top of an
     * entirely redundant database round trip.
     *
     * @param list<string> $installed_language_keys
     */
    private function buildInputDescription(array $installed_language_keys): FormInput
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

        // A whitespace-only or "0" value for a mandatory "de"/"en" still
        // passes withRequired(true) here (a plain withRequired(true) only
        // enforces hasMinLength(1) on the raw, untrimmed string) - not
        // closed at the field level because it would need a custom
        // Refinery\Constraint via $this->refinery->custom(), which every
        // bare-mock test of this class would then need to stub too; left to
        // a dedicated follow-up instead. perform()'s own mandatory-language
        // check (see there) still rejects it once trimmed.
        $translation_fields = [];
        foreach ($installed_language_keys as $lang_key) {
            $is_mandatory = in_array($lang_key, ['de', 'en'], true);
            $translation_fields[$lang_key] = $ui_factory->input()->field()->text(
                $this->lng->txt('meta_l_' . $lang_key)
            )->withRequired($is_mandatory)->withDedicatedName($lang_key);
        }

        $translations = $ui_factory->input()->field()->group(
            $translation_fields,
            'Translations',
            'Value of the new entry for each installed language; "de" and "en" are mandatory ' .
            '(if installed) - a missing/blank value for either rejects the whole request, ' .
            'nothing is written for any language. Every other installed language is optional ' .
            'and simply skipped if left blank.'
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

    /**
     * @param mixed $parameters must additionally carry a `usr_id` (int) key - see class docblock for
     *        why this is not part of getInputDescription()/normalizeParameters(). May additionally
     *        carry an `installed_language_keys` (list<string>) key - maybePerformAs() supplies this
     *        with the exact same snapshot it already built getInputDescription() from (see
     *        buildInputDescription()), so this method never re-reads
     *        InstalledLanguageRepository::getInstalledLanguages() a second time within the same
     *        maybePerformAs() call. A direct perform()/isAllowedToPerform() caller (see README.md,
     *        "As User of a Specific Activity") that never goes through maybePerformAs() may omit
     *        it entirely - it is then resolved here instead, exactly as before. Both keys are
     *        reserved, internally-injected parameters, NOT part of the form data
     *        getInputDescription() declares.
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
            || !is_int($usr_id)
        ) {
            throw new InvalidInputException(
                'The module, identifier, translations and usr_id parameters are required.'
            );
        }

        $installed_language_keys = $parameters['installed_language_keys']
            ?? $this->installed_language_repository->getInstalledLanguages();

        // "de"/"en" are mandatory, all-or-nothing - see class docblock for
        // why (mirrors the legacy ilPropertyFormGUI's setRequired(true) on
        // these two fields). This must be checked, and must reject the
        // WHOLE request, before a single write happens below - and must be
        // enforced here in perform() itself, not only via
        // getInputDescription()'s required-field declaration, so that a
        // direct perform()/isAllowedToPerform() caller that never goes
        // through maybePerformAs() (see README.md, "As User of a Specific
        // Activity") cannot bypass this business rule.
        $missing_mandatory_language_keys = [];
        foreach (['de', 'en'] as $mandatory_lang_key) {
            if (!in_array($mandatory_lang_key, $installed_language_keys, true)) {
                // Not installed at all - the legacy form never rendered a
                // field for it either, so nothing is required here.
                continue;
            }

            $value = $translations[$mandatory_lang_key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            // Same truthy check as the per-language loop below (a value of
            // exactly "0" counts as blank too, see below) - applied here
            // first so an "0" value for "de"/"en" is rejected outright
            // rather than silently reaching the loop's own skip bucket.
            if (!$value) {
                $missing_mandatory_language_keys[] = $mandatory_lang_key;
            }
        }

        if ($missing_mandatory_language_keys !== []) {
            throw new InvalidInputException(
                'A value is required for: ' . implode(', ', $missing_mandatory_language_keys) . '.'
            );
        }

        $login = ($this->user_login)($usr_id);
        $local_change = gmdate('Y-m-d H:i:s');

        $added_language_keys = [];
        $skipped_empty_language_keys = [];

        // Not transactional across multiple languages: if
        // replace_lang_entry()/update_module_cache() throws partway through
        // writing several already-validated languages (e.g. a database
        // error), languages processed before the failing one remain written
        // while the whole call is still reported as a single Result\Error.
        // Not fixed with a database transaction here: both collaborators are
        // freely replaceable by a caller (every test in this class does),
        // so this class cannot generally guarantee they share one
        // connection to begin with.
        foreach ($installed_language_keys as $lang_key) {
            $value = $translations[$lang_key] ?? '';
            $value = is_string($value) ? trim($value) : '';

            // A truthy check on purpose, not `$value === ''`: the extracted
            // GUI code did `if ($trans) {...}` on the trimmed string, so a
            // value of exactly "0" was (and still is here) silently skipped
            // too.
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
        // Resolved exactly once per call (see buildInputDescription()'s own
        // docblock) and threaded through to perform() below via the
        // reserved 'installed_language_keys' parameter, alongside 'usr_id'.
        $installed_language_keys = $this->installed_language_repository->getInstalledLanguages();

        $grind_result = $this->grind($this->buildInputDescription($installed_language_keys), $raw_parameters);
        if ($grind_result->isError()) {
            return new Result\Error($grind_result->error());
        }

        try {
            $parameters = $this->normalizeParameters($grind_result->value());
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform(
                $parameters + ['usr_id' => $usr_id, 'installed_language_keys' => $installed_language_keys]
            ));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * Builds the parameters perform()/isAllowedToPerform() expect from the
     * already-grinded content of getInputDescription() (see grind() in the
     * GrindsFormInput trait). 'translations' is already an array<string,
     * string> keyed exactly by the installed languages getInputDescription()
     * built a field for - every value is already guaranteed to be a string
     * by the Text field's own withInput() (a non-string raw value, or a
     * language key not present as a field at all, already turned into a
     * Result\Error during grind() itself, see GrindsFormInput) - so, unlike
     * before, no separate structural validation of 'translations' is needed
     * here anymore. Only 'module'/'identifier' still need trimming:
     * getInputDescription()'s own required-check only enforces a minimum
     * length of 1 on the raw string, which a whitespace-only value would
     * already satisfy.
     *
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
            // Reachable via a genuine maybePerformAs() call: getInputDescription()'s
            // withRequired(true) only enforces hasMinLength(1) on the raw,
            // untrimmed string (see buildInputDescription()), so a
            // whitespace-only value passes grinding and must still be
            // rejected here - as a SafeToDisplayActivityError, since this is
            // a genuine input mistake, not an internal failure.
            throw new InvalidInputException("The $field parameter must be a non-empty string.");
        }

        return trim($value);
    }
}

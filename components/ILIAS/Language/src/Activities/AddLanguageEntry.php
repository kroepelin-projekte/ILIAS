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
 * left completely untouched and reported separately - EXCEPT "de" and "en"
 * (see "de"/"en" are mandatory below), which are never merely skipped; use
 * UpdateLanguage/RemoveLocalLanguageChanges if an *existing* entry needs to
 * be changed back to the shipped default instead - this Activity only ever
 * adds/overwrites the given module/identifier pair with the given values.
 *
 * "de"/"en" are mandatory, all-or-nothing: mirroring the legacy
 * `ilPropertyFormGUI`'s `$trans->setRequired(true)` on these two translation
 * fields (see `git show 4b1cbf1a488:.../class.ilObjLanguageExtGUI.php`), a
 * missing or blank value for "de" or "en" - whichever is currently installed
 * - rejects the WHOLE request: perform() throws InvalidInputException and
 * writes NOTHING for ANY language, before a single
 * `ilObjLanguage::replaceLangEntry()` call is made - exactly reproducing the
 * legacy form's `$form->checkInput()` behaviour. A "de"/"en" that is not
 * currently installed imposes no such requirement, just like the legacy
 * form never rendered a field for it. Every OTHER installed language stays
 * optional and is simply skipped if left blank, as before.
 *
 * This guarantee is scoped EXACTLY to this one failure mode and is upheld
 * unconditionally (the check runs entirely before the write loop starts) -
 * it is NOT a general transactional guarantee for the write loop itself,
 * see "Known limitations" below for that (pre-existing, narrower) gap.
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
 * `updateModuleCache()`'s default needs a database connection to read the
 * `lng_modules` row it refreshes. Like every other legacy collaborator this
 * component's Activities depend on, that connection is injected via `$db`
 * (accepting either an `\ilDBInterface` or a `\Closure` resolving one) rather
 * than reached for directly here in `src/` - `Language.php` wires it to the
 * same `$resolve_db` closure (`$GLOBALS['ilDB'] ?? $GLOBALS['DIC']->database()`)
 * already shared by `InstalledLanguageDatabaseRepository`/
 * `LanguageInstallationManager`, so this class has no direct `$GLOBALS['DIC']`
 * access of its own, consistent with every sibling Activity in this
 * component.
 *
 * Permission check, and why this does NOT introduce a new restriction:
 * `isAllowedToPerform()` here requires "write" access to the language folder
 * ref_id, exactly like every other Activity in this component. The extracted
 * GUI code this Activity replaces (`ilObjLanguageExtGUI::saveNewEntryObject()`)
 * never performed this check itself either, but it did not need to: every
 * command of that same class - including `saveNewEntryObject()` - already
 * runs behind `ilObjLanguageExtGUI::executeCommand()`'s blanket
 * `ilObjLanguageAccess::_checkMaintenance()` gate, which itself requires
 * `$rbacsystem->checkAccess("read,write", $ref_id)` on that very same
 * language folder ref_id (RBAC's `checkAccess()` with a comma-separated
 * operation list requires ALL listed operations, i.e. this is an AND, not an
 * OR - see `\ilRbacSystem::checkAccessOfUser()`). So `saveNewEntryObject()`
 * could never have run in the first place without the acting user already
 * holding write access to that same ref_id - this Activity's
 * `isAllowedToPerform()` re-affirms an already-enforced business rule at the
 * Activity layer, exactly like SetLanguageTranslationEnabled's own
 * `isAllowedToPerform()` (see its class docblock, which documents the same
 * `_checkMaintenance()` gate for its own extracted GUI method); it does not
 * add a new one.
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
 *  - perform()'s write loop itself is NOT transactional (unlike the
 *    "de"/"en" mandatory check above, which is atomic by construction -
 *    see there): if `replace_lang_entry()`/`update_module_cache()` throws
 *    partway through writing multiple already-validated languages (e.g. a
 *    database error), languages processed before the failing one remain
 *    written while the whole call is still reported as a single
 *    Result\Error, with no indication of which languages that were. A
 *    database transaction was deliberately not introduced here to cover
 *    this: `replace_lang_entry()`/`update_module_cache()` are both
 *    injectable collaborators a caller may freely replace (every test in
 *    this class does), so this class cannot generally guarantee they
 *    share one single database connection/transaction to begin with - a
 *    transaction wrapped only around the *default* implementations would
 *    give a guarantee that quietly stops applying the moment either
 *    collaborator is overridden, which is worse than documenting the
 *    limitation honestly.
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
 *  - getInputDescription() accepts a whitespace-only or "0" value for a
 *    mandatory "de"/"en" translation (a plain `withRequired(true)` only
 *    enforces a minimum length of 1 on the RAW, untrimmed string - see
 *    Text::getConstraintForRequirement()), which perform() then still
 *    rejects once trimmed (see the mandatory-language check above) - a
 *    narrow violation of this component's own README.md rule that input
 *    accepted by getInputDescription() must not make perform() crash. See
 *    buildInputDescription() for why this is left as a known limitation
 *    rather than fixed at the field level.
 */
class AddLanguageEntry extends ActivityImpl
{
    use GrindsFormInput;

    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;
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
        private readonly RefineryFactory $refinery,
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
        // passes withRequired(true) here (see class docblock, "Known
        // limitations") - not closed at the field level because it would
        // need a custom Refinery\Constraint via $this->refinery->custom(),
        // which every bare-mock test of this class would then need to stub
        // too; left to a dedicated follow-up instead.
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
     *        why this is not part of getInputDescription()/normalizeParameters(). May additionally
     *        carry an `installed_language_keys` (list<string>) key - maybePerformAs() supplies this
     *        with the exact same snapshot it already built getInputDescription() from (see
     *        buildInputDescription()), so this method never re-reads
     *        InstalledLanguageRepository::getInstalledLanguages() a second time within the same
     *        maybePerformAs() call. A direct perform()/isAllowedToPerform() caller (see README.md,
     *        "As User of a Specific Activity") that never goes through maybePerformAs() may omit
     *        it entirely - it is then resolved here instead, exactly as before.
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
            // exactly "0" counts as blank too, see class docblock) - applied
            // here first so an "0" value for "de"/"en" is rejected outright
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

        foreach ($installed_language_keys as $lang_key) {
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
    private function normalizeParameters(array $grind_result): array
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
            // untrimmed string (see class docblock note on
            // buildInputDescription()), so a whitespace-only value passes
            // grinding and must still be rejected here - as a
            // SafeToDisplayActivityError, since this is a genuine input
            // mistake, not an internal failure.
            throw new InvalidInputException("The $field parameter must be a non-empty string.");
        }

        return trim($value);
    }
}

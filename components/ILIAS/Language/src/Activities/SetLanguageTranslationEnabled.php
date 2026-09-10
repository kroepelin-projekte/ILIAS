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

use ILIAS\Administration\Setting;
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
 * Switches the "page translation" feature on or off for one specific
 * language. This is a per-language flag - unlike SetLanguageDetectionEnabled,
 * which is a single system-wide flag - stored under the "lang_translate_<key>"
 * system setting and read by ilObjLanguageAccess::_checkTranslate() (which
 * additionally requires the acting user to have read+write access to the
 * language folder before actually showing the translation link) and by
 * ilLanguageFolderTable, both keyed by the same language key this Activity
 * is given.
 *
 * This is a separate Activity from SetLanguageDetectionEnabled on purpose,
 * even though both are simple boolean toggles backed by a single ilSetting
 * key: SetLanguageDetectionEnabled has no notion of "which language" at all
 * (it is one flag for the whole installation), while this Activity is
 * necessarily parameterised by a language key - two domain actions with a
 * different shape, not two variations of the same one. Unlike
 * InstallLanguage/UpdateLanguage, this has no Setup counterpart - Setup never
 * touches this system setting - which is why, like UninstallLanguage/
 * RemoveLocalLanguageChanges/AddLanguageEntry/SetLanguageDetectionEnabled,
 * this class deliberately has no `forSetup()` factory.
 *
 * `$settings` wraps the single `\ilSetting::set()`/`get()` pair the extracted
 * GUI code (`ilObjLanguageExtGUI::saveSettingsObject()`) used directly (via
 * `$DIC->settings()`), typed against the `\ILIAS\Administration\Setting`
 * interface rather than the concrete `\ilSetting` class - same reasoning as
 * SetLanguageDetectionEnabled's own `$settings` collaborator (see its class
 * docblock).
 *
 * Deliberate, but harmless, change in the raw value written to the setting:
 * the extracted GUI code stored whatever raw string `getParsedBody()['translation']`
 * happened to carry - "1" for a checked checkbox (see
 * `ilCheckboxInputGUI::$value`, which defaults to "1" and is never changed by
 * `initNewSettingsForm()`), or "" (missing from the POST body entirely, as
 * unchecked HTML checkboxes are not submitted) for an unchecked one. This
 * Activity instead always normalises the stored value to the same "1"/"0"
 * convention SetLanguageDetectionEnabled already uses. This is not a
 * behavioural change from the perspective of any reader of the setting:
 * every consumer (`_checkTranslate()`, `ilLanguageFolderTable`, and this
 * class' own `perform()`) treats the value as a boolean via a plain falsy
 * check (`!$value` / `(bool) $value`), and both "" and "0" are falsy - only
 * the literal database value differs, never any observable outcome.
 *
 * Deliberately NOT reproduced here: the dead `is_null($post_translation)`
 * check from the extracted GUI code. `$post_translation` there came from
 * `... ?? ""`, so it could never actually be null - the extracted code's
 * factual behaviour was simply "write (and report success) only if the
 * requested value differs from the currently stored one", which is what
 * `perform()` reproduces via its returned `changed` flag (see
 * getOutputDescription()) - the caller decides whether to show a success
 * message from that flag.
 *
 * This reproduces the extracted GUI code's behaviour with ONE known,
 * deliberate exception, not exactly: the extracted code decided "changed"
 * via a loose `!=` comparison of two raw strings
 * (`$post_translation != $translate`, both coming straight from
 * `getParsedBody()`/`ilSetting::get()`), while `perform()` compares two
 * proper booleans (`$currently_enabled !== $enabled`). These two ways of
 * comparing disagree in exactly one case: a stored value of the literal
 * string "0" (a disabled setting written by *this* Activity, or by any
 * writer using the "0"/"1" convention) together with a submitted, unchecked
 * checkbox (`$post_translation === ""`). Loosely, `"" != "0"` is `true` -
 * the legacy code treated this as "changed", wrote the redundant "" over
 * the existing "0", and showed the `settings_saved` success message - even
 * though the *actual*, boolean state (disabled) never changed at all. This
 * was never a deliberate feature: nothing that ever reads this setting
 * distinguishes "" from "0" (both are falsy), so no reader could tell the
 * two apart either way - it was simply an accidental side effect of
 * comparing raw strings loosely instead of the booleans they were always
 * meant to represent. `perform()` deliberately does NOT reproduce this
 * side effect: for that exact case it correctly reports `changed = false`
 * and performs no write at all, since no observable state actually changed.
 * See SetLanguageTranslationEnabledTest for a regression test pinning this
 * exact case down as intended behaviour.
 *
 * Permission check, and why this does NOT introduce a new restriction -
 * unlike SetLanguageDetectionEnabled, which documents a genuine, deliberate
 * behavioural change of this kind in its own class docblock (its two
 * extracted GUI methods never enforced a write check themselves at all):
 * `isAllowedToPerform()` here requires "write" access to the language folder
 * ref_id, exactly like every other Activity in this component. But unlike
 * `ilObjLanguageFolderGUI` (where the generic command gate only ever checks
 * "read"), every command of the extracted GUI code's own class -
 * `ilObjLanguageExtGUI::executeCommand()` - already refuses to run at all
 * unless `ilObjLanguageAccess::_checkMaintenance()` holds, which itself
 * requires `$rbacsystem->checkAccess("read,write", $ref_id)` on the very
 * same language folder ref_id (RBAC's `checkAccess()` with a comma-separated
 * operation list requires ALL listed operations, i.e. this is an AND, not an
 * OR - see `\ilRbacSystem::checkAccessOfUser()`). So `saveSettingsObject()`
 * could never have run in the first place without the acting user already
 * holding write access to that same ref_id - this Activity's
 * `isAllowedToPerform()` re-affirms an already-enforced business rule at the
 * Activity layer, it does not add a new one. AddLanguageEntry's own
 * `isAllowedToPerform()` re-affirms the exact same pre-existing
 * `_checkMaintenance()` precedent for its own extracted GUI method
 * (`saveNewEntryObject()`, gated by the very same `executeCommand()`) - see
 * its class docblock.
 *
 * `maybePerformAs()` now actually grinds $raw_parameters through
 * getInputDescription() (see the GrindsFormInput trait) instead of reading
 * $raw_parameters directly. Two deliberate, spec-compliant consequences:
 *  - 'enabled' now accepts the common primitive representations of
 *    true/false a generic (non-HTML) caller would reasonably send (e.g.
 *    "1"/"0", "true"/"false"), not only a strict PHP bool - see
 *    GrindsFormInput::normalizeCheckboxRawValue(). perform()'s own contract
 *    is unchanged: it still requires a strict bool.
 *  - `language_key` is now validated against the set of actually installed
 *    languages (via the newly injected `$installed_language_repository`,
 *    the same collaborator AddLanguageEntry already uses for the same
 *    purpose). Before this, an unknown language key was accepted without
 *    complaint and silently created a new "lang_translate_<key>" setting
 *    row for a language that does not exist - this was never caught by a
 *    test or a real caller before, because the extracted GUI code
 *    (`ilObjLanguageExtGUI`) only ever reached this method for its own
 *    `$this->object->key`, which `ilCtrl`'s object resolution already
 *    guarantees to be a real, installed language; that guarantee obviously
 *    no longer holds now that this Activity is reachable generically via
 *    maybePerformAs().
 */
class SetLanguageTranslationEnabled extends ActivityImpl
{
    use GrindsFormInput;

    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;
    private readonly \Closure $settings;

    /**
     * @param InstalledLanguageRepository $installed_language_repository Validates the given
     *        `language_key` is actually installed - see class docblock.
     */
    public function __construct(
        private readonly RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        Setting|\Closure $settings,
        private readonly InstalledLanguageRepository $installed_language_repository,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        $this->lng = $language;
        $this->ui_factory = $ui_factory instanceof \Closure
            ? $ui_factory
            : static fn(): UIFactory => $ui_factory;
        $this->rbac_system = $rbac_system instanceof \Closure
            ? $rbac_system
            : static fn(): \ilRbacSystem => $rbac_system;
        $this->settings = $settings instanceof \Closure
            ? $settings
            : static fn(): Setting => $settings;
        $this->language_folder_ref_id = $language_folder_ref_id instanceof \Closure
            ? $language_folder_ref_id
            : static fn(): int => $language_folder_ref_id;
    }

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Enables or disables the "page translation" feature for one specific
language. This is a per-language on/off flag, not a system-wide setting -
use SetLanguageDetectionEnabled for the system-wide automatic language
detection flag instead.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $language_key = $ui_factory->input()->field()->text(
            'Language key',
            'Language key the page translation setting applies to, e.g. de, fr, it.'
        )->withRequired(true)->withDedicatedName('language_key');

        $enabled = $ui_factory->input()->field()->checkbox(
            'Enabled',
            'Whether page translation should be enabled for this language.'
        )->withDedicatedName('enabled');

        return $ui_factory->input()->field()->group([
            'language_key' => $language_key,
            'enabled' => $enabled,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of changing the page translation setting.'),
            [
                'language_key' => $f->string(
                    $this->markdown('Language key the page translation setting was changed for.')
                ),
                'enabled' => $f->bool(
                    $this->markdown('The page translation setting for this language after this change.')
                ),
                'changed' => $f->bool(
                    $this->markdown(
                        'Whether the setting actually differed from its previously stored value - ' .
                        'false if the requested value already matched the stored one, in which case ' .
                        'nothing was written.'
                    )
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

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)
            || !array_key_exists('language_key', $parameters)
            || !is_string($parameters['language_key'])
            || trim($parameters['language_key']) === ''
            || !array_key_exists('enabled', $parameters)
            || !is_bool($parameters['enabled'])
        ) {
            throw new InvalidInputException(
                'The language_key (non-empty string) and enabled (bool) parameters are required.'
            );
        }

        $language_key = trim($parameters['language_key']);
        $enabled = $parameters['enabled'];

        // The extracted GUI code never needed this check because ilCtrl's
        // object resolution guaranteed $this->object->key was always a real,
        // installed language - see class docblock. This Activity is
        // reachable generically via maybePerformAs() now, so that guarantee
        // no longer holds and must be enforced here explicitly, for direct
        // perform()/isAllowedToPerform() callers too (see README.md, "As
        // User of a Specific Activity").
        if (!in_array($language_key, $this->installed_language_repository->getInstalledLanguages(), true)) {
            throw new InvalidInputException(
                'Unknown language key "' . htmlspecialchars($language_key, ENT_QUOTES) . '" - not an installed language.'
            );
        }

        $translate_key = 'lang_translate_' . $language_key;

        // Same falsy check `_checkTranslate()`/`ilLanguageFolderTable` already
        // use to interpret this setting - see class docblock for why this
        // (rather than a strict string comparison against a fixed "1"/"0"
        // representation) is the correct way to decide the current state,
        // regardless of which raw value happens to be stored for it.
        $currently_enabled = (bool) ($this->settings)()->get($translate_key, '0');
        $changed = $currently_enabled !== $enabled;

        if ($changed) {
            ($this->settings)()->set($translate_key, $enabled ? '1' : '0');
        }

        return [
            'language_key' => $language_key,
            'enabled' => $enabled,
            'changed' => $changed,
        ];
    }

    public function maybePerformAs(int $usr_id, array $raw_parameters): Result
    {
        $grind_result = $this->grind($this->getInputDescription(), $raw_parameters);
        if ($grind_result->isError()) {
            return new Result\Error($grind_result->error());
        }

        try {
            $parameters = $this->normalizeParameters($grind_result->value());
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform($parameters));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * Builds the parameters perform()/isAllowedToPerform() expect from the
     * already-grinded content of getInputDescription() (see grind() in the
     * GrindsFormInput trait) - 'enabled' is already a strict bool at this
     * point (the Checkbox field's own withInput() already tolerantly
     * normalized it, see GrindsFormInput::normalizeCheckboxRawValue()); the
     * installed-language check for 'language_key' still happens in
     * perform() itself (see there), not here, so it also applies to direct
     * perform()/isAllowedToPerform() callers that never go through
     * maybePerformAs() at all.
     *
     * @param array{language_key: string, enabled: bool} $grind_result
     * @return array{language_key: string, enabled: bool}
     */
    private function normalizeParameters(array $grind_result): array
    {
        return [
            'language_key' => trim($grind_result['language_key']),
            'enabled' => $grind_result['enabled'],
        ];
    }
}

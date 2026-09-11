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
use ILIAS\Data\Description;
use ILIAS\Data\Text;
use ILIAS\Language\Language;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Factory as UIFactory;

/**
 * Switches the "page translation" feature on or off for one specific
 * language - a per-language flag (unlike SetLanguageDetectionEnabled, which
 * is system-wide) stored under "lang_translate_<key>" and read by
 * ilObjLanguageAccess::_checkTranslate()/ilLanguageFolderTable.
 *
 * Separate Activity from SetLanguageDetectionEnabled on purpose: this one is
 * necessarily parameterised by a language key, that one has no notion of
 * "which language" at all - two domain actions with a different shape, not
 * two variations of one. No Setup counterpart, hence no `forSetup()`
 * factory. `$settings` wraps the same `\ilSetting` pair as
 * SetLanguageDetectionEnabled's own `$settings` collaborator, for the same
 * reasoning (see its class docblock).
 *
 * Permission check does NOT introduce a new restriction (contrast
 * SetLanguageDetectionEnabled, which deliberately does): the extracted GUI
 * method (`ilObjLanguageExtGUI::saveSettingsObject()`) already ran behind
 * that class' `executeCommand()`, gated by
 * `ilObjLanguageAccess::_checkMaintenance()` - which itself requires
 * `checkAccess("read,write", $ref_id)` (an AND, not an OR) on the same
 * language folder ref_id. So the acting user already needed write access
 * before this extraction; `isAllowedToPerform()` merely re-affirms that
 * already-enforced rule at the Activity layer. AddLanguageEntry's own
 * `isAllowedToPerform()` re-affirms the exact same `_checkMaintenance()`
 * precedent for its own extracted GUI method - see its class docblock.
 *
 * `maybePerformAs()` grinds $raw_parameters through getInputDescription()
 * (see GrindsFormInput): 'enabled' now tolerates common primitive true/false
 * representations (not only a strict bool, see
 * GrindsFormInput::normalizeCheckboxRawValue()); `language_key` is now
 * validated against the actually installed languages (via
 * `$installed_language_repository`) - previously accepted unchecked, since
 * the extracted GUI code only ever reached this method for its own,
 * ilCtrl-guaranteed-valid `$this->object->key`, a guarantee that no longer
 * holds now that this Activity is reachable generically.
 */
class SetLanguageTranslationEnabled extends LanguageActivity
{
    private readonly \Closure $settings;

    /**
     * @param InstalledLanguageRepository $installed_language_repository Validates the given
     *        `language_key` is actually installed - see class docblock.
     */
    public function __construct(
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        Setting|\Closure $settings,
        private readonly InstalledLanguageRepository $installed_language_repository,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        parent::__construct($refinery, $ui_factory, $language, $rbac_system, $language_folder_ref_id);
        $this->settings = $settings instanceof \Closure
            ? $settings
            : static fn(): Setting => $settings;
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
        // User of a Specific Activity"). $language_key is plain text here -
        // any HTML-escaping needed for display is applied by the caller
        // (see \ILIAS\Language\RendersActivityErrors::activityErrorMessage()),
        // never here in the domain layer.
        if (!in_array($language_key, $this->installed_language_repository->getInstalledLanguages(), true)) {
            throw new InvalidInputException(
                'Unknown language key "' . $language_key . '" - not an installed language.'
            );
        }

        $translate_key = 'lang_translate_' . $language_key;

        // Same falsy check `_checkTranslate()`/`ilLanguageFolderTable` already
        // use to interpret this setting - see class docblock for why this
        // (rather than a strict string comparison against a fixed "1"/"0"
        // representation) is the correct way to decide the current state,
        // regardless of which raw value happens to be stored for it.
        $currently_enabled = (bool) ($this->settings)()->get($translate_key, '0');
        // Comparing proper booleans here (rather than the legacy GUI code's
        // loose `!=` on two raw strings) deliberately changes ONE edge case:
        // a stored "0" plus a submitted, unchecked checkbox ("") used to be
        // reported as "changed" (and written again) even though the actual,
        // boolean state never changed - see SetLanguageTranslationEnabledTest
        // for a regression test pinning this corrected behaviour down.
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
    protected function normalizeParameters(array $grind_result): array
    {
        return [
            'language_key' => trim($grind_result['language_key']),
            'enabled' => $grind_result['enabled'],
        ];
    }
}

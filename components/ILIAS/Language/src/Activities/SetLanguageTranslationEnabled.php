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
 * message from that flag, exactly reproducing
 * `ilObjLanguageExtGUI::saveSettingsObject()`'s original "only show
 * 'settings_saved' if something actually changed" behaviour without baking
 * that GUI-level presentation decision into the Activity itself.
 *
 * Permission check, and why this does NOT introduce a new restriction
 * (unlike AddLanguageEntry/SetLanguageDetectionEnabled, both of which
 * document closing a pre-existing enforcement gap in their own class
 * docblocks): `isAllowedToPerform()` here requires "write" access to the
 * language folder ref_id, exactly like every other Activity in this
 * component. But unlike `ilObjLanguageFolderGUI` (where the generic command
 * gate only ever checks "read"), every command of the extracted GUI code's
 * own class - `ilObjLanguageExtGUI::executeCommand()` - already refuses to
 * run at all unless `ilObjLanguageAccess::_checkMaintenance()` holds, which
 * itself requires `$rbacsystem->checkAccess("read,write", $ref_id)` on the
 * very same language folder ref_id (RBAC's `checkAccess()` with a
 * comma-separated operation list requires ALL listed operations, i.e. this
 * is an AND, not an OR - see `\ilRbacSystem::checkAccessOfUser()`). So
 * `saveSettingsObject()` could never have run in the first place without the
 * acting user already holding write access to that same ref_id - this
 * Activity's `isAllowedToPerform()` re-affirms an already-enforced business
 * rule at the Activity layer, it does not add a new one.
 */
class SetLanguageTranslationEnabled extends ActivityImpl
{
    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;
    private readonly \Closure $settings;

    public function __construct(
        private readonly RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        Setting|\Closure $settings,
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
            throw new \InvalidArgumentException(
                'The language_key (non-empty string) and enabled (bool) parameters are required.'
            );
        }

        $language_key = trim($parameters['language_key']);
        $enabled = $parameters['enabled'];

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
        try {
            $parameters = $this->normalizeParameters($raw_parameters);
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform($parameters));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * @param mixed $raw_parameters
     * @return array{language_key: string, enabled: bool}
     */
    private function normalizeParameters(mixed $raw_parameters): array
    {
        if (!is_array($raw_parameters)
            || !array_key_exists('language_key', $raw_parameters)
            || !array_key_exists('enabled', $raw_parameters)
        ) {
            throw new \InvalidArgumentException('The language_key and enabled parameters are required.');
        }

        return [
            'language_key' => $this->toNonEmptyString($raw_parameters['language_key']),
            'enabled' => $this->toBool($raw_parameters['enabled']),
        ];
    }

    private function toNonEmptyString(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('language_key must be a non-empty string.');
        }

        return trim($value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('enabled must be a boolean.');
    }
}

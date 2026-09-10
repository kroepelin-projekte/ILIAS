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
 * Switches the system-wide "detect the user's language from the browser's
 * Accept-Language header" setting on or off. This is a single boolean flag
 * for the whole installation - it is not tied to any particular language,
 * user, or session - stored under the "lang_detection" system setting and
 * read by ilLanguageDetectorFactory::getValidInstances() on every request
 * that has not yet resolved a user language.
 *
 * This is modelled as ONE Command with a boolean `enabled` parameter, not as
 * two separate Activities ("EnableLanguageDetection"/"DisableLanguageDetection"):
 * both extracted GUI methods (ilObjLanguageFolderGUI::enableLanguageDetectionObject()/
 * disableLanguageDetectionObject()) do nothing but flip the very same domain
 * flag to a different fixed value - two Activities would only duplicate the
 * permission check, the description and the write itself for what is, in
 * the domain, a single "set this flag to X" action. This mirrors how
 * InstallLanguage already models two related outcomes ("install"/
 * "install_local") as one Activity with a mode parameter rather than two
 * separate classes.
 *
 * Unlike InstallLanguage/UpdateLanguage, this has no Setup counterpart -
 * Setup never touches this system setting - which is why, like
 * UninstallLanguage/RemoveLocalLanguageChanges/AddLanguageEntry, this class
 * deliberately has no `forSetup()` factory.
 *
 * `$settings` wraps the single `\ilSetting::set()`/`get()` pair the extracted
 * GUI code used directly (via `$this->settings`, itself `$DIC->settings()`),
 * typed against the `\ILIAS\Administration\Setting` interface rather than
 * the concrete `\ilSetting` class - the Administration component does not
 * (yet) contribute this interface via the component graph (see
 * components/ILIAS/Administration/Administration.php), so it is injected as
 * a plain collaborator here, exactly like `\ilRbacSystem` on every sibling
 * Activity in this component.
 *
 * Known, deliberate behavioural difference from the extracted GUI code: the
 * two extracted methods (`enableLanguageDetectionObject()`/
 * `disableLanguageDetectionObject()`) call neither `checkPermission()` nor
 * any other write-permission check themselves - they rely solely on
 * `executeCommand()`'s blanket `checkPermission('read', ...)` plus the fact
 * that the toggle button in `viewObject()` is only rendered when
 * `checkPermissionBool('write')` holds. A request forged directly against
 * `ilCtrl`'s `enableLanguageDetection`/`disableLanguageDetection` commands by
 * a user with only "read" access on the language folder would, before this
 * extraction, still have flipped the setting - the write check was purely a
 * UI-level convenience, not an enforced business rule. `isAllowedToPerform()`
 * here DOES enforce a "write" RBAC check on the language folder, consistent
 * with every other Activity in this component and with the domain rule that
 * a system-wide setting must not be changeable by a merely-read-permitted
 * user. This closes what looks like a pre-existing enforcement gap, but it
 * is a genuine behavioural change and must be treated as such, not silently
 * introduced - unlike, say, AddLanguageEntry's or SetLanguageTranslationEnabled's
 * own `isAllowedToPerform()` (see their class docblocks), which merely
 * re-affirm a write check their respective extracted GUI methods already had
 * enforced on them by their class' `executeCommand()`, this Activity's write
 * check has no such precedent to point to: `enableLanguageDetectionObject()`/
 * `disableLanguageDetectionObject()` ran behind `ilObjLanguageFolderGUI`'s own
 * generic command gate, which - as stated above - only ever checked "read",
 * so no prior enforcement of "write" existed for these two commands at all.
 *
 * `maybePerformAs()` now actually grinds $raw_parameters through
 * getInputDescription() (see the GrindsFormInput trait) instead of reading
 * $raw_parameters directly. One deliberate, spec-compliant behavioural
 * consequence: since the 'enabled' Checkbox field is not marked required,
 * an entirely missing 'enabled' key in $raw_parameters is no longer
 * rejected - it now defaults to false, exactly like an unchecked, and
 * therefore never submitted, HTML checkbox would. Before this change,
 * normalizeParameters() rejected a missing 'enabled' key outright, which
 * contradicted getInputDescription()'s own (correct) "not required"
 * declaration.
 */
class SetLanguageDetectionEnabled extends ActivityImpl
{
    use GrindsFormInput;

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
Enables or disables the system-wide automatic language detection from the
browser's Accept-Language header. This is a single on/off flag for the
whole installation, not a per-language or per-user setting.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $enabled = $ui_factory->input()->field()->checkbox(
            'Enabled',
            'Whether automatic language detection from the browser should be enabled.'
        )->withDedicatedName('enabled');

        return $ui_factory->input()->field()->group([
            'enabled' => $enabled,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of changing the language detection setting.'),
            [
                'enabled' => $f->bool(
                    $this->markdown('The language detection setting after this change.')
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
        if (!is_array($parameters) || !array_key_exists('enabled', $parameters) || !is_bool($parameters['enabled'])) {
            throw new \InvalidArgumentException('The enabled parameter (bool) is required.');
        }

        $enabled = $parameters['enabled'];

        ($this->settings)()->set('lang_detection', $enabled ? '1' : '0');

        return ['enabled' => $enabled];
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
     * point, produced by the Checkbox field's own withInput().
     *
     * @param array{enabled: bool} $grind_result
     * @return array{enabled: bool}
     */
    private function normalizeParameters(array $grind_result): array
    {
        return ['enabled' => $grind_result['enabled']];
    }
}

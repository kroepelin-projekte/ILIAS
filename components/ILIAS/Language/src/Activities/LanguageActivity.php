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
use ILIAS\Data\Result;
use ILIAS\Data\Text;
use ILIAS\Data\Text\Shape\SimpleDocumentMarkdown as SimpleDocumentMarkdownShape;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Factory as UIFactory;

/**
 * Common base for every Command Activity in this component: all seven
 * (InstallLanguage, UpdateLanguage, UninstallLanguage,
 * RemoveLocalLanguageChanges, AddLanguageEntry, SetLanguageDetectionEnabled,
 * SetLanguageTranslationEnabled) share the exact same getType(), markdown(),
 * isAllowedToPerform() (a "write" RBAC check on the language folder ref_id)
 * and maybePerformAs() (grind $raw_parameters, check isAllowedToPerform(),
 * then perform()) - this class hoists that byte-for-byte duplication into
 * one place instead of seven, together with the constructor's
 * Closure-normalisation for $ui_factory/$rbac_system/$language_folder_ref_id
 * (see forSetup() factories on InstallLanguage/UpdateLanguage for why these
 * three are accepted as either a resolved value or a \Closure).
 *
 * A subclass only needs to implement the Activity methods that are actually
 * specific to it (getDescription(), getInputDescription(),
 * getOutputDescription(), perform()) plus normalizeParameters() (not part of
 * the public Activity interface, but required by maybePerformAs() here to
 * turn a grind()ed FormInput result into perform()'s expected shape).
 *
 * This class is intentionally NOT final: AddLanguageEntry overrides
 * maybePerformAs() itself, to pass its reserved 'usr_id'/'installed_language_keys'
 * parameters through to perform() (see its own class docblock) - a subclass
 * providing its own maybePerformAs() must stay possible.
 */
abstract class LanguageActivity extends ActivityImpl
{
    use GrindsFormInput;

    protected readonly RefineryFactory $refinery;
    protected Language $lng;
    protected readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;

    public function __construct(
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        $this->refinery = $refinery;
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
    }

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    protected function markdown(string $raw): Text\SimpleDocumentMarkdown
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
     * GrindsFormInput trait) - see each implementor for the exact shape.
     *
     * @return array<string, mixed>
     */
    abstract protected function normalizeParameters(array $grind_result): array;
}

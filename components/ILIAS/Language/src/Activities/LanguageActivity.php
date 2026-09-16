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
use ILIAS\UI\Component\Input\Factory as InputFactory;

abstract class LanguageActivity extends ActivityImpl
{
    use GrindsFormInput;

    protected readonly RefineryFactory $refinery;
    protected Language $lng;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;

    public function __construct(
        RefineryFactory $refinery,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        $this->refinery = $refinery;
        $this->lng = $language;
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

    public function maybePerformAs(InputFactory $input_factory, int $usr_id, array $raw_parameters): Result
    {
        try {
            // getInputDescription() itself, or $input_factory->field(), might throw (e.g. a
            // concrete Activity building its FormInput eagerly from other collaborators) - keep
            // this call inside the try block so any such exception is still wrapped in a
            // Result\Error, per the `maybePerformAs()` contract in Activity.php ("Wraps the
            // result and possible errors in the Result type"), rather than escaping uncaught.
            $grind_result = $this->grind($this->getInputDescription($input_factory->field()), $raw_parameters);
            if ($grind_result->isError()) {
                return new Result\Error($grind_result->error());
            }

            $parameters = $this->normalizeParameters($grind_result->value());
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform($parameters));
        } catch (\Throwable $e) {
            return new Result\Error(
                $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function normalizeParameters(array $grind_result): array;
}

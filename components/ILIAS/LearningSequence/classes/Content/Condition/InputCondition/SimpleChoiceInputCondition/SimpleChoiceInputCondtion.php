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

namespace ILIAS\LearningSequence\Content\Condition\InputCondition;

use ILIAS\LearningSequence\Content\Condition\AbstractCondition;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\InputCondition;
use ilLPStatus;

/**
 * Class SimpleChoiceInputCondtion
 */
final class SimpleChoiceInputCondtion extends AbstractCondition implements InputCondition
{
    final protected const NAME = "simple_choice";
    private ?int $condition_target_ref_id;

    public function __construct()
    {
        parent::__construct();
        $this->condition_target_ref_id = null;
    }

    /**
     * @inheritDoc
     */
    public function migrate(): array
    {
        // TODO: To implement
        return [];
    }

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return ilLPStatus::_hasUserCompleted(
            $this->condition_target_ref_id,
            $this->dic->user()->getId()
        );
    }

    /**
     * @inheritDoc
     */
    public function setupSteps(): array
    {
        // TODO: We need some Tree > Expandable to select the condition_target_ref_id
        return [];
    }
}

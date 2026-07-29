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

namespace ILIAS\LearningSequence\Content\Condition;

use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionsGUI;

abstract class AbstractLeafCondition extends AbstractCondition
{
    public function getStep(): \ILIAS\UI\Component\Link\Bulky
    {
        $this->dic->ctrl()->setParameterByClass(ilObjLearningSequenceConditionsGUI::class, 'condition', static::NAME);
        $this->dic->ctrl()->setParameterByClass(ilObjLearningSequenceConditionsGUI::class, 'item_ref_id', (string) $this->obj_ref_id);
        $this->dic->ctrl()->setParameterByClass(ilObjLearningSequenceConditionsGUI::class, 'ref_id', (string) $this->lso_ref_id);

        $uri = $this->buildUrl(ilObjLearningSequenceConditionsGUI::SAVE);
        $this->dic->ctrl()->clearParametersByClass(ilObjLearningSequenceConditionsGUI::class);

        return $this->ui_factory->link()->bulky(
            $this->buildIcon($this->getStepIconAbbreviation()),
            $this->getName(),
            $uri
        );
    }

    public function setupSteps(): array
    {
        return [$this->getStep()];
    }

    protected function getStepIconAbbreviation(): string
    {
        return '>';
    }
}

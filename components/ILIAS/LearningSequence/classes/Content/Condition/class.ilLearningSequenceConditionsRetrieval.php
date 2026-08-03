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

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

class ilLearningSequenceConditionsRetrieval implements DataRetrieval
{
    private ilObjLearningSequenceConditionDiscover $discoverer;
    private array $conditions;

    public function __construct(
        protected int $lso_ref_id,
        protected int $item_ref_id
    ) {
        $this->discoverer = new ilObjLearningSequenceConditionDiscover();
        $this->conditions = $this->discoverer->getAllConditionIdsForItem($this->item_ref_id);
    }

    /**
     * @throws ilException
     */
    public function getRows(DataRowBuilder $row_builder, array $visible_column_ids, Range $range, Order $order, mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): Generator
    {
        foreach ($this->conditions as $condition_id) {
            $condition = $this->discoverer->getConditionInstanceById($condition_id);

            if ($condition instanceof InputConditionInterface) {
                $type = 'InputCondition';
            } else {
                $type = 'OutputCondition';
            }

            yield $row_builder->buildDataRow(
                (string) $condition_id,
                [
                    'id' => (string) $condition_id,
                    'type' => $type,
                    'name' => $condition->getName()
                ]
            );
        }
    }

    /**
     * @param mixed $additional_viewcontrol_data
     * @param mixed $filter_data
     * @param mixed $additional_parameters
     * @return int|null
     */
    public function getTotalRowCount(mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): ?int
    {
        return count($this->conditions);
    }
}
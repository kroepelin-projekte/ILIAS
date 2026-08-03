<?php

declare(strict_types=1);

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

class ilLearningSequenceConditionsRetrieval implements DataRetrieval
{
    private ilObjLearningSequenceConditionDiscover $discoverer;

    public function __construct(
        protected int $lso_ref_id,
        protected int $item_ref_id
    ) {
        $this->discoverer = new ilObjLearningSequenceConditionDiscover();
    }

    public function getRows(DataRowBuilder $row_builder, array $visible_column_ids, Range $range, Order $order, mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): Generator
    {
        $input_conditions_steps = array_map(
            function(string $class): InputConditionInterface {
                $condition = new $class();
                $condition->setObjRefId($this->item_ref_id);
                $condition->setLsoRefId($this->lso_ref_id);
                return $condition;
            },
            $this->discoverer->getAllInputConditions()
        );

        $output_conditions_steps = array_map(
            function(string $class): OutputConditionInterface {
                $condition = new $class();
                $condition->setObjRefId($this->item_ref_id);
                $condition->setLsoRefId($this->lso_ref_id);
                return $condition;
            },
            $this->discoverer->getAllOutputConditions()
        );

        foreach ([...$input_conditions_steps, ...$output_conditions_steps] as $key => $condition) {
            yield $row_builder->buildDataRow(
                (string) $key,
                [
                    'id' => (string) $key,
                    'type' => 'i',
                    'name' => $condition->getName()
                ]
            );
        }
    }

    public function getTotalRowCount(mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): ?int
    {
        return 2;
    }
}
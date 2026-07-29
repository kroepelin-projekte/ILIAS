<?php

declare(strict_types=1);

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

class ilLearningSequenceConditionsOutpubRetrieval implements DataRetrieval
{
    public function getRows(DataRowBuilder $row_builder, array $visible_column_ids, Range $range, Order $order, mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): Generator
    {
        $rows = [
            [
                'condition_type' => 'Output Points',
            ],
            [
                'condition_type' => 'Output Always',
            ]
        ];

        foreach ($rows as $key => $row) {
            yield $row_builder->buildDataRow((string) $key, $row);
        }
    }

    public function getTotalRowCount(mixed $additional_viewcontrol_data, mixed $filter_data, mixed $additional_parameters): ?int
    {
        return 2;
    }
}
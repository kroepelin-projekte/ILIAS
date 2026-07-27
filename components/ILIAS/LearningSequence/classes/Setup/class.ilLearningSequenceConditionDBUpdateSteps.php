<?php

declare(strict_types=1);

use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;

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

class ilLearningSequenceConditionDBUpdateSteps implements \ilDatabaseUpdateSteps
{
    private const string TABLE_CONDITIONS = "lso_conditions";
    private const string TABLE_TYPES = "lso_condition_types";

    protected \ilDBInterface $db;

    public function prepare(\ilDBInterface $db): void
    {
        $this->db = $db;
    }

    public function step_1(): void
    {
        if (!$this->db->tableExists(self::TABLE_TYPES)) {
            $fields = [
                'type_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'condition_name' => [
                    'type' => 'text',
                    'length' => 255,
                    'notnull' => true
                ]
            ];
            $this->db->createTable(self::TABLE_TYPES, $fields);
            $this->db->addPrimaryKey(self::TABLE_TYPES, ["type_id"]);
            $this->db->createSequence(self::TABLE_TYPES);
        }
    }

    public function step_2(): void
    {
        if (!$this->db->tableExists(self::TABLE_CONDITIONS)) {
            $fields = [
                'condition_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'lso_ref_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'obj_ref_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'type_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ]
            ];
            $this->db->createTable(self::TABLE_CONDITIONS, $fields);
            $this->db->addPrimaryKey(self::TABLE_CONDITIONS, ["condition_id"]);
            $this->db->createSequence(self::TABLE_CONDITIONS);
        }
    }

}

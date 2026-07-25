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

class ilObjLearningSequenceContentBoundariesUpdateSteps implements \ilDatabaseUpdateSteps
{
    private const TABLE_NAME = "lso_item_boundaries";

    protected \ilDBInterface $db;

    public function prepare(\ilDBInterface $db): void
    {
        $this->db = $db;
    }

    public function step_1(): void
    {
        if (!$this->db->tableExists(self::TABLE_NAME)) {
            $fields = [
                'obj_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'start_ref_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => false
                ],
                'end_ref_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => false
                ]
            ];
            $this->db->createTable(self::TABLE_NAME, $fields);
            $this->db->addPrimaryKey(self::TABLE_NAME, ["obj_id"]);
        }
    }
}

<?php

namespace ILIAS\LearningSequence\Content\Condition;

abstract class ConditionHandler
{
    protected $db = null;

    public function __construct()
    {
        global $DIC;
        if (isset($DIC) && $DIC instanceof \ILIAS\DI\Container) {
            $this->db = $DIC->database();
        }
    }

    abstract public function getName(): string;

    /**
     * @return TableDefinition[]
     */
    abstract public function migrate(): array;

    /**
     * @return array[]
     */
    public function getInputConditions(int $lso_ref_id, int $obj_ref_id = 0): array
    {
        return $this->getConditionsByRefIdAndName($lso_ref_id, $this->getName(), $obj_ref_id);
    }

    /**
     * @return array[]
     */
    public function getOutputConditions(int $lso_ref_id, int $obj_ref_id = 0): array
    {
        return $this->getConditionsByRefIdAndName($lso_ref_id, $this->getName(), $obj_ref_id);
    }

    /**
     * @return array[]
     */
    protected function getConditionsByRefIdAndName(int $lso_ref_id, string $name, int $obj_ref_id = 0): array
    {
        $query = "SELECT c.* 
                  FROM lso_conditions c
                  INNER JOIN lso_condition_types t ON c.type_id = t.type_id
                  WHERE c.lso_ref_id = %s AND t.condition_name = %s";

        $types = ['integer', 'text'];
        $values = [$lso_ref_id, $name];

        if ($obj_ref_id > 0) {
            $query .= " AND c.obj_ref_id = %s";
            $types[] = 'integer';
            $values[] = $obj_ref_id;
        }

        $res = $this->db->queryF($query, $types, $values);
        $conditions = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $conditions[] = $row;
        }
        return $conditions;
    }

}

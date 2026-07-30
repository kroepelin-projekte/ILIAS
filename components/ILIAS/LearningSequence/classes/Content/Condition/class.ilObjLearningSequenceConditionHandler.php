<?php

namespace ILIAS\LearningSequence\Content\Condition;

class ConditionHandler
{
    protected ?\ilDBInterface $db = null;
    protected ilObjLearningSequenceConditionDiscover $discoverer;

    public function __construct(?\ilDBInterface $db = null)
    {
        global $DIC;
        $this->db = $db;
        if ($this->db === null && isset($DIC) && $DIC instanceof \ILIAS\DI\Container) {
            $this->db = $DIC->database();
        }
        $this->discoverer = new ilObjLearningSequenceConditionDiscover();
    }

    /**
     * @return array[]
     */
    public function getInputConditionsByRefId(int $lso_ref_id, int $obj_ref_id): array
    {
        $db_conditions = $this->getConditionsFromDb($lso_ref_id, $obj_ref_id, $this->discoverer->getAllInputConditions());
        return $this->formatConditionsForGui($db_conditions);
    }

    /**
     * @return array[]
     */
    public function getOutputConditionsByRefId(int $lso_ref_id, int $obj_ref_id): array
    {
        $db_conditions = $this->getConditionsFromDb($lso_ref_id, $obj_ref_id, $this->discoverer->getAllOutputConditions());
        return $this->formatConditionsForGui($db_conditions);
    }

    /**
     * @param array[] $db_conditions
     * @return array[]
     */
    protected function formatConditionsForGui(array $db_conditions): array
    {
        $result = [];
        foreach ($db_conditions as $db_cond) {
            $type_id = $db_cond['type_id'];
            $res = $this->db->queryF(
                "SELECT condition_name FROM lso_condition_types WHERE type_id = %s",
                ['integer'],
                [$type_id]
            );
            $row = $this->db->fetchAssoc($res);
            if ($row) {
                $class = $this->discoverer->getConditionByName($row['condition_name']);
                if ($class) {
                    try {
                        $result[] = [
                            'title' => $this->discoverer->getConditionTitleByClass($class),
                            'value' => '-',
                            'internal_name' => $row['condition_name']
                        ];
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @return array[]
     */
    protected function getConditionsFromDb(int $lso_ref_id, int $obj_ref_id, array $available_classes): array
    {
        if ($this->db === null) {
            return [];
        }

        $names = [];
        foreach ($available_classes as $class) {
            try {
                $reflection = new \ReflectionClass($class);
                if ($reflection->isInstantiable()) {
                    $names[] = $this->discoverer->getConditionNameByClass($class);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (count($names) === 0) {
            return [];
        }

        $query = "SELECT c.* 
                  FROM lso_conditions c
                  INNER JOIN lso_condition_types t ON c.type_id = t.type_id
                  WHERE c.lso_ref_id = %s AND c.obj_ref_id = %s AND " . $this->db->in('t.condition_name', $names, false, 'text');

        $res = $this->db->queryF($query, ['integer', 'integer'], [$lso_ref_id, $obj_ref_id]);
        $result = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $result[] = $row;
        }
        return $result;
    }
}

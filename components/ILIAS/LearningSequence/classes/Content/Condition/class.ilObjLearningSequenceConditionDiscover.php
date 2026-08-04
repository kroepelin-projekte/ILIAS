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

use ilException;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

class ilObjLearningSequenceConditionDiscover
{
    private const string BASE_PATH = __DIR__;

    /**
     * @return string[]
     */
    public function getAllInputConditions(): array
    {
        return $this->discover(InputConditionInterface::class);
    }

    /**
     * @return string[]
     */
    public function getAllOutputConditions(): array
    {
        return $this->discover(OutputConditionInterface::class);
    }

    /**
     * @return string[]
     */
    public function getAllConditions(): array
    {
        return array_unique(array_merge(
            $this->getAllInputConditions(),
            $this->getAllOutputConditions()
        ));
    }

    /**
     * @param string $class
     * @return string
     */
    public function getConditionNameByClass(string $class): string
    {
        $parts = explode('\\', $class);
        $className = end($parts);
        if (str_ends_with($className, 'Condition')) {
            return substr($className, 0, -9);
        }
        return $className;
    }

    public function getConditionTitleByClass(string $class): string
    {
        $name = $this->getConditionNameByClass($class);
        // CamelCase to Space separated
        return preg_replace('/(?<!^)[A-Z]/', ' $0', $name);
    }

    public function getConditionByName(string $name_to_find): ?string
    {
        return array_find($this->getAllConditions(), fn($class) => $this->getConditionNameByClass($class) === $name_to_find);
    }

    /**
     * @throws ilException
     */
    public function getConditionInstanceById(int $condition_id): AbstractCondition
    {
        global $DIC;
        $db = $DIC->database();

        $query = $db->queryF(
            'SELECT * FROM lso_conditions AS c JOIN lso_condition_types AS t ON c.type_id = t.type_id WHERE condition_id = %s',
            ['integer'],
            [$condition_id],
        );
        $record = $db->fetchAssoc($query);
        if (empty($record)) {
            throw new ilException('Condition not found');
        }
        return $this->getConditionInstanceByName($record['condition_name'], (int) $record['lso_ref_id'], (int) $record['obj_ref_id']);
    }

    /**
     * @throws ilException
     */
    public function getConditionInstanceByTypeId(int $type_id, int $lso_ref_id, int $item_ref_id, ?string $subtype = null): AbstractCondition
    {
        global $DIC;
        $db = $DIC->database();

        $query = $db->queryF(
            'SELECT condition_name FROM lso_condition_types WHERE type_id = %s',
            ['integer'],
            [$type_id],
        );
        $record = $db->fetchAssoc($query);
        if (empty($record)) {
            throw new ilException('Condition not found');
        }
        $condition_name = $record['condition_name'];
        return $this->getConditionInstanceByName($condition_name, $lso_ref_id, $item_ref_id, $subtype);

    }

    /**
     * @throws ilException
     */
    public function getConditionInstanceByName(string $condition_name, int $lso_ref_id, int $item_ref_id, ?string $subtype = null): AbstractCondition
    {
        foreach ($this->getAllConditions() as $class) {
            if (str_contains($class, $condition_name)) {
                /** @var AbstractCondition $condition */
                $condition = new $class();
                $condition->setLsoRefId($lso_ref_id);
                $condition->setObjRefId($item_ref_id);
                if (!is_null($subtype)) {
                    $condition->setSubtype($subtype);
                }
                return $condition;
            }
        }

        throw new ilException('Condition not found');
    }

    /**
     * @param int $item_ref_id
     * @return array
     */
    public function getAllConditionIdsForItem(int $item_ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        $query = $db->queryF(
            'SELECT * FROM lso_conditions WHERE obj_ref_id = %s',
            ['integer'],
            [$item_ref_id],
        );

        $conditions = [];
        while ($record = $db->fetchAssoc($query)) {
            $conditions[] = (int) $record['condition_id'];
        }
        return $conditions;

    }

    /**
     * @return string[]
     */
    private function discover(string $baseClassOrInterface): array
    {
        $classes = [];
        $path = self::BASE_PATH;

        if (!is_dir($path)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->isDir() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $filePath = $fileInfo->getRealPath();
            $content = file_get_contents($filePath);

            if (preg_match('/namespace\s+(.+?);/', $content, $m)) {
                $namespace = trim($m[1]);
                $className = $fileInfo->getBasename('.php');
                $fullClass = $namespace . "\\" . $className;

                try {
                    if (!class_exists($fullClass, true)) {
                        continue;
                    }
                    $reflection = new \ReflectionClass($fullClass);
                    if ($reflection->isInstantiable() &&
                        $reflection->isSubclassOf($baseClassOrInterface)
                    ) {
                        $classes[] = $fullClass;
                    }
                } catch (\Throwable $t) {
                    continue;
                }
            }
        }

        return $classes;
    }
}

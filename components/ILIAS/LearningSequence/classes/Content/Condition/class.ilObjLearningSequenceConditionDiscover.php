<?php

namespace ILIAS\LearningSequence\Content\Condition;

use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;
use ReflectionClass;

class ilObjLearningSequenceConditionDiscover
{
    private const string BASE_PATH = __DIR__;

    public function __construct()
    {
    }

    /**
     * @return string[]
     */
    public function getAllInputConditions(): array
    {
        return $this->discover(
            self::BASE_PATH . "/InputCondition",
            InputConditionInterface::class,
            "ILIAS\\LearningSequence\\Content\\Condition\\InputCondition"
        );
    }

    /**
     * @return string[]
     */
    public function getAllOutputConditions(): array
    {
        return $this->discover(
            self::BASE_PATH . "/OutputCondition",
            OutputConditionInterface::class,
            "ILIAS\\LearningSequence\\Content\\Condition\\OutputCondition"
        );
    }

    /**
     * @return string[]
     */
    public function getAllConditions(): array
    {
        return array_merge($this->getAllInputConditions(), $this->getAllOutputConditions());
    }

    public function getConditionByName(string $name): ?string
    {
        foreach ($this->getAllConditions() as $class) {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue;
            }
            /** @var ConditionAbstract $instance */
            $instance = $reflection->newInstance();
            if ($instance->getName() === $name) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function discover(string $path, string $interface, string $baseNamespace): array
    {
        $classes = [];
        if (!is_dir($path)) {
            return $classes;
        }

        foreach (scandir($path) as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }

            $folderPath = $path . '/' . $folder;
            if (!is_dir($folderPath)) {
                continue;
            }

            $filePath = $folderPath . '/' . $folder . 'Condition.php';
            if (file_exists($filePath)) {
                require_once $filePath;
                $className = $baseNamespace . "\\" . $folder . "\\" . $folder . 'Condition';
                if (!class_exists($className, false)) {
                    if (!class_exists($className, true)) {
                        continue;
                    }
                }
                if (is_subclass_of($className, $interface)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }
}

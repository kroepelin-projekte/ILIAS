<?php

namespace ILIAS\LearningSequence\Content\Condition;

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

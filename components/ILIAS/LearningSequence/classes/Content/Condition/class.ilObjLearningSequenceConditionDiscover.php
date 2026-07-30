<?php

namespace ILIAS\LearningSequence\Content\Condition;

use ReflectionClass;
use ILIAS\LearningSequence\Content\Condition\InputCondition\InputConditionInterface;
use ILIAS\LearningSequence\Content\Condition\OutputCondition\OutputConditionInterface;

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
            /** @var AbstractCondition $instance */
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

        $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                if (str_contains($file->getFilename(), 'Interface.php')) {
                    continue;
                }

                require_once $file->getPathname();

                $relativePath = substr($file->getPathname(), strlen($path));
                $classRelative = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relativePath);

                $className = $baseNamespace . '\\' . ltrim($classRelative, '\\');

                if (class_exists($className) && is_subclass_of($className, $interface)) {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->isAbstract()) {
                        $classes[] = $className;
                    }
                }
            }
        }

        return $classes;
    }
}

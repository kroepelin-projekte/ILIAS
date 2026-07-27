<?php

/*********************************************************************
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

namespace ILIAS\LearningSequence\Setup;

use ILIAS\Setup;
use ILIAS\Setup\Environment;
use ILIAS\Setup\Objective;
use ILIAS\LearningSequence\Content\Condition\ilObjLearningSequenceConditionDiscover;
use ILIAS\LearningSequence\Content\Condition\ConditionHandler;
use ilDBInterface;
use ilDatabaseInitializedObjective;
use ilDatabaseUpdateStepsExecutedObjective;
use ilLoggerFactory;
use ilLogger;
use ReflectionClass;

/**
 * Objective to ensure all Learning Sequence Conditions are registered in the database.
 */
class ilLearningSequenceConditionsSyncedObjective implements Objective
{
    public function __construct()
    {
    }

    public function getLabel(): string
    {
        return "Ensure all Learning Sequence Conditions are registered in Database";
    }

    public function isFulfilled(Environment $environment): bool
    {
        $db = $environment->getResource(Environment::RESOURCE_DATABASE);
        if (!$db instanceof ilDBInterface) {
            return true;
        }

        $discoverer = new ilObjLearningSequenceConditionDiscover();
        $conditions = $discoverer->getAllConditions();
        $expected_names = [];
        $expected_tables = [];

        foreach ($conditions as $class) {
            try {
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if (!$reflection->isInstantiable()) {
                    continue;
                }

                /** @var ConditionHandler $instance */
                $instance = $reflection->newInstance();
                $name = $instance->getName();
                $expected_names[] = $name;

                $res = $db->queryF(
                    "SELECT type_id FROM lso_condition_types WHERE condition_name = %s",
                    ['text'],
                    [$name]
                );
                $row = $db->fetchAssoc($res);
                if (!$row) {
                    return false;
                }

                $table_definitions = $instance->migrate();
                foreach ($table_definitions as $def) {
                    $expected_tables[] = $def->tableName;
                    if (!$db->tableExists($def->tableName)) {
                        return false;
                    }
                }
            } catch (\Throwable $t) {
                continue;
            }
        }
        $res = $db->query("SELECT condition_name FROM lso_condition_types");
        while ($row = $db->fetchAssoc($res)) {
            if (!in_array($row['condition_name'], $expected_names)) {
                return false;
            }
        }
        $all_tables = $db->listTables();
        foreach ($all_tables as $table) {
            if (str_starts_with($table, "lso_c_")) {
                if (!in_array($table, $expected_tables)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function achieve(Environment $environment): Environment
    {
        $db = $environment->getResource(Environment::RESOURCE_DATABASE);
        if (!$db instanceof ilDBInterface) {
            return $environment;
        }

        $discoverer = new ilObjLearningSequenceConditionDiscover();
        $conditions = $discoverer->getAllConditions();
        $expected_names = [];
        $expected_tables = [];

        foreach ($conditions as $class) {
            try {
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if (!$reflection->isInstantiable()) {
                    continue;
                }

                /** @var ConditionHandler $instance */
                $instance = $reflection->newInstance();
                $name = $instance->getName();
                $expected_names[] = $name;

                $res = $db->queryF(
                    "SELECT type_id FROM lso_condition_types WHERE condition_name = %s",
                    ['text'],
                    [$name]
                );
                $row = $db->fetchAssoc($res);
                if (!$row) {
                    $next_id = $db->nextId("lso_condition_types");
                    $db->insert("lso_condition_types", [
                        "type_id" => ["integer", $next_id],
                        "condition_name" => ["text", $name]
                    ]);
                }
                $table_definitions = $instance->migrate();
                foreach ($table_definitions as $def) {
                    $table_name = $def->tableName;
                    $expected_tables[] = $table_name;
                    if (!$db->tableExists($table_name)) {
                        $db->createTable($table_name, $def->fields);
                        if (count($def->primaryKeys) > 0) {
                            $db->addPrimaryKey($table_name, $def->primaryKeys);
                        }
                        if ($def->hasSequence) {
                            $db->createSequence($table_name);
                        }
                    }
                }
            } catch (\Throwable $t) {
                continue;
            }
        }
        $res = $db->query("SELECT condition_name FROM lso_condition_types");
        $names_in_db = [];
        while ($row = $db->fetchAssoc($res)) {
            $names_in_db[] = $row['condition_name'];
        }

        foreach ($names_in_db as $name_in_db) {
            if (!in_array($name_in_db, $expected_names)) {
                $db->manipulateF(
                    "DELETE FROM lso_condition_types WHERE condition_name = %s",
                    ['text'],
                    [$name_in_db]
                );
            }
        }
        $all_tables = $db->listTables();
        foreach ($all_tables as $table) {
            if (str_starts_with($table, "lso_c_")) {
                if (!in_array($table, $expected_tables)) {
                    if ($db->sequenceExists($table)) {
                        $db->dropSequence($table);
                    }
                    $db->dropTable($table);
                }
            }
        }

        return $environment;
    }

    public function getPreconditions(Environment $environment): array
    {
        return [
            new ilDatabaseInitializedObjective(),
            new ilDatabaseUpdateStepsExecutedObjective(
                new \ilLearningSequenceConditionDBUpdateSteps()
            )
        ];
    }

    public function getHash(): string
    {
        return hash("sha256", self::class);
    }

    public function isApplicable(Environment $environment): bool
    {
        return true;
    }

    public function isNotable(): bool
    {
        return true;
    }
}

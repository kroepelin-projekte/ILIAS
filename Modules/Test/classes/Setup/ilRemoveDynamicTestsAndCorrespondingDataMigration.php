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

namespace ILIAS\Test\Setup;

use ILIAS\Setup;
use ILIAS\Setup\Environment;
use ilDatabaseInitializedObjective;
use ilDatabaseUpdatedObjective;
use ilDBInterface;
use Exception;
use ILIAS\Setup\CLI\IOWrapper;

class ilRemoveDynamicTestsAndCorrespondingDataMigration implements Setup\Migration
{
    private const DEFAULT_AMOUNT_OF_STEPS = 10000;
    private ilDBInterface $db;

    /**
     * @var IOWrapper
     */
    private mixed $io;

    private bool $ilias_is_initialized = false;

    public function getLabel(): string
    {
        return "Delete All Data of Dynamic (CTM) Tests from Database.";
    }

    public function getDefaultAmountOfStepsPerRun(): int
    {
        return self::DEFAULT_AMOUNT_OF_STEPS;
    }

    public function getPreconditions(Environment $environment): array
    {
        return [
            new ilDatabaseInitializedObjective(),
            new ilDatabaseUpdatedObjective()
        ];
    }

    public function prepare(Environment $environment): void
    {
        $this->db = $environment->getResource(Setup\Environment::RESOURCE_DATABASE);
        $this->io = $environment->getResource(Environment::RESOURCE_ADMIN_INTERACTION);
    }

    public function step(Environment $environment): void
    {
        if (!$this->ilias_is_initialized) {
            //This is necessary for using ilObjects delete function to remove existing objects
            \ilContext::init(\ilContext::CONTEXT_CRON);
            \ilInitialisation::initILIAS();
            $this->ilias_is_initialized = true;
        }
        $row_test_info = $this->db->fetchObject(
            $this->db->query(
                'SELECT obj_fi, test_id FROM tst_tests '
                . 'WHERE '
                . $this->db->equals('question_set_type', 'DYNAMIC_QUEST_SET', \ilDBConstants::T_TEXT, true)
                . 'Limit 1'
            )
        );

        $row_ref_id = $this->db->fetchObject(
            $this->db->query(
                'SELECT ref_id FROM object_reference '
                . 'WHERE '
                . $this->db->equals('object_reference.obj_id', $row_test_info->obj_fi, \ilDBConstants::T_INTEGER)
            )
        );

        if ($row_ref_id !== null) {
            try {
                \ilRepUtil::removeObjectsFromSystem([$row_ref_id->ref_id]);
                return;
            } catch (\Exception $e) {
            }
        }

        try {
            (new \ilObjTest($row_test_info->obj_fi, false))->delete();
        } catch (\Exception $e) {
        }

        try {
            $test_obj = new \ilObjTest();
            $test_obj->setTestId($row_test_info->test_id);
            $test_obj->deleteTest();
        } catch (\Exception $e) {
        }
    }

    public function getRemainingAmountOfSteps(): int
    {
        $result = $this->db->query(
            "SELECT count(*) as cnt FROM tst_tests"
            . " WHERE " . $this->db->equals('question_set_type', 'DYNAMIC_QUEST_SET', 'text', true)
        );
        $row = $this->db->fetchAssoc($result);

        return (int) $row['cnt'] ?? 0;
    }
}
